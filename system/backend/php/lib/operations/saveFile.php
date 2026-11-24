<?php
trait OperationsRouteSaveFile {
  private function normalizeUploadCandidate($uploadCandidate)
  {
    if (!is_array($uploadCandidate) || !isset($uploadCandidate['tmp_name'])) {
      return null;
    }
    if (is_array($uploadCandidate['tmp_name'])) {
      foreach ($uploadCandidate['tmp_name'] as $index => $tmpName) {
        if (!is_string($tmpName) || trim($tmpName) == '') {
          continue;
        }
        return array(
          'name' => (
            isset($uploadCandidate['name']) &&
            is_array($uploadCandidate['name']) &&
            isset($uploadCandidate['name'][$index])
          ) ? $uploadCandidate['name'][$index] : '',
          'type' => (
            isset($uploadCandidate['type']) &&
            is_array($uploadCandidate['type']) &&
            isset($uploadCandidate['type'][$index])
          ) ? $uploadCandidate['type'][$index] : '',
          'tmp_name' => $tmpName,
          'error' => (
            isset($uploadCandidate['error']) &&
            is_array($uploadCandidate['error']) &&
            isset($uploadCandidate['error'][$index])
          ) ? $uploadCandidate['error'][$index] : 0,
          'size' => (
            isset($uploadCandidate['size']) &&
            is_array($uploadCandidate['size']) &&
            isset($uploadCandidate['size'][$index])
          ) ? $uploadCandidate['size'][$index] : 0,
        );
      }
      return null;
    }
    if (!is_string($uploadCandidate['tmp_name']) || trim($uploadCandidate['tmp_name']) == '') {
      return null;
    }
    return $uploadCandidate;
  }
  private function resolveUploadFromRequestFiles()
  {
    if (!isset($_FILES) || !is_array($_FILES)) {
      return null;
    }
    $preferredUploadKeys = array('file-upload', 'upload', 'file', 'files[]');
    foreach ($preferredUploadKeys as $uploadKey) {
      if (!isset($_FILES[$uploadKey])) {
        continue;
      }
      $normalizedUpload = $this->normalizeUploadCandidate($_FILES[$uploadKey]);
      if (!is_null($normalizedUpload)) {
        return $normalizedUpload;
      }
    }
    foreach ($_FILES as $uploadCandidate) {
      $normalizedUpload = $this->normalizeUploadCandidate($uploadCandidate);
      if (!is_null($normalizedUpload)) {
        return $normalizedUpload;
      }
    }
    return null;
  }
  public function saveFile() {
    // resolve front-end parsing issue with saveFiles based on how that was structured
    // this is a bit of a hack but site token will have the ?siteName in it as opposed to stand alone params
    if (isset($this->params['site_token']) && !isset($this->params['site'])) {
      $tmp = explode('?siteName=', $this->params['site_token']);
      if (count($tmp) == 2) {
        $this->params['site_token'] = $tmp[0];
        $this->params['site']['name'] = $tmp[1];
      }
    }
    $siteName = '';
    if (isset($this->params['site']) && isset($this->params['site']['name'])) {
      $siteName = (string) $this->params['site']['name'];
    }
    else if (isset($this->params['siteName'])) {
      $siteName = (string) $this->params['siteName'];
      $this->params['site']['name'] = $siteName;
    }
    else if (isset($this->params['site[name]'])) {
      $siteName = (string) $this->params['site[name]'];
      $this->params['site']['name'] = $siteName;
    }
    if ($siteName == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing site name',
        )
      );
    }
    if (!isset($this->params['node']) || !is_array($this->params['node'])) {
      $this->params['node'] = array();
    }
    $nodeId = '';
    if (isset($this->params['node']) && isset($this->params['node']['id'])) {
      $nodeId = (string) $this->params['node']['id'];
    }
    else if (isset($this->params['nodeId'])) {
      $nodeId = (string) $this->params['nodeId'];
      $this->params['node']['id'] = $nodeId;
    }
    else if (isset($this->params['node[id]'])) {
      $nodeId = (string) $this->params['node[id]'];
      $this->params['node']['id'] = $nodeId;
    }
    else if (isset($_GET['nodeId'])) {
      $nodeId = (string) $_GET['nodeId'];
      $this->params['node']['id'] = $nodeId;
    }
    else if (isset($_GET['node[id]'])) {
      $nodeId = (string) $_GET['node[id]'];
      $this->params['node']['id'] = $nodeId;
    }
    $upload = $this->resolveUploadFromRequestFiles();
    if (is_null($upload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing file upload',
        )
      );
    }
    if (isset($upload['error']) && intval($upload['error']) !== 0) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Upload failed before processing',
        )
      );
    }
    if (
      isset($this->params['site_token']) &&
      $GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['site_token'],
        $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName
      )
    ) {
      $rateCheck = $this->checkFileOpsRateLimit(
        $GLOBALS['HAXCMS']->getActiveUserName(),
        $siteName
      );
      if ($rateCheck !== null) {
        return $rateCheck;
      }
      $site = $GLOBALS['HAXCMS']->loadSite($siteName);
      if (!$this->platformAllows($site, 'uploadMedia')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Uploading media is disabled for this site',
          )
        );
      }
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      $page = null;
      if ($nodeId != '') {
        $page = $site->loadNode($nodeId);
      }
      // DRAFT plugin platform: file.before.save for the upload; a veto refuses it before it is stored
      $pluginUpload = null;
      $pluginOpts = array();
      if (getenv('HAXCMS_PLUGINS') !== 'off') {
        include_once __DIR__ . '/../plugins/HAXCMSPluginSystem.php';
        if (HAXCMSPluginSystem::listening($site)) {
          $pluginOpts = array('user' => HAXCMSPluginSystem::userRecord($this->getSiteTokenUserName()));
          $pluginUpload = array(
            'id' => null,
            'name' => isset($upload['name']) && is_string($upload['name']) ? $upload['name'] : '',
            'path' => '',
            'mimeType' => isset($upload['type']) && is_string($upload['type']) ? $upload['type'] : '',
            'size' => isset($upload['size']) ? (int) $upload['size'] : 0,
          );
          $pre = HAXCMSPluginSystem::before($site, 'file', $pluginUpload, null, $pluginOpts);
          if (!empty($pre['veto'])) {
            return HAXCMSPluginSystem::vetoFailure($pre['veto']);
          }
        }
      }
      $file = new HAXCMSFile();
      $fileResult = $file->save($upload, $site, $page);
      if ($fileResult['status'] == 500) {
        // a file refused for what it is answers 400; a failed write stays 500
        return array(
          '__failed' => array(
            'status' => HAXCMSFile::uploadRejectionStatus($fileResult['data']),
            'message' => $fileResult['data'],
          )
        );
      }
      // Security (SEC-UPLOAD, SEC-EXEC): use the server-sanitized filename
      // from the save result (data.file.name) instead of the raw attacker-
      // controlled $_FILES name, so user input never reaches the git commit
      // message. Mirrors the NodeJS backend (files.js:1218-1222).
      $savedFileName = 'upload';
      if (
        is_array($fileResult) &&
        isset($fileResult['data']['file']['name']) &&
        is_string($fileResult['data']['file']['name']) &&
        $fileResult['data']['file']['name'] !== ''
      ) {
        $savedFileName = (string) $fileResult['data']['file']['name'];
      }
      $site->gitCommit('File added: ' . $savedFileName);
      if ($pluginUpload !== null) {
        try {
          $stored = isset($fileResult['data']['file']) && is_array($fileResult['data']['file']) ? $fileResult['data']['file'] : array();
          HAXCMSPluginSystem::after($site, 'file', array_merge($pluginUpload, array(
            'id' => isset($stored['uuid']) ? $stored['uuid'] : null,
            'name' => $savedFileName,
            'path' => isset($stored['path']) ? (string) $stored['path'] : '',
          )), null, $pluginOpts);
        } catch (\Throwable $e) {
          // the file is stored; a plugin failing afterwards never undoes it
          error_log('HAXcms plugins: file after hooks failed: ' . $e->getMessage());
        }
      }
      return $fileResult;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }
}
