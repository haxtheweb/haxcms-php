<?php
/**
 * Page structure history (haxtheweb/issues#3191). Mirrors haxcms-nodejs
 * src/lib/structureHistory.js; keep the two in step.
 *
 * Outline saves (moves, renames, publish toggles) only change site.json, so
 * `git log -- <page file>` never shows them in a page's revision history.
 * This reads site.json at each recent commit that touched it and reports the
 * commits where one item's outline entry changed, with before/after values.
 *
 * Read-only: nothing here writes to the site or changes how saves work.
 */
class StructureHistory
{
    const SITE_FILE = 'site.json';
    // how many of the most recent site.json commits are inspected per request
    const STRUCTURE_SCAN_LIMIT = 300;

    private static $topLevelFields = array('title', 'slug', 'parent', 'order');
    private static $metadataFields = array('published', 'hideInMenu', 'locked');

    /**
     * Read many blobs with one `git cat-file --batch` process. Returns an
     * array aligned with $specs: the blob contents, or null when missing.
     *
     * The specs are "<hash>:site.json" lines (about 50 bytes each, at most
     * STRUCTURE_SCAN_LIMIT + 1 of them), so they fit in the pipe buffer and
     * writing them all before reading cannot deadlock.
     */
    public static function readBlobs($siteDirectory, $specs)
    {
        if (count($specs) === 0) {
            return array();
        }
        $empty = array_fill(0, count($specs), null);
        $gitBin = Git::get_bin();
        if (!is_string($gitBin) || $gitBin === '') {
            $gitBin = 'git';
        }
        $command = escapeshellarg($gitBin) . ' --no-pager cat-file --batch';
        $pipes = array();
        $process = proc_open(
            $command,
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $siteDirectory
        );
        if (!is_resource($process)) {
            return $empty;
        }
        fwrite($pipes[0], implode("\n", $specs) . "\n");
        fclose($pipes[0]);
        $data = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        if (!is_string($data)) {
            return $empty;
        }
        $results = array();
        $pos = 0;
        $length = strlen($data);
        foreach ($specs as $spec) {
            $newline = $pos < $length ? strpos($data, "\n", $pos) : false;
            if ($newline === false) {
                $results[] = null;
                continue;
            }
            $header = substr($data, $pos, $newline - $pos);
            $pos = $newline + 1;
            $parts = explode(' ', $header);
            if (count($parts) < 3 || $parts[1] !== 'blob') {
                // "<spec> missing" or a non-blob object
                $results[] = null;
                continue;
            }
            $size = intval($parts[2]);
            $results[] = substr($data, $pos, $size);
            // content is followed by a newline
            $pos = $pos + $size + 1;
        }
        return $results;
    }

    public static function parseManifest($blob)
    {
        if (!is_string($blob) || $blob === '') {
            return null;
        }
        $manifest = json_decode($blob, true);
        if (is_array($manifest) && isset($manifest['items']) && is_array($manifest['items'])) {
            return $manifest;
        }
        return null;
    }

    private static function displayValue($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return (string) $value;
    }

    /**
     * The outline snapshot of one item within a manifest, or null when the
     * item is not in it. Parent is resolved to its title in the same manifest.
     */
    public static function itemSnapshot($manifest, $itemId)
    {
        if (!is_array($manifest)) {
            return null;
        }
        $item = null;
        $titles = array();
        foreach ($manifest['items'] as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            $titles[(string) $entry['id']] = isset($entry['title']) ? $entry['title'] : '';
            if ((string) $entry['id'] === (string) $itemId) {
                $item = $entry;
            }
        }
        if ($item === null) {
            return null;
        }
        $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : array();
        $parentId = isset($item['parent']) && $item['parent'] ? (string) $item['parent'] : '';
        $snapshot = array(
            'title' => self::displayValue(isset($item['title']) ? $item['title'] : null),
            'slug' => self::displayValue(isset($item['slug']) ? $item['slug'] : null),
            'parent' => $parentId === ''
                ? ''
                : self::displayValue(isset($titles[$parentId]) && $titles[$parentId] !== '' ? $titles[$parentId] : $parentId),
            'parentId' => $parentId,
            'order' => self::displayValue(isset($item['order']) ? $item['order'] : null),
        );
        foreach (self::$metadataFields as $field) {
            // unset and false read the same
            $snapshot[$field] = (isset($metadata[$field]) && $metadata[$field] === true) ? 'true' : 'false';
        }
        return $snapshot;
    }

    /**
     * The field changes between two snapshots, [] when nothing changed. A
     * parent that is renamed but not changed is not reported as a move.
     */
    public static function diffSnapshots($before, $after)
    {
        $changes = array();
        if (!is_array($before) || !is_array($after)) {
            return $changes;
        }
        foreach (array_merge(self::$topLevelFields, self::$metadataFields) as $field) {
            if ($field === 'parent') {
                if ($before['parentId'] !== $after['parentId']) {
                    $changes[] = array(
                        'field' => 'parent',
                        'from' => $before['parent'],
                        'to' => $after['parent'],
                        'fromId' => $before['parentId'],
                        'toId' => $after['parentId'],
                    );
                }
                continue;
            }
            if ($before[$field] !== $after[$field]) {
                $changes[] = array('field' => $field, 'from' => $before[$field], 'to' => $after[$field]);
            }
        }
        return $changes;
    }

    /**
     * Map of commit hash => changes for the site.json commits where $itemId's
     * outline entry changed. $hashes must be newest first; each commit is
     * compared with the next older site.json version in the list (the oldest
     * one is only used as a baseline).
     */
    public static function findStructureChanges($siteDirectory, $itemId, $hashes)
    {
        $result = array();
        if (!is_array($hashes) || count($hashes) < 2) {
            return $result;
        }
        $specs = array();
        foreach ($hashes as $hash) {
            $specs[] = $hash . ':' . self::SITE_FILE;
        }
        $blobs = self::readBlobs($siteDirectory, $specs);
        $newer = self::itemSnapshot(self::parseManifest($blobs[0]), $itemId);
        for ($i = 1; $i < count($hashes); $i++) {
            $older = self::itemSnapshot(self::parseManifest($blobs[$i]), $itemId);
            $changes = self::diffSnapshots($older, $newer);
            if (count($changes) > 0) {
                $result[$hashes[$i - 1]] = $changes;
            }
            $newer = $older;
        }
        return $result;
    }
}
