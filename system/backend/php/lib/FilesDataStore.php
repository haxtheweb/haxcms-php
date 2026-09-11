<?php
include_once dirname(__FILE__) . '/EntityRegistry.php';
/**
 * Low-level files.json I/O for the file entity datastore (Phase 2, #3043).
 *
 * Reads and writes {siteDirectory}/files/files.json (the HAXCMS-FILE-SCHEMA-V1
 * envelope) with an atomic write and two in-memory indexes for O(1) lookup:
 *   - uuid -> record (for load-by-uuid)
 *   - path -> uuid   (for the page-save content path-scan and social-share)
 *
 * Reuses SiteRouteUtils::getSiteDirectory for path resolution (no new
 * abstractions). The deterministic UUID helpers mirror the procedural
 * functions in siteRoutes/v1/files.php so persisted UUIDs match the values
 * existing consumers expect.
 */
class FilesDataStore
{
    /** @var mixed The site context (HAXCMSSite or test fake with siteDirectory). */
    private $site;

    /** @var array|null Parsed envelope (schema/site/generated/data). */
    private $envelope = null;

    /** @var array uuid -> record index. */
    private $uuidIndex = array();

    /** @var array path -> uuid index. */
    private $pathIndex = array();

    /** @var bool Whether the envelope was loaded from disk this request. */
    private $loaded = false;

    /**
     * @param mixed $site The site context (needs manifest.metadata.site.name
     *                     and either siteDirectory or directory+manifest).
     */
    public function __construct($site)
    {
        $this->site = $site;
    }

    // ------------------------------------------------------------------
    // Deterministic UUID helpers (mirror haxcmsSiteDeterministicFileUuid so
    // persisted UUIDs match the values the v1 list/get handlers computed).
    // ------------------------------------------------------------------

    /**
     * Canonicalize a relative path to 'files/...' form.
     *
     * @param string $relativePath
     * @return string
     */
    public static function canonicalPath($relativePath = '')
    {
        $normalized = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
        if ($normalized === '') {
            return 'files';
        }
        if (strpos($normalized, 'files/') === 0) {
            return $normalized;
        }
        return 'files/' . $normalized;
    }

    /**
     * Format a sha256 hex digest as a UUID string.
     *
     * @param string $hash
     * @return string
     */
    public static function uuidFromHash($hash = '')
    {
        $normalized = strtolower((string) $hash);
        if (strlen($normalized) < 32) {
            return '';
        }
        return
            substr($normalized, 0, 8) . '-' .
            substr($normalized, 8, 4) . '-' .
            substr($normalized, 12, 4) . '-' .
            substr($normalized, 16, 4) . '-' .
            substr($normalized, 20, 12);
    }

