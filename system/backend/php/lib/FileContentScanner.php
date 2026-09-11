<?php
include_once dirname(__FILE__) . '/FilesDataStore.php';
/**
 * Extract file references from saved page HTML (Phase 2, issue #3043).
 *
 * extractFileReferences(html) returns the deduped set of 'files/...' paths
 * found in src and href attributes, ignoring external URLs and non-files
 * paths. Used on page save to rebuild page.metadata.files as a uuid-string
 * array via FilesDataStore->resolveUuidByPath.
 */
class FileContentScanner
{
    /**
     * Extract deduped files/... paths from HTML src and href attributes.
     *
     * Only relative paths starting with 'files/' (after stripping a leading
     * basePath or './') are returned. External URLs (http://, https://, //,
     * data:, etc.) and non-files paths are ignored. Query strings and
     * fragments are stripped so the same file referenced with different
     * cache-busters dedupes to one path.
     *
     * @param string $html The saved page HTML content.
     * @return string[] Deduped files/... paths (preserves first-seen order).
     */
    public static function extractFileReferences($html)
    {
        $html = (string) $html;
        if ($html === '') {
            return array();
        }
        // Collect every src="..." and href="..." value. Match single and
        // double-quoted attribute values (case-insensitive tag/attr names).
        $paths = array();
        if (preg_match_all('/\b(?:src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $value = isset($match[1]) && $match[1] !== '' ? $match[1] : (isset($match[2]) ? $match[2] : '');
                $normalized = self::normalizeFileReference($value);
                if ($normalized !== '') {
                    $paths[] = $normalized;
                }
            }
        }
        // Dedupe preserving first-seen order.
        $deduped = array();
        foreach ($paths as $path) {
            if (!in_array($path, $deduped, true)) {
                $deduped[] = $path;
            }
        }
        return $deduped;
    }

    /**
     * Normalize a single src/href value into a canonical 'files/...' path.
     *
     * Strips query strings, fragments, leading './', and leading basePath
     * segments so that absolute-ish site URLs still resolve to the files/
     * relative path. Returns '' for external URLs and non-files paths.
     *
     * @param string $value The raw attribute value.
     * @return string The canonical 'files/...' path, or '' if not a file ref.
     */
    public static function normalizeFileReference($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        // Strip query string and fragment.
        $value = preg_replace('/[?#].*$/', '', $value);
        if ($value === '' || $value === null) {
            return '';
        }
        // Reject external URLs: anything with a scheme, or protocol-relative
        // (//), or data: URIs.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $value) || strpos($value, '//') === 0) {
            return '';
        }
        // Normalize backslashes and trim.
        $value = str_replace('\\', '/', $value);
        $value = ltrim($value, '/');
        // Strip leading './' segments.
        while (strpos($value, './') === 0) {
            $value = substr($value, 2);
        }
        // If the path contains a sitesDirectory/<siteName>/files/ prefix
        // (e.g. /_sites/mysite/files/banner.jpg), reduce to files/banner.jpg.
        $filesPos = strpos($value, 'files/');
        if ($filesPos !== false && $filesPos > 0) {
            $before = substr($value, 0, $filesPos);
            // Only strip if the preceding segment chain looks like a site
            // path (no query/fragment left, no other 'files/' in before).
            if (strpos($before, 'files/') === false) {
                $value = substr($value, $filesPos);
            }
        }
        // Must start with 'files/'.
        if (strpos($value, 'files/') !== 0) {
            return '';
        }
        // Reject traversal attempts.
        if (strpos($value, '..') !== false || strpos($value, "\0") !== false) {
            return '';
        }
        return $value;
    }

    /**
     * Rebuild page.metadata.files as a deduped uuid-string array from a
     * content path-scan. For each files/... path found in the HTML, resolve
     * it to a uuid via FilesDataStore->resolveUuidByPath (upserting unknown
     * paths first with the deterministic uuid at first ingest). A file
     * removed from the content drops out of the set automatically.
     *
     * @param mixed $site The site context.
     * @param object $page The JOS item (modified in place).
     * @param string $html The saved page HTML content.
     * @return string[] The uuid array (also set on $page->metadata->files).
     */
    public static function rebuildPageFilesUuids($site, $page, $html)
    {
        $paths = self::extractFileReferences($html);
        $uuids = array();
        if (count($paths) > 0) {
            $dataStore = new FilesDataStore($site);
            foreach ($paths as $path) {
                $uuid = $dataStore->resolveUuidByPath($path);
                if ($uuid !== '' && !in_array($uuid, $uuids, true)) {
                    $uuids[] = $uuid;
                }
            }
        }
        if (!isset($page->metadata) || !is_object($page->metadata)) {
            $page->metadata = new stdClass();
        }
        $page->metadata->files = $uuids;
        return $uuids;
    }
}
