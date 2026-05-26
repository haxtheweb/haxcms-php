<?php
trait OperationsRouteListFiles {
  /**
   * @OA\Get(
   *    path="/listFiles",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load existing files for presentation in HAX find area"
   *   )
   * )
   */
  public function listFiles() {
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
    if (
      !isset($this->params['site_token']) ||
      $siteName == '' ||
      !$GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName)
    ) {
      return array();
    }
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site) {
      return array();
    }
    $search = (isset($this->params['filename'])) ? (string) $this->params['filename'] : '';
    $fileDir = $this->getSiteFilesDirectory($site);
    return $this->collectSiteFiles($site, $fileDir, $search);
  }
}
