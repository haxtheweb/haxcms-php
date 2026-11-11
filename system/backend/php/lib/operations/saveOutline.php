<?php
include_once dirname(__FILE__) . '/../FileContentScanner.php';
include_once dirname(__FILE__) . '/../SanitizeContent.php';
trait OperationsRouteSaveOutline {
  /**
   * Checks a normalized saveOutline payload before anything is written.
   * Returns the 400 message for the first problem found, or null when the
   * outline can be saved. Mirrors validateOutlineItems() in haxcms-nodejs
   * (src/siteRoutes/v1/routes/saveOutline.js), with the same messages:
   *   - a new page (an id the site does not have) is sent once: idMap can
   *     only map a client id to one server id. An existing id sent twice is
   *     one page, the last entry wins.
   *   - no page is its own ancestor in the outline the save would produce
   *     (the site's parents with the sent ones applied): a parent cycle would
   *     loop forever in every parent walk (slugs, breadcrumbs, menus)
   * Items that are not objects are rejected while normalizing, with the same
   * message as haxcms-nodejs. A parent that does not exist is allowed; the
   * save rescues that page to the top level.
   */
  private function validateOutlineItems($site, $items) {
    $parents = array();
    foreach ($site->manifest->items as $existing) {
      $parents[(string) $existing->id] = $existing->parent;
    }
    $newIds = array();
    foreach ($items as $item) {
      $id = (string) $item->id;
      if (!$site->loadNode($item->id)) {
        if (isset($newIds[$id])) {
          return 'Each new outline item requires its own id';
        }
        $newIds[$id] = true;
      }
    }
    foreach ($items as $item) {
      $parents[(string) $item->id] = $item->parent;
    }
    foreach ($items as $item) {
      $seen = array();
      $current = (string) $item->id;
      while (array_key_exists($current, $parents)) {
        if (isset($seen[$current])) {
          return 'Outline item parents cannot form a cycle';
        }
        $seen[$current] = true;
        $parent = $parents[$current];
        // top level, or not an id at all
        if ((!is_string($parent) && !is_int($parent) && !is_float($parent)) || $parent === '') {
          break;
        }
        $current = (string) $parent;
      }
    }
    return null;
  }

  /**
   * True when an outline id is empty or only whitespace. Blank is the
   * whitespace JavaScript's String.prototype.trim removes, so this answers
   * the same as hasOutlineItemId() in haxcms-nodejs (PHP's trim() has a
   * different set: it strips NUL but not a no-break space).
   */
  private static function isBlankOutlineId($id) {
    return preg_match('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]*$/u', $id) === 1;
  }

