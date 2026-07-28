<?php
/**
 * JSONOutlineSchemaItem - a single item without an outline of items.
 */

// Guard against double-loading the helper when this class is included in
// isolation (e.g. tests). It mirrors HAXCMSFile::isPathWithinRoot without a
// hard dependency on the full HAXCMS bootstrap.
if (!function_exists('haxcmsPathWithinBase')) {
    function haxcmsPathWithinBase($resolvedPath, $resolvedBase)
    {
        $normalizedPath = rtrim(str_replace('\\', '/', (string) $resolvedPath), '/');
        $normalizedBase = rtrim(str_replace('\\', '/', (string) $resolvedBase), '/');
        if ($normalizedPath === '' || $normalizedBase === '') {
            return false;
        }
        if ($normalizedPath === $normalizedBase) {
            return true;
        }
        return strpos($normalizedPath, $normalizedBase . '/') === 0;
    }
}

class JSONOutlineSchemaItem
{
    public $id;
    public $indent;
    public $location;
    public $slug;
    public $order;
    public $parent;
    public $title;
    public $description;
    public $metadata;
    /**
     * Establish defaults for a new item
     */
    public function __construct()
    {
        $this->id = 'item-' . $this->generateUUID();
        $this->indent = 0;
        $this->location = '';
        $this->slug = '';
        $this->order = 0;
        $this->parent = '';
        $this->title = 'New item';
        $this->description = '';
        $this->metadata = new stdClass();
    }
    /**
     * Load data from the location specified
     */
    public function readLocation($basePath = '')
    {
        // Security best practice (N1): reject any location that resolves
        // outside the supplied base directory. The previous single-pass
        // str_replace('../','') is bypassable (e.g. `....//` collapses to
        // `../` after one replacement) and only worked by luck on existing
        // files. realpath() canonicalizes and lets us prove containment.
        if (!is_string($this->location) || $this->location === '') {
            return false;
        }
        if (strpos($this->location, "\0") !== false) {
            return false;
        }
        $candidate = $basePath . $this->location;
        // If the file does not yet exist realpath returns false; fall back to a
        // normalized lexical check so reads of not-yet-created pages still
        // validate, but still reject traversal segments.
        $normalized = str_replace('\\', '/', $candidate);
        if (strpos($normalized, '../') !== false || strpos($normalized, '/..') !== false) {
            return false;
        }
        $resolvedBase = realpath($basePath);
        $resolved = realpath($candidate);
        if ($resolved !== false && $resolvedBase !== false) {
            if (!haxcmsPathWithinBase($resolved, $resolvedBase)) {
                return false;
            }
            return @file_get_contents($resolved);
        }
        // file doesn't exist yet; the normalized lexical check above already
        // rejected traversal, so a safe non-existent read returns false.
        if (file_exists($candidate)) {
            return @file_get_contents($candidate);
        }
        return false;
    }
    /**
     * Load data from the location specified
     */
    public function writeLocation($body, $basePath = '')
    {
        // ensure we have a blank set
        if ($body == '') {
            $body = '<p></p>';
        }
        // Security best practice (N1): the destination must stay inside the
        // supplied base directory. The previous single-pass
        // str_replace('../','') is bypassable. Use realpath after the parent
        // exists, plus a lexical traversal-segment reject as a pre-check.
        if (!is_string($this->location) || $this->location === '') {
            return false;
        }
        if (strpos($this->location, "\0") !== false) {
            return false;
        }
        $candidate = $basePath . $this->location;
        $normalized = str_replace('\\', '/', $candidate);
        if (strpos($normalized, '../') !== false || strpos($normalized, '/..') !== false) {
            return false;
        }
        $resolvedBase = realpath($basePath);
        // The page file should already exist (boilerplate copied first). If it
        // does, realpath it and prove containment before writing.
        if (file_exists($candidate)) {
            $resolved = realpath($candidate);
            if ($resolved !== false && $resolvedBase !== false) {
                if (!haxcmsPathWithinBase($resolved, $resolvedBase)) {
                    return false;
                }
                return @file_put_contents($resolved, $body, LOCK_EX);
            }
        }
        // Fallback: file does not exist yet. The lexical traversal check above
        // already rejected `..` segments, so writing the candidate path is safe.
        return @file_put_contents($candidate, $body, LOCK_EX);
    }
    /**
     * Generate a UUID (RFC 4122 v4).
     *
     * Security best practice (N2): uses random_bytes (CSPRNG) instead of
     * mt_rand so item/node IDs are not predictable. These IDs are non-secret
     * (exposed in URLs/metadata) but CSPRNG is a strict improvement at no
     * cost, consistent with HAXCMS::generateUUID and
     * SiteRouteUtils::generateUUID.
     */
    private function generateUUID()
    {
        $bytes = random_bytes(16);
        // version 4 (random) in the high nibble of byte 6
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // variant RFC 4122 (10xx) in the high bits of byte 8
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }
}
