<?php
/**
 * Outline upkeep shared by the single-page operations (create, move, indent,
 * outdent, set parent, delete). saveOutline writes the whole outline as the
 * outline designer sent it and does not use any of this.
 *
 * The rules kept here, the same in haxcms-nodejs (src/lib/outlineUpkeep.js):
 *  - siblings never share an `order` value after a single-page operation
 *  - `indent` is the page's depth: 0 at the top level, parent's indent + 1 below
 *  - a locked page is changed by nothing but unlocking it
 */
class OutlineUpkeep {
  public static function parentOf($item) {
    if (!is_object($item) || !isset($item->parent) || $item->parent === null || $item->parent === '') {
      return null;
    }
    return $item->parent;
  }

  public static function orderOf($item) {
    if (!is_object($item) || !isset($item->order) || !is_numeric($item->order)) {
      return 0;
    }
    return (int) $item->order;
  }

  /**
   * The pages under one parent, in the sequence a reader sees them: by order,
   * and where two share an order, by their position in the items array.
   */
  public static function sortedSiblings($items, $parentId = null) {
    $siblings = array();
    $index = 0;
    foreach ($items as $item) {
      if (is_object($item) && self::parentOf($item) === $parentId) {
        $siblings[] = array('item' => $item, 'index' => $index);
      }
      $index++;
    }
    usort($siblings, function ($a, $b) {
      $byOrder = OutlineUpkeep::orderOf($a['item']) - OutlineUpkeep::orderOf($b['item']);
      return $byOrder !== 0 ? $byOrder : ($a['index'] - $b['index']);
    });
    $sorted = array();
    foreach ($siblings as $entry) {
      $sorted[] = $entry['item'];
    }
    return $sorted;
  }

  /**
   * When pages under one parent share an order value (or hold one that is not
   * a whole number), renumber that group 0..n-1 in the sequence they are
   * already shown in. A group whose orders are distinct is left as it is.
   * @return bool whether anything was renumbered
   */
  public static function healSiblingOrder($items, $parentId = null) {
    $siblings = self::sortedSiblings($items, $parentId);
    $seen = array();
    $broken = false;
    foreach ($siblings as $sibling) {
      $raw = isset($sibling->order) ? $sibling->order : null;
      if (!(is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw))) || isset($seen[(int) $raw])) {
        $broken = true;
        break;
      }
      $seen[(int) $raw] = true;
    }
    if (!$broken) {
      return false;
    }
    foreach ($siblings as $position => $sibling) {
      $sibling->order = $position;
    }
    return true;
  }

  /**
   * A page was just placed at $page->order under its parent. When a sibling
   * already holds that order, move it and every sibling after it down one so
   * the page takes the spot it asked for.
   */
  public static function makeRoomFor($items, $page) {
    if (!is_object($page)) {
      return;
    }
    $parentId = self::parentOf($page);
    $wanted = self::orderOf($page);
    $taken = false;
    foreach ($items as $item) {
      if (is_object($item) && $item !== $page && self::parentOf($item) === $parentId && self::orderOf($item) === $wanted) {
        $taken = true;
        break;
      }
    }
    if (!$taken) {
      return;
    }
    foreach ($items as $item) {
      if (is_object($item) && $item !== $page && self::parentOf($item) === $parentId && self::orderOf($item) >= $wanted) {
        $item->order = self::orderOf($item) + 1;
      }
    }
  }

  /** Highest order among the pages under a parent, -1 when it has none. */
  public static function lastSiblingOrder($items, $parentId = null, $except = null) {
    $max = -1;
    foreach ($items as $item) {
      if (is_object($item) && $item !== $except && self::parentOf($item) === $parentId && self::orderOf($item) > $max) {
        $max = self::orderOf($item);
      }
    }
    return $max;
  }

  /**
   * Set every page's indent to its depth. A parent that is not in the outline
   * counts as one level; a parent chain that loops counts as the top level,
   * so this always ends.
   */
  public static function syncIndentToDepth($items) {
    $byId = array();
    foreach ($items as $item) {
      if (is_object($item) && isset($item->id)) {
        $byId[(string) $item->id] = $item;
      }
    }
    foreach ($items as $item) {
      if (!is_object($item)) {
        continue;
      }
      $depth = 0;
      $cursor = $item;
      $visited = array();
      while ($cursor) {
        $parentId = self::parentOf($cursor);
        if ($parentId === null) {
          break;
        }
        $key = (string) $parentId;
        if (isset($visited[$key])) {
          $depth = 0;
          break;
        }
        if (!isset($byId[$key])) {
          // a parent that is not in the outline still counts as one level
          $depth++;
          break;
        }
        $visited[$key] = true;
        $depth++;
        $cursor = $byId[$key];
      }
      $item->indent = $depth;
    }
  }

  public static function isLocked($page) {
    return is_object($page)
      && isset($page->metadata)
      && is_object($page->metadata)
      && isset($page->metadata->locked)
      && ($page->metadata->locked === true || $page->metadata->locked === 'true');
  }

  /**
   * Re-slug a page from its title under its current parent, then every page
   * in the branch under it. A page with overridePathauto keeps its slug, and
   * so does its own branch. Nothing happens when the site has pathauto off.
   */
  public static function reslugPageAndBranch($site, $page, $items) {
    $overridePathauto = isset($page->metadata->overridePathauto) && $page->metadata->overridePathauto === true;
    if (!$site->isPathautoEnabled() || $overridePathauto) {
      return;
    }
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
}
