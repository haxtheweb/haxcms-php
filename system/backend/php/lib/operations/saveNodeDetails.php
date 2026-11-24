<?php
include_once dirname(__FILE__) . '/../FileContentScanner.php';
include_once dirname(__FILE__) . '/../OutlineUpkeep.php';
trait OperationsRouteSaveNodeDetails {
  public function saveNodeDetails() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $this->getSiteTokenUserName() . ':' . $this->params['site']['name'])) {
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
      if (!isset($this->params['node']['id'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Missing node id',
          )
        );
      }
      $operation = isset($this->params['node']['details']['operation']) ? $this->params['node']['details']['operation'] : null;
      $pageDetailOperations = array(
        'setTitle',
        'setDescription',
        'setTags',
        'setIcon',
        'setMedia',
        'setImage',
        'setRelatedItems',
        'setLocked',
        'setPublished',
        'setHideInMenu',
      );
      if (in_array($operation, $pageDetailOperations, true) && !$this->platformAllows($site, 'pageBreak')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Page details editing is disabled for this site',
          )
        );
      }
      $page = $site->loadNode($this->params['node']['id']);
      if (!$page) {
        return array(
          '__failed' => array(
            'status' => 404,
            'message' => 'Node not found',
          )
        );
      }
      // Store original count for safety check
      // a locked page is changed by nothing but unlocking it
      if (OutlineUpkeep::isLocked($page) && $operation !== 'setLocked') {
        return array(
          '__failed' => array(
            'status' => 423,
            'message' => 'Page is locked',
            'reason' => 'locked',
          )
        );
      }
      $originalItemCount = count($site->manifest->items);
      $items = $site->manifest->items;
      $outlineOperations = array('moveUp', 'moveDown', 'indent', 'outdent', 'setParent');
      $isOutlineOperation = in_array($operation, $outlineOperations, true);
      $siblings = array();
      $position = false;
      if ($isOutlineOperation) {
        // number every group 0..n-1 first: siblings that share an order have
        // no defined neighbour, and an order the client sends counts positions
        OutlineUpkeep::compactSiblingOrders($items);
        $siblings = OutlineUpkeep::sortedSiblings($items, OutlineUpkeep::parentOf($page));
        $position = array_search($page, $siblings, true);
      }
      switch ($operation) {
        case 'moveUp':
          if ($position !== false && $position > 0) {
            $other = $siblings[$position - 1];
            $otherOrder = OutlineUpkeep::orderOf($other);
            $other->order = OutlineUpkeep::orderOf($page);
            $page->order = $otherOrder;
          }
          break;
        case 'moveDown':
          if ($position !== false && $position < count($siblings) - 1) {
            $other = $siblings[$position + 1];
            $otherOrder = OutlineUpkeep::orderOf($other);
            $other->order = OutlineUpkeep::orderOf($page);
            $page->order = $otherOrder;
          }
          break;
        case 'indent':
          if ($position !== false && $position > 0) {
            $prev = $siblings[$position - 1];
            if ($prev) {
              $page->parent = $prev->id;
              $page->indent = isset($prev->indent) ? ((int)$prev->indent + 1) : 1;
              // the page is now one of $prev's children: leave it out, or
              // its own old order counts as the last child's
              $page->order = OutlineUpkeep::lastSiblingOrder($items, $prev->id, $page) + 1;
            }
          }
          $pathautoEnabled = $site->isPathautoEnabled();
          $overridePathauto = isset($page->metadata->overridePathauto) && $page->metadata->overridePathauto === true;
          if ($pathautoEnabled && !$overridePathauto) {
            $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
            $page->slug = $site->getUniqueSlugName($cleanTitle, $page, true);
            $site->manifest->items = $items;
            $changedIds = array($page->id);
            $keepGoing = true;
            while ($keepGoing) {
              $keepGoing = false;
              foreach ($items as $item) {
                if (in_array($item->parent, $changedIds) && !in_array($item->id, $changedIds)) {
                  $childOverride = isset($item->metadata->overridePathauto) && $item->metadata->overridePathauto === true;
                  if (!$childOverride) {
                    $childCleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
                    $item->slug = $site->getUniqueSlugName($childCleanTitle, $item, true);
                    $changedIds[] = $item->id;
                    $keepGoing = true;
                  }
                }
              }
            }
          }
          break;
        case 'outdent':
          if (isset($page->parent) && $page->parent !== null) {
            $parentNode = $site->loadNode($page->parent);
            $newParent = $parentNode ? $parentNode->parent : null;
            $insertAfterOrder = $parentNode && isset($parentNode->order) ? ((int)$parentNode->order + 1) : 0;
            // shift siblings in new parent group to make space
            foreach ($items as $it) {
              $p = isset($it->parent) ? $it->parent : null;
              if ($p === $newParent && isset($it->order) && (int)$it->order >= $insertAfterOrder) {
                $it->order = (int)$it->order + 1;
              }
            }
            $page->parent = $newParent;
            $page->indent = isset($page->indent) ? max(((int)$page->indent) - 1, 0) : 0;
            $page->order = $insertAfterOrder;
          }
          $pathautoEnabled = $site->isPathautoEnabled();
          $overridePathauto = isset($page->metadata->overridePathauto) && $page->metadata->overridePathauto === true;
          if ($pathautoEnabled && !$overridePathauto) {
            $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
            $page->slug = $site->getUniqueSlugName($cleanTitle, $page, true);
            $site->manifest->items = $items;
            $changedIds = array($page->id);
            $keepGoing = true;
            while ($keepGoing) {
              $keepGoing = false;
              foreach ($items as $item) {
                if (in_array($item->parent, $changedIds) && !in_array($item->id, $changedIds)) {
                  $childOverride = isset($item->metadata->overridePathauto) && $item->metadata->overridePathauto === true;
                  if (!$childOverride) {
                    $childCleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
                    $item->slug = $site->getUniqueSlugName($childCleanTitle, $item, true);
                    $changedIds[] = $item->id;
                    $keepGoing = true;
                  }
                }
              }
            }
          }
          break;
        case 'setParent':
          // Move page under a specific parent
          // Use array_key_exists to properly handle null values
          $newParent = array_key_exists('parent', $this->params['node']['details']) ? $this->params['node']['details']['parent'] : null;
          $requestedOrder = array_key_exists('order', $this->params['node']['details']) ? $this->params['node']['details']['order'] : null;
          // account for this being set to empty string which means null
          if (!$newParent || $newParent === '') {
            $newParent = null;
          }
          // Update the page's parent and order
          $page->parent = $newParent;
          if (is_int($requestedOrder) || is_float($requestedOrder) || (is_string($requestedOrder) && preg_match('/^\s*-?\d+/', $requestedOrder))) {
            // the page takes the spot it asked for; whoever held it moves down
            $page->order = (int) $requestedOrder;
            OutlineUpkeep::makeRoomFor($items, $page);
          } else {
            // no order given: the page goes to the end of its new siblings
            $page->order = OutlineUpkeep::lastSiblingOrder($items, $newParent, $page) + 1;
          }
          // Calculate indent based on new parent depth
          if ($newParent === null) {
            $page->indent = 0;
          } else {
            $parentNode = $site->loadNode($newParent);
            $page->indent = $parentNode && isset($parentNode->indent) ? ((int)$parentNode->indent + 1) : 1;
          }
          // If pathauto is on and overridePathauto is not set, regenerate the slug and cascade
          $pathautoEnabled = $site->isPathautoEnabled();
          $overridePathauto = isset($page->metadata->overridePathauto) && $page->metadata->overridePathauto === true;
          if ($pathautoEnabled && !$overridePathauto) {
            $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
            $page->slug = $site->getUniqueSlugName($cleanTitle, $page, true);
            // Temporarily update manifest items so getUniqueSlugName can see parent changes
            $site->manifest->items = $items;
            $changedIds = array($page->id);
            $keepGoing = true;
            while ($keepGoing) {
              $keepGoing = false;
              foreach ($items as $item) {
                if (in_array($item->parent, $changedIds) && !in_array($item->id, $changedIds)) {
                  $childOverride = isset($item->metadata->overridePathauto) && $item->metadata->overridePathauto === true;
                  if (!$childOverride) {
                    $childCleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
                    $item->slug = $site->getUniqueSlugName($childCleanTitle, $item, true);
                    $changedIds[] = $item->id;
                    $keepGoing = true;
                  }
                }
              }
            }
          }
          break;
        // Singular field modification operations
        case 'setTitle':
          if (array_key_exists('title', $this->params['node']['details']) && $this->params['node']['details']['title'] !== '') {
            // stored without leading or trailing white space, like every
            // other write path and the CLI
            $page->title = SanitizeContent::trimTitle(strip_tags($this->params['node']['details']['title']));
            // white space or the text "null" is no title
            if (SanitizeContent::isBlankPageTitle($page->title)) {
              $page->title = SanitizeContent::DEFAULT_PAGE_TITLE;
            }
            // If pathauto is on and overridePathauto is not set, regenerate the slug and cascade
            $pathautoEnabled = $site->isPathautoEnabled();
            $overridePathauto = isset($page->metadata->overridePathauto) && $page->metadata->overridePathauto === true;
            if ($pathautoEnabled && !$overridePathauto) {
              $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
              $page->slug = $site->getUniqueSlugName($cleanTitle, $page, true);
              // Temporarily update manifest items so getUniqueSlugName can see parent changes
              $site->manifest->items = $items;
              $changedIds = array($page->id);
              $keepGoing = true;
              while ($keepGoing) {
                $keepGoing = false;
                foreach ($items as $item) {
                  if (in_array($item->parent, $changedIds) && !in_array($item->id, $changedIds)) {
                    $childOverride = isset($item->metadata->overridePathauto) && $item->metadata->overridePathauto === true;
                    if (!$childOverride) {
                      $childCleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
                      $item->slug = $site->getUniqueSlugName($childCleanTitle, $item, true);
                      $changedIds[] = $item->id;
                      $keepGoing = true;
                    }
                  }
                }
              }
            }
          }
          break;
        case 'setDescription':
          if (array_key_exists('description', $this->params['node']['details'])) {
            if ($this->params['node']['details']['description'] !== '') {
              $page->description = strip_tags($this->params['node']['details']['description']);
            } else {
              $page->description = '';
            }
          }
          break;
        case 'setTags':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('tags', $this->params['node']['details'])) {
            if ($this->params['node']['details']['tags'] !== '' && $this->params['node']['details']['tags'] !== null) {
              $tagsValue = $this->params['node']['details']['tags'];
              // a list of tags means the same as the comma separated text the
              // editor sends; join it (as haxcms-nodejs does) instead of
              // casting the array to the word "Array"
              if (is_array($tagsValue)) {
                $tagsValue = implode(',', array_map(function ($tag) {
                  if (is_bool($tag)) {
                    return $tag ? 'true' : 'false';
                  }
                  return is_scalar($tag) ? (string) $tag : '';
                }, $tagsValue));
              }
              $page->metadata->tags = SanitizeContent::sanitizeMetadataValue(
                $tagsValue
              );
            } else {
              unset($page->metadata->tags);
            }
          }
          break;
        case 'setIcon':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('icon', $this->params['node']['details'])) {
            if ($this->params['node']['details']['icon'] !== '' && $this->params['node']['details']['icon'] !== null) {
              $page->metadata->icon = SanitizeContent::sanitizeMetadataValue(
                $this->params['node']['details']['icon']
              );
            } else {
              unset($page->metadata->icon);
            }
          }
          break;
        case 'setMedia':
        case 'setImage':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('image', $this->params['node']['details'])) {
            // a value that is blank once cleaned removes the image instead
            // of saving '' (haxcms-nodejs does the same, haxtheweb/issues#3394)
            $cleanImage = '';
            if ($this->params['node']['details']['image'] !== '' && $this->params['node']['details']['image'] !== null) {
              $cleanImage = SanitizeContent::sanitizeURLValue(
                $this->params['node']['details']['image'],
                ''
              );
            }
            if (is_string($cleanImage) && trim($cleanImage) !== '') {
              $page->metadata->image = $cleanImage;
            } else {
              unset($page->metadata->image);
            }
          }
          break;
        case 'setRelatedItems':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('relatedItems', $this->params['node']['details'])) {
            if ($this->params['node']['details']['relatedItems'] !== '' && $this->params['node']['details']['relatedItems'] !== null) {
              $page->metadata->relatedItems = SanitizeContent::sanitizeMetadataValue(
                $this->params['node']['details']['relatedItems']
              );
            } else {
              unset($page->metadata->relatedItems);
            }
          }
          break;
        case 'setLocked':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('locked', $this->params['node']['details'])) {
            $page->metadata->locked = filter_var($this->params['node']['details']['locked'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        case 'setPublished':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('published', $this->params['node']['details'])) {
            $page->metadata->published = filter_var($this->params['node']['details']['published'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        case 'setHideInMenu':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('hideInMenu', $this->params['node']['details'])) {
            $page->metadata->hideInMenu = filter_var($this->params['node']['details']['hideInMenu'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        case 'setSlug':
          // Limited case - allow modifying slug but validate it's unique
          if (array_key_exists('slug', $this->params['node']['details']) && $this->params['node']['details']['slug'] !== '') {
            $newSlug = $this->params['node']['details']['slug'];
            // no segment may be the reserved "x" route
            $page->slug = HAXCMSSite::reserveSlugRoutes(
              $GLOBALS['HAXCMS']->generateSlugName($newSlug)
            );
            // When user manually sets a slug, mark it as overridden so pathauto won't overwrite it
            if (!isset($page->metadata)) {
              $page->metadata = new stdClass();
            }
            $page->metadata->overridePathauto = true;
          }
          break;
        case 'setOverridePathauto':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('overridePathauto', $this->params['node']['details'])) {
            $page->metadata->overridePathauto = filter_var($this->params['node']['details']['overridePathauto'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        default:
          break;
      }

      // no gaps or repeats left behind in the old or the new group, and
      // indent is the page's depth, for the moved page and the branch under it
      if ($isOutlineOperation) {
        OutlineUpkeep::compactSiblingOrders($items);
        OutlineUpkeep::syncIndentToDepth($items);
      }
      // Since loadNode returns a reference, $page modifications already update the manifest
      // Only reassign items if we made a copy that needs to go back
      $site->manifest->items = $items;
      
      // Safety check: ensure item count hasn't changed
      if (count($site->manifest->items) !== $originalItemCount) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Item count mismatch: expected ' . $originalItemCount . ' but got ' . count($site->manifest->items) . '. Operation aborted to prevent data loss.',
          )
        );
      }
      
      // #3043: rebuild page.metadata.files from a content path-scan so the
      // uuid set stays in sync (and legacy object-shape entries self-heal to
      // uuids on the next details save). Reads the current on-disk content.
      $pageContentForScan = '';
      if (method_exists($site, 'getPageContent')) {
        $pageContentForScan = (string) $site->getPageContent($page);
      }
      FileContentScanner::rebuildPageFilesUuids($site, $page, $pageContentForScan);
      $site->manifest->metadata->site->updated = time();
      $site->manifest->save(false);
      $site->updateAlternateFormats();
      $site->gitCommit('Node operation: ' . $operation . ' on ' . $page->title . ' (' . $page->id . ')');

      $updated = $site->loadNode($page->id);
      return array(
        'status' => 200,
        'data' => $updated,
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
