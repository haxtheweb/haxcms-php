<?php
trait OperationsRouteArchiveSite {
  /**
   * @OA\Post(
   *    path="/archiveSite",
   *    tags={"cms","authenticated","site"},
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
   *        description="Archive a site by moving it on the file system"
   *   )
   * )
   */
  public function archiveSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if ($site->manifest->metadata->site->name) {
        rename(
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name,
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->archivedDirectory . '/' . $site->manifest->metadata->site->name);
        return array(
          'status' => 200,
          'data' => array(
            'name' => $site->name,
            'detail' => 'Site archived',
          )
        );
      }
      else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Site does not exist',
          )
        );
      }
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
