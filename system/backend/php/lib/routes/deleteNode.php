<?php
trait OperationsRouteDeleteNode {
  /**
   * @OA\Post(
   *    path="/deleteNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Delete a node"
   *   )
   * )
   */
  public function deleteNode() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);

      // Check platform configuration
      if (!$this->platformAllows($site, 'deletePage')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Delete is disabled for this site',
          )
        );
      }
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      if ($page = $site->loadNode($this->params['node']['id'])) {
          if ($site->deleteNode($page) === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to delete',
              )
            );
          } else {
            // now, we need to look for orphans if we deleted anything
            $orphanCheck = $site->manifest->items;
            foreach ($orphanCheck as $key => $item) {
              // just to be safe..
              if ($page = $site->loadNode($item->id)) {
                // ensure that parent is valid to rescue orphan items
                if ($page->parent != null && !($parentPage = $site->loadNode($page->parent))) {
                  $page->parent = null;
                  // force to bottom of things while still being in old order if lots of things got axed
                  $page->order = (int)$page->order + count($site->manifest->items) - 1;
                  $site->updateNode($page);
                }
              }
            }
            $site->gitCommit(
              'Page deleted: ' . $page->title . ' (' . $page->id . ')'
            );
            return array(
              'status' => 200,
              'data' => $page
            );
          }
          exit();
      } else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'failed to delete',
          )
        );
      }
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
