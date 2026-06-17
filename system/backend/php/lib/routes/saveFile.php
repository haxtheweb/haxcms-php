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
  /**
   * @OA\Post(
   *    path="/saveFile",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="file-upload",
   *         description="File to upload",
   *         in="header",
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
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                    "node": {
   *                      "id": ""
   *                    }
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="User is uploading a file to present in a site"
   *   )
   * )
   */
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
      $file = new HAXCMSFile();
      $fileResult = $file->save($upload, $site, $page);
      if ($fileResult['status'] == 500) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => $fileResult['data'],
          )
        );
      }
      $uploadName = isset($upload['name']) ? $upload['name'] : 'upload';
      $site->gitCommit('File added: ' . $uploadName);
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
