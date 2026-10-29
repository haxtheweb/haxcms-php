<?php
/**
 * Managed-file policies (haxtheweb/issues#3186).
 *
 * rebuildManagedFiles() used to copy every boilerplate file over the site's
 * copy on every save. These helpers let each file declare how it is kept in
 * sync instead:
 *
 * - replace    the rendered boilerplate is the whole file (default)
 * - block      only the span between version-neutral markers belongs to
 *              HAXcms; everything outside it belongs to the site owner
 * - json-merge HAXcms owns the keys it renders; other keys are kept
 *
 * Every policy is applied in memory and the caller only writes when the
 * result differs from what is on disk, so an unchanged rebuild writes
 * nothing and makes no git commit.
 *
 * Mirrors NodeJS src/lib/managedFiles.js. Keep both in step.
 */
class ManagedFiles
{
    // Version-neutral markers: never put a HAXcms version in a marker.
    const MARKERS = array(
        'html' => array(
            'start' => '<!-- haxcms:managed:start -->',
            'end' => '<!-- haxcms:managed:end -->',
        ),
        'hash' => array(
            'start' => '# BEGIN HAXcms managed',
            'end' => '# END HAXcms managed',
        ),
    );
    // legacy (what to do with an existing file that has no markers yet):
    // - whole  the file was always fully managed (overwritten on every save),
    //          so nothing in it can be owner content: replace it with the
    //          marked baseline
    // - hashes the file was copied once at site creation and may have been
    //          edited: replace it only if it is byte-identical to a released
    //          boilerplate version, otherwise keep it below the managed block
    const POLICIES = array(
        'agents' => array('policy' => 'block', 'markers' => 'html', 'render' => false, 'legacy' => 'hashes'),
        'htaccess' => array('policy' => 'block', 'markers' => 'hash', 'render' => true, 'legacy' => 'whole'),
        'robots' => array('policy' => 'block', 'markers' => 'hash', 'render' => true, 'legacy' => 'whole'),
        'package' => array('policy' => 'json-merge', 'render' => true),
    );
    // sha256 (LF-normalized) of every boilerplate site/AGENTS.md released by
    // haxcms-nodejs or haxcms-php before it gained managed-block markers.
    // Regenerate by hashing each historical version of the file in both repos.
    const LEGACY_AGENTS_MD_HASHES = array(
        '0be8e2ff53abdfe547117e3822ea0f7631f8ac21171abb1e0a810aee24e45330',
        '65639decc4c8a018b8417bf4144284384ce60b6d6edbdbc9ddefdfc7466db9f0',
        '6a4b7ae0dc63aa5f266a197c72ce69f4a066df4abcf504519009be1756a0e592',
        '6b2be8a4ce58820a1a7ceca2d0953d40ed54015209d3e17c5411a873941636f5',
        '77bb916f500024cb75c5ce1643e40e7fc5163f5619341c3d4ff96d66a30a9bf9',
        '79551daf52a538f444b49f677e3ddccebc47cede9faf6e2c4019f8da8e38c9d6',
        '9c03d861a53cdae000a39c1c00e6f5d3fbfca94fdcd12104576d14d81ed1457b',
        '9cad2830f306844a31508be59adc6d84c7f641880eacc273f5ae3c6ac4059b6e',
        'cdd42032dcff0ac1d28ac9fe776c2fca6bf5582388e11eeaec86490f215862cf',
        'ce7c7f108a824274a81d09104188480b4d0f393b8a4b1dee1967d2b6e0e0d61c',
        'd1c36de097e46e4e7da1bb573755be673716c825a9e3130fdf295af5161e1ad2',
        'e30f17b98e59688eb1cf8ee84ad1f12ef36c7e0bf992cb621323fce9f85f9395',
    );
    // package.json objects merged one level deep (HAXcms entries win, owner
    // entries the boilerplate does not define are kept)
    const JSON_MERGE_NESTED_KEYS = array('scripts', 'dependencies', 'devDependencies');
    // security.txt Expires: keep the existing value while more than this many
    // days remain, so the file does not change on every save
    const SECURITY_TXT_REFRESH_DAYS = 90;
    const SECURITY_TXT_LIFETIME_DAYS = 180;

    public static function getPolicy($key)
    {
        if (isset(self::POLICIES[$key])) {
            return self::POLICIES[$key];
        }
        return array('policy' => 'replace', 'render' => true);
    }

    public static function isKnownLegacyAgentsMd($text)
    {
        $hash = hash('sha256', str_replace("\r\n", "\n", (string) $text));
        return in_array($hash, self::LEGACY_AGENTS_MD_HASHES, true);
    }

