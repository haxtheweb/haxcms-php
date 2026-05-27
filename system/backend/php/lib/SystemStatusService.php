<?php
class HAXCMSSystemStatusService
{
    const GITHUB_RELEASES_LATEST_URL = 'https://api.github.com/repos/haxtheweb/haxcms-php/releases/latest';
    const RELEASE_CACHE_TTL_SECONDS = 300;
    const RELEASES_PAGE_URL = 'https://github.com/haxtheweb/haxcms-php/releases';
    const UPLOAD_LIMIT_HELP_URL = 'https://www.php.net/manual/en/ini.core.php#ini.upload-max-filesize';
    const DISCORD_SUPPORT_URL = 'https://discord.gg/qGBZMBnHc';

    private static function normalizeVersion($value)
    {
        $normalized = trim((string) $value);
        if (strtolower(substr($normalized, 0, 1)) === 'v') {
            $normalized = substr($normalized, 1);
        }
        return $normalized;
    }

    private static function getRuntimeVersionLabel()
    {
        return 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    private static function getServerVersionLabel()
    {
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE']) && trim($_SERVER['SERVER_SOFTWARE']) !== '') {
            return trim($_SERVER['SERVER_SOFTWARE']);
        }
        return 'php-web-server';
    }

    private static function joinPath($base, $segment)
    {
        $normalizedBase = rtrim((string) $base, '/');
        $normalizedSegment = ltrim((string) $segment, '/');
        if ($normalizedBase === '') {
            return '/' . $normalizedSegment;
        }
        return $normalizedBase . '/' . $normalizedSegment;
    }

