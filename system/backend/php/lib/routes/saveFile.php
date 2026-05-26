<?php
trait OperationsRouteSaveFile {
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
    if (
      isset($this->params['site_token']) &&
      $siteName != '' &&
      $nodeId != '' &&
      $GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['site_token'],
        $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName
      ) &&
      isset($_FILES['file-upload'])
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
      $page = $site->loadNode($nodeId);
      $upload = $_FILES['file-upload'];
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
      $site->gitCommit('File added: ' . $upload['name']);
      return $fileResult;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Invalid file request',
        )
      );
    }
  }
}