    /**
     * Resolve the site name from a site context object.
     *
     * @param mixed $site
     * @return string
     */
    public static function siteName($site)
    {
        if (
            isset($site) && is_object($site) &&
            isset($site->manifest) && isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->name) &&
            is_string($site->manifest->metadata->site->name) &&
            $site->manifest->metadata->site->name != ''
        ) {
            return $site->manifest->metadata->site->name;
        }
        if (isset($site) && is_object($site) && isset($site->name) && is_string($site->name) && $site->name != '') {
            return $site->name;
        }
        return 'site';
    }

    /**
     * Deterministic UUID: sha256(siteName:canonicalPath:size) formatted as UUID.
     *
     * @param mixed $site
     * @param string $relativePath
     * @param int $fileSize
     * @return string
     */
    public static function deterministicUuid($site, $relativePath = '', $fileSize = 0)
    {
        $canonical = self::canonicalPath($relativePath);
        $canonicalSize = (is_numeric($fileSize) && intval($fileSize) > 0) ? intval($fileSize) : 0;
        $identity = self::siteName($site) . ':' . $canonical . ':' . $canonicalSize;
        return self::uuidFromHash(hash('sha256', $identity));
    }

    // ------------------------------------------------------------------
    // Path resolution
    // ------------------------------------------------------------------

    /**
     * The absolute path to files.json.
     *
     * @return string
     */
    public function getFilesJsonPath()
    {
        $siteDirectory = '';
        if (function_exists('SiteRouteUtils::getSiteDirectory')) {
            $siteDirectory = SiteRouteUtils::getSiteDirectory($this->site);
        }
        if ($siteDirectory === '' && isset($this->site->siteDirectory) && is_string($this->site->siteDirectory)) {
            $siteDirectory = rtrim($this->site->siteDirectory, '/');
        }
        return rtrim($siteDirectory, '/') . '/files/files.json';
    }

    /**
     * The absolute path to the files/ directory.
     *
     * @return string
     */
    public function getFilesDirectory()
    {
        $siteDirectory = '';
        if (function_exists('SiteRouteUtils::getSiteDirectory')) {
            $siteDirectory = SiteRouteUtils::getSiteDirectory($this->site);
        }
        if ($siteDirectory === '' && isset($this->site->siteDirectory) && is_string($this->site->siteDirectory)) {
            $siteDirectory = rtrim($this->site->siteDirectory, '/');
        }
        return rtrim($siteDirectory, '/') . '/files';
    }

    // ------------------------------------------------------------------
    // Load / save the envelope
    // ------------------------------------------------------------------

    /**
     * Load the files.json envelope from disk (lazy, once per instance).
     * Auto-builds from disk if files.json is missing. Builds indexes.
     *
     * @return array The parsed envelope.
     */
    public function load()
    {
        if ($this->loaded) {
            return $this->envelope;
        }
        $this->loaded = true;
        $path = $this->getFilesJsonPath();
        if (is_file($path)) {
            $contents = @file_get_contents($path);
            if ($contents !== false && $contents !== '') {
                $decoded = json_decode($contents, true);
                if (is_array($decoded) && isset($decoded['schema']) && isset($decoded['data'])) {
                    $this->envelope = $decoded;
                    $this->buildIndexes();
                    return $this->envelope;
                }
            }
        }
        // Missing or corrupt: auto-build from disk, then persist.
        $this->envelope = $this->buildEmptyEnvelope();
        $this->buildIndexes();
        $this->reconcileMissingFromDisk();
        return $this->envelope;
    }

    /**
     * Persist the envelope to disk atomically (write temp + rename).
     *
     * @return bool
     */
    public function save()
    {
        if (!$this->loaded) {
            $this->load();
        }
        $path = $this->getFilesJsonPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $this->envelope['generated'] = time();
        $json = json_encode($this->envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Index management
    // ------------------------------------------------------------------

    private function buildIndexes()
    {
        $this->uuidIndex = array();
        $this->pathIndex = array();
        $files = $this->getRecordsRaw();
        foreach ($files as $record) {
            $uuid = isset($record['uuid']) ? (string) $record['uuid'] : '';
            $path = isset($record['path']) ? (string) $record['path'] : '';
            if ($uuid !== '') {
                $this->uuidIndex[$uuid] = $record;
            }
            if ($uuid !== '' && $path !== '') {
                $this->pathIndex[$path] = $uuid;
            }
        }
    }

    /**
     * The raw records array from the envelope (by reference to the envelope).
     *
     * @return array
     */
    private function &getRecordsRaw()
    {
        if (!isset($this->envelope['data']['files']) || !is_array($this->envelope['data']['files'])) {
            $this->envelope['data']['files'] = array();
        }
        return $this->envelope['data']['files'];
    }

    // ------------------------------------------------------------------
    // Read access
    // ------------------------------------------------------------------

    /**
     * All records as arrays (a copy of the envelope's files array).
     *
     * @return array
     */
    public function getRecords()
    {
        $this->load();
        $files = $this->getRecordsRaw();
        return array_values($files);
    }

    /**
     * O(1) lookup by UUID.
     *
     * @param string $uuid
     * @return array|null The record array, or null if not found.
     */
    public function getByUuid($uuid)
    {
        $this->load();
        $uuid = strtolower(trim((string) $uuid));
        if ($uuid === '' || !array_key_exists($uuid, $this->uuidIndex)) {
            return null;
        }
        return $this->uuidIndex[$uuid];
    }

    /**
     * O(1) lookup by canonical path.
     *
     * @param string $path
     * @return array|null The record array, or null if not found.
     */
    public function getByPath($path)
    {
        $this->load();
        $canonical = self::canonicalPath($path);
        if (!array_key_exists($canonical, $this->pathIndex)) {
            return null;
        }
        $uuid = $this->pathIndex[$canonical];
        return isset($this->uuidIndex[$uuid]) ? $this->uuidIndex[$uuid] : null;
    }

    /**
     * Resolve a files/... path to its UUID, upserting unknown paths first
     * with the deterministic UUID at first ingest.
     *
     * @param string $path
     * @return string The UUID, or '' if the file does not exist on disk.
     */
    public function resolveUuidByPath($path)
    {
        $this->load();
        $canonical = self::canonicalPath($path);
        if (array_key_exists($canonical, $this->pathIndex)) {
            return $this->pathIndex[$canonical];
        }
        // Unknown path: build a record from disk (deterministic uuid) and
        // upsert it, then return the uuid.
        $record = $this->buildFileRecordFromDisk($canonical);
        if ($record === null) {
            return '';
        }
        $this->upsertRecord($record);
        return isset($record['uuid']) ? (string) $record['uuid'] : '';
    }

    // ------------------------------------------------------------------
    // Write access
    // ------------------------------------------------------------------

    /**
     * Upsert a record into files.json by UUID. Rebuilds indexes and persists.
     *
     * @param array $record The record (must contain uuid + path).
     * @return bool
     */
    public function upsertRecord(array $record)
    {
        $this->load();
        $uuid = isset($record['uuid']) ? strtolower((string) $record['uuid']) : '';
        if ($uuid === '') {
            return false;
        }
        $record['uuid'] = $uuid;
        $files = &$this->getRecordsRaw();
        $found = false;
        foreach ($files as $index => $existing) {
            if (isset($existing['uuid']) && strtolower((string) $existing['uuid']) === $uuid) {
                $files[$index] = $record;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $files[] = $record;
        }
        $this->buildIndexes();
        return $this->save();
    }

    /**
     * Remove a record by UUID. Rebuilds indexes and persists.
     *
     * @param string $uuid
     * @return bool
     */
    public function removeRecord($uuid)
    {
        $this->load();
        $uuid = strtolower(trim((string) $uuid));
        if ($uuid === '') {
            return false;
        }
        $files = &$this->getRecordsRaw();
        $removed = false;
        foreach ($files as $index => $existing) {
            if (isset($existing['uuid']) && strtolower((string) $existing['uuid']) === $uuid) {
                array_splice($files, (int) $index, 1);
                $removed = true;
                break;
            }
        }
        if (!$removed) {
            return false;
        }
        $this->buildIndexes();
        return $this->save();
    }

    // ------------------------------------------------------------------
    // Reconciliation (non-destructive)
    // ------------------------------------------------------------------

    /**
     * Scan the files/ directory and build records for any on-disk files
     * missing from the index. Persists files.json if any were added.
     *
     * @return int The number of records added.
     */
    public function reconcileMissingFromDisk()
    {
        $this->load();
        $filesDir = $this->getFilesDirectory();
        if (!is_dir($filesDir)) {
            return 0;
        }
        $diskFiles = $this->collectDiskFiles($filesDir);
        $added = 0;
        foreach ($diskFiles as $relativePath) {
            $canonical = 'files/' . $relativePath;
            if (array_key_exists($canonical, $this->pathIndex)) {
                continue;
            }
            $record = $this->buildFileRecordFromDisk($canonical);
            if ($record !== null) {
                $files = &$this->getRecordsRaw();
                $files[] = $record;
                $uuid = isset($record['uuid']) ? (string) $record['uuid'] : '';
                $path = isset($record['path']) ? (string) $record['path'] : '';
                if ($uuid !== '') {
                    $this->uuidIndex[$uuid] = $record;
                }
                if ($uuid !== '' && $path !== '') {
                    $this->pathIndex[$path] = $uuid;
                }
                $added++;
            }
        }
        if ($added > 0) {
            $this->save();
        }
        return $added;
    }

    /**
     * Flag records whose disk file is gone. NON-DESTRUCTIVE: does NOT
     * remove the records from files.json. Returns the orphan record arrays.
     *
     * @return array
     */
    public function flagOrphans()
    {
        $this->load();
        $filesDir = $this->getFilesDirectory();
        $orphans = array();
        foreach ($this->uuidIndex as $uuid => $record) {
            $path = isset($record['path']) ? (string) $record['path'] : '';
            if ($path === '') {
                continue;
            }
            // path is 'files/...', strip the leading 'files/' to get the
            // relative path within the files directory.
            $relative = $path;
            if (strpos($relative, 'files/') === 0) {
                $relative = substr($relative, 6);
            }
            $absolute = rtrim($filesDir, '/') . '/' . $relative;
            if (!is_file($absolute)) {
                $orphans[] = $record;
            }
        }
        return $orphans;
    }

    // ------------------------------------------------------------------
    // Disk scanning + record building
    // ------------------------------------------------------------------

    /**
     * Recursively scan the files/ directory for relative file paths,
     * excluding haxcms-managed/, dotfiles, and symlinks.
     *
     * @param string $filesDir
     * @return string[] Relative paths (relative to filesDir).
     */
    private function collectDiskFiles($filesDir)
    {
        $files = array();
        if (!is_dir($filesDir)) {
            return $files;
        }
        $ignored = array('.', '..', '.gitkeep', '.DS_Store', '._.DS_Store', '.htaccess', '._htaccess', 'files.json');
        $directories = array($filesDir);
        while (count($directories) > 0) {
            $activeDirectory = array_pop($directories);
            $entries = @scandir($activeDirectory);
            if (!is_array($entries)) {
                $entries = array();
            }
            foreach ($entries as $entryName) {
                if (in_array($entryName, $ignored, true)) {
                    continue;
                }
                $absoluteEntryPath = $activeDirectory . '/' . $entryName;
                if (is_link($absoluteEntryPath)) {
                    continue;
                }
                if (is_dir($absoluteEntryPath)) {
                    $relativeDirectoryPath = ltrim(str_replace($filesDir, '', $absoluteEntryPath), '/');
                    if (
                        $relativeDirectoryPath === 'haxcms-managed' ||
                        strpos($relativeDirectoryPath, 'haxcms-managed/') === 0
                    ) {
                        continue;
                    }
                    $directories[] = $absoluteEntryPath;
                    continue;
                }
                if (!is_file($absoluteEntryPath)) {
                    continue;
                }
                $relativePath = ltrim(str_replace($filesDir, '', $absoluteEntryPath), '/');
                if (
                    $relativePath === '' ||
                    $relativePath === 'haxcms-managed' ||
                    strpos($relativePath, 'haxcms-managed/') === 0
                ) {
                    continue;
                }
                $files[] = $relativePath;
            }
        }
        usort($files, function ($a, $b) {
            return strcmp($a, $b);
        });
        return $files;
    }

    /**
     * Build a file record from the on-disk file at the given canonical path.
     * Reuses the same field shape as haxcmsBuildFileRecord (files.php) but
     * adds width/height for images.
     *
     * @param string $canonicalPath The 'files/...' API path.
     * @return array|null The record, or null if the file does not exist.
     */
    public function buildFileRecordFromDisk($canonicalPath)
    {
        $canonical = self::canonicalPath($canonicalPath);
        $filesDir = $this->getFilesDirectory();
        $relative = $canonical;
        if (strpos($relative, 'files/') === 0) {
            $relative = substr($relative, 6);
        }
        $absolutePath = rtrim($filesDir, '/') . '/' . $relative;
        if (!is_file($absolutePath)) {
            return null;
        }
        $stats = @stat($absolutePath);
        $size = (is_array($stats) && isset($stats['size'])) ? intval($stats['size']) : 0;
        $dateCreated = (is_array($stats) && isset($stats['mtime'])) ? intval($stats['mtime']) : 0;

        $mimetype = '';
        if (function_exists('mime_content_type')) {
            $mimetype = (string) @mime_content_type($absolutePath);
        }
        if ($mimetype === '') {
            $mimetype = self::mimetypeFromExtension($absolutePath);
        }

        $baseFileUrl = self::buildBaseFileUrl($this->site, $canonical);
        $fullUrl = $baseFileUrl;
        if ($dateCreated > 0) {
            $fullUrl .= (strpos($baseFileUrl, '?') === false ? '?t=' : '&t=') . $dateCreated;
        }

        $width = 0;
        $height = 0;
        if (strpos($mimetype, 'image/') === 0 && $mimetype !== 'image/svg+xml') {
            $info = @getimagesize($absolutePath);
            if (is_array($info) && isset($info[0]) && isset($info[1]) && (int) $info[0] > 0 && (int) $info[1] > 0) {
                $width = (int) $info[0];
                $height = (int) $info[1];
            }
        }

        return array(
            'uuid' => self::deterministicUuid($this->site, $canonical, $size),
            'path' => $canonical,
            'name' => basename($canonical),
            'mimetype' => $mimetype,
            'size' => $size,
            'dateCreated' => $dateCreated,
            'fullUrl' => $fullUrl,
            'url' => $canonical,
            'width' => $width,
            'height' => $height,
        );
    }

    /**
     * Build the base file URL (basePath + sitesDirectory + siteName + path).
     * Mirrors haxcmsBuildFileRecord's fullUrl prefix logic.
     *
     * @param mixed $site
     * @param string $apiPath
     * @return string
     */
    public static function buildBaseFileUrl($site, $apiPath)
    {
        $apiPath = (string) $apiPath;
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->sitesDirectory) &&
            isset($site->manifest->metadata->site->name)
        ) {
            $basePath = '/';
            if (isset($GLOBALS['HAXCMS']->basePath)) {
                $basePath = SiteRouteUtils::normalizeBasePath($GLOBALS['HAXCMS']->basePath);
            }
            return $basePath .
                $GLOBALS['HAXCMS']->sitesDirectory . '/' .
                $site->manifest->metadata->site->name . '/' .
                $apiPath;
        }
        // Fallback for tests / single-site context: just the API path.
        return $apiPath;
    }

    /**
     * Extension-based MIME type fallback (mirrors haxcmsBuildFileRecord).
     *
     * @param string $path
     * @return string
     */
    public static function mimetypeFromExtension($path)
    {
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return 'image/jpeg';
        }
        if ($extension === 'png') {
            return 'image/png';
        }
        if ($extension === 'gif') {
            return 'image/gif';
        }
        if ($extension === 'svg') {
            return 'image/svg+xml';
        }
        if ($extension === 'pdf') {
            return 'application/pdf';
        }
        if ($extension === 'md') {
            return 'text/markdown';
        }
        if ($extension === 'mp4') {
            return 'video/mp4';
        }
        if ($extension === 'mp3') {
            return 'audio/mpeg';
        }
        if ($extension === 'webp') {
            return 'image/webp';
        }
        if ($extension === 'webm') {
            return 'video/webm';
        }
        if ($extension === 'csv') {
            return 'text/csv';
        }
        if ($extension === 'txt') {
            return 'text/plain';
        }
        if ($extension === 'html') {
            return 'text/html';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Envelope helpers
    // ------------------------------------------------------------------

    /**
     * Build an empty HAXCMS-FILE-SCHEMA-V1 envelope.
     *
     * @return array
     */
    public function buildEmptyEnvelope()
    {
        return array(
            'schema' => 'HAXCMS-FILE-SCHEMA-V1',
            'site' => self::siteName($this->site),
            'generated' => time(),
            'data' => array(
                'path' => 'files',
                'files' => array(),
            ),
        );
    }

    /**
     * Whether files.json exists on disk.
     *
     * @return bool
     */
    public function exists()
    {
        return is_file($this->getFilesJsonPath());
    }
}
