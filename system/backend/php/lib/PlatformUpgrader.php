<?php
/**
 * Shell-side platform upgrades for haxcms-php (haxtheweb/issues#1768).
 *
 * Runs only from the command line (scripts/platform-upgrade.php), as the
 * user who owns the files, never inside a web request. Everything is driven
 * by the release manifest (platform-manifest.json): the list of code files
 * with a SHA-256 for each. Site data never appears in a manifest and is
 * never read, written or deleted here.
 *
 * Invariants:
 * - only files the manifest lists are written, and only at safe relative
 *   paths outside the protected folders (PROTECTED)
 * - an archive is used only when its SHA-256 matches the release's
 *   SHA256SUMS, and every extracted file is checked against the manifest
 *   before anything in the install changes
 * - every code file that will be replaced or removed is copied to a backup
 *   first; a failed health check rolls the install back automatically
 * - one upgrade at a time (lock file); downloads only from github.com and
 *   GitHub's release-asset hosts over HTTPS
 * - never on a HAXiam (haxiam-managed) deployment: the host owns the code
 *   there, so upgrade, verify and rollback all refuse
 */
class HAXCMSPlatformUpgrader
{
    const MANIFEST_SCHEMA = 1;
    const PACKAGE = 'haxcms-php';
    const MANIFEST_NAME = 'platform-manifest.json';
    // where the manifest lives inside an install / release archive
    const MANIFEST_PATH = 'system/platform-manifest.json';
    // site data and configuration: never in a manifest, never touched
    const PROTECTED_PATHS = array(
        '_config',
        '_sites',
        '_published',
        '_archived',
        'config',
        'sites',
        'published',
        'archived',
        '.git',
    );
    const RELEASE_TAG_API_PREFIX = 'https://api.github.com/repos/haxtheweb/haxcms-php/releases/tags/';
    const RELEASE_LATEST_API_URL = 'https://api.github.com/repos/haxtheweb/haxcms-php/releases/latest';
    const DOWNLOAD_PREFIX = 'https://github.com/haxtheweb/haxcms-php/releases/download/';
    const DOWNLOAD_HOSTS = array(
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    );
    const KEEP_BACKUPS = 3;
    const MAX_DOWNLOAD_BYTES = 209715200;

    /** @var string */
    private $root;
    /** @var callable|null */
    private $logger;

    public function __construct($root, $logger = null)
    {
        $this->root = rtrim((string) $root, '/');
        $this->logger = is_callable($logger) ? $logger : null;
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }

