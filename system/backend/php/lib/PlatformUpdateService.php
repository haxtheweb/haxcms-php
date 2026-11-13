<?php
/**
 * Platform update awareness for the system status report.
 *
 * Read-only by design: works out whether a newer release exists, whether the
 * installed version is missing a published security fix, and how this install
 * was put on the server so the admin panel can show matching upgrade steps.
 * It never downloads, unpacks or writes release files; upgrades run from a
 * shell (`php system/platform-upgrade.php` / `hax platform upgrade`), outside
 * the web process.
 *
 * Mirrors haxcms-nodejs src/lib/platformUpdates.js. Both produce the same
 * `update` object for SystemStatusData (PlatformUpdateStatus).
 */
class HAXCMSPlatformUpdateService
{
    // Security (SEC-SSRF): every outbound URL here is a fixed constant. No URL
    // is ever taken from a request, from config or from a remote payload.
    const RELEASES_LATEST_API_URL = 'https://api.github.com/repos/haxtheweb/haxcms-php/releases/latest';
    const ADVISORIES_API_URL = 'https://api.github.com/repos/haxtheweb/issues/security-advisories?state=published&per_page=100';
    const RELEASES_PAGE_URL = 'https://github.com/haxtheweb/haxcms-php/releases';
    const PACKAGE_KEY = 'haxcms-php';
    const FETCH_TIMEOUT_SECONDS = 4;
    const MAX_RESPONSE_BYTES = 2097152;
    const MAX_ADVISORIES = 20;
    // links shown in the admin panel must point at our own GitHub org
    const TRUSTED_URL_PREFIX = 'https://github.com/haxtheweb/';

    const INSTALL_METHODS = array(
        'node-npm',
        'node-docker',
        'node-source',
        'desktop',
        'php-archive',
        'php-git',
        'php-docker',
        'unknown',
    );

    public static function normalizeVersion($value)
    {
        $normalized = trim((string) $value);
        if (strtolower(substr($normalized, 0, 1)) === 'v') {
            $normalized = substr($normalized, 1);
        }
        return $normalized;
    }

    /**
     * First x.y.z in a version string ("v26.8.2", "10.0.3+"), or null.
     */
    public static function parseVersion($value)
    {
        if (!preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', (string) $value, $match)) {
            return null;
        }
        return array(
            (int) $match[1],
            (int) $match[2],
            isset($match[3]) ? (int) $match[3] : 0,
        );
    }

