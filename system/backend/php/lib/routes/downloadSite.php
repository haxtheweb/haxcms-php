<?php
trait OperationsRouteDownloadSite {
  /**
   * @OA\Post(
   *    path="/downloadSite",
   *    tags={"cms","authenticated","site","meta"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Download the site folder as a zip file"
   *   )
   * )
   */
  public function downloadSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // load site
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      // helpful boilerplate https://stackoverflow.com/questions/29873248/how-to-zip-a-whole-directory-and-download-using-php
      $dir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name;
      // form a basic name
      $zip_file =
        HAXCMS_ROOT .
        '/' .
        $GLOBALS['HAXCMS']->publishedDirectory .
        '/' .
        $site->manifest->metadata->site->name .
        '.zip';
      // Get real path for our folder
      $rootPath = realpath($dir);
      // Initialize archive object
      $zip = new ZipArchive();
      $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
      // Create recursive directory iterator
      $directory = new RecursiveDirectoryIterator($rootPath);
      $filtered = new DirFilter($directory, array('node_modules'));
      $files = new RecursiveIteratorIterator($filtered);
      foreach ($files as $name => $file) {
        // Skip directories (they would be added automatically)
        if (!$file->isDir()) {
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
}