    public static function isReleaseVersion($value)
    {
        return is_string($value) && preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $value) === 1;
    }

    /**
     * A relative path the manifest may name: no absolute path, no "..", no
     * empty or dot segments, no NUL, and not inside a protected folder.
     */
    public static function isSafeCodePath($rel)
    {
        if (!is_string($rel) || $rel === '' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false) {
            return false;
        }
        if ($rel[0] === '/') {
            return false;
        }
        $segments = explode('/', $rel);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return !self::isProtected($rel);
    }

    public static function isProtected($rel)
    {
        $first = explode('/', (string) $rel, 2)[0];
        return in_array($first, self::PROTECTED_PATHS, true);
    }

    public function getRoot()
    {
        return $this->root;
    }

    /**
     * The deployment profile from _config/config.json, by the same rule as
     * HAXCMS::getDeploymentProfile(): an explicit deploymentProfile wins, and
     * a config with "iam" set and no profile is haxiam-managed.
     */
    public function deploymentProfile()
    {
        $file = $this->root . '/_config/config.json';
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($config)) {
            return 'single-site';
        }
        if (isset($config['deploymentProfile']) && is_string($config['deploymentProfile']) && $config['deploymentProfile'] !== '') {
            $profile = strtolower($config['deploymentProfile']);
            return in_array($profile, array('single-site', 'self-hosted-multi-site', 'haxiam-managed'), true) ? $profile : 'single-site';
        }
        return !empty($config['iam']) ? 'haxiam-managed' : 'self-hosted-multi-site';
    }

    public function isHostManaged()
    {
        return $this->deploymentProfile() === 'haxiam-managed';
    }

    private function refuseIfHostManaged()
    {
        if ($this->isHostManaged()) {
            throw new RuntimeException('This is a HAXiam (haxiam-managed) deployment. Platform upgrades are not allowed here; the host manages HAXcms.');
        }
    }

    public function installedVersion()
    {
        foreach (array('.version', 'VERSION.txt') as $name) {
            $file = $this->root . '/' . $name;
            if (is_file($file)) {
                $value = trim((string) file_get_contents($file));
                if (strtolower(substr($value, 0, 1)) === 'v') {
                    $value = substr($value, 1);
                }
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return 'unknown';
    }

    /**
     * Relative paths of every regular file and symlink under $dir, skipping
     * protected folders and the manifest itself. Never follows symlinks.
     *
     * @return array<string, string> rel => 'file' | 'link'
     */
    public static function listTree($dir)
    {
        $dir = rtrim($dir, '/');
        $found = array();
        $stack = array('');
        while (count($stack) > 0) {
            $relDir = array_pop($stack);
            $abs = $relDir === '' ? $dir : $dir . '/' . $relDir;
            $entries = @scandir($abs);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $rel = $relDir === '' ? $entry : $relDir . '/' . $entry;
                if (self::isProtected($rel) || $rel === self::MANIFEST_PATH) {
                    continue;
                }
                $path = $dir . '/' . $rel;
                if (is_link($path)) {
                    $found[$rel] = 'link';
                }
                elseif (is_dir($path)) {
                    $stack[] = $rel;
                }
                elseif (is_file($path)) {
                    $found[$rel] = 'file';
                }
            }
        }
        ksort($found, SORT_STRING);
        return $found;
    }

    /**
     * Build the manifest for a release tree (used by the release workflow
     * through scripts/platform-manifest.php, and by tests).
     */
    public static function buildManifest($dir, $version, $package = self::PACKAGE)
    {
        $files = array();
        $links = array();
        foreach (self::listTree($dir) as $rel => $type) {
            if (!self::isSafeCodePath($rel)) {
                continue;
            }
            if ($type === 'link') {
                $links[$rel] = (string) readlink(rtrim($dir, '/') . '/' . $rel);
            }
            else {
                $files[$rel] = hash_file('sha256', rtrim($dir, '/') . '/' . $rel);
            }
        }
        return array(
            'schema' => self::MANIFEST_SCHEMA,
            'package' => $package,
            'version' => $version,
            'protected' => array_values(array_diff(self::PROTECTED_PATHS, array('.git'))),
            'files' => $files,
            'links' => $links,
        );
    }

    /**
     * Decode and validate a manifest. Throws on anything unexpected, so a
     * damaged or hostile manifest can never name a path outside the code.
     */
    public static function parseManifest($json, $expectedVersion = null)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !isset($data['schema']) || $data['schema'] !== self::MANIFEST_SCHEMA) {
            throw new RuntimeException('Not a platform manifest (schema ' . self::MANIFEST_SCHEMA . ').');
        }
        if (!isset($data['package']) || $data['package'] !== self::PACKAGE) {
            throw new RuntimeException('Manifest is not for ' . self::PACKAGE . '.');
        }
        if (!isset($data['version']) || !self::isReleaseVersion($data['version'])) {
            throw new RuntimeException('Manifest has no valid version.');
        }
        if ($expectedVersion !== null && $data['version'] !== $expectedVersion) {
            throw new RuntimeException('Manifest is for ' . $data['version'] . ', expected ' . $expectedVersion . '.');
        }
        if (!isset($data['files']) || !is_array($data['files']) || count($data['files']) === 0) {
            throw new RuntimeException('Manifest lists no files.');
        }
        foreach ($data['files'] as $rel => $hash) {
            if (!self::isSafeCodePath((string) $rel) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException('Manifest names an unsafe path or hash: ' . $rel);
            }
        }
        $links = (isset($data['links']) && is_array($data['links'])) ? $data['links'] : array();
        foreach ($links as $rel => $target) {
            if (!self::isSafeCodePath((string) $rel) || !is_string($target) || $target === '' || $target[0] === '/' || strpos($target, '..') !== false) {
                throw new RuntimeException('Manifest names an unsafe link: ' . $rel);
            }
        }
        $data['links'] = $links;
        return $data;
    }

    public function localManifest()
    {
        $file = $this->root . '/' . self::MANIFEST_PATH;
        if (!is_file($file)) {
            return null;
        }
        return self::parseManifest(file_get_contents($file));
    }

    /**
     * Compare a tree with a manifest.
     *
     * @return array{modified: string[], missing: string[], extra: string[]}
     */
    public static function compareTree($dir, $manifest)
    {
        $result = array('modified' => array(), 'missing' => array(), 'extra' => array());
        $present = self::listTree($dir);
        foreach ($manifest['files'] as $rel => $hash) {
            $path = rtrim($dir, '/') . '/' . $rel;
            if (!isset($present[$rel]) || $present[$rel] !== 'file') {
                $result['missing'][] = $rel;
            }
            elseif (hash_file('sha256', $path) !== $hash) {
                $result['modified'][] = $rel;
            }
        }
        foreach ($present as $rel => $type) {
            if (!isset($manifest['files'][$rel]) && !isset($manifest['links'][$rel])) {
                $result['extra'][] = $rel;
            }
        }
        return $result;
    }

    public function verify($manifest = null)
    {
        $this->refuseIfHostManaged();
        if ($manifest === null) {
            $manifest = $this->localManifest();
        }
        if ($manifest === null) {
            throw new RuntimeException('No ' . self::MANIFEST_PATH . ' in this install. Verify needs a release that ships one.');
        }
        return self::compareTree($this->root, $manifest);
    }

    public static function parseSha256Sums($text)
    {
        $sums = array();
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(\S+)$/', trim($line), $m) === 1) {
                $sums[$m[2]] = $m[1];
            }
        }
        return $sums;
    }

    private function workDir($name)
    {
        $dir = $this->root . '/_config/platform-upgrade/' . $name;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create ' . $dir . '.');
        }
        return $dir;
    }

    private static function removeTree($dir)
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * Copy a file, creating parent folders, via a temp file and rename so a
     * reader never sees a half-written file.
     */
    private static function placeFile($from, $to)
    {
        $parent = dirname($to);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create ' . $parent . '.');
        }
        $tmp = $to . '.platform-upgrade-tmp';
        if (!@copy($from, $tmp)) {
            throw new RuntimeException('Cannot write ' . $to . '.');
        }
        $perms = @fileperms($from);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }
        if (!@rename($tmp, $to)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace ' . $to . '.');
        }
    }

    /**
     * HTTPS GET with redirects limited to GitHub hosts. Writes to $dest when
     * given, otherwise returns the body.
     */
    public function httpGet($url, $dest = null, $accept = 'application/octet-stream')
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is required to download releases.');
        }
        $hops = 0;
        while (true) {
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https'
                || (!in_array($parts['host'], self::DOWNLOAD_HOSTS, true) && $parts['host'] !== 'api.github.com')) {
                throw new RuntimeException('Refusing to download from ' . $url . '.');
            }
            $ch = curl_init($url);
            $handle = null;
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: ' . $accept, 'User-Agent: haxcms-php-platform-upgrade'));
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_MAXFILESIZE, self::MAX_DOWNLOAD_BYTES);
            if ($dest !== null) {
                $handle = fopen($dest, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $handle);
            }
            else {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            }
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($handle) {
                fclose($handle);
            }
            if ($code >= 300 && $code < 400 && $location !== '' && $hops < 5) {
                $hops++;
                $url = $location;
                continue;
            }
            if ($code < 200 || $code >= 300 || $body === false) {
                throw new RuntimeException('Download failed (HTTP ' . $code . '): ' . $url);
            }
            return $dest !== null ? true : (string) $body;
        }
    }

    /**
     * Release metadata for a version (or the latest), from the fixed API URL.
     */
    public function fetchRelease($version = null)
    {
        if ($version !== null && !self::isReleaseVersion($version)) {
            throw new RuntimeException('Not a release version: ' . $version);
        }
        $url = $version === null ? self::RELEASE_LATEST_API_URL : self::RELEASE_TAG_API_PREFIX . $version;
        $release = json_decode($this->httpGet($url, null, 'application/vnd.github+json'), true);
        if (!is_array($release) || !isset($release['tag_name'])) {
            throw new RuntimeException('Could not read release ' . ($version === null ? 'latest' : $version) . '.');
        }
        return $release;
    }

    /**
     * Download the archive, SHA256SUMS and manifest for $version into $dir.
     *
     * @return array{archive: string, sums: string, manifest: string}
     */
    public function downloadRelease($version, $dir)
    {
        $name = 'haxcms-php-' . $version . '.tar.gz';
        $files = array(
            'archive' => $name,
            'sums' => 'SHA256SUMS',
            'manifest' => self::MANIFEST_NAME,
        );
        $paths = array();
        foreach ($files as $key => $file) {
            $paths[$key] = $dir . '/' . $file;
            $this->log('Downloading ' . $file);
            $this->httpGet(self::DOWNLOAD_PREFIX . $version . '/' . $file, $paths[$key]);
        }
        return $paths;
    }

    /**
     * Unpack a verified archive into $staging; returns the release root
     * inside it (archives carry one "haxcms-php-<version>/" folder).
     */
    public static function extractArchive($archive, $staging)
    {
        $phar = new PharData($archive);
        $phar->extractTo($staging, null, true);
        $entries = array_values(array_diff(scandir($staging), array('.', '..')));
        if (count($entries) === 1 && is_dir($staging . '/' . $entries[0])) {
            return $staging . '/' . $entries[0];
        }
        return $staging;
    }

    private function acquireLock()
    {
        $lock = $this->workDir('') . '/.lock';
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw new RuntimeException('Another upgrade is running (or one stopped part-way). If none is running, delete ' . $lock . ' and run again.');
        }
        fwrite($handle, (string) getmypid());
        fclose($handle);
        return $lock;
    }

    /**
     * Upgrade the install. Options:
     *   to                version (default: latest release)
     *   archive, sums, manifest  local files instead of downloading (offline)
     *   dryRun            report what would change, change nothing
     *   overwriteModified replace code files changed by hand (default: stop)
     *   allowDowngrade    allow a version at or below the installed one
     *   confirm           callable(array $plan): bool, asked once the plan is
     *                     known and before anything changes
     *
     * @return array<string, mixed> summary
     */
    public function upgrade($options = array())
    {
        $this->refuseIfHostManaged();
        if (is_dir($this->root . '/.git')) {
            // a checkout is updated with git; writing files would leave it dirty
            throw new RuntimeException('This install is a git checkout. Update it with: git fetch --tags && git checkout <version>');
        }
        $current = $this->installedVersion();
        $target = isset($options['to']) ? $options['to'] : null;
        $local = !empty($options['archive']);
        if ($target === null && !$local) {
            $release = $this->fetchRelease();
            $target = ltrim((string) $release['tag_name'], 'v');
        }
        if ($target !== null && !self::isReleaseVersion($target)) {
            throw new RuntimeException('Not a release version: ' . $target);
        }
        $work = $this->workDir('staging-' . gmdate('YmdHis'));
        try {
            if ($local) {
                $paths = array(
                    'archive' => $options['archive'],
                    'sums' => isset($options['sums']) ? $options['sums'] : '',
                    'manifest' => isset($options['manifest']) ? $options['manifest'] : '',
                );
            }
            else {
                $paths = $this->downloadRelease($target, $work);
            }
            // 1. the archive must match the release checksum
            $sums = self::parseSha256Sums(is_file($paths['sums']) ? file_get_contents($paths['sums']) : '');
            $archiveName = basename($paths['archive']);
            if (!isset($sums[$archiveName]) || !hash_equals($sums[$archiveName], hash_file('sha256', $paths['archive']))) {
                throw new RuntimeException('Checksum mismatch for ' . $archiveName . '. Nothing was changed.');
            }
            if (isset($sums[self::MANIFEST_NAME]) && is_file($paths['manifest'])
                && !hash_equals($sums[self::MANIFEST_NAME], hash_file('sha256', $paths['manifest']))) {
                throw new RuntimeException('Checksum mismatch for ' . self::MANIFEST_NAME . '. Nothing was changed.');
            }
            $manifest = self::parseManifest(file_get_contents($paths['manifest']), $target);
            $target = $manifest['version'];
            if (!empty($current) && $current !== 'unknown' && empty($options['allowDowngrade'])
                && version_compare($target, $current, '<=')) {
                throw new RuntimeException('Installed ' . $current . ' is not older than ' . $target . '. Use --allow-downgrade to install it anyway.');
            }
            // 2. every extracted file must match the manifest
            $releaseRoot = self::extractArchive($paths['archive'], $work . '/release');
            $check = self::compareTree($releaseRoot, $manifest);
            if (count($check['modified']) || count($check['missing'])) {
                throw new RuntimeException('Archive contents do not match the manifest. Nothing was changed.');
            }
            // 3. what changes in the install
            $previous = $this->localManifest();
            $modified = array();
            $stale = array();
            if ($previous !== null) {
                $state = self::compareTree($this->root, $previous);
                $modified = $state['modified'];
                foreach (array_keys($previous['files']) as $rel) {
                    if (!isset($manifest['files'][$rel]) && is_file($this->root . '/' . $rel)) {
                        $stale[] = $rel;
                    }
                }
            }
            // files already identical to the release are left alone
            $changed = array();
            foreach ($manifest['files'] as $rel => $hash) {
                $path = $this->root . '/' . $rel;
                if (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $hash) {
                    $changed[] = $rel;
                }
            }
            $plan = array(
                'from' => $current,
                'to' => $target,
                'write' => count($changed),
                'remove' => $stale,
                'modified' => $modified,
                'previousManifest' => $previous !== null,
            );
            if (count($modified) && empty($options['overwriteModified'])) {
                $plan['blocked'] = true;
                if (empty($options['dryRun'])) {
                    throw new RuntimeException(count($modified) . ' code file(s) were changed by hand (' . implode(', ', array_slice($modified, 0, 10)) . '). Re-run with --overwrite-modified to replace them; they are backed up first.');
                }
            }
            if (!empty($options['dryRun'])) {
                return $plan;
            }
            if (isset($options['confirm']) && is_callable($options['confirm'])
                && !call_user_func($options['confirm'], $plan)) {
                $plan['cancelled'] = true;
                return $plan;
            }
            $lock = $this->acquireLock();
            try {
                // 4. back up every code file this upgrade replaces or removes
                $backup = $this->workDir('backups/' . $current . '-' . gmdate('YmdHis'));
                $saved = array();
                $added = array();
                $touch = array_unique(array_merge($changed, $stale, array(self::MANIFEST_PATH)));
                foreach ($touch as $rel) {
                    $path = $this->root . '/' . $rel;
                    if (is_file($path) && !is_link($path)) {
                        self::placeFile($path, $backup . '/files/' . $rel);
                        $saved[] = $rel;
                    }
                    elseif (!file_exists($path)) {
                        $added[] = $rel;
                    }
                }
                file_put_contents($backup . '/backup.json', json_encode(array(
                    'from' => $current,
                    'to' => $target,
                    'created' => gmdate('c'),
                    'saved' => $saved,
                    'added' => $added,
                ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->log('Backed up ' . count($saved) . ' file(s) to ' . $backup);
                // 5. swap code files
                try {
                    foreach ($changed as $rel) {
                        self::placeFile($releaseRoot . '/' . $rel, $this->root . '/' . $rel);
                    }
                    foreach ($stale as $rel) {
                        @unlink($this->root . '/' . $rel);
                    }
                    self::placeFile($paths['manifest'], $this->root . '/' . self::MANIFEST_PATH);
                    // 6. health check
                    $after = self::compareTree($this->root, $manifest);
                    if (count($after['modified']) || count($after['missing']) || $this->installedVersion() !== $target) {
                        throw new RuntimeException('Health check failed after the swap.');
                    }
                }
                catch (Throwable $e) {
                    $this->log('Rolling back: ' . $e->getMessage());
                    $this->restoreBackup($backup);
                    throw new RuntimeException($e->getMessage() . ' The install was rolled back to ' . $current . '.');
                }
                $this->pruneBackups();
                $plan['backup'] = $backup;
                return $plan;
            }
            finally {
                @unlink($lock);
            }
        }
        finally {
            self::removeTree($work);
        }
    }

    /**
     * Put back the files a backup saved and remove the ones that upgrade
     * added, then the manifest that matched the old files.
     */
    public function restoreBackup($backup)
    {
        $meta = json_decode((string) @file_get_contents($backup . '/backup.json'), true);
        if (!is_array($meta) || !isset($meta['saved'], $meta['added'])) {
            throw new RuntimeException('Not a platform-upgrade backup: ' . $backup);
        }
        foreach ($meta['saved'] as $rel) {
            if (self::isSafeCodePath($rel) || $rel === self::MANIFEST_PATH) {
                self::placeFile($backup . '/files/' . $rel, $this->root . '/' . $rel);
            }
        }
        foreach ($meta['added'] as $rel) {
            if (self::isSafeCodePath($rel)) {
                @unlink($this->root . '/' . $rel);
            }
        }
        return $meta;
    }

    public function listBackups()
    {
        $dir = $this->root . '/_config/platform-upgrade/backups';
        if (!is_dir($dir)) {
            return array();
        }
        $found = array();
        foreach (scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $entry . '/backup.json')) {
                $found[] = $dir . '/' . $entry;
            }
        }
        usort($found, function ($a, $b) {
            return filemtime($b . '/backup.json') <=> filemtime($a . '/backup.json') ?: strcmp($b, $a);
        });
        return $found;
    }

    public function rollback()
    {
        $this->refuseIfHostManaged();
        $backups = $this->listBackups();
        if (count($backups) === 0) {
            throw new RuntimeException('No upgrade backup to roll back to.');
        }
        $lock = $this->acquireLock();
        try {
            $meta = $this->restoreBackup($backups[0]);
            self::removeTree($backups[0]);
            return $meta;
        }
        finally {
            @unlink($lock);
        }
    }

    private function pruneBackups()
    {
        $backups = $this->listBackups();
        foreach (array_slice($backups, self::KEEP_BACKUPS) as $old) {
            self::removeTree($old);
        }
    }
}
