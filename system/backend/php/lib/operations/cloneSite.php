<?php
trait OperationsRouteCloneSite {
  public function cloneSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // security (F2/IDOR-001): object-level authorization before filesystem access
      if (!isset($this->params['site']['name']) || !$GLOBALS['HAXCMS']->userCanAccessSite($this->params['site']['name'])) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied to site',
          )
        );
      }
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->site->name;
      $originalSiteName = $site->manifest->metadata->site->name;
      // F6: build the file-path rewrite prefix from the configured basePath +
      // sitesDirectory instead of hardcoding /sites/<name>/files/ (mirror Node
      // cloneSite.js:99-155). Keep the legacy /sites/ prefix as a fallback
      // source so existing paths that use it are still rewritten correctly.
      $basePath = isset($GLOBALS['HAXCMS']->basePath) ? rtrim((string) $GLOBALS['HAXCMS']->basePath, '/') : '';
      $sitesDirectory = isset($GLOBALS['HAXCMS']->sitesDirectory) && $GLOBALS['HAXCMS']->sitesDirectory != ''
        ? $GLOBALS['HAXCMS']->sitesDirectory : '_sites';
      $configuredSourcePrefix = $basePath . '/' . $sitesDirectory . '/' . $originalSiteName . '/files/';
      $legacySourcePrefix = '/sites/' . $originalSiteName . '/files/';
      $cloneName = $GLOBALS['HAXCMS']->getUniqueName($site->name);
      // ensure the path to the new folder is valid
      // resolve symlinks so that mirror copies real contents instead of recreating links
      $sourcePath = realpath(
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name
      );
      if ($sourcePath === false) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Source site path could not be resolved',
          )
        );
      }
      $GLOBALS['fileSystem']->mirror(
          $sourcePath,
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $cloneName
      );
      // we need to then load and rewrite the site name var or it will conflict given the name change
      $site = $GLOBALS['HAXCMS']->loadSite($cloneName);
      $site->manifest->metadata->site->name = $cloneName;
      $site->manifest->id = $GLOBALS['HAXCMS']->generateUUID();
      // loop through all items and rewrite the path to files as we cloned it
      $targetPrefix = $basePath . '/' . $sitesDirectory . '/' . $cloneName . '/files/';
      foreach ($site->manifest->items as $delta => $item) {
        if (isset($item->metadata->files)) {
          foreach ($item->metadata->files as $delta2 => $file) {
            // F6: replace both the configured prefix and the legacy /sites/
            // prefix with the configured target prefix.
            $site->manifest->items[$delta]->metadata->files[$delta2]->path = str_replace(
              array($configuredSourcePrefix, $legacySourcePrefix),
              $targetPrefix,
              $site->manifest->items[$delta]->metadata->files[$delta2]->path
            );
            $site->manifest->items[$delta]->metadata->files[$delta2]->fullUrl = str_replace(
              array($configuredSourcePrefix, $legacySourcePrefix),
              $targetPrefix,
              $site->manifest->items[$delta]->metadata->files[$delta2]->fullUrl
            );
          }
        }
      }
      $site->save();
      return array(
        'status' => 200,
        'data' => array(
          'detail' =>
            $GLOBALS['HAXCMS']->basePath .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $cloneName,
          'name' => $cloneName
        ),
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