    private static function getProcessOwnership()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $gid = function_exists('posix_getegid') ? posix_getegid() : null;
        return array(
            'uid' => $uid,
            'gid' => $gid,
        );
    }
    private static function getPHPMemoryLimitLabel()
    {
        $limit = ini_get('memory_limit');
        if (is_string($limit) && trim($limit) !== '') {
            return trim($limit);
        }
        return 'Unknown';
    }
    private static function getUploadLimitLabel()
    {
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        $parts = array();
        if (is_string($uploadMaxFilesize) && trim($uploadMaxFilesize) !== '') {
            $parts[] = 'upload_max_filesize=' . trim($uploadMaxFilesize);
        }
        if (is_string($postMaxSize) && trim($postMaxSize) !== '') {
            $parts[] = 'post_max_size=' . trim($postMaxSize);
        }
        if (count($parts) > 0) {
            return implode(' · ', $parts);
        }
        return 'Unknown';
    }
    private static function detectGitVersion()
    {
        if (function_exists('exec')) {
            $output = array();
            $statusCode = 1;
            @exec('git --version 2>&1', $output, $statusCode);
            if ($statusCode === 0 && is_array($output) && count($output) > 0) {
                return trim(implode(' ', $output));
            }
        }
        if (function_exists('shell_exec')) {
            $shellOutput = @shell_exec('git --version 2>&1');
            if (is_string($shellOutput) && trim($shellOutput) !== '') {
                return trim($shellOutput);
            }
        }
        return '';
    }

    private static function buildDirectoryStatusRow($directory, $processOwnership)
    {
        $key = isset($directory['key']) ? $directory['key'] : 'directory';
        $title = isset($directory['title']) ? $directory['title'] : 'Directory';
        $required = !isset($directory['required']) || $directory['required'] !== false;
        $directoryPath = isset($directory['path']) ? (string) $directory['path'] : '';
        if ($directoryPath === '') {
            return array(
                'key' => $key,
                'tone' => $required ? 'error' : 'warning',
                'title' => $title,
                'value' => 'Unavailable',
                'description' => 'Directory path could not be determined.',
                'required' => $required,
            );
        }
        if (!file_exists($directoryPath)) {
            return array(
                'key' => $key,
                'tone' => $required ? 'error' : 'warning',
                'title' => $title,
                'value' => 'Missing',
                'description' => 'Expected path: ' . $directoryPath,
                'required' => $required,
            );
        }
        if (!is_dir($directoryPath)) {
            return array(
                'key' => $key,
                'tone' => $required ? 'error' : 'warning',
                'title' => $title,
                'value' => 'Invalid',
                'description' => 'Path exists but is not a directory: ' . $directoryPath,
                'required' => $required,
            );
        }
        $writable = is_writable($directoryPath);
        $ownerUid = @fileowner($directoryPath);
        $ownerGid = @filegroup($directoryPath);
        $ownershipComparable = is_numeric($processOwnership['uid']) && is_numeric($processOwnership['gid']);
        $ownerMatchesProcess = !$ownershipComparable || (
            ((int) $ownerUid === (int) $processOwnership['uid']) &&
            ((int) $ownerGid === (int) $processOwnership['gid'])
        );
        $tone = 'ok';
        $value = 'Writable';
        if (!$writable) {
            $tone = $required ? 'error' : 'warning';
            $value = 'Read-only';
        }
        else if (!$ownerMatchesProcess) {
            $tone = 'warning';
            $value = 'Writable (owner mismatch)';
        }
        return array(
            'key' => $key,
            'tone' => $tone,
            'title' => $title,
            'value' => $value,
            'description' => 'Path: ' . $directoryPath,
            'required' => $required,
        );
    }

    private static function fetchLatestReleaseVersionFromGitHub()
    {
        static $cache = array(
            'version' => '',
            'expiresAt' => 0,
        );
        $now = time();
        if ($cache['version'] !== '' && $cache['expiresAt'] > $now) {
            return $cache['version'];
        }
        $responseBody = '';
        if (function_exists('curl_init')) {
            $ch = curl_init(self::GITHUB_RELEASES_LATEST_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Accept: application/vnd.github+json',
                'User-Agent: haxcms-php-system-status',
            ));
            $responseBody = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode < 200 || $httpCode >= 300 || !is_string($responseBody)) {
                $responseBody = '';
            }
        }
        else {
            $context = stream_context_create(array(
                'http' => array(
                    'method' => 'GET',
                    'timeout' => 4,
                    'header' =>
                        "Accept: application/vnd.github+json\r\n" .
                        "User-Agent: haxcms-php-system-status\r\n",
                ),
            ));
            $result = @file_get_contents(self::GITHUB_RELEASES_LATEST_URL, false, $context);
            if (is_string($result)) {
                $responseBody = $result;
            }
        }
        $latestVersion = '';
        if (is_string($responseBody) && $responseBody !== '') {
            $payload = json_decode($responseBody, true);
            if (is_array($payload) && isset($payload['tag_name']) && is_string($payload['tag_name'])) {
                $latestVersion = self::normalizeVersion($payload['tag_name']);
            }
        }
        if ($latestVersion !== '') {
            $cache = array(
                'version' => $latestVersion,
                'expiresAt' => $now + self::RELEASE_CACHE_TTL_SECONDS,
            );
        }
        return $latestVersion;
    }

    public static function buildStatusReport($options = array())
    {
        $summary = array(
            'programmingLanguage' => isset($options['programmingLanguage'])
                ? $options['programmingLanguage']
                : self::getRuntimeVersionLabel(),
            'serverVersion' => isset($options['serverVersion'])
                ? $options['serverVersion']
                : self::getServerVersionLabel(),
            'haxcmsVersionCurrent' => isset($options['haxcmsVersionCurrent']) && $options['haxcmsVersionCurrent'] !== ''
                ? self::normalizeVersion($options['haxcmsVersionCurrent'])
                : 'unknown',
            'haxcmsVersionLatest' => isset($options['haxcmsVersionLatest']) && $options['haxcmsVersionLatest'] !== ''
                ? self::normalizeVersion($options['haxcmsVersionLatest'])
                : 'unknown',
            'configDirectory' => isset($options['configDirectory']) && is_string($options['configDirectory']) && $options['configDirectory'] !== ''
                ? $options['configDirectory']
                : 'unknown',
        );
        if ($summary['haxcmsVersionLatest'] === 'unknown' && $summary['haxcmsVersionCurrent'] !== 'unknown') {
            $summary['haxcmsVersionLatest'] = $summary['haxcmsVersionCurrent'];
        }
        $rows = array(
            array(
                'key' => 'runtime',
                'tone' => 'info',
                'title' => 'Programming language runtime',
                'value' => $summary['programmingLanguage'],
                'description' => 'Detected runtime used by the active backend process.',
            ),
            array(
                'key' => 'server',
                'tone' => 'info',
                'title' => 'Server version',
                'value' => $summary['serverVersion'],
                'description' => 'Detected web server stack serving this request.',
            ),
            array(
                'key' => 'config-directory-path',
                'tone' => 'info',
                'title' => 'Detected config directory',
                'value' => $summary['configDirectory'],
                'description' => 'Resolved runtime configuration directory path.',
            ),
            array(
                'key' => 'php-memory-limit',
                'tone' => 'info',
                'title' => 'PHP memory limit',
                'value' => isset($options['phpMemoryLimit']) ? $options['phpMemoryLimit'] : self::getPHPMemoryLimitLabel(),
                'description' => 'memory_limit from active PHP runtime.',
            ),
            array(
                'key' => 'file-upload-limit',
                'tone' => 'info',
                'title' => 'File upload limit',
                'value' => isset($options['uploadLimit']) ? $options['uploadLimit'] : self::getUploadLimitLabel(),
                'description' => 'Increase upload limit via server settings: ' . (
                    isset($options['uploadLimitHelpUrl']) && is_string($options['uploadLimitHelpUrl']) && $options['uploadLimitHelpUrl'] !== ''
                        ? $options['uploadLimitHelpUrl']
                        : self::UPLOAD_LIMIT_HELP_URL
                ),
            ),
        );
        $gitVersion = isset($options['gitVersion']) && is_string($options['gitVersion'])
            ? trim($options['gitVersion'])
            : self::detectGitVersion();
        $rows[] = array(
            'key' => 'git-installed',
            'tone' => $gitVersion !== '' ? 'ok' : 'warning',
            'title' => 'Git availability',
            'value' => $gitVersion !== '' ? 'Installed' : 'Not detected',
            'description' => $gitVersion !== '' ? 'Detected: ' . $gitVersion : 'Git is not detected on PATH.',
        );
        $directories = isset($options['directories']) && is_array($options['directories'])
            ? $options['directories']
            : array();
        $processOwnership = self::getProcessOwnership();
        $directoryRows = array();
        $requiredDirectoryErrors = 0;
        foreach ($directories as $directory) {
            $row = self::buildDirectoryStatusRow($directory, $processOwnership);
            if (isset($row['required']) && $row['required'] && isset($row['tone']) && $row['tone'] === 'error') {
                $requiredDirectoryErrors++;
            }
            unset($row['required']);
            $directoryRows[] = $row;
        }
        $releasePageUrl = isset($options['releasePageUrl']) && is_string($options['releasePageUrl']) && $options['releasePageUrl'] !== ''
            ? $options['releasePageUrl']
            : self::RELEASES_PAGE_URL;
        $versionDescription =
            'Current: ' . $summary['haxcmsVersionCurrent'] .
            ' · Latest: ' . $summary['haxcmsVersionLatest'];
        if (
            $summary['haxcmsVersionCurrent'] !== 'unknown' &&
            $summary['haxcmsVersionLatest'] !== 'unknown' &&
            $summary['haxcmsVersionCurrent'] !== $summary['haxcmsVersionLatest']
        ) {
            $versionDescription .= ' · Update: ' . $releasePageUrl;
        }
        $rows[] = array(
            'key' => 'installation-state',
            'tone' => $requiredDirectoryErrors === 0 ? 'ok' : 'error',
            'title' => 'Installation directories',
            'value' => $requiredDirectoryErrors === 0 ? 'Installed' : 'Incomplete',
            'description' => 'Checks required runtime directories for existence, writability, and ownership alignment.',
        );
        foreach ($directoryRows as $directoryRow) {
            $rows[] = $directoryRow;
        }
        if (array_key_exists('securitySecretsLoaded', $options)) {
            $securityLoaded = (bool) $options['securitySecretsLoaded'];
            $rows[] = array(
                'key' => 'security-secrets',
                'tone' => $securityLoaded ? 'ok' : 'error',
                'title' => 'Security secrets',
                'value' => $securityLoaded ? 'Loaded' : 'Missing',
                'description' => isset($options['securityDescription'])
                    ? $options['securityDescription']
                    : 'Checks runtime secret material availability.',
            );
        }
        if (array_key_exists('jwtChecksEnabled', $options)) {
            $jwtChecksEnabled = (bool) $options['jwtChecksEnabled'];
            $rows[] = array(
                'key' => 'jwt-security',
                'tone' => $jwtChecksEnabled ? 'ok' : 'warning',
                'title' => 'JWT security checks',
                'value' => $jwtChecksEnabled ? 'Enabled' : 'Disabled',
                'description' => isset($options['jwtDescription'])
                    ? $options['jwtDescription']
                    : 'Indicates whether JWT validation is enforced.',
            );
        }
        $rows[] = array(
            'key' => 'haxcms-version',
            'tone' => (
                $summary['haxcmsVersionCurrent'] !== 'unknown' &&
                $summary['haxcmsVersionLatest'] !== 'unknown' &&
                $summary['haxcmsVersionCurrent'] === $summary['haxcmsVersionLatest']
            ) ? 'ok' : 'warning',
            'title' => 'HAXcms version',
            'value' => $summary['haxcmsVersionCurrent'],
            'description' => $versionDescription,
        );
        $rows[] = array(
            'key' => 'community-support',
            'tone' => 'info',
            'title' => 'Community support',
            'value' => 'Discord',
            'description' => 'Join community support: ' . (
                isset($options['supportUrl']) && is_string($options['supportUrl']) && $options['supportUrl'] !== ''
                    ? $options['supportUrl']
                    : self::DISCORD_SUPPORT_URL
            ),
        );
        return array(
            'summary' => $summary,
            'rows' => $rows,
        );
    }

    public static function buildHAXCMSStatusReport($haxcms)
    {
        $rootPath = defined('HAXCMS_ROOT') ? HAXCMS_ROOT : getcwd();
        $configDirectory = isset($haxcms->configDirectory)
            ? $haxcms->configDirectory
            : self::joinPath($rootPath, '_config');
        $sitesDirectoryName = isset($haxcms->sitesDirectory) ? $haxcms->sitesDirectory : '_sites';
        $publishedDirectoryName = isset($haxcms->publishedDirectory) ? $haxcms->publishedDirectory : '_published';
        $archivedDirectoryName = isset($haxcms->archivedDirectory) ? $haxcms->archivedDirectory : '_archived';
        $currentVersion = (is_object($haxcms) && method_exists($haxcms, 'getHAXCMSVersion'))
            ? self::normalizeVersion($haxcms->getHAXCMSVersion())
            : '';
        $latestVersion = self::normalizeVersion(self::fetchLatestReleaseVersionFromGitHub());
        if ($latestVersion === '') {
            $latestVersion = $currentVersion;
        }
        $secretsLoaded = (
            isset($haxcms->salt) &&
            isset($haxcms->privateKey) &&
            isset($haxcms->refreshPrivateKey) &&
            trim((string) $haxcms->salt) !== '' &&
            trim((string) $haxcms->privateKey) !== '' &&
            trim((string) $haxcms->refreshPrivateKey) !== ''
        );
        return self::buildStatusReport(array(
            'programmingLanguage' => self::getRuntimeVersionLabel(),
            'serverVersion' => self::getServerVersionLabel(),
            'haxcmsVersionCurrent' => $currentVersion === '' ? 'unknown' : $currentVersion,
            'haxcmsVersionLatest' => $latestVersion === '' ? 'unknown' : $latestVersion,
            'configDirectory' => $configDirectory,
            'phpMemoryLimit' => self::getPHPMemoryLimitLabel(),
            'uploadLimit' => self::getUploadLimitLabel(),
            'uploadLimitHelpUrl' => self::UPLOAD_LIMIT_HELP_URL,
            'gitVersion' => self::detectGitVersion(),
            'releasePageUrl' => self::RELEASES_PAGE_URL,
            'supportUrl' => self::DISCORD_SUPPORT_URL,
            'directories' => array(
                array(
                    'key' => 'config-directory',
                    'title' => 'Configuration directory',
                    'path' => $configDirectory,
                    'required' => true,
                ),
                array(
                    'key' => 'sites-directory',
                    'title' => 'Sites directory',
                    'path' => self::joinPath($rootPath, $sitesDirectoryName),
                    'required' => true,
                ),
                array(
                    'key' => 'published-directory',
                    'title' => 'Published directory',
                    'path' => self::joinPath($rootPath, $publishedDirectoryName),
                    'required' => true,
                ),
                array(
                    'key' => 'archived-directory',
                    'title' => 'Archived directory',
                    'path' => self::joinPath($rootPath, $archivedDirectoryName),
                    'required' => true,
                ),
                array(
                    'key' => 'user-files-directory',
                    'title' => 'User files directory',
                    'path' => self::joinPath($configDirectory, 'user/files'),
                    'required' => true,
                ),
            ),
            'securitySecretsLoaded' => $secretsLoaded,
            'securityDescription' => 'Checks SALT, private key, and refresh key loading in runtime configuration.',
            'jwtChecksEnabled' => true,
            'jwtDescription' => 'JWT validation is required for authenticated API routes.',
        ));
    }

    public static function buildInstallerStatusReport($rootPath)
    {
        $normalizedRootPath = rtrim((string) $rootPath, '/');
        if ($normalizedRootPath === '') {
            $normalizedRootPath = getcwd();
        }
        $versionFile = self::joinPath($normalizedRootPath, 'VERSION.txt');
        $currentVersion = file_exists($versionFile)
            ? self::normalizeVersion(@file_get_contents($versionFile))
            : 'unknown';
        $latestVersion = self::normalizeVersion(self::fetchLatestReleaseVersionFromGitHub());
        if ($latestVersion === '') {
            $latestVersion = $currentVersion;
        }
        $configFilePath = self::joinPath($normalizedRootPath, '_config/config.php');
        $saltFilePath = self::joinPath($normalizedRootPath, '_config/SALT.txt');
        $configHasKeys = false;
        if (file_exists($configFilePath)) {
            $configContents = @file_get_contents($configFilePath);
            if (is_string($configContents) && trim($configContents) !== '') {
                $configHasKeys = (
                    strpos($configContents, 'HAXTHEWEBPRIVATEKEY') === false &&
                    strpos($configContents, 'HAXTHEWEBREFRESHPRIVATEKEY') === false
                );
            }
        }
        $secretsLoaded = file_exists($saltFilePath) && $configHasKeys;
        $configDirectory = self::joinPath($normalizedRootPath, '_config');
        return self::buildStatusReport(array(
            'programmingLanguage' => self::getRuntimeVersionLabel(),
            'serverVersion' => self::getServerVersionLabel(),
            'haxcmsVersionCurrent' => $currentVersion === '' ? 'unknown' : $currentVersion,
            'haxcmsVersionLatest' => $latestVersion === '' ? 'unknown' : $latestVersion,
            'configDirectory' => $configDirectory,
            'phpMemoryLimit' => self::getPHPMemoryLimitLabel(),
            'uploadLimit' => self::getUploadLimitLabel(),
            'uploadLimitHelpUrl' => self::UPLOAD_LIMIT_HELP_URL,
            'gitVersion' => self::detectGitVersion(),
            'releasePageUrl' => self::RELEASES_PAGE_URL,
            'supportUrl' => self::DISCORD_SUPPORT_URL,
            'directories' => array(
                array(
                    'key' => 'config-directory',
                    'title' => 'Configuration directory',
                    'path' => $configDirectory,
                    'required' => true,
                ),
                array(
                    'key' => 'sites-directory',
                    'title' => 'Sites directory',
                    'path' => self::joinPath($normalizedRootPath, '_sites'),
                    'required' => true,
                ),
                array(
                    'key' => 'published-directory',
                    'title' => 'Published directory',
                    'path' => self::joinPath($normalizedRootPath, '_published'),
                    'required' => true,
                ),
                array(
                    'key' => 'archived-directory',
                    'title' => 'Archived directory',
                    'path' => self::joinPath($normalizedRootPath, '_archived'),
                    'required' => true,
                ),
                array(
                    'key' => 'user-files-directory',
                    'title' => 'User files directory',
                    'path' => self::joinPath($normalizedRootPath, '_config/user/files'),
                    'required' => true,
                ),
            ),
            'securitySecretsLoaded' => $secretsLoaded,
            'securityDescription' => 'Checks generated install secrets in _config/config.php and _config/SALT.txt.',
            'jwtChecksEnabled' => true,
            'jwtDescription' => 'JWT validation is active once login is configured after installation.',
        ));
    }
}
