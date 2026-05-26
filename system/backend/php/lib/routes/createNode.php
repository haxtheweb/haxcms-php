<?php
trait OperationsRouteCreateNode {
  /**
   * @OA\Post(
   *     path="/createNode",
   *     tags={"cms","authenticated","node"},
   *     @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *     ),
   *     @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="items",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="indent",
   *                     type="number"
   *                 ),
   *                 @OA\Property(
   *                     property="order",
   *                     type="number"
   *                 ),
   *                 @OA\Property(
   *                     property="parent",
   *                     type="string"
   *                 ),
   *                 @OA\Property(
   *                     property="description",
   *                     type="string"
   *                 ),
   *                 @OA\Property(
   *                     property="metadata",
   *                     type="object"
   *                 ),
   *                 required={"site","node"},
   *                 example={
   *                    "site": {
   *                      "name": "mysite"
   *                    },
   *                    "node": {
   *                      "id": null,
   *                      "title": "Cool post",
   *                      "location": null,
   *                      "duplicate": "item-123-ddd-333"
   *                    },
   *                    "indent": null,
   *                    "order": null,
   *                    "parent": null,
   *                    "description": "An example description for the post",
   *                    "metadata": {"tags": "metadata,can,be,whatever,you,want","other":"stuff"}
   *                 }
   *             )
   *         )
   *     ),
   *    @OA\Response(
   *        response="200",
   *        description="object with full properties returned"
   *   )
   * )
   */
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
            $alternateContent = SanitizeContent::sanitizeHTMLForStorage($nodeParams['node']['contents']);
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
