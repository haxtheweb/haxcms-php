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
            $deletedPage = $page;
            $items = $site->manifest->items;
            // the deleted page's children move up one level, into its place
            $moved = OutlineUpkeep::adoptChildren($items, (object) array(
              'id' => $deletedPage->id,
              'parent' => isset($deletedPage->parent) ? $deletedPage->parent : null,
              'order' => isset($deletedPage->order) ? $deletedPage->order : 0,
            ));
            // any other page whose parent is gone (left by an older save)
            // goes to the end of the top level, as before
            foreach ($items as $item) {
              if (is_object($item) && isset($item->parent) && $item->parent !== null && $item->parent !== '' && !$site->loadNode($item->parent)) {
                $item->parent = null;
                $item->order = (int) $item->order + count($items) - 1;
                $moved[] = $item;
              }
            }
            // the moved pages' addresses (and their branches') drop the
            // deleted parent's slug, no two siblings share an order, and
            // indent follows the new depth
            if (count($moved) > 0) {
              foreach ($moved as $movedPage) {
                OutlineUpkeep::reslugPageAndBranch($site, $movedPage, $items);
              }
              $parents = array();
              foreach ($moved as $movedPage) {
                $parentKey = OutlineUpkeep::parentOf($movedPage);
                $parents[$parentKey === null ? "\0" : (string) $parentKey] = $parentKey;
              }
              foreach ($parents as $parentId) {
                OutlineUpkeep::healSiblingOrder($items, $parentId);
              }
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
