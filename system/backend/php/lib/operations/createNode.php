<?php
include_once dirname(__FILE__) . '/../MaterializeInlineImages.php';
include_once dirname(__FILE__) . '/../SanitizeContent.php';
trait OperationsRouteCreateNode {
  public function createNode() {
    $nodeParams = $this->params;
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $nodeParams['site']['name'])) {
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
      if (isset($nodeParams['items'])) {
        // create pages
        for ($i=0; $i < count($nodeParams['items']); $i++) {
          // outline-designer allows delete + confirmation but we don't have anything
          // so instead, just don't process the thing in question if asked to delete it
          if (isset($nodeParams['items'][$i]['delete']) && $nodeParams['items'][$i]['delete'] == TRUE) {
            // do nothing
          }
          else {
            $item = $site->addPage(
              $nodeParams['items'][$i]['parent'], 
              $nodeParams['items'][$i]['title'], 
              'html', 
              $nodeParams['items'][$i]['slug'],
              $nodeParams['items'][$i]['id'],
              $nodeParams['items'][$i]['indent'],
              ((isset($nodeParams['items'][$i]['content']) && $nodeParams['items'][$i]['content'] != '') ? $nodeParams['items'][$i]['content'] : (isset($nodeParams['items'][$i]['contents']) ? $nodeParams['items'][$i]['contents'] : '')),
              (isset($nodeParams['items'][$i]['order']) ? $nodeParams['items'][$i]['order'] : null),
              (isset($nodeParams['items'][$i]['metadata']) ? $nodeParams['items'][$i]['metadata'] : null)
            );  
          }
        }
        $site->gitCommit(count($nodeParams['items']) . ' pages added'); 
      }
      else {
        // generate a new item based on the site
        $item = $site->itemFromParams($nodeParams);
        $item->metadata->images = array();
        $item->metadata->videos = array();
        // generate the boilerplate to fill this page
        $site->recurseCopy(
            HAXCMS_ROOT . '/system/boilerplate/page/default',
            $site->directory .
                '/' .
                $site->manifest->metadata->site->name .
                '/' .
                str_replace('/index.html', '', $item->location)
        );
        // add the item back into the outline schema
        $site->manifest->addItem($item);
        $site->manifest->save();
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
            if (!isset($page->metadata) || !is_object($page->metadata)) {
              $page->metadata = new stdClass();
            }
            $page->metadata->files = isset($materialized['uuids']) && is_array($materialized['uuids'])
              ? $materialized['uuids']
              : array();
            if (count($page->metadata->files) > 0) {
              $site->manifest->save();
            }
          }
        }
        if ($page = $site->loadNode($item->id)) {
          $site->writePageAlternateFormats($page, $alternateContent);
        }
        $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')'); 
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
