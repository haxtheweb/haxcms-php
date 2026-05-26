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
        "description" => "All of my micro sites I know and love.",
        "license" => "by-sa",
        "metadata" => array(),
        "items" => array()
      );
      // loop through files directory so we can cache those things too
      if ($handle = opendir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory)) {
        while (false !== ($item = readdir($handle))) {
          if ($item != "." && $item != ".." && is_dir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item) && file_exists(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json')) {
            $json = file_get_contents(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json');
            $site = json_decode($json);
            if (isset($site->title)) {
              $site->indent = 0;
              $site->order = 0;
              $site->parent = null;
              $site->location = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
              $site->slug = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
              $site->metadata->pageCount = count($site->items);
              // we don't need all items stored here
              unset($site->items);
              // unset other things we don't need to send across in this meta site.json response
              if (isset($site->metadata->dynamicElementLoader)) {
                unset($site->metadata->dynamicElementLoader);
              }
              
              if (isset($site->metadata->node)) {
                unset($site->metadata->node);
              }
              if (isset($site->metadata->build->items)) {
                unset($site->metadata->build->items);
              }
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