  /**
   * Remove the page folders this request created ($createdFolders, page id
   * => folder) that site.json does not list ($listedItems), so a failed
   * save leaves no page folder without its outline entry. Mirrors
   * removeUnlistedPageFolders() in haxcms-nodejs saveOutline.js.
   */
  private function removeUnlistedPageFolders($site, $createdFolders, $listedItems) {
    $listed = array();
    foreach ($listedItems as $listedItem) {
      $listed[(string) $listedItem->id] = true;
    }
    foreach ($createdFolders as $pageId => $folder) {
      if (!isset($listed[(string) $pageId])) {
        $site->removeCreatedPageFolder($folder);
      }
    }
  }

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
      if (!isset($this->rawParams['items']) || !is_array($this->rawParams['items'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Outline payload requires an items array',
          )
        );
      }
      $normalizedItems = array();
      foreach ($this->rawParams['items'] as $itemIndex => $rawItem) {
        // JSON objects arrive as associative arrays; a list is not an item
        if (is_array($rawItem) && $rawItem !== array() && array_is_list($rawItem)) {
          $rawItem = null;
        }
        if (is_array($rawItem)) {
          $rawItem = json_decode(json_encode($rawItem));
        }
        if (!is_object($rawItem)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'Each outline item must be an object',
            )
          );
        }
        // every item has an id: missing, null, blank or not a string (a
        // number included) is a 400, the same as haxcms-nodejs. Checked for
        // every item before validateOutlineItems. Ids are opaque: one with
        // surrounding whitespace is kept exactly as sent (trimming it could
        // turn an existing page's id into a new page).
        if (!isset($rawItem->id) || !is_string($rawItem->id) || self::isBlankOutlineId($rawItem->id)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'Each outline item requires an id',
            )
          );
        }
        if (!isset($rawItem->title) || !is_string($rawItem->title)) {
          $rawItem->title = '';
        }
        if (!isset($rawItem->parent)) {
          $rawItem->parent = null;
        }
        if (!isset($rawItem->indent) || !is_numeric($rawItem->indent)) {
          $rawItem->indent = 0;
        }
        if (!isset($rawItem->order) || !is_numeric($rawItem->order)) {
          $rawItem->order = (int) $itemIndex;
        }
        if (!isset($rawItem->slug) || !is_string($rawItem->slug)) {
          $rawItem->slug = '';
        }
        if (!isset($rawItem->metadata) || is_null($rawItem->metadata)) {
          $rawItem->metadata = new stdClass();
        }
        else if (is_array($rawItem->metadata)) {
          $rawItem->metadata = json_decode(json_encode($rawItem->metadata));
        }
        else if (!is_object($rawItem->metadata)) {
          $rawItem->metadata = new stdClass();
        }
        if (isset($rawItem->contents) && !is_string($rawItem->contents)) {
          $rawItem->contents = '';
        }
        if (isset($rawItem->duplicate) && !is_string($rawItem->duplicate)) {
          $rawItem->duplicate = '';
        }
        if (isset($rawItem->delete)) {
          $rawItem->delete = ($rawItem->delete === true);
        }
        $normalizedItems[] = $rawItem;
      }
      // Invariant: an invalid outline is rejected with a 400 before anything
      // is written (no page folder, no site.json change)
      $invalid = $this->validateOutlineItems($site, $normalizedItems);
      if ($invalid !== null) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => $invalid,
          )
        );
      }
      $this->rawParams['items'] = $normalizedItems;
      $items = $this->rawParams['items'];
      $itemMap = array();
      $pageAlternateContentMap = array();
      $normalizeOutlineSlug = function ($slug, $page = null, $pathAuto = false) use ($site) {
        // no segment may be the reserved "x" route (getUniqueSlugName
        // applies the same rule); an empty slug becomes "blank"
        $normalizedSlug = HAXCMSSite::reserveSlugRoutes(
          $GLOBALS['HAXCMS']->generateSlugName($slug)
        );
        if ($normalizedSlug == '') {
          $normalizedSlug = 'blank';
        }
        return $site->getUniqueSlugName($normalizedSlug, $page, $pathAuto);
      };
      $pathautoEnabled = $site->isPathautoEnabled();
      // #3114: the manifest is updated in memory per item and written, along
      // with the RSS / Atom / sitemap / llms.txt / search index rebuild, once
      // per request instead of once per item. $pendingPersist tracks whether
      // an early error return must still flush so the persisted site.json and
      // generated files match what per-item saving used to leave behind.
      //
      // Same result as the NodeJS backend for the same request: site.json
      // holds every page handled so far, new pages included (so a failure
      // never leaves a new page's folder on disk without its outline entry),
      // and the feeds are rebuilt from the items as of the last EXISTING
      // page. $savedItems and $feedItems are those arrays; flushing writes
      // them, not whatever is in memory, so the checkpoint and every failure
      // leave the same site.json and feeds on both backends.
      $pendingPersist = false;
      $feedsPending = false;
      $savedItems = null;
      $feedItems = null;
      // Invariant: a page folder exists only for a page site.json lists.
      // $createdFolders holds the folders this request created (page id =>
      // folder); $listedItems is what site.json lists (what it was loaded
      // with, then what each successful write of it held). A failure
      // removes every created folder site.json does not list
      // (removeUnlistedPageFolders below).
      $createdFolders = array();
      $listedItems = $original;
      $flushPending = function () use ($site, &$pendingPersist, &$feedsPending, &$savedItems, &$feedItems, &$listedItems) {
        // attempted once: the error handler does not retry a failed write,
        // nor rebuild the feeds over it
        if ($pendingPersist) {
          $pendingPersist = false;
          $live = $site->manifest->items;
          $site->manifest->items = $savedItems;
          try {
            $site->manifest->save(false);
            $listedItems = $savedItems;
          } catch (Throwable $e) {
            $feedsPending = false;
            throw $e;
          } finally {
            $site->manifest->items = $live;
          }
        }
        if ($feedsPending) {
          $feedsPending = false;
          $live = $site->manifest->items;
          $site->manifest->items = $feedItems;
          try {
            $site->updateAlternateFormats();
          } finally {
            $site->manifest->items = $live;
          }
        }
      };
      // a failure part-way through must not lose what per-item saving would
      // already have written: persist what is in memory, then fail as before
      try {
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
            // Security (SEC-SANITIZE): titles are plain text, same as saveNode;
            // a title that is nothing but markup keeps the existing title
            $plainTitle = SanitizeContent::plainText($item->title);
            if ($plainTitle !== '') {
              $page->title = $plainTitle;
            }
          }
          // description belongs to the JSON Outline Schema item; same handling
          // as the setDescription node operation
          // (mirrors the NodeJS saveOutline fix, haxcms-nodejs PR #46)
          if (isset($item->description) && is_string($item->description)) {
            $page->description = ($item->description === '') ? '' : strip_tags($item->description);
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
          // Check for per-page overridePathauto flag
          $overridePathauto = false;
          if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->overridePathauto) && $item->metadata->overridePathauto === true) {
            $overridePathauto = true;
          }
          // Determine slug based on pathauto and overridePathauto
          if ($pathautoEnabled && !$overridePathauto) {
            // Pathauto is on and user has not overridden: auto-generate from title
            $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
          } else {
            // Pathauto is off, or user has overridden: use client-provided slug or generate from title
            if (isset($item->slug) && $item->slug != '') {
              $page->slug = $normalizeOutlineSlug($item->slug, $page, false);
            } else {
              $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
            }
          }
          // verify this exists, front end could have set what they wanted
          // or it could have just been renamed
          // if it doesn't exist currently make sure the name is unique
          if (!$site->loadNode($page->id)) {
            $pageFolder = $siteDirectory . '/' . str_replace('/index.html', '', $page->location);
            $folderExisted = file_exists($pageFolder);
            $site->recurseCopy(
                HAXCMS_ROOT . '/system/boilerplate/page/default',
                $pageFolder
            );
            if (!$folderExisted && is_dir($pageFolder)) {
              $createdFolders[$page->id] = $pageFolder;
            }
            $pageAlternateContentMap[$page->id] = '';
          }
          // this would imply existing item, lets see if it moved or needs moved
          else {
              $moved = false;
              foreach ($original as $key => $tmpItem) {
                  // see if this is something moving as opposed to brand new
                  if (
                      JSONOutlineSchema::sameOutlineId($tmpItem->id, $page->id) &&
                      $tmpItem->slug != ''
                  ) {
                      // core support for automatically managing paths to make them nice
                      if ($pathautoEnabled && !$overridePathauto) {
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
                  if ($pathautoEnabled && !$overridePathauto) {
                    $pAuto = true;
                  }
                  $tmpTitle = $normalizeOutlineSlug($cleanTitle, $page, $pAuto);
                  $page->location = 'pages/' . $page->id . '/index.html';
                  $page->slug = $tmpTitle;
                  $pageFolder = $siteDirectory . '/' . str_replace('/index.html', '', $page->location);
                  $folderExisted = file_exists($pageFolder);
                  $site->recurseCopy(
                      HAXCMS_ROOT . '/system/boilerplate/page/default',
                      $pageFolder
                  );
                  if (!$folderExisted && is_dir($pageFolder)) {
                    $createdFolders[$page->id] = $pageFolder;
                  }
                  $pageAlternateContentMap[$page->id] = '';
              }
          }
          if (!isset($page->slug) || !is_string($page->slug) || $page->slug == '') {
              $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
          }
          $safeLocationMap[$page->id] = $page->location;
          // check for any metadata keys that did come over
          if (isset($item->metadata) && (is_object($item->metadata) || is_array($item->metadata))) {
            // Security (SEC-SANITIZE): route client metadata through the same
            // per-field sanitisation saveNode applies instead of mass-assigning
            SanitizeContent::applyPageMetadata(
              $page,
              $item->metadata,
              $GLOBALS['HAXCMS']->getThemes()
            );
          }
          // safety check for new things
          if (!isset($page->metadata->created)) {
              $page->metadata->created = time();
              $page->metadata->images = array();
              $page->metadata->videos = array();
          }
          // always update at this time
          $page->metadata->updated = time();
          $existing = (bool) $site->loadNode($page->id);
          if ($existing) {
              $site->updateNode($page, false);
          } else {
              $site->manifest->addItem($page);
          }
          // site.json as of this page, and the feeds as of the last
          // existing one (as the NodeJS backend writes them)
          $pendingPersist = true;
          $savedItems = $site->manifest->items;
          if ($existing) {
              $feedsPending = true;
              $feedItems = $savedItems;
          }
        }
        // siblings that share an order are told apart by their position in
        // the manifest; keep the order the request sent them in (the order
        // the outline showed), so a tie never depends on how the save ran.
        // Only sent siblings that share a parent and an order move, and only
        // between the slots they already hold, so saves without ties write
        // exactly what they always did
        $requested = array();
        foreach ($items as $position => $sent) {
          $sentId = isset($itemMap[$sent->id]) ? $itemMap[$sent->id] : $sent->id;
          if (!isset($requested[$sentId])) {
            $requested[$sentId] = $position;
          }
        }
        $tied = array();
        foreach ($site->manifest->items as $slot => $manifestItem) {
          if (!isset($requested[$manifestItem->id])) {
            continue;
          }
          $tieKey = (string) $manifestItem->parent . "\0" . (string) $manifestItem->order;
          $tied[$tieKey][] = $slot;
        }
        foreach ($tied as $slots) {
          if (count($slots) < 2) {
            continue;
          }
          $group = array();
          foreach ($slots as $slot) {
            $group[] = $site->manifest->items[$slot];
          }
          usort($group, function ($a, $b) use ($requested) {
            return $requested[$a->id] <=> $requested[$b->id];
          });
          foreach ($slots as $k => $slot) {
            $site->manifest->items[$slot] = $group[$k];
          }
        }
        // checkpoint: write the structure once here, as per-item saving had
        // it by this point, so a failure in the content or delete phase
        // below leaves the same site.json and feeds behind
        $flushPending();
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
            $flushPending();
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
            $flushPending();
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid page location',
              )
            );
          }
          // location is backend-controlled based on page id, ignore client input
          if (!$this->getValidatedOutlineWriteTarget($siteDirectory, $expectedLocation)) {
            $flushPending();
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
              $flushPending();
              return array(
                '__failed' => array(
                  'status' => 400,
                  'message' => 'invalid duplicate source',
                )
              );
            }
            $content = $site->getPageContent($nodeToDuplicate);
            if (!$this->isLikelyHtmlContent($content)) {
              $flushPending();
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
              $flushPending();
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
              $flushPending();
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
              $flushPending();
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
          // #3043: rebuild page.metadata.files from a content path-scan so
          // the uuid set stays in sync. If content was just written, scan that;
          // otherwise read the current on-disk content so moved pages keep
          // their uuid refs and legacy object-shape entries self-heal.
          $scanContent = $shouldWriteAlternate ? $alternateContent : '';
          if ($scanContent === '' && method_exists($site, 'getPageContent')) {
            $scanContent = (string) $site->getPageContent($page);
          }
          FileContentScanner::rebuildPageFilesUuids($site, $page, $scanContent);
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
      } catch (Throwable $e) {
        try {
          $flushPending();
        } catch (Throwable $flushError) {
          // the original failure is the one to report
        }
        $this->removeUnlistedPageFolders($site, $createdFolders, $listedItems);
        throw $e;
      }
      $site->manifest->save();
      // now, we need to look for orphans if we deleted anything
      $orphanCheck = $site->manifest->items;
      $orphansRescued = false;
      foreach ($orphanCheck as $key => $item) {
        // just to be safe..
        if ($page = $site->loadNode($item->id)) {
          // ensure that parent is valid to rescue orphan items
          if ($page->parent != null && !($parentPage = $site->loadNode($page->parent))) {
            $page->parent = null;
            // force to bottom of things while still being in old order if lots of things got axed
            $page->order = (int)$page->order + count($site->manifest->items) - 1;
            // in memory only: the save and rebuild below cover every orphan
            $site->updateNode($page, false);
            $orphansRescued = true;
          }
        }
      }
      // per-item saving wrote site.json and rebuilt the feeds after every
      // rescued orphan; doing that once leaves the same feeds (and service
      // worker, which hashes site.json) if rebuildManagedFiles() below fails
      if ($orphansRescued) {
        $site->manifest->save(false);
        $site->updateAlternateFormats();
      }
      $site->manifest->metadata->site->updated = time();
      $site->manifest->save();
      // rebuild managed files after manifest changes that may affect generated output
      $site->rebuildManagedFiles();
      $site->gitCommit('Managed files updated');
      // update alt formats like rss as we did massive changes
      $site->updateAlternateFormats();
      $site->gitCommit('Outline updated in bulk');
      // D49: return items wrapped in an object with 'items' key so the
      // siteMutation.php wrapper produces {status:200, data:{items:[...]}}
      // matching the Node canonical outline success shape.
      // idMap (client id -> server id for new items) lets clients update
      // their own references to the pages they created
      // (mirrors the NodeJS saveOutline fix, haxcms-nodejs PR #46).
      // Cast to object so an empty map serializes as {} (site-spec declares
      // idMap as type: object) and numeric client ids stay object keys.
      return array(
        'items' => $site->manifest->items,
        'idMap' => (object) $itemMap,
      );
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
