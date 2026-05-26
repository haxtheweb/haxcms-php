<?php
trait OperationsRouteSaveNodeDetails {
  /**
   * @OA\Post(
   *    path="/saveNodeDetails",
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
   *        description="Perform a singular node operation: moveUp, moveDown, indent, outdent, setParent, setTitle, setDescription, setTags, setIcon, setMedia, setImage, setRelatedItems, setLocked, setPublished, setHideInMenu, setSlug"
   *   )
   * )
   */
  public function saveNodeDetails() {
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
      $originalItemCount = count($site->manifest->items);
      $items = $site->manifest->items;
      $sameParent = function($a, $b) {
        $pa = isset($a->parent) ? $a->parent : null;
        $pb = isset($b->parent) ? $b->parent : null;
        return ($pa === $pb);
      };
      $siblings = array();
      foreach ($items as $it) {
        if ($sameParent($it, $page)) { $siblings[] = $it; }
      }
      // helper to find sibling by order within same parent
      $findSiblingByOrder = function($order) use ($siblings) {
        foreach ($siblings as $s) {
          if (isset($s->order) && (int)$s->order === (int)$order) { return $s; }
        }
        return null;
      };
      // helper to get last child order for a given parent id
      $lastChildOrder = function($parentId) use ($items) {
        $max = -1;
        foreach ($items as $it) {
          $p = isset($it->parent) ? $it->parent : null;
          if ($p === $parentId && isset($it->order)) {
            $o = (int)$it->order;
            if ($o > $max) { $max = $o; }
          }
        }
        return $max;
      };

      switch ($operation) {
        case 'moveUp':
          if (isset($page->order) && (int)$page->order > 0) {
            $other = $findSiblingByOrder((int)$page->order - 1);
            if ($other && $other->id !== $page->id) {
              $other->order = (int)$other->order + 1;
              $page->order = (int)$page->order - 1;
            }
          }
          break;
        case 'moveDown':
          if (isset($page->order)) {
            $other = $findSiblingByOrder((int)$page->order + 1);
            if ($other && $other->id !== $page->id) {
              $other->order = (int)$other->order - 1;
              $page->order = (int)$page->order + 1;
            }
          }
          break;
        case 'indent':
          if (isset($page->order)) {
            $prev = $findSiblingByOrder((int)$page->order - 1);
            if ($prev) {
              $page->parent = $prev->id;
              $page->indent = isset($prev->indent) ? ((int)$prev->indent + 1) : 1;
              $page->order = $lastChildOrder($prev->id) + 1;
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
          break;
        case 'setParent':
          // Move page under a specific parent
          // Use array_key_exists to properly handle null values
          $newParent = array_key_exists('parent', $this->params['node']['details']) ? $this->params['node']['details']['parent'] : null;
          $newOrder = array_key_exists('order', $this->params['node']['details']) ? (int)$this->params['node']['details']['order'] : 0;
          // account for this being set to empty string which means null
          if (!$newParent || $newParent === '') {
            $newParent = null;
          }
          // Update the page's parent and order
          $page->parent = $newParent;
          $page->order = $newOrder;
          // Calculate indent based on new parent depth
          if ($newParent === null) {
            $page->indent = 0;
          } else {
            $parentNode = $site->loadNode($newParent);
            $page->indent = $parentNode && isset($parentNode->indent) ? ((int)$parentNode->indent + 1) : 1;
          }
          break;
        // Singular field modification operations
        case 'setTitle':
          if (array_key_exists('title', $this->params['node']['details']) && $this->params['node']['details']['title'] !== '') {
            $page->title = strip_tags($this->params['node']['details']['title']);
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
              $page->metadata->tags = SanitizeContent::sanitizeMetadataValue(
                $this->params['node']['details']['tags']
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
            if ($this->params['node']['details']['image'] !== '' && $this->params['node']['details']['image'] !== null) {
              $page->metadata->image = SanitizeContent::sanitizeURLValue(
                $this->params['node']['details']['image'],
                ''
              );
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
            // account for x being the only front end reserved route
            if ($newSlug == "x") {
              $newSlug = "x-x";
            }
            // same but trying to force a sub-route; paths cannot conflict with frontend
            if (substr($newSlug, 0, 2) == "x/") {
              $newSlug = str_replace('x/', 'x-x/', $newSlug);
            }
            $page->slug = $GLOBALS['HAXCMS']->generateSlugName($newSlug);
          }
          break;
        default:
          break;
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