    /**
     * -1 when a < b, 0 when equal, 1 when a > b, null when either has no x.y.z.
     */
    public static function compareVersions($a, $b)
    {
        $left = self::parseVersion($a);
        $right = self::parseVersion($b);
        if ($left === null || $right === null) {
            return null;
        }
        for ($i = 0; $i < 3; $i++) {
            if ($left[$i] < $right[$i]) {
                return -1;
            }
            if ($left[$i] > $right[$i]) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * Reduce a hand-entered advisory package name to a backend key:
     * "haxcms-php", "haxcms-nodejs", or "" when it names something else.
     */
    public static function normalizeAdvisoryPackage($name)
    {
        $normalized = strtolower(trim((string) $name));
        $normalized = preg_replace('/^\/+/', '', $normalized);
        $normalized = preg_replace('/^@?(haxtheweb|haxcms)\//', '', $normalized);
        $normalized = preg_replace('/\.operations$/', '', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized);
        if ($normalized === 'haxcms-php' || $normalized === 'haxcms-nodejs') {
            return $normalized;
        }
        return '';
    }

    private static function stringField($array, $key)
    {
        return (is_array($array) && isset($array[$key]) && is_string($array[$key])) ? $array[$key] : '';
    }

    /**
     * Published advisories whose patched version for this backend is newer
     * than the installed version. Unparsable patched versions are skipped.
     */
    public static function findSecurityAdvisories($advisories, $currentVersion, $packageKey = self::PACKAGE_KEY)
    {
        $found = array();
        if (!is_array($advisories) || self::parseVersion($currentVersion) === null) {
            return $found;
        }
        foreach ($advisories as $advisory) {
            if (!is_array($advisory) || !empty($advisory['withdrawn_at'])) {
                continue;
            }
            $vulnerabilities = (isset($advisory['vulnerabilities']) && is_array($advisory['vulnerabilities']))
                ? $advisory['vulnerabilities']
                : array();
            $patched = '';
            foreach ($vulnerabilities as $vulnerability) {
                if (!is_array($vulnerability)) {
                    continue;
                }
                $package = (isset($vulnerability['package']) && is_array($vulnerability['package']))
                    ? $vulnerability['package']
                    : array();
                if (self::normalizeAdvisoryPackage(self::stringField($package, 'name')) !== $packageKey) {
                    continue;
                }
                $candidate = self::normalizeVersion(self::stringField($vulnerability, 'patched_versions'));
                if (self::compareVersions($currentVersion, $candidate) === -1) {
                    $patched = implode('.', self::parseVersion($candidate));
                    break;
                }
            }
            if ($patched === '') {
                continue;
            }
            $url = self::stringField($advisory, 'html_url');
            if (strpos($url, self::TRUSTED_URL_PREFIX) !== 0) {
                $url = '';
            }
            $found[] = array(
                'id' => self::stringField($advisory, 'ghsa_id'),
                'cve' => self::stringField($advisory, 'cve_id'),
                'severity' => self::stringField($advisory, 'severity'),
                'summary' => self::stringField($advisory, 'summary'),
                'url' => $url,
                'patched' => $patched,
            );
            if (count($found) >= self::MAX_ADVISORIES) {
                break;
            }
        }
        return $found;
    }

    /**
     * How this backend was installed: a container, a git checkout, or an
     * unpacked release archive.
     */
    public static function detectInstallMethod($rootPath = null, $exists = null)
    {
        if ($rootPath === null) {
            $rootPath = defined('HAXCMS_ROOT') ? HAXCMS_ROOT : getcwd();
        }
        if (!is_callable($exists)) {
            $exists = 'file_exists';
        }
        if (getenv('HAXCMS_CONTAINER') || call_user_func($exists, '/.dockerenv')) {
            return 'php-docker';
        }
        if (call_user_func($exists, rtrim((string) $rootPath, '/') . '/.git')) {
            return 'php-git';
        }
        return 'php-archive';
    }

    /**
     * Release assets the admin panel may link to: name + https URL on our org.
     */
    public static function pickReleaseAssets($release)
    {
        $assets = array();
        if (!is_array($release) || !isset($release['assets']) || !is_array($release['assets'])) {
            return $assets;
        }
        foreach ($release['assets'] as $asset) {
            $name = self::stringField($asset, 'name');
            $url = self::stringField($asset, 'browser_download_url');
            if ($name !== '' && strpos($url, self::TRUSTED_URL_PREFIX) === 0) {
                $assets[] = array('name' => $name, 'url' => $url);
            }
        }
        return $assets;
    }

    /**
     * Pure: assemble the `update` object of the status report.
     */
    public static function buildUpdateInfo($options = array())
    {
        $current = self::normalizeVersion(isset($options['currentVersion']) ? $options['currentVersion'] : '');
        if ($current === '') {
            $current = 'unknown';
        }
        $managed = !empty($options['managed']);
        $checkEnabled = !$managed && (!array_key_exists('checkEnabled', $options) || $options['checkEnabled'] !== false);
        $installMethod = (isset($options['installMethod']) && in_array($options['installMethod'], self::INSTALL_METHODS, true))
            ? $options['installMethod']
            : 'unknown';
        $release = ($checkEnabled && isset($options['release']) && is_array($options['release']))
            ? $options['release']
            : null;
        $latest = $release !== null ? self::normalizeVersion(self::stringField($release, 'tag_name')) : '';
        if ($latest === '') {
            $latest = 'unknown';
        }
        $releaseUrl = $release !== null ? self::stringField($release, 'html_url') : '';
        if (strpos($releaseUrl, self::TRUSTED_URL_PREFIX) !== 0) {
            $releaseUrl = isset($options['releasesPageUrl']) ? $options['releasesPageUrl'] : self::RELEASES_PAGE_URL;
        }
        $advisories = $checkEnabled
            ? self::findSecurityAdvisories(
                isset($options['advisories']) ? $options['advisories'] : null,
                $current,
                isset($options['packageKey']) ? $options['packageKey'] : self::PACKAGE_KEY
            )
            : array();
        return array(
            'checkEnabled' => $checkEnabled,
            'managed' => $managed,
            'current' => $current,
            'latest' => $latest,
            'releasedAt' => $release !== null ? self::stringField($release, 'published_at') : '',
            'releaseUrl' => $releaseUrl,
            'updateAvailable' => self::compareVersions($current, $latest) === -1,
            'security' => count($advisories) > 0,
            'advisories' => $advisories,
            'installMethod' => $installMethod,
            'assets' => self::pickReleaseAssets($release),
        );
    }

    /**
     * GET a fixed GitHub API URL and decode it; null on any failure. Cached
     * for the life of the PHP process.
     */
    private static function fetchJson($url)
    {
        static $cache = array();
        if (array_key_exists($url, $cache)) {
            return $cache[$url];
        }
        $body = '';
        $headers = array(
            'Accept: application/vnd.github+json',
            'User-Agent: haxcms-php-system-status',
        );
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::FETCH_TIMEOUT_SECONDS);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::FETCH_TIMEOUT_SECONDS);
            // Security (SEC-SSRF): fixed https URL, no redirects followed.
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $result = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 300 && is_string($result)) {
                $body = $result;
            }
        }
        else {
            $context = stream_context_create(array(
                'http' => array(
                    'method' => 'GET',
                    'timeout' => self::FETCH_TIMEOUT_SECONDS,
                    'follow_location' => 0,
                    'header' => implode("\r\n", $headers) . "\r\n",
                ),
            ));
            $result = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES);
            if (is_string($result)) {
                $body = $result;
            }
        }
        $value = null;
        if ($body !== '' && strlen($body) <= self::MAX_RESPONSE_BYTES) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        $cache[$url] = $value;
        return $value;
    }

    public static function fetchLatestRelease()
    {
        return self::fetchJson(self::RELEASES_LATEST_API_URL);
    }

    public static function fetchPublishedAdvisories()
    {
        return self::fetchJson(self::ADVISORIES_API_URL);
    }

    /**
     * Whether outbound update checks are allowed: never on a host-managed
     * (HAXiam) deployment, and not when config sets updates.check to false.
     */
    public static function getUpdateCheckPolicy($haxcms)
    {
        $profile = (is_object($haxcms) && method_exists($haxcms, 'getDeploymentProfile'))
            ? $haxcms->getDeploymentProfile()
            : 'single-site';
        $managed = $profile === 'haxiam-managed';
        $check = true;
        if (is_object($haxcms) && isset($haxcms->config) && is_object($haxcms->config)
            && isset($haxcms->config->updates) && is_object($haxcms->config->updates)
            && isset($haxcms->config->updates->check) && $haxcms->config->updates->check === false) {
            $check = false;
        }
        return array(
            'managed' => $managed,
            'checkEnabled' => !$managed && $check,
        );
    }

    /**
     * Build the `update` object for a running backend.
     */
    public static function buildPlatformUpdateInfo($haxcms, $currentVersion)
    {
        $policy = self::getUpdateCheckPolicy($haxcms);
        $release = null;
        $advisories = null;
        if ($policy['checkEnabled']) {
            $release = self::fetchLatestRelease();
            $advisories = self::fetchPublishedAdvisories();
        }
        return self::buildUpdateInfo(array(
            'currentVersion' => $currentVersion,
            'managed' => $policy['managed'],
            'checkEnabled' => $policy['checkEnabled'],
            'release' => $release,
            'advisories' => $advisories,
            'installMethod' => self::detectInstallMethod(),
        ));
    }
}
