<?php
include_once dirname(__FILE__) . '/../FilesDataStore.php';
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
      // #3043: page.metadata.files is now an array of uuid strings (stable,
      // no rewrite needed). The files.json datastore is copied into the clone
      // and its path/fullUrl prefixes are rewritten inside it, PRESERVING
      // uuids (per #3043: "uuids for files don't get rewritten if we clone
      // the site"). Legacy object-shape page.metadata.files entries on old
      // source sites are left as-is and self-heal to uuids on the next page
      // save; the clone's files.json is lazily auto-built on first list load.
      $targetPrefix = $basePath . '/' . $sitesDirectory . '/' . $cloneName . '/files/';
      $cloneSiteDirectory = $site->directory . '/' . $cloneName;
      $cloneFilesJsonPath = $cloneSiteDirectory . '/files/files.json';
      if (is_file($cloneFilesJsonPath)) {
        $filesJsonContents = @file_get_contents($cloneFilesJsonPath);
        if ($filesJsonContents !== false && $filesJsonContents !== '') {
          $decoded = json_decode($filesJsonContents, true);
          if (is_array($decoded) && isset($decoded['data']) && isset($decoded['data']['files']) && is_array($decoded['data']['files'])) {
            $rewritten = false;
            foreach ($decoded['data']['files'] as $fIdx => $record) {
              $path = isset($record['path']) ? (string) $record['path'] : '';
              $fullUrl = isset($record['fullUrl']) ? (string) $record['fullUrl'] : '';
              if ($path !== '') {
                // Rewrite the files/ path prefix — the path stays relative
                // (files/...) so only the fullUrl prefix actually changes.
                $newPath = str_replace(
                  array($configuredSourcePrefix, $legacySourcePrefix),
                  $targetPrefix,
                  $path
                );
                // Normalize back to files/... if the prefix rewrite produced
                // an absolute path (the canonical form is relative).
                $filesPos = strpos($newPath, 'files/');
                if ($filesPos !== false && $filesPos > 0) {
                  $newPath = substr($newPath, $filesPos);
                }
                $decoded['data']['files'][$fIdx]['path'] = $newPath;
                if ($newPath !== $path) { $rewritten = true; }
              }
              if ($fullUrl !== '') {
                $newFullUrl = str_replace(
                  array($configuredSourcePrefix, $legacySourcePrefix),
                  $targetPrefix,
                  $fullUrl
                );
                $decoded['data']['files'][$fIdx]['fullUrl'] = $newFullUrl;
                if ($newFullUrl !== $fullUrl) { $rewritten = true; }
              }
              // url mirrors path
              if (isset($decoded['data']['files'][$fIdx]['url']) && $path !== '') {
                $decoded['data']['files'][$fIdx]['url'] = $decoded['data']['files'][$fIdx]['path'];
              }
              // site name in the envelope
            }
            $decoded['site'] = $cloneName;
            if ($rewritten || $decoded['site'] !== $cloneName) {
              @file_put_contents($cloneFilesJsonPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            }
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