    /**
     * Locate the first complete managed block. Returns array(start, end) where
     * end is the offset just past the end marker, or null when there is none.
     */
    public static function findBlock($text, $markersKey)
    {
        if (!isset(self::MARKERS[$markersKey]) || !is_string($text)) {
            return null;
        }
        $markers = self::MARKERS[$markersKey];
        $start = strpos($text, $markers['start']);
        if ($start === false) {
            return null;
        }
        $endMarker = strpos($text, $markers['end'], $start + strlen($markers['start']));
        if ($endMarker === false) {
            return null;
        }
        return array($start, $endMarker + strlen($markers['end']));
    }

    /**
     * Merge a rendered boilerplate (which contains the markers) into the
     * existing site file. $existing is null when the file does not exist.
     */
    public static function mergeBlock($existing, $rendered, $markersKey, $legacy)
    {
        $renderedBlock = self::findBlock($rendered, $markersKey);
        // a boilerplate without markers cannot be merged safely: treat as replace
        if ($renderedBlock === null) {
            return $rendered;
        }
        if ($existing === null) {
            return $rendered;
        }
        $block = substr($rendered, $renderedBlock[0], $renderedBlock[1] - $renderedBlock[0]);
        $existingBlock = self::findBlock($existing, $markersKey);
        if ($existingBlock !== null) {
            return substr($existing, 0, $existingBlock[0]) . $block . substr($existing, $existingBlock[1]);
        }
        if ($legacy === 'whole') {
            return $rendered;
        }
        if ($legacy === 'hashes' && self::isKnownLegacyAgentsMd($existing)) {
            return $rendered;
        }
        // unknown owner content with no markers: keep all of it below the block
        return $block . "\n\n" . $existing;
    }

    private static function isPlainObject($value)
    {
        return is_array($value) && ($value === array() || array_keys($value) !== range(0, count($value) - 1));
    }

    /**
     * HAXcms owns the keys it renders; any other keys in the existing file are
     * kept. When the existing file adds nothing, the rendered text is returned
     * verbatim so formatting stays exactly as the boilerplate defines it.
     */
    public static function mergeJson($existing, $rendered)
    {
        if ($existing === null) {
            return $rendered;
        }
        $current = json_decode($existing, true);
        $next = json_decode($rendered, true);
        if (!self::isPlainObject($current) || !self::isPlainObject($next)) {
            return $rendered;
        }
        $hasOwnerKeys = false;
        $merged = array();
        foreach ($next as $key => $value) {
            if (
                in_array($key, self::JSON_MERGE_NESTED_KEYS, true) &&
                self::isPlainObject($value) &&
                isset($current[$key]) &&
                self::isPlainObject($current[$key])
            ) {
                $nested = $value;
                foreach ($current[$key] as $nestedKey => $nestedValue) {
                    if (!array_key_exists($nestedKey, $value)) {
                        $nested[$nestedKey] = $nestedValue;
                        $hasOwnerKeys = true;
                    }
                }
                $merged[$key] = $nested;
            } else {
                $merged[$key] = $value;
            }
        }
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $next)) {
                $merged[$key] = $value;
                $hasOwnerKeys = true;
            }
        }
        if (!$hasOwnerKeys) {
            return $rendered;
        }
        return json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Apply a file's policy. $existing is the current site file (null if
     * absent), $rendered is the boilerplate after Twig.
     */
    public static function apply($key, $existing, $rendered)
    {
        $config = self::getPolicy($key);
        if ($config['policy'] === 'block') {
            return self::mergeBlock($existing, $rendered, $config['markers'], $config['legacy']);
        }
        if ($config['policy'] === 'json-merge') {
            return self::mergeJson($existing, $rendered);
        }
        return $rendered;
    }

    /**
     * Expires value for security.txt. Reuses the existing value while more
     * than SECURITY_TXT_REFRESH_DAYS remain; otherwise a new value at day
     * precision.
     */
    public static function getSecurityTxtExpires($existingText, $now = null)
    {
        $nowTs = ($now === null) ? time() : (int) $now;
        if (is_string($existingText) && preg_match('/^Expires:\s*(\S+)\s*$/m', $existingText, $match)) {
            $existingTs = strtotime($match[1]);
            if ($existingTs !== false && ($existingTs - $nowTs) > self::SECURITY_TXT_REFRESH_DAYS * 86400) {
                return $match[1];
            }
        }
        $expires = $nowTs + self::SECURITY_TXT_LIFETIME_DAYS * 86400;
        return gmdate('Y-m-d', $expires) . 'T00:00:00.000Z';
    }
}
