<?php
/**
 * JSONOutlineSchema - An object for interfacing with the JSON Outline schema
 * specification. @see https://github.com/haxtheweb/json-outline-schema
 * for more details. This provides a simple way of loading outlines, parsing
 * and working with the items in them while writing back to the specification
 * accurately.
 */

include_once 'JSONOutlineSchemaItem.php';

class JSONOutlineSchema
{
    public $file;
    public $id;
    public $title;
    public $author;
    public $description;
    public $license;
    public $metadata;
    public $items;
    public $location;
    /**
     * Establish defaults
     */
    public function __construct()
    {
        $this->file = null;
        $this->id = $this->generateUUID();
        $this->title = 'New site';
        $this->author = '';
        $this->description = '';
        $this->license = 'by-sa';
        $this->metadata = new stdClass();
        $this->items = array();
    }

    /**
     * Get a reasonable license name from the short hand
     */
    public function getLicenseDetails($all = FALSE)
    {
        $list = array(
            "by" => array(
                'name' => "Creative Commons: Attribution",
                'link' => "https://creativecommons.org/licenses/by/4.0/",
                'image' => "https://i.creativecommons.org/l/by/4.0/88x31.png"
            ),
            "by-sa" => array(
                'name' => "Creative Commons: Attribution Share a like",
                'link' => "https://creativecommons.org/licenses/by-sa/4.0/",
                'image' => "https://i.creativecommons.org/l/by-sa/4.0/88x31.png"
            ),
            "by-nd" => array(
                'name' => "Creative Commons: Attribution No derivatives",
                'link' => "https://creativecommons.org/licenses/by-nd/4.0/",
                'image' => "https://i.creativecommons.org/l/by-nd/4.0/88x31.png"
            ),
            "by-nc" => array(
                'name' => "Creative Commons: Attribution non-commercial",
                'link' => "https://creativecommons.org/licenses/by-nc/4.0/",
                'image' => "https://i.creativecommons.org/l/by-nc/4.0/88x31.png"
            ),
            "by-nc-sa" => array(
                'name' =>
                    "Creative Commons: Attribution non-commercial share a like",
                'link' => "https://creativecommons.org/licenses/by-nc-sa/4.0/",
                'image' =>
                    "https://i.creativecommons.org/l/by-nc-sa/4.0/88x31.png"
            ),
            "by-nc-nd" => array(
                'name' =>
                    "Creative Commons: Attribution Non-commercial No derivatives",
                'link' => "https://creativecommons.org/licenses/by-nc-nd/4.0/",
                'image' =>
                    "https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png"
            )
        );
        if ($all) {
            return $list;
        }
        if (isset($list[$this->license])) {
            return $list[$this->license];
        }
        return array();
    }
    /**
     * Get a new item matching schema standards
     * @return new JSONOutlineSchemaItem Object
     */
    public function newItem()
    {
        $item = new JSONOutlineSchemaItem();
        return $item;
    }
    /**
     * Add an item to the outline
     * @var $item an array of values, keyed to match JSON Outline Schema
     * @return count of items in the array
     */
    public function addItem($item)
    {
        $safeItem = $this->validateItem($item);
        $count = array_push($this->items, $safeItem);
        return $count;
    }
    /**
     * Validate that an item matches JSONOutlineSchemaItem format
     * @var $item JSONOutlineSchemaItem
     * @return JSONOutlineSchemaItem matching the specification
     */
    private function validateItem($item)
    {
        // create a generic schema item
        $tmp = new JSONOutlineSchemaItem();
        // crush the item given into a stdClass object
        $ary = get_object_vars($item);
        foreach ($ary as $key => $value) {
            // only set what the element from spec allows into a new object.
            // property_exists (not isset) so spec keys whose value is null,
            // like a top-level parent, are still copied.
            if (property_exists($tmp, $key)) {
                $tmp->{$key} = $value;
            }
        }
        return $tmp;
    }
    /**
     * Remove an item from the outline if it exists
     * @var $id an id that's in the array of items
     * @return JSONOutlineSchemaItem or FALSE if not found
     */
    public function removeItem($id)
    {
        foreach ($this->items as $key => $item) {
            if ($item->id == $id) {
                $tmp = $this->items[$key];
                unset($this->items[$key]);
                return $tmp;
            }
        }
        return false;
    }
    /**
     * Update an item in the outline
     * @var $id an id that's in the array of items
     * @return JSONOutlineSchemaItem or FALSE if not found
     */
    public function updateItem($item, $save = false)
    {
        // verify this is a legit item
        $safeItem = $this->validateItem($item);
        foreach ($this->items as $key => $tmpItem) {
            // match the current item's ID to our safeItem passed in
            if ($tmpItem->id == $safeItem->id) {
                // overwrite the item
                $this->items[$key] = $safeItem;
                // if we save, then we let that return the whole file
                if ($save) {
                    return $this->save();
                }
                // this was successful
                return true;
            }
        }
        // we didn't find a match on the ID to bother saving an update
        return false;
    }
    /**
     * Load a schema from a file
     */
    public function load($location)
    {
        if (file_exists($location)) {
            $this->file = $location;
            $fileData = json_decode(file_get_contents($location));
            if (is_object($fileData)) {
                $vars = get_object_vars($fileData);
                foreach ($vars as $key => $var) {
                    if (isset($this->{$key}) && $key != 'items') {
                        $this->{$key} = $var;
                    }
                }
                // check for items and escalate to full JSONOutlineSchemaItem object
                // also ensures data matches only what is supported
                if (isset($vars['items'])) {
                    foreach ($vars['items'] as $key => $item) {
                        $newItem = new JSONOutlineSchemaItem();
                        $newItem->id = $item->id;
                        $newItem->indent = $item->indent;
                        $newItem->location = $item->location;
                        // support for legacy spec prior to slug existing
                        $newItem->slug = (isset($item->slug) ? $item->slug : str_replace('pages/', '', str_replace('/index.html','', $item->location)));
                        $newItem->order = $item->order;
                        $newItem->parent = $item->parent;
                        $newItem->title = $item->title;
                        $newItem->description = $item->description;
                        // metadata can be anything so whatever
                        $newItem->metadata = $item->metadata;
                        $this->items[$key] = $newItem;
                    }
                }
                return true;
            }
        }
        return false;
    }
    /**
     * Get an item by ID
     */
    public function getItemById($id) {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }
        return false;
    }
    
    /**
     * Get a key by ID, useful to find previous and next items quickly
     */
    public function getItemKeyById($id) {
        foreach ($this->items as $key => $item) {
            if ($item->id === $id) {
                return $key;
            }
        }
        return false;
    }
    /**
     * Save data back to the file system location
     */
    public function save($reorder = true)
    {
        // on every save we ensure it's sorted in the right order
        if ($reorder) {
            $this->items = $this->orderTree($this->items);
        }
        // write canonical value types (see normalizeItemValues)
        foreach ($this->items as $key => $item) {
            $this->items[$key] = self::normalizeItemValues($item);
        }
        $this->metadata = self::normalizeSiteMetadata($this->metadata);
        $schema = get_object_vars($this);
        $file = $schema['file'];
        unset($schema['file']);
        $schema['items'] = array();
        foreach ($this->items as $item) {
            $newItem = get_object_vars($item);
            array_push($schema['items'], $newItem);
        }
        // ensure we have valid json object
        if ($output = json_encode($schema, JSON_PRETTY_PRINT)) {
          return @file_put_contents($file, $output, LOCK_EX);
        }
    }
    /**
     * Canonical value types for site.json, per haxtheweb/json-outline-schema:
     * top-level parent is null, indent / order / timestamps are integers,
     * published / hideInMenu / locked are booleans, tags and relatedItems are
     * arrays. Older sites stored "1" / "" flags, numeric strings and
     * comma-separated tags; readers keep accepting those, every save writes
     * the canonical form. Meaning is preserved: published is only false for
     * an explicit false / 0 / "0" / "false" / "no" / "off" (matching
     * SiteRouteUtils::isMetadataBooleanFalse and the front end's
     * `published !== false`), hideInMenu / locked are only true for an
     * explicit true / 1 / "1" / "true" / "yes" / "on".
     */
    public static function normalizeItemValues($item)
    {
        if (!is_object($item)) {
            return $item;
        }
        if (property_exists($item, 'parent') && ($item->parent === '' || $item->parent === false)) {
            $item->parent = null;
        }
        foreach (array('indent', 'order') as $key) {
            if (property_exists($item, $key)) {
                $item->{$key} = self::normalizeInteger($item->{$key});
            }
        }
        if (isset($item->metadata) && (is_object($item->metadata) || is_array($item->metadata))) {
            $isArray = is_array($item->metadata);
            $metadata = $isArray ? $item->metadata : get_object_vars($item->metadata);
            if (array_key_exists('published', $metadata)) {
                $metadata['published'] = !self::isFalseLike($metadata['published']);
            }
            foreach (array('hideInMenu', 'locked') as $flag) {
                if (array_key_exists($flag, $metadata)) {
                    $metadata[$flag] = self::isTrueLike($metadata[$flag]);
                }
            }
            foreach (array('created', 'updated') as $stamp) {
                if (array_key_exists($stamp, $metadata)) {
                    $metadata[$stamp] = self::normalizeInteger($metadata[$stamp]);
                }
            }
            foreach (array('tags', 'relatedItems') as $listKey) {
                if (array_key_exists($listKey, $metadata) && !is_null($metadata[$listKey])) {
                    $metadata[$listKey] = self::normalizeList($metadata[$listKey]);
                }
            }
            $item->metadata = $isArray ? $metadata : (object) $metadata;
        }
        return $item;
    }
    /**
     * Canonical site-level metadata: metadata.site.tags as an array and
     * metadata.site.created / updated as integers.
     */
    public static function normalizeSiteMetadata($metadata)
    {
        if (!is_object($metadata) || !isset($metadata->site) || !is_object($metadata->site)) {
            return $metadata;
        }
        if (isset($metadata->site->tags)) {
            $metadata->site->tags = self::normalizeList($metadata->site->tags);
        }
        foreach (array('created', 'updated') as $stamp) {
            if (property_exists($metadata->site, $stamp)) {
                $metadata->site->{$stamp} = self::normalizeInteger($metadata->site->{$stamp});
            }
        }
        return $metadata;
    }
    private static function normalizeInteger($value)
    {
        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value) && floor($value) == $value) {
            return (int) $value;
        }
        return $value;
    }
    private static function isFalseLike($value)
    {
        if ($value === false || $value === 0) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), array('0', 'false', 'no', 'off'), true);
        }
        return false;
    }
    private static function isTrueLike($value)
    {
        if ($value === true || $value === 1) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
        }
        return false;
    }
    /**
     * Comma-separated string or array -> array of trimmed, non-empty, unique strings.
     */
    private static function normalizeList($value)
    {
        $parts = is_array($value) ? $value : (is_object($value) ? array_values(get_object_vars($value)) : explode(',', (string) $value));
        $list = array();
        foreach ($parts as $part) {
            if (is_scalar($part)) {
                $part = trim((string) $part);
                if ($part !== '' && !in_array($part, $list, true)) {
                    $list[] = $part;
                }
            }
        }
        return $list;
    }
    /**
     * Organize the items based on tree order. This makes front end navigation line up correctly
     */
    public function orderTree($items)
    {
        $sorted = array();
        // do an initial by order
        usort($items, function ($a, $b) {
            if ($a->order == $b->order) {
                return 0;
            }
            return ($a->order < $b->order) ? -1 : 1;
        });
        $this->orderRecurse($items, $sorted);
        // sanity check, should always be equal
        if (count($sorted) == count($items)) {
            return $sorted;
        }
        // if it bombed just pass it through... again, sanity
        return $items;
    }
    /**
     * Sort a JOS
     */
    private function orderRecurse(
        $currentItems,
        &$sorted = array(),
        &$idList = array()
    ) {
        foreach ($currentItems as $item) {
            if (!array_search($item->id, $idList)) {
                array_push($idList, $item->id);
                array_push($sorted, $item);
                $children = array();
                foreach ($this->items as $child) {
                    if ($child->parent == $item->id) {
                        array_push($children, $child);
                    }
                }
                // sort the kids
                usort($children, function ($a, $b) {
                    if ($a->order == $b->order) {
                        return 0;
                    }
                    return ($a->order < $b->order) ? -1 : 1;
                });
                
                // only walk deeper if there were children for this page
                if (count($children) > 0) {
                    $this->orderRecurse($children, $sorted, $idList);
                }
            }
        }
    }
    /**
     * Generate a UUID
     */
    private function generateUUID()
    {
        // Security (SEC-12): use a CSPRNG (random_bytes) instead of mt_rand so
        // identifiers are not predictable, matching SiteRouteUtils::generateUUID.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
