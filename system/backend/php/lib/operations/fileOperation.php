<?php
include_once dirname(__FILE__) . '/../MediaSettingsService.php';
include_once dirname(__FILE__) . '/../FilesDataStore.php';
include_once dirname(__FILE__) . '/../pptxDeckHelper.php';
/**
 * #3043: After a file operation (compress, scale, sepia, rotate-90,
 * convert-jpg, rename, duplicate), upsert the updated record into the
 * per-site files.json datastore so the uuid's metadata (size, dimensions,
 * mtime, fullUrl cache-buster) is current.
 *
 * For in-place transforms (compress, scale, sepia, black-and-white,
 * rotate-90) the uuid is preserved — files.json owns identity (hybrid
 * model), so the uuid stays stable across size/content changes while
 * the metadata updates. For new-path operations (convert-jpg to a new
 * filename, duplicate) a new uuid is assigned from the new path+size.
 */
trait OperationsRouteFileOperation {
  /**
   * Upsert a file record into files.json after an operation. Builds a
   * fresh record from disk (current size/dimensions/mtime) and preserves
   * the existing uuid when the path was already indexed (in-place transforms).
   *
   * @param mixed $site The site context.
   * @param string $normalizedPath The 'files/...' API path of the file.
   * @param string|null $oldNormalizedPath For rename: the pre-rename path
   *   so the existing uuid can be carried over to the new path.
   */
  private function upsertFileRecordInDataStore($site, $normalizedPath, $oldNormalizedPath = null) {
    $dataStore = new FilesDataStore($site);
    // Look up the existing uuid. For in-place ops this is the same path;
    // for rename, look up by the OLD path (the file moved).
    $lookupPath = $oldNormalizedPath !== null ? $oldNormalizedPath : $normalizedPath;
    $existing = $dataStore->getByPath($lookupPath);
    $existingUuid = '';
    if (is_array($existing) && isset($existing['uuid'])) {
      $existingUuid = (string) $existing['uuid'];
    }
    // Build a fresh record from disk (new size, dimensions, mtime, fullUrl).
    $record = $dataStore->buildFileRecordFromDisk($normalizedPath);
    if (!is_array($record)) {
      return;
    }
    // Preserve the existing uuid for in-place transforms / rename (hybrid
    // model: files.json owns identity, uuid stable across content changes).
    if ($existingUuid !== '' && isset($record['uuid'])) {
      $record['uuid'] = $existingUuid;
    }
    $dataStore->upsertRecord($record);
    // For rename: remove the old-path record if the uuid changed or the
    // old path is now stale. The path index rebuilds on upsert, but if the
    // old path had a different uuid (edge case), scrub it.
    if ($oldNormalizedPath !== null && $oldNormalizedPath !== $normalizedPath) {
      $oldRecord = $dataStore->getByPath($oldNormalizedPath);
      if (is_array($oldRecord) && isset($oldRecord['uuid']) && $oldRecord['uuid'] !== $existingUuid) {
        $dataStore->removeRecord($oldRecord['uuid']);
      }
    }
  }
  public function fileOperation() {
    if (isset($this->params['site_token']) && !isset($this->params['site']) && !isset($this->params['siteName'])) {
      $tmp = explode('?siteName=', $this->params['site_token']);
      if (count($tmp) == 2) {
        $this->params['site_token'] = $tmp[0];
        $this->params['siteName'] = $tmp[1];
      }
    }
    $siteName = '';
    if (isset($this->params['site']) && isset($this->params['site']['name'])) {
      $siteName = (string) $this->params['site']['name'];
    }
    else if (isset($this->params['siteName'])) {
      $siteName = (string) $this->params['siteName'];
    }
    if (!isset($this->params['site_token'])) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Missing site token',
        )
      );
    }
    if ($siteName == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing site name',
        )
      );
    }
    if (!$GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName)) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Invalid site token',
        )
      );
    }
    $rateCheck = $this->checkFileOpsRateLimit(
      $GLOBALS['HAXCMS']->getActiveUserName(),
      $siteName
    );
    if ($rateCheck !== null) {
      return $rateCheck;
    }
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site) {
      return array(
        '__failed' => array(
          'status' => 404,
          'message' => 'Site not found',
        )
      );
    }
    if (!$this->platformAllows($site, 'uploadMedia')) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'File operations are disabled for this site',
        )
      );
    }
    $mediaSettings = array();
    try {
      $mediaSettings = HAXCMSMediaSettingsService::readMediaSettings($GLOBALS['HAXCMS']);
    }
    catch (Exception $e) {
      $mediaSettings = array();
    }
    $jpegQuality = null;
    if (is_array($mediaSettings) && array_key_exists('jpegQuality', $mediaSettings)) {
      $jpegQuality = $mediaSettings['jpegQuality'];
    }
    $operation = isset($this->params['operation']) ? trim((string) $this->params['operation']) : '';
    if (!in_array($operation, array('delete', 'rename', 'convert-jpg', 'scale', 'sepia', 'black-and-white', 'rotate-90', 'compress', 'duplicate', 'convert-pptx-deck'), true)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Unsupported file operation',
        )
      );
    }
    $requestedPath = '';
    if (isset($this->params['path'])) {
      $requestedPath = $this->params['path'];
    }
    else if (isset($this->params['filePath'])) {
      $requestedPath = $this->params['filePath'];
    }
    else if (isset($this->params['file'])) {
      $requestedPath = $this->params['file'];
    }
    if (is_array($requestedPath) || is_object($requestedPath)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Only a single file path is allowed per request',
        )
      );
    }
    $pathResult = $this->resolveSiteFileOperationPath($site, $requestedPath);
    if (!$pathResult['valid']) {
      return array(
        '__failed' => array(
          'status' => $pathResult['status'],
          'message' => $pathResult['message'],
        )
      );
    }
    if ($operation == 'convert-pptx-deck') {
      // The .pptx stays where it was uploaded in files/ (no move). The deck
      // folder files/decks/<name>/ gets only deck.json + extracted media.
      // The manifest's pptx field references the original files/ path.
      $sourceExtension = strtolower(pathinfo($pathResult['normalizedPath'], PATHINFO_EXTENSION));
      if ($sourceExtension !== 'pptx') {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'convert-pptx-deck requires a .pptx file',
          )
        );
      }
      $firstBytes = @file_get_contents($pathResult['resolvedPath'], false, null, 0, 4);
      if ($firstBytes === false || strlen($firstBytes) < 4 || substr($firstBytes, 0, 2) !== 'PK') {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'File is not a valid .pptx (missing ZIP signature)',
          )
        );
      }
      try {
        $deckData = haxcmsSystemBuildPptxDeckManifest($pathResult['resolvedPath']);
      } catch (\Exception $e) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Error processing PPTX: ' . $e->getMessage(),
          )
        );
      }
      $manifestSlides = $deckData['slides'];
      $extractedFiles = $deckData['extractedFiles'];
      // Derive the deck folder name from the .pptx filename: strip extension,
      // sanitize to alphanumeric/hyphen/underscore.
      $baseDeckName = preg_replace('/\.pptx$/i', '', basename($pathResult['normalizedPath']));
      $baseDeckName = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $baseDeckName);
      if ($baseDeckName === '' || $baseDeckName === null) {
        $baseDeckName = 'deck';
      }
      // Uniquify the deck folder name (-1, -2, ... matching archiveSite pattern)
      // so a repeated convert never silently overwrites a prior deck's files.
      $deckName = $baseDeckName;
      $deckCounter = 1;
      while (is_dir($pathResult['filesRoot'] . '/decks/' . $deckName)) {
        $deckName = $baseDeckName . '-' . $deckCounter;
        $deckCounter++;
      }
      $deckDirAbsolute = $pathResult['filesRoot'] . '/decks/' . $deckName;
      $deckDirRelative = 'files/decks/' . $deckName;
      // Create the deck directory (Symfony Filesystem::mkdir is recursive)
      if (isset($GLOBALS['fileSystem']) && is_object($GLOBALS['fileSystem'])) {
        $GLOBALS['fileSystem']->mkdir($deckDirAbsolute);
      } else {
        @mkdir($deckDirAbsolute, 0777, true);
      }
      // Write extracted media to the deck folder (basename strips path components)
      foreach ($extractedFiles as $fileReference => $extracted) {
        $destName = basename($fileReference);
        @file_put_contents($deckDirAbsolute . '/' . $destName, base64_decode($extracted['buffer']));
      }
      // Rewrite slide image src from the converter's deck-agnostic default
      // (files/pptx-media/) to where the media actually landed (files/decks/<name>/)
      $deckSlides = array();
      foreach ($manifestSlides as $slide) {
        $slideHtml = is_string($slide['html'])
          ? str_replace('files/pptx-media/', 'files/decks/' . $deckName . '/', $slide['html'])
          : $slide['html'];
        $deckSlides[] = array(
          'number' => $slide['number'],
          'title'  => $slide['title'],
          'html'   => $slideHtml,
          'notes'  => $slide['notes'],
        );
      }
      // The manifest's pptx field references the ORIGINAL files/ path (not
      // files/decks/<name>/original.pptx) since the .pptx was never moved.
      $deckManifest = array(
        'title'  => $deckName,
        'source' => basename($pathResult['normalizedPath']),
        'pptx'   => $pathResult['normalizedPath'],
        'slides' => $deckSlides,
      );
      @file_put_contents(
        $deckDirAbsolute . '/deck.json',
        json_encode($deckManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
      );
      // Register deck.json + extracted media in the per-site files.json index
      // via FilesDataStore so they are immediately discoverable via
      // /x/api/v1/files. The original .pptx record is already there from the
      // upload (unchanged).
      $dataStore = new FilesDataStore($site);
      $deckJsonRecord = $dataStore->buildFileRecordFromDisk($deckDirRelative . '/deck.json');
      if ($deckJsonRecord !== null) {
        $dataStore->upsertRecord($deckJsonRecord);
      }
      foreach ($extractedFiles as $fileReference => $extracted) {
        $destName = basename($fileReference);
        $mediaRecord = $dataStore->buildFileRecordFromDisk($deckDirRelative . '/' . $destName);
        if ($mediaRecord !== null) {
          $dataStore->upsertRecord($mediaRecord);
        }
      }
      $site->gitCommit(
        'PPTX deck converted: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $deckDirRelative . '/deck.json'
      );
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'deckPath'  => $deckDirRelative . '/deck.json',
          'embedHtml' => '<slide-deck source="' . $deckDirRelative . '/deck.json"></slide-deck>',
          'manifest'  => $deckManifest,
        )
      );
    }
    if ($operation == 'delete') {
      if (!@unlink($pathResult['resolvedPath'])) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to delete file',
          )
        );
      }
      $site->gitCommit('File deleted: ' . $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'path' => $pathResult['normalizedPath'],
          'deleted' => true,
        )
      );
    }
    if ($operation == 'rename') {
      $renameValue = '';
      if (isset($this->params['newName'])) {
        $renameValue = $this->params['newName'];
      }
      else if (isset($this->params['name'])) {
        $renameValue = $this->params['name'];
      }
      else if (isset($this->params['value'])) {
        $renameValue = $this->params['value'];
      }
      $renameResult = $this->buildRenamedFilePath($pathResult, $renameValue);
      if (!$renameResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $renameResult['status'],
            'message' => $renameResult['message'],
          )
        );
      }
      if (!@rename($pathResult['resolvedPath'], $renameResult['outputPath'])) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to rename file',
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $renameResult['outputPath'],
        $renameResult['relativePath']
      );
      $site->gitCommit(
        'File renamed: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $renameResult['relativePath']
      );
      // #3043: carry the uuid to the new path in files.json (uuid stable,
      // path/name/fullUrl updated; old path record scrubbed if stale).
      $this->upsertFileRecordInDataStore($site, $renameResult['relativePath'], $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'path' => $renameResult['relativePath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'rotate-90') {
      $rotateResult = $this->rotateImageInPlaceFile(
        $pathResult['resolvedPath'],
        90
      );
      if (!$rotateResult['success']) {
        return array(
          '__failed' => array(
            'status' => $rotateResult['status'],
            'message' => $rotateResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $pathResult['resolvedPath'],
        $pathResult['normalizedPath']
      );
      $site->gitCommit('File rotated (90deg): ' . $pathResult['normalizedPath']);
      // #3043: update the uuid's metadata in files.json (in-place transform —
      // uuid preserved, size/dimensions/mtime refreshed).
      $this->upsertFileRecordInDataStore($site, $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'path' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'convert-jpg') {
      // #3043: write the converted JPG in the SAME directory as the source
      // file (files/<basename>.jpg), not under files/imgops/. The output
      // path is derived from the validated source path so it stays within
      // the files/ directory. If the source is already a .jpg the output
      // path equals the source — an in-place re-encode.
      $sourceBasename = pathinfo($pathResult['normalizedPath'], PATHINFO_FILENAME);
      $outputRelativePath = dirname($pathResult['normalizedPath']) . '/' . $sourceBasename . '.jpg';
      if (strpos($outputRelativePath, '/') === 0) {
        $outputRelativePath = ltrim($outputRelativePath, '/');
      }
      $outputAbsolutePath = dirname($pathResult['resolvedPath']) . '/' . $sourceBasename . '.jpg';
      // Security: verify the output path stays within the files root.
      $resolvedOutput = realpath(dirname($outputAbsolutePath));
      $normalizedFilesRoot = rtrim($this->normalizeFilePathValue($pathResult['filesRoot']), '/');
      if ($resolvedOutput === false || (rtrim($this->normalizeFilePathValue($resolvedOutput), '/') !== $normalizedFilesRoot && strpos(rtrim($this->normalizeFilePathValue($resolvedOutput), '/'), $normalizedFilesRoot . '/') !== 0)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Invalid output file path',
          )
        );
      }
      $conversionResult = $this->convertImageToJpgFile(
        $pathResult['resolvedPath'],
        $outputAbsolutePath,
        'none',
        $jpegQuality
      );
      if (!$conversionResult['success']) {
        return array(
          '__failed' => array(
            'status' => $conversionResult['status'],
            'message' => $conversionResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $outputAbsolutePath,
        $outputRelativePath
      );
      $site->gitCommit(
        'File converted to JPG: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $outputRelativePath
      );
      // #3043: upsert the new/updated file's record into files.json. For a
      // new path (png -> jpg) this assigns a new uuid; for an in-place
      // re-encode (jpg -> jpg) the existing uuid is preserved.
      $this->upsertFileRecordInDataStore($site, $outputRelativePath);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'sepia' || $operation == 'black-and-white') {
      // Apply the transform in place (preserving the original format/filename)
      // so the operation does not create a new file in the site files list.
      $transformResult = $this->transformImageInPlaceFile(
        $pathResult['resolvedPath'],
        $operation,
        $jpegQuality
      );
      if (!$transformResult['success']) {
        return array(
          '__failed' => array(
            'status' => $transformResult['status'],
            'message' => $transformResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $pathResult['resolvedPath'],
        $pathResult['normalizedPath']
      );
      $site->gitCommit(
        'File transformed (' .
        $operation .
        '): ' .
        $pathResult['normalizedPath']
      );
      // #3043: update the uuid's metadata in files.json (in-place transform —
      // uuid preserved, size/dimensions/mtime refreshed).
      $this->upsertFileRecordInDataStore($site, $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'path' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'duplicate') {
      $duplicateResult = $this->getDuplicateFilePath($pathResult);
      if (!$duplicateResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $duplicateResult['status'],
            'message' => $duplicateResult['message'],
          )
        );
      }
      if (!@copy($pathResult['resolvedPath'], $duplicateResult['outputPath'])) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to duplicate file',
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $duplicateResult['outputPath'],
        $duplicateResult['relativePath']
      );
      $site->gitCommit(
        'File duplicated: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $duplicateResult['relativePath']
      );
      // #3043: upsert the new file's record into files.json (new path ->
      // new uuid from path+size).
      $this->upsertFileRecordInDataStore($site, $duplicateResult['relativePath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'path' => $duplicateResult['relativePath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'compress') {
      $compressLevel = $this->getCompressLevelByKey(
        isset($this->params['level']) ? $this->params['level'] : ''
      );
      $compressResult = $this->compressImageInPlaceFile(
        $pathResult['resolvedPath'],
        $compressLevel['quality']
      );
      if (!$compressResult['success']) {
        return array(
          '__failed' => array(
            'status' => $compressResult['status'],
            'message' => $compressResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $pathResult['resolvedPath'],
        $pathResult['normalizedPath']
      );
      $site->gitCommit(
        'File compressed (' .
        $compressLevel['key'] .
        '): ' .
        $pathResult['normalizedPath']
      );
      // #3043: update the uuid's metadata in files.json after compression
      // (in-place transform — uuid preserved, new size/dimensions/mtime).
      $this->upsertFileRecordInDataStore($site, $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'path' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    $presetResult = $this->getScalePresetByKey(
      isset($this->params['size']) ? $this->params['size'] : ''
    );
    $scaleResult = $this->scaleImageInPlaceFile(
      $pathResult['resolvedPath'],
      $presetResult['preset']['width'],
      $presetResult['preset']['height'],
      $jpegQuality
    );
    if (!$scaleResult['success']) {
      return array(
        '__failed' => array(
          'status' => $scaleResult['status'],
          'message' => $scaleResult['message'],
        )
      );
    }
    $fileRecord = $this->buildSiteFileRecord(
      $site,
      $pathResult['resolvedPath'],
      $pathResult['normalizedPath']
    );
    $site->gitCommit(
      'File scaled (' .
      $presetResult['key'] .
      '): ' .
      $pathResult['normalizedPath']
    );
    // #3043: update the uuid's metadata in files.json after scaling
    // (in-place transform — uuid preserved, new size/dimensions/mtime).
    $this->upsertFileRecordInDataStore($site, $pathResult['normalizedPath']);
    return array(
      'status' => 200,
      'data' => array(
        'operation' => $operation,
        'path' => $pathResult['normalizedPath'],
        'file' => $fileRecord,
      )
    );
  }
}
