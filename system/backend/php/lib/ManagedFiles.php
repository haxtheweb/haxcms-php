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
     * Offset of the first occurrence of $marker at the start of a line, at or
     * after $from; false when absent. Markers only count on their own line so
     * that owner text quoting a marker mid-line is never mistaken for one.
     */
    private static function indexOfLineMarker($text, $marker, $from)
    {
        $index = strpos($text, $marker, $from);
        while ($index !== false) {
            if ($index === 0 || $text[$index - 1] === "\n") {
                return $index;
            }
            $index = strpos($text, $marker, $index + 1);
        }
        return false;
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
        $start = self::indexOfLineMarker($text, $markers['start'], 0);
        if ($start === false) {
            return null;
        }
        $endMarker = self::indexOfLineMarker($text, $markers['end'], $start + strlen($markers['start']));
        if ($endMarker === false) {
            return null;
        }
        return array($start, $endMarker + strlen($markers['end']));
    }

    /**
     * Whether either marker of the pair appears anywhere in the text.
     */
    public static function hasAnyMarker($text, $markersKey)
    {
        if (!isset(self::MARKERS[$markersKey]) || !is_string($text)) {
            return false;
        }
        $markers = self::MARKERS[$markersKey];
        return strpos($text, $markers['start']) !== false || strpos($text, $markers['end']) !== false;
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
        // an always-managed file is replaced only when it carries no marker at
        // all; a damaged block (e.g. a deleted END line) means an owner has been
        // editing it, so keep everything below a fresh block instead
        if ($legacy === 'whole' && !self::hasAnyMarker($existing, $markersKey)) {
            return $rendered;
        }
        if ($legacy === 'hashes' && self::isKnownLegacyAgentsMd($existing)) {
            return $rendered;
        }
        // unknown owner content with no markers: keep all of it below the block
        return $block . "\n\n" . $existing;
    }

    /**
     * HAXcms owns the keys it renders; any other keys in the existing file are
     * kept. When the existing file adds nothing, the rendered text is returned
     * verbatim so formatting stays exactly as the boilerplate defines it.
     * Decoded as objects (not assoc arrays) so {} stays {} and numeric-string
     * keys stay object keys, matching NodeJS JSON.parse.
     */
    public static function mergeJson($existing, $rendered)
    {
        if ($existing === null) {
            return $rendered;
        }
        $current = json_decode($existing);
        $next = json_decode($rendered);
        if (!($current instanceof stdClass) || !($next instanceof stdClass)) {
            return $rendered;
        }
        $hasOwnerKeys = false;
        $merged = new stdClass();
        foreach (get_object_vars($next) as $key => $value) {
            if (
                in_array($key, self::JSON_MERGE_NESTED_KEYS, true) &&
                $value instanceof stdClass &&
                property_exists($current, $key) &&
                $current->{$key} instanceof stdClass
            ) {
                $nested = clone $value;
                foreach (get_object_vars($current->{$key}) as $nestedKey => $nestedValue) {
                    if (!property_exists($value, $nestedKey)) {
                        $nested->{$nestedKey} = $nestedValue;
                        $hasOwnerKeys = true;
                    }
                }
                $merged->{$key} = $nested;
            } else {
                $merged->{$key} = $value;
            }
        }
        foreach (get_object_vars($current) as $key => $value) {
            if (!property_exists($next, $key)) {
                $merged->{$key} = $value;
                $hasOwnerKeys = true;
            }
        }
        if (!$hasOwnerKeys) {
            return $rendered;
        }
        return self::encodeJson($merged) . "\n";
    }

    /**
     * JSON matching NodeJS JSON.stringify(value, null, 2): 2-space indent,
     * unescaped slashes, unicode and line separators.
     */
    private static function encodeJson($value)
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
        );
        // JSON_PRETTY_PRINT indents by 4; JSON strings never contain raw
        // newlines, so every leading run of spaces is indentation
        return preg_replace_callback('/^( +)/m', function ($match) {
            return str_repeat(' ', intdiv(strlen($match[1]), 2));
        }, $json);
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
        // only a full UTC timestamp is reused (date-only values parse
        // differently across runtimes), and only within the normal lifetime
        // window (RFC 9116 advises staying under a year)
        if (
            is_string($existingText) &&
            preg_match('/^Expires:\s*(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)\s*$/m', $existingText, $match)
        ) {
            $existingTs = strtotime($match[1]);
            if ($existingTs !== false) {
                $remaining = $existingTs - $nowTs;
                if (
                    $remaining > self::SECURITY_TXT_REFRESH_DAYS * 86400 &&
                    $remaining <= (self::SECURITY_TXT_LIFETIME_DAYS + 1) * 86400
                ) {
                    return $match[1];
                }
            }
        }
        $expires = $nowTs + self::SECURITY_TXT_LIFETIME_DAYS * 86400;
        return gmdate('Y-m-d', $expires) . 'T00:00:00.000Z';
    }
}
