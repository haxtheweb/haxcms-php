<?php
/**
 * Parsed OpenAPI spec (site-spec.yaml / system-spec.yaml) shared across
 * requests.
 *
 * The specs are ~120KB of YAML and Symfony Yaml takes ~40ms to parse one.
 * They are read on every request (route auth policies, the mutation guard,
 * userToken policies, commit operationIds), and PHP statics only last one
 * request, so every page view, GET and save paid for one to four parses.
 *
 * load() returns the same array Yaml::parse() would, from:
 *   1. an in-request memo, then
 *   2. a JSON copy in <_config>/cache/, used only while the SHA-1 stored in
 *      it matches the spec file's current SHA-1 (a changed spec is parsed
 *      again, whatever its mtime), then
 *   3. a fresh parse, which refreshes the JSON copy.
 *
 * The copy is JSON (never PHP code or serialize()), written
 * atomically, and any cache read or write problem falls back to parsing.
 * No cache file is written when there is no _config directory.
 */
class OpenApiSpecCache
{
    private static $memo = array();

    /**
     * @param string $specPath absolute path to an OpenAPI YAML file
     * @return mixed the parsed spec (normally an array); null when the file
     *   is missing, empty, unreadable or no YAML parser is installed.
     *   Invalid YAML throws exactly as Yaml::parse() does.
     */
    public static function load($specPath)
    {
        $specPath = (string) $specPath;
        if (!is_file($specPath)) {
            return null;
        }
        $hash = @sha1_file($specPath);
        if (!is_string($hash)) {
            return null;
        }
        $memoKey = $specPath . "\0" . $hash;
        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }
        $cacheFile = self::getCacheFilePath($specPath);
        if ($cacheFile !== null) {
            $cached = self::readCacheFile($cacheFile, $hash);
            if ($cached !== null) {
                self::$memo[$memoKey] = $cached['spec'];
                return $cached['spec'];
            }
        }
        $contents = @file_get_contents($specPath);
        if (!is_string($contents) || $contents === '') {
            return null;
        }
        if (!class_exists('\\Symfony\\Component\\Yaml\\Yaml')) {
            return null;
        }
        $spec = \Symfony\Component\Yaml\Yaml::parse($contents);
        self::$memo[$memoKey] = $spec;
        if ($cacheFile !== null) {
            self::writeCacheFile($cacheFile, $hash, $spec);
        }
        return $spec;
    }

    /**
     * Forget the in-request memo (tests, or a long-running worker that
     * rewrites a spec). The JSON copies revalidate by hash on their own.
     */
    public static function reset()
    {
        self::$memo = array();
    }

    /**
     * <_config>/cache/openapi-<sha1 of spec path>.json, or null when there is
     * no _config directory to keep it in.
     */
    public static function getCacheFilePath($specPath)
    {
        $configDirectory = null;
        if (
            isset($GLOBALS['HAXCMS']) && is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->configDirectory) &&
            is_string($GLOBALS['HAXCMS']->configDirectory) &&
            $GLOBALS['HAXCMS']->configDirectory !== ''
        ) {
            $configDirectory = $GLOBALS['HAXCMS']->configDirectory;
        } elseif (defined('HAXCMS_ROOT') && is_dir(HAXCMS_ROOT . '/_config')) {
            $configDirectory = HAXCMS_ROOT . '/_config';
        }
        if ($configDirectory === null || !is_dir($configDirectory)) {
            return null;
        }
        return $configDirectory . '/cache/openapi-' . sha1((string) $specPath) . '.json';
    }

    private static function readCacheFile($cacheFile, $hash)
    {
        if (!is_file($cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($cacheFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (
            !is_array($decoded) ||
            !isset($decoded['sha1']) ||
            !is_string($decoded['sha1']) ||
            !hash_equals($decoded['sha1'], $hash) ||
            !array_key_exists('spec', $decoded)
        ) {
            return null;
        }
        return $decoded;
    }

    private static function writeCacheFile($cacheFile, $hash, $spec)
    {
        $encoded = json_encode(
            array('sha1' => $hash, 'spec' => $spec),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            return;
        }
        // only keep a copy that decodes back to exactly what was parsed
        $roundTrip = json_decode($encoded, true);
        if (!is_array($roundTrip) || $roundTrip['spec'] !== $spec) {
            return;
        }
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmpFile, $encoded, LOCK_EX) === false) {
            @unlink($tmpFile);
            return;
        }
        if (!@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
        }
    }
}
