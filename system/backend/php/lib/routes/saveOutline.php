<?php
trait OperationsRouteSaveOutline {
  /**
   * @OA\Post(
   *    path="/saveOutline",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save an entire site outline"
   *   )
   * )
   */
  public function saveOutline() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      
      // Check platform configuration
      if (!$this->platformAllows($site, 'outlineDesigner')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Outline operations are disabled for this site',
          )
        );
      }
      $siteDirectory = $site->directory . '/' . $site->manifest->metadata->site->name;
      $original = $site->manifest->items;
      $originalLocationMap = array();
      foreach ($original as $originalItem) {
        $originalLocationMap[$originalItem->id] = $this->normalizeOutlineLocation($originalItem->location);
      }
      $safeLocationMap = array();
      $items = $this->rawParams['items'];
      $itemMap = array();
      $pageAlternateContentMap = array();
      $normalizeOutlineSlug = function ($slug, $page = null, $pathAuto = false) use ($site) {
        $normalizedSlug = $GLOBALS['HAXCMS']->generateSlugName($slug);
        if ($normalizedSlug == 'x') {
          $normalizedSlug = 'x-x';
        }
        if (substr($normalizedSlug, 0, 2) == 'x/') {
          $normalizedSlug = str_replace('x/', 'x-x/', $normalizedSlug);
        }
        if ($normalizedSlug == '') {
          $normalizedSlug = 'blank';
        }
        return $site->getUniqueSlugName($normalizedSlug, $page, $pathAuto);
      };
      // items from the POST
      foreach ($items as $key => $item) {
        // get a fake item of the existing
        if (!($page = $site->loadNode($item->id))) {
          $page = $GLOBALS['HAXCMS']->outlineSchema->newItem();
          // we don't trust the front end UUID if it wasn't existing already
          $itemMap[$item->id] = $page->id;
        }
        // set a title if we have one
        if ($item->title != '' && $item->title) {
          $page->title = $item->title;
        }
        $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
        if ($item->parent == null) {
          $page->parent = null;
          $page->indent = 0;
        } else {
          // check the item map as backend dictates unique ID
          if (isset($itemMap[$item->parent])) {
            $page->parent = $itemMap[$item->parent];
          } else {
            // set to the parent id
            $page->parent = $item->parent;
          }
          // move it one indentation below the parent; this can be changed later if desired
          $page->indent = $item->indent;
        }
        if (isset($item->order)) {
          $page->order = (int)$item->order;
        } else {
          $page->order = (int)$key;
        }
        // location is backend-controlled to prevent arbitrary writes
        if (isset($originalLocationMap[$page->id]) && $originalLocationMap[$page->id]) {
          $page->location = $originalLocationMap[$page->id];
        } else {
          // generate a logical page slug
          $page->location = 'pages/' . $page->id . '/index.html';
        }
        // keep slug if we get one already, but sanitize / normalize it
        if (isset($item->slug) && $item->slug != '') {
            $page->slug = $normalizeOutlineSlug($item->slug, $page, false);
        } else {
            // generate a logical page slug
            $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
        }
        // verify this exists, front end could have set what they wanted
        // or it could have just been renamed
        // if it doesn't exist currently make sure the name is unique
        if (!$site->loadNode($page->id)) {
          $site->recurseCopy(
              HAXCMS_ROOT . '/system/boilerplate/page/default',
              $siteDirectory . '/' . str_replace('/index.html', '', $page->location)
          );
          $pageAlternateContentMap[$page->id] = '';
        }
        // this would imply existing item, lets see if it moved or needs moved
        else {
            $moved = false;
            foreach ($original as $key => $tmpItem) {
                // see if this is something moving as opposed to brand new
                if (
                    $tmpItem->id == $page->id &&
                    $tmpItem->slug != ''
                ) {
                    // core support for automatically managing paths to make them nice
                    if (isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto) {
                        $moved = true;
                        $page->slug = $normalizeOutlineSlug(
                          $GLOBALS['HAXCMS']->cleanTitle($page->title),
                          $page,
                          true
                        );
                    }
                    else if ($tmpItem->slug != $page->slug) {
                        $moved = true;
                        $page->slug = $normalizeOutlineSlug($page->slug, $page, false);
                    }
                }
            }
            // it wasn't moved and it doesn't exist... let's fix that
            // this is beyond an edge case
            if (
                !$moved &&
                !file_exists($siteDirectory . '/' . $page->location)
            ) {
                $pAuto = false;
                if (isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto) {
                  $pAuto = true;
                }
                $tmpTitle = $normalizeOutlineSlug($cleanTitle, $page, $pAuto);
                $page->location = 'pages/' . $page->id . '/index.html';
                $page->slug = $tmpTitle;
                $site->recurseCopy(
                    HAXCMS_ROOT . '/system/boilerplate/page/default',
                    $siteDirectory . '/' . str_replace('/index.html', '', $page->location)
                );
                $pageAlternateContentMap[$page->id] = '';
            }
        }
        if (!isset($page->slug) || !is_string($page->slug) || $page->slug == '') {
            $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
        }
        $safeLocationMap[$page->id] = $page->location;
        // check for any metadata keys that did come over
        foreach ($item->metadata as $key => $value) {
            $page->metadata->{$key} = $value;
        }
        // safety check for new things
        if (!isset($page->metadata->created)) {
            $page->metadata->created = time();
            $page->metadata->images = array();
            $page->metadata->videos = array();
        }
        // always update at this time
        $page->metadata->updated = time();
        if ($site->loadNode($page->id)) {
            $site->updateNode($page);
        } else {
            $site->manifest->addItem($page);
        }
      }
      // process any duplicate / contents requests we had now that structure is sane
      // including potentially duplication of material from something
      // we are about to act on and now that we have the map
      $items = $this->rawParams['items'];
      foreach ($items as $key => $item) {
        // load the item, or the item as built out of the itemMap
        // since we reset the UUID on creation
        if (!($page = $site->loadNode($item->id))) {
          if (isset($itemMap[$item->id])) {
            $page = $site->loadNode($itemMap[$item->id]);
          }
        }
        if (!$page) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid page reference',
            )
          );
        }
        $expectedLocation = null;
        if (isset($safeLocationMap[$page->id]) && $safeLocationMap[$page->id]) {
          $expectedLocation = $safeLocationMap[$page->id];
        } else {
          $expectedLocation = $this->normalizeOutlineLocation($page->location);
        }
        if (!$expectedLocation) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid page location',
            )
          );
        }
        // location is backend-controlled based on page id, ignore client input
        if (!$this->getValidatedOutlineWriteTarget($siteDirectory, $expectedLocation)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid write target',
            )
          );
        }
        $page->location = $expectedLocation;
        $alternateContent = '';
        $shouldWriteAlternate = false;
        if (isset($pageAlternateContentMap[$page->id])) {
          $shouldWriteAlternate = true;
        }
        if (isset($item->duplicate)) {
          // load the node we are duplicating with support for the same map needed for page loading
          if (!$nodeToDuplicate = $site->loadNode($item->duplicate)) {
            if (isset($itemMap[$item->duplicate])) {
              $nodeToDuplicate = $site->loadNode($itemMap[$item->duplicate]);
            }
          }
          if (!$nodeToDuplicate) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid duplicate source',
              )
            );
          }
          $content = $site->getPageContent($nodeToDuplicate);
          if (!$this->isLikelyHtmlContent($content)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid duplicate content',
              )
            );
          }
          // write it to the file system
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
          if ($bytes === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to write',
              )
            );
          }
          $shouldWriteAlternate = true;
        }
        // contents that were shipped across, and not null, take priority over a dup request
        if (isset($item->contents) && $item->contents && $item->contents != '') {
          if (!$this->isLikelyHtmlContent($item->contents)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid page contents',
              )
            );
          }
          // write it to the file system
          $alternateContent = SanitizeContent::sanitizeHTMLForStorage($item->contents);
          $bytes = $page->writeLocation(
            $alternateContent,
            HAXCMS_ROOT .
            '/' .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $site->manifest->metadata->site->name .
            '/'
          );
          if ($bytes === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to write',
              )
            );
          }
          $shouldWriteAlternate = true;
        }
        if ($shouldWriteAlternate) {
          $site->writePageAlternateFormats($page, $alternateContent);
        }
      }
      $items = $this->rawParams['items'];
      // now, we can finally delete as content operations have finished
      foreach ($items as $key => $item) {
        // verify if we were told to delete this item via flag not in the real spec
        if (isset($item->delete) && $item->delete == TRUE) {
          // load the item, or the item as built out of the itemMap
          // since we reset the UUID on creation
          if (!($page = $site->loadNode($item->id))) {
            if (isset($itemMap[$item->id])) {
              $page = $site->loadNode($itemMap[$item->id]);
            }
          }
          if (!$page) {
            continue;
          }
          $site->deleteNode($page);
          $site->gitCommit(
            'Page deleted: ' . $page->title . ' (' . $page->id . ')'
          );
        }
      }
      $site->manifest->save();
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
      $site->manifest->metadata->site->updated = time();
      $site->manifest->save();
      // update alt formats like rss as we did massive changes
      $site->updateAlternateFormats();
      $site->gitCommit('Outline updated in bulk');
      return $site->manifest->items;
    } else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }
}
