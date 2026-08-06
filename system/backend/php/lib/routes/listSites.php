<?php
trait OperationsRouteListSites {
  /**
   * @OA\Get(
   *    path="/listSites",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Load a list of all sites the user has created"
   *   )
   * )
   */
  public function listSites() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // top level fake JOS
      $return = array(
        "id" => "123-123-123-123",
        "title" => "My sites",
        "author" => "me",
        "description" => "All of my micro sites I know and love",
        "license" => "by-sa",
        "metadata" => array(
          "pageCount" => 0
        ),
        "items" => array()
      );
      // loop through files directory so we can cache those things too
      if ($handle = opendir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory)) {
        while (false !== ($item = readdir($handle))) {
          if ($item != "." && $item != ".." && is_dir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item) && file_exists(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json')) {
            $json = file_get_contents(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json');
            $site = json_decode($json);
            // F6: don't filter by title (Node listSites.js includes all valid
            // sites). Just verify the json_decode produced a valid site object.
            if ($site && is_object($site)) {
              $site->location = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
              $site->slug = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
              if (isset($site->metadata) && is_object($site->metadata)) {
                $site->metadata->pageCount = isset($site->items) && is_array($site->items) ? count($site->items) : 0;
              }
              // we don't need all items stored here
              unset($site->items);
              $return['items'][] = $site;
            }
          }
        }
        closedir($handle);
      }
      return array(
        "status" => 200,
        "data" => $return
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
