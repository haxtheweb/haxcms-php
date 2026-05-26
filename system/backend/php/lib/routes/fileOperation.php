<?php
trait OperationsRouteFileOperation {
  /**
   * @OA\\Post(
   *    path=\"/fileOperation\",
   *    tags={\"hax\",\"authenticated\",\"file\"},
   *    @OA\\Response(
   *        response=\"200\",
   *        description=\"Perform file operations for a site file\"
   *   )
   * )
   */
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
    $operation = isset($this->params['operation']) ? trim((string) $this->params['operation']) : '';
    if (!in_array($operation, array('delete', 'rename', 'convert-jpg', 'scale', 'sepia', 'black-and-white', 'rotate-90'), true)) {
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
      $sourceDimensions = @getimagesize($pathResult['resolvedPath']);
      $targetWidth = (is_array($sourceDimensions) && isset($sourceDimensions[0]) && $sourceDimensions[0] > 0)
        ? (int) $sourceDimensions[0]
        : (int) $this->imageScalePresets['md']['width'];
      $targetHeight = (is_array($sourceDimensions) && isset($sourceDimensions[1]) && $sourceDimensions[1] > 0)
        ? (int) $sourceDimensions[1]
        : (int) $this->imageScalePresets['md']['height'];
      $outputResult = $this->buildImageOpsOutputPath(
        $pathResult['filesRoot'],
        $pathResult['normalizedPath'],
        $targetWidth,
        $targetHeight
      );
      if (!$outputResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $outputResult['status'],
            'message' => $outputResult['message'],
          )
        );
      }
      $conversionResult = $this->convertImageToJpgFile(
        $pathResult['resolvedPath'],
        $outputResult['outputPath']
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
        $outputResult['outputPath'],
        $outputResult['relativePath']
      );
      $site->gitCommit(
        'File converted to JPG: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $outputResult['relativePath']
      );
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
      $sourceDimensions = @getimagesize($pathResult['resolvedPath']);
      $targetWidth = (is_array($sourceDimensions) && isset($sourceDimensions[0]) && $sourceDimensions[0] > 0)
        ? (int) $sourceDimensions[0]
        : (int) $this->imageScalePresets['md']['width'];
      $targetHeight = (is_array($sourceDimensions) && isset($sourceDimensions[1]) && $sourceDimensions[1] > 0)
        ? (int) $sourceDimensions[1]
        : (int) $this->imageScalePresets['md']['height'];
      $outputResult = $this->buildImageOpsOutputPath(
        $pathResult['filesRoot'],
        $pathResult['normalizedPath'] . '-' . $operation,
        $targetWidth,
        $targetHeight
      );
      if (!$outputResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $outputResult['status'],
            'message' => $outputResult['message'],
          )
        );
      }
      $conversionResult = $this->convertImageToJpgFile(
        $pathResult['resolvedPath'],
        $outputResult['outputPath'],
        $operation
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
        $outputResult['outputPath'],
        $outputResult['relativePath']
      );
      $site->gitCommit(
        'File transformed (' .
        $operation .
        '): ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $outputResult['relativePath']
      );
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    $presetResult = $this->getScalePresetByKey(
      isset($this->params['size']) ? $this->params['size'] : ''
    );
    $outputResult = $this->buildImageOpsOutputPath(
      $pathResult['filesRoot'],
      $pathResult['normalizedPath'],
      $presetResult['preset']['width'],
      $presetResult['preset']['height']
    );
    if (!$outputResult['valid']) {
      return array(
        '__failed' => array(
          'status' => $outputResult['status'],
          'message' => $outputResult['message'],
        )
      );
    }
    $scaleResult = $this->scaleImageToPresetFile(
      $pathResult['resolvedPath'],
      $outputResult['outputPath'],
      $presetResult['preset']['width'],
      $presetResult['preset']['height']
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
      $outputResult['outputPath'],
      $outputResult['relativePath']
    );
    $site->gitCommit(
      'File scaled (' .
      $presetResult['key'] .
      '): ' .
      $pathResult['normalizedPath'] .
      ' -> ' .
      $outputResult['relativePath']
    );
    return array(
      'status' => 200,
      'data' => array(
        'operation' => $operation,
        'source' => $pathResult['normalizedPath'],
        'size' => $presetResult['key'],
        'dimensions' => array(
          'width' => $presetResult['preset']['width'],
          'height' => $presetResult['preset']['height'],
        ),
        'presets' => $presetResult['presets'],
        'file' => $fileRecord,
      )
    );
  }
}
