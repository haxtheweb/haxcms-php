<?php
trait OperationsRouteDownloadSite {
  /**
   * Seconds a generated download zip stays in the published directory before
   * the next downloadSite call removes it. Long enough for the browser to
   * follow the link it was just handed, short enough that a site export does
   * not sit on disk indefinitely.
   */
  public static $downloadZipTtlSeconds = 900;

  public function downloadSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // Security (SEC-ROUTE): object-level authorization before filesystem access
      if (!isset($this->params['site']['name']) || !$GLOBALS['HAXCMS']->userCanAccessSite($this->params['site']['name'])) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied to site',
          )
        );
      }
      // load site
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      // helpful boilerplate https://stackoverflow.com/questions/29873248/how-to-zip-a-whole-directory-and-download-using-php
      $dir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name;
      $publishedDir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->publishedDirectory;
      if (!is_dir($publishedDir)) {
        @mkdir($publishedDir, 0755, true);
      }
      // Security: the published directory is web-served. Earlier exports sat
      // there forever under the guessable name <site>.zip, so anyone who
      // knew a site name could fetch its full export (including .git
      // history) without logging in. Sweep stale exports first, then write
      // this one under a name only the caller is told.
      self::sweepStaleDownloadZips($publishedDir, self::$downloadZipTtlSeconds);
      $zipName = self::randomDownloadZipName($site->manifest->metadata->site->name);
      $zip_file = $publishedDir . '/' . $zipName;
      // Get real path for our folder
      $rootPath = realpath($dir);
      // Initialize archive object
      $zip = new ZipArchive();
      $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
      // Create recursive directory iterator
      $directory = new RecursiveDirectoryIterator($rootPath);
      // .git is the site's full history: not site content, and it can hold
      // revisions the author deliberately removed. Matches haxcms-nodejs.
      $filtered = new DirFilter($directory, array('node_modules', '.git'));
      $files = new RecursiveIteratorIterator($filtered);
      foreach ($files as $name => $file) {
        // Skip directories (they would be added automatically) and symlinks.
        // A scaffolded site links build, dist, wc-registry.json and the babel
        // files to the multi-site root; those are multi-site plumbing, not
        // site content, and a link's real path lies outside $rootPath so the
        // relative name cut from it below would be wrong.
        if (!$file->isDir() && !$file->isLink()) {
          // Get real and relative path for current file
          $filePath = $file->getRealPath();
          $relativePath = substr($filePath, strlen($rootPath) + 1);
          // Add current file to archive
          if ($filePath != '' && $relativePath != '') {
            $zip->addFile($filePath, $relativePath);
          }
        }
      }
      // Zip archive will be created only after closing object
      $zip->close();
      return array(
        'status' => 200,
        'data' => array(
          'link' =>
            $GLOBALS['HAXCMS']->basePath .
            $GLOBALS['HAXCMS']->publishedDirectory .
            '/' .
            basename($zip_file),
          'name' => basename($zip_file)
        )
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }

  /**
   * <siteName>-<32 hex chars>.zip: unguessable, still recognisable in the
   * browser's download list. 128 bits from random_bytes.
   */
  public static function randomDownloadZipName($siteName) {
    return $siteName . '-' . bin2hex(random_bytes(16)) . '.zip';
  }

  /**
   * Remove download zips in $publishedDir whose mtime is older than
   * $ttlSeconds. Only top-level *.zip files are touched; anything else that
   * may live in the published directory is left alone. Legacy <site>.zip
   * exports from before random names are swept the same way.
   */
  public static function sweepStaleDownloadZips($publishedDir, $ttlSeconds) {
    if (!is_dir($publishedDir)) {
      return 0;
    }
    $removed = 0;
    $cutoff = time() - (int) $ttlSeconds;
    $entries = @scandir($publishedDir);
    if (!is_array($entries)) {
      return 0;
    }
    foreach ($entries as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      if (strtolower(substr($entry, -4)) !== '.zip') {
        continue;
      }
      $path = $publishedDir . '/' . $entry;
      if (is_link($path) || !is_file($path)) {
        continue;
      }
      $mtime = @filemtime($path);
      if ($mtime !== false && $mtime < $cutoff) {
        if (@unlink($path)) {
          $removed++;
        }
      }
    }
    return $removed;
  }
}
