<?php
include_once dirname(__FILE__) . '/../OutlineUpkeep.php';
include_once dirname(__FILE__) . '/../MaterializeInlineImages.php';
include_once dirname(__FILE__) . '/../SanitizeContent.php';
trait OperationsRouteCreateNode {
  /**
   * The id a create request asks for that is already taken, or '' when none
   * is. A page's id is also its folder (pages/<id>/index.html), so creating a
   * page under an id the site already has would leave two outline items on
   * one file and replace the first page's content. Checked for the whole
   * request before anything is written: against the pages the site has, and
   * against the other new pages in the same request. Ids are compared as they
   * are stored (generateMachineName), so two spellings of one id count as the
   * same. Mirrors haxcms-nodejs createNode.
   */
  private function findRequestedPageIdConflict($site, $nodeParams) {
    $taken = array();
    if (isset($site->manifest->items) && is_array($site->manifest->items)) {
      foreach ($site->manifest->items as $existing) {
        if (isset($existing->id)) {
          $taken[(string) $existing->id] = true;
        }
      }
    }
    $requested = array();
    if (isset($nodeParams['items']) && is_array($nodeParams['items']) && count($nodeParams['items']) > 0) {
      foreach ($nodeParams['items'] as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        // entries marked delete are skipped by the create loop
        if (isset($entry['delete']) && $entry['delete'] == TRUE) {
          continue;
        }
        // same reading of the id as addPage: a whole number is its string
        $requested[] = JSONOutlineSchema::normalizeOutlineId(isset($entry['id']) ? $entry['id'] : null);
      }
    }
    else if (isset($nodeParams['node']) && is_array($nodeParams['node'])) {
      $requested[] = isset($nodeParams['node']['id']) ? $nodeParams['node']['id'] : null;
    }
    foreach ($requested as $raw) {
      // no id asked for: the server makes a new one
      if ($raw === null || $raw === '' || $raw === false || !is_scalar($raw)) {
        continue;
      }
      $id = $GLOBALS['HAXCMS']->generateMachineName((string) $raw);
      if (isset($taken[$id])) {
        return $id;
      }
      $taken[$id] = true;
    }
    return '';
  }
  public function createNode() {
    $nodeParams = $this->params;
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $this->getSiteTokenUserName() . ':' . $nodeParams['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite(strtolower($nodeParams['site']['name']));
      
      // Check platform configuration
      if (!$this->platformAllows($site, 'addPage')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Adding pages is disabled for this site',
          )
        );
      }
      // implies we've been TOLD to create nodes
      // this is typically from a docx import
      // (same test as the items route: an empty items array is no bulk payload)
      $hasItems = isset($nodeParams['items']) && is_array($nodeParams['items']) && count($nodeParams['items']) > 0;
      if (!$hasItems && (!isset($nodeParams['node']) || !is_array($nodeParams['node']))) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Node payload is required',
          )
        );
      }
      // a page id that is already taken is refused before anything is written
      // (the same answer saveOutline gives a new id sent twice)
      if ($this->findRequestedPageIdConflict($site, $nodeParams) !== '') {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Each new outline item requires its own id',
          )
        );
      }
      if ($hasItems) {
        $item = null;
        // DRAFT plugin platform: every page about to be added goes to the
        // before hooks first, as one bulk transaction; a veto refuses the
        // whole request before a folder is made
        $pluginOpts = array('origin' => 'createNode');
        $pluginChanges = array();
        if (getenv('HAXCMS_PLUGINS') !== 'off') {
          include_once __DIR__ . '/../plugins/HAXCMSPluginSystem.php';
          $pluginOpts['user'] = HAXCMSPluginSystem::userRecord($this->getSiteTokenUserName());
          if (HAXCMSPluginSystem::listening($site, $pluginOpts)) {
            $pluginChanges = HAXCMSPluginSystem::outlineChanges(array(), $nodeParams['items']);
          }
        }
        if (count($pluginChanges) > 0) {
          $pre = HAXCMSPluginSystem::beforeMany($site, 'page', $pluginChanges, $pluginOpts);
          if (!empty($pre['veto'])) {
            return HAXCMSPluginSystem::vetoFailure($pre['veto']);
          }
        }
        // create pages; site.json is written and the generated files rebuilt
        // once for the whole request, not once per page (haxtheweb/issues#3248)
        $bulk = new stdClass();
        $bulk->createdFolders = array();
        $added = 0;
        try {
          for ($i=0; $i < count($nodeParams['items']); $i++) {
            // outline-designer allows delete + confirmation but we don't have anything
            // so instead, just don't process the thing in question if asked to delete it
            if (isset($nodeParams['items'][$i]['delete']) && $nodeParams['items'][$i]['delete'] == TRUE) {
              // do nothing
            }
            else {
              // fields like parent/slug/id/indent are optional for bulk page
              // creation; addPage() falls back to sane defaults for nulls
              $item = $site->addPage(
                (isset($nodeParams['items'][$i]['parent']) ? $nodeParams['items'][$i]['parent'] : null),
                $nodeParams['items'][$i]['title'],
                'html',
                (isset($nodeParams['items'][$i]['slug']) ? $nodeParams['items'][$i]['slug'] : null),
                (isset($nodeParams['items'][$i]['id']) ? $nodeParams['items'][$i]['id'] : null),
                (isset($nodeParams['items'][$i]['indent']) ? $nodeParams['items'][$i]['indent'] : null),
                ((isset($nodeParams['items'][$i]['content']) && $nodeParams['items'][$i]['content'] != '') ? $nodeParams['items'][$i]['content'] : (isset($nodeParams['items'][$i]['contents']) ? $nodeParams['items'][$i]['contents'] : '')),
                (isset($nodeParams['items'][$i]['order']) ? $nodeParams['items'][$i]['order'] : null),
                (isset($nodeParams['items'][$i]['metadata']) ? $nodeParams['items'][$i]['metadata'] : null),
                $bulk
              );
              $added++;
            }
          }
        } catch (Throwable $e) {
          // a page failed part way: keep the pages added before it, as the
          // per-page writes did, then report the error
          if ($added > 0 || count($bulk->createdFolders) > 0) {
            $site->finishBulkAddPages($bulk);
          }
          throw $e;
        }
        if ($added > 0) {
          $site->finishBulkAddPages($bulk);
        }
        // every entry was marked delete: nothing was created or committed
        if (is_null($item)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'Invalid items payload (every item is marked delete)',
            )
          );
        }
        $site->gitCommit(count($nodeParams['items']) . ' pages added'); 
        if (count($pluginChanges) > 0) {
          HAXCMSPluginSystem::afterMany($site, 'page', $pluginChanges, $pluginOpts);
        }
      }
      else {
        // generate a new item based on the site
        $item = $site->itemFromParams($nodeParams);
        // DRAFT plugin page.before.save for the new page: a veto refuses it
        // before anything is copied
        $pluginsOn = getenv('HAXCMS_PLUGINS') !== 'off';
        $pluginOpts = array();
        if ($pluginsOn) {
          include_once __DIR__ . '/../plugins/HAXCMSPluginSystem.php';
          $pluginOpts['user'] = HAXCMSPluginSystem::userRecord($this->getSiteTokenUserName());
          $pre = HAXCMSPluginSystem::before($site, 'page', json_decode(json_encode($item), true), null, $pluginOpts);
          if (!empty($pre['veto'])) {
            return HAXCMSPluginSystem::vetoFailure($pre['veto']);
          }
        }
        $item->metadata->images = array();
        $item->metadata->videos = array();
        // generate the boilerplate to fill this page
        $pageFolder = $site->directory .
            '/' .
            $site->manifest->metadata->site->name .
            '/' .
            str_replace('/index.html', '', $item->location);
        $folderExisted = file_exists($pageFolder);
        $site->recurseCopy(
            HAXCMS_ROOT . '/system/boilerplate/page/default',
            $pageFolder
        );
        // add the item back into the outline schema
        $site->manifest->addItem($item);
        // place it among its siblings: at the order asked for (whoever held
        // that spot moves down), or at the end when no order was sent, so no
        // two siblings share an order; indent is the page's depth
        if ($placed = $site->loadNode($item->id)) {
          $requestedOrder = is_array($nodeParams) && array_key_exists('order', $nodeParams) ? $nodeParams['order'] : null;
          // the client counts orders as positions among the other pages, so
          // number those 0..n-1 before reading the order it sent
          OutlineUpkeep::compactSiblingOrders($site->manifest->items, $placed);
          if (is_int($requestedOrder) || is_float($requestedOrder) || (is_string($requestedOrder) && preg_match('/^\s*-?\d+/', $requestedOrder))) {
            $placed->order = (int) $requestedOrder;
            OutlineUpkeep::makeRoomFor($site->manifest->items, $placed);
          } else {
            $placed->order = OutlineUpkeep::lastSiblingOrder($site->manifest->items, OutlineUpkeep::parentOf($placed), $placed) + 1;
          }
          OutlineUpkeep::compactSiblingOrders($site->manifest->items);
          OutlineUpkeep::syncIndentToDepth($site->manifest->items);
          $item->order = $placed->order;
          $item->indent = $placed->indent;
        }
        try {
          $site->manifest->save();
        } catch (Throwable $e) {
          // no page folder without its outline entry
          if (!$folderExisted) {
            $site->removeCreatedPageFolder($pageFolder);
          }
          throw $e;
        }
        $alternateContent = '';
        // support for duplicating the content of another item
        if (isset($nodeParams['node']['duplicate'])) {
          // verify we can load this id
          if ($nodeToDuplicate = $site->loadNode($nodeParams['node']['duplicate'])) {
            $content = $site->getPageContent($nodeToDuplicate);
            // verify we actually have the id of an item that we just created
            if ($page = $site->loadNode($item->id)) {
              // write it to the file system
              // this all seems round about but it's more secure
              $alternateContent = SanitizeContent::sanitizeHTMLForStorage($content);
              $bytes = $page->writeLocation(
                $alternateContent,
                HAXCMS_ROOT .
                '/' .
                $GLOBALS['HAXCMS']->sitesDirectory .
                '/' .
                $site->manifest->metadata->site->name .
                '/'
              );
            }
          }
        }
        // implies front end was told to generate a page with set content
        // this is possible when importing and processing a file to generate
        // html which becomes the boilerplated content in effect
        else if (isset($nodeParams['node']['contents'])) {
          if ($page = $site->loadNode($item->id)) {
            // write it to the file system
            // #2945 / #3043: materialize inline images; associate FileEntity uuids
            $pageTitle = $item->title;
            if (isset($nodeParams['node']['title']) && is_string($nodeParams['node']['title'])) {
              $pageTitle = $nodeParams['node']['title'];
            }
            $materialized = MaterializeInlineImages::materialize(
              $nodeParams['node']['contents'],
              $site,
              array('pageTitle' => $pageTitle)
            );
            $alternateContent = SanitizeContent::sanitizeHTMLForStorage($materialized['html']);
            $bytes = $page->writeLocation(
              $alternateContent,
              HAXCMS_ROOT .
              '/' .
              $GLOBALS['HAXCMS']->sitesDirectory .
              '/' .
              $site->manifest->metadata->site->name .
              '/'
            );
            // only a page that brought files gets the list (same rule as
            // addPage and haxcms-nodejs)
            $uuids = isset($materialized['uuids']) && is_array($materialized['uuids'])
              ? $materialized['uuids']
              : array();
            if (count($uuids) > 0) {
              if (!isset($page->metadata) || !is_object($page->metadata)) {
                $page->metadata = new stdClass();
              }
              $page->metadata->files = $uuids;
              $site->manifest->save();
            }
          }
        }
        if ($page = $site->loadNode($item->id)) {
          $site->writePageAlternateFormats($page, $alternateContent);
        }
        $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')'); 
        if ($pluginsOn) {
          $pluginStored = $site->loadNode($item->id);
          HAXCMSPluginSystem::after($site, 'page', json_decode(json_encode($pluginStored ? $pluginStored : $item), true), null, $pluginOpts);
        }
        // update the alternate formats as a new page exists
        $site->updateAlternateFormats();
      }
      return array(
        'status' => 200,
        'data' => $item
      );
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
