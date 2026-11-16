<?php
include_once dirname(__FILE__) . '/../OutlineUpkeep.php';
trait OperationsRouteDeleteNode {
  public function deleteNode() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $this->getSiteTokenUserName() . ':' . $this->params['site']['name'])) {
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
          // a locked page is changed by nothing but unlocking it
          if (OutlineUpkeep::isLocked($page)) {
            return array(
              '__failed' => array(
                'status' => 423,
                'message' => 'Page is locked',
                'reason' => 'locked',
              )
            );
          }
          if ($site->deleteNode($page) === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to delete',
              )
            );
          } else {
            // capture the deleted page before the orphan loop clobbers $page
            $deletedPage = $page;
            // now, we need to look for orphans if we deleted anything
            $orphanCheck = $site->manifest->items;
            $orphans = array();
            foreach ($orphanCheck as $key => $item) {
              // just to be safe..
              if ($orphanPage = $site->loadNode($item->id)) {
                // ensure that parent is valid to rescue orphan items
                if ($orphanPage->parent != null && !($parentPage = $site->loadNode($orphanPage->parent))) {
                  $orphanPage->parent = null;
                  // force to bottom of things while still being in old order if lots of things got axed
                  $orphanPage->order = (int)$orphanPage->order + count($site->manifest->items) - 1;
                  $orphans[] = $orphanPage;
                }
              }
            }
            // the rescued pages now live at the top level: their addresses
            // (and their branches') drop the deleted parent's slug, no two top
            // level pages share an order, and indent follows the new depth
            if (count($orphans) > 0) {
              $items = $site->manifest->items;
              foreach ($orphans as $orphan) {
                OutlineUpkeep::reslugPageAndBranch($site, $orphan, $items);
              }
              OutlineUpkeep::healSiblingOrder($items, null);
              OutlineUpkeep::syncIndentToDepth($items);
              $site->manifest->save(false);
              $site->updateAlternateFormats();
            }
            $site->gitCommit(
              'Page deleted: ' . $deletedPage->title . ' (' . $deletedPage->id . ')'
            );
            return array(
              'status' => 200,
              'data' => $deletedPage
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
