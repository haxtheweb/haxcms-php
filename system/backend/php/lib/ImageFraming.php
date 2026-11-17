<?php
include_once dirname(__FILE__) . '/HAXCMSImageResize.php';

/**
 * Image framing: one photo framed for each screen size
 * (haxtheweb/issues#2976).
 *
 * Elements that frame a photo (ddd-hero, or anything carrying a `framing`
 * attribute next to its `source`) are served from files cut when the page is
 * saved, under files/haxcms-managed/framing/:
 *
 * - width variants of the original, for the sizes left automatic:
 *   <stem>-full-<w>w.<ext> at 600, 1200, 1920 and 2880
 * - each custom framing cut out and sized at 1x and 2x of its screen size:
 *   <stem>-<size>-<fnv1a(key)>-<w>w.<ext>
 *
 * The names are a contract shared with the front end
 * (@haxtheweb/d-d-d/lib/ImageFraming.js) and haxcms-nodejs
 * (src/lib/imageFraming.js); all three test the same golden names. Files are
 * only ever made, never upscaled: a file is named for the width asked for
 * even when the photo is smaller.
 *
 * Security (SEC-PATH): the photo is read and every cut written only through
 * containedPath(), so both stay inside the site's directory, and only plain
 * files/ paths with still-image extensions are cut at all.
 */
class ImageFraming
{
    const SIZES = array('sm' => 600, 'md' => 900, 'lg' => 1440, 'xl' => 1920);
    const FULL_WIDTHS = array(600, 1200, 1920, 2880);
    const FORMATS = array('jpg', 'jpeg', 'png', 'webp');
    const DIR = 'files/haxcms-managed/framing/';
    // elements that are served from cuts even with every size automatic
    const TAGS = array('ddd-hero');

    private static function isNum($n)
    {
        return (is_int($n) || is_float($n)) && is_finite((float) $n);
    }

    public static function roundFraming($n)
    {
        return round($n * 1000) / 1000;
    }

    /**
     * array(sizeId => rect) for the well-formed rects in a framing attribute
     */
    public static function parseFraming($value)
    {
        $data = $value;
        if (is_string($value)) {
            if (trim($value) === '') {
                return array();
            }
            $data = json_decode($value, true);
        }
        $out = array();
        if (!is_array($data) || array_is_list($data) && count($data) > 0) {
            return $out;
        }
        foreach (array_keys(self::SIZES) as $id) {
            if (!isset($data[$id]) || !is_array($data[$id])) {
                continue;
            }
            $r = $data[$id];
            $ok = true;
            foreach (array('x', 'y', 'w', 'h') as $k) {
                if (!array_key_exists($k, $r) || !self::isNum($r[$k])) {
                    $ok = false;
                }
            }
            if (
                $ok &&
                $r['w'] > 0 &&
                $r['h'] > 0 &&
                $r['x'] >= 0 &&
                $r['y'] >= 0 &&
                $r['x'] + $r['w'] <= 1.0005 &&
                $r['y'] + $r['h'] <= 1.0005
            ) {
                $out[$id] = array(
                    'x' => self::roundFraming($r['x']),
                    'y' => self::roundFraming($r['y']),
                    'w' => self::roundFraming($r['w']),
                    'h' => self::roundFraming($r['h']),
                );
            }
        }
        return $out;
    }

    /** 32-bit FNV-1a of an ASCII string, as 8 hex digits */
    public static function fnv1a($str)
    {
        $hash = 0x811c9dc5;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= ord($str[$i]) & 0xff;
            $hash = ($hash * 0x01000193) & 0xffffffff;
        }
        return str_pad(dechex($hash), 8, '0', STR_PAD_LEFT);
    }

    /** the rect as it is hashed: 3 decimals, comma separated */
    public static function framingKey($rect)
    {
        $parts = array();
        foreach (array('x', 'y', 'w', 'h') as $k) {
            $parts[] = number_format((float) $rect[$k], 3, '.', '');
        }
        return implode(',', $parts);
    }

    /**
     * array('stem' => ..., 'ext' => ...) for a still image under files/
     * (not a managed file), else null
     */
    public static function framingSource($source)
    {
        if (!is_string($source)) {
            return null;
        }
        $clean = preg_split('/[?#]/', $source)[0];
        $parts = explode('/', $clean);
        if (count($parts) < 2 || $parts[0] !== 'files') {
            return null;
        }
        if ($parts[1] === 'haxcms-managed') {
            return null;
        }
        for ($i = 1; $i < count($parts); $i++) {
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $parts[$i]) || strpos($parts[$i], '..') !== false) {
                return null;
            }
        }
        $file = $parts[count($parts) - 1];
        $dot = strrpos($file, '.');
        if ($dot === false || $dot < 1) {
            return null;
        }
        $ext = strtolower(substr($file, $dot + 1));
        if (!in_array($ext, self::FORMATS, true)) {
            return null;
        }
        $stemParts = array_slice($parts, 1, -1);
        $stemParts[] = substr($file, 0, $dot);
        return array('stem' => implode('--', $stemParts), 'ext' => $ext);
    }

    public static function framingFullUrl($source, $width)
    {
        $s = self::framingSource($source);
        if (!$s) {
            return null;
        }
        return self::DIR . $s['stem'] . '-full-' . $width . 'w.' . $s['ext'];
    }

    public static function framingCutUrl($source, $sizeId, $rect, $width)
    {
        $s = self::framingSource($source);
        if (!$s) {
            return null;
        }
        return self::DIR . $s['stem'] . '-' . $sizeId . '-' . self::fnv1a(self::framingKey($rect)) . '-' . $width . 'w.' . $s['ext'];
    }

    public static function framingCutWidths($sizeId)
    {
        if (!isset(self::SIZES[$sizeId])) {
            return array();
        }
        return array(self::SIZES[$sizeId], self::SIZES[$sizeId] * 2);
    }

    /**
     * Every file the framed photos in this HTML are served from:
     * a list of array(source, location, width, sizeId?, rect?)
     */
    public static function framingPlan($html)
    {
        $plan = array();
        $seen = array();
        if (!is_string($html) || strpos($html, 'source') === false || !class_exists('DOMDocument')) {
            return $plan;
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $plan;
        }
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[@source]') as $el) {
            $tag = strtolower($el->nodeName);
            $hasFraming = $el->hasAttribute('framing');
            if (!in_array($tag, self::TAGS, true) && !$hasFraming) {
                continue;
            }
            $mediaType = $el->getAttribute('media-type');
            if ($mediaType !== '' && $mediaType !== 'image') {
                continue;
            }
            $source = $el->getAttribute('source');
            if (!self::framingSource($source)) {
                continue;
            }
            foreach (self::FULL_WIDTHS as $width) {
                $location = self::framingFullUrl($source, $width);
                if (!isset($seen[$location])) {
                    $seen[$location] = true;
                    $plan[] = array('source' => $source, 'location' => $location, 'width' => $width);
                }
            }
            $custom = self::parseFraming($hasFraming ? $el->getAttribute('framing') : '');
            foreach (array_keys(self::SIZES) as $id) {
                if (!isset($custom[$id])) {
                    continue;
                }
                foreach (self::framingCutWidths($id) as $width) {
                    $location = self::framingCutUrl($source, $id, $custom[$id], $width);
                    if (!isset($seen[$location])) {
                        $seen[$location] = true;
                        $plan[] = array(
                            'source' => $source,
                            'location' => $location,
                            'width' => $width,
                            'sizeId' => $id,
                            'rect' => $custom[$id],
                        );
                    }
                }
            }
        }
        return $plan;
    }

    /**
     * The absolute path of `location` inside `base`, or false. Rejects null
     * bytes and `..` segments, then proves the nearest existing ancestor's
     * realpath is inside realpath(base), so a symlink can't lead out.
     */
    public static function containedPath($base, $location)
    {
        if (!is_string($base) || $base === '' || !is_string($location) || $location === '' || strpos($location, "\0") !== false) {
            return false;
        }
        $normalized = str_replace('\\', '/', $location);
        if (in_array('..', explode('/', $normalized), true) || $normalized[0] === '/') {
            return false;
        }
        $resolvedBase = realpath($base);
        if ($resolvedBase === false) {
            return false;
        }
        $resolvedBase = rtrim(str_replace('\\', '/', $resolvedBase), '/');
        $candidate = $resolvedBase . '/' . trim($normalized, '/');
        $probe = $candidate;
        while (!file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }
            $probe = $parent;
        }
        $real = rtrim(str_replace('\\', '/', realpath($probe)), '/');
        if ($real !== $resolvedBase && strpos($real, $resolvedBase . '/') !== 0) {
            return false;
        }
        if ($probe === $candidate) {
            return $real;
        }
        return $candidate;
    }

    /**
     * Make the files the framed photos in `html` need, inside
     * `siteDirectory`. Files already there are kept; a photo that is missing
     * or can't be read is skipped (the element falls back to framing the
     * original in CSS). Returns the locations written.
     */
    public static function cutFramings($siteDirectory, $html)
    {
        $written = array();
        $plan = self::framingPlan($html);
        if (count($plan) === 0) {
            return $written;
        }
        if (self::containedPath($siteDirectory, self::DIR) === false) {
            return $written;
        }
        foreach ($plan as $entry) {
            $input = self::containedPath($siteDirectory, preg_split('/[?#]/', $entry['source'])[0]);
            $target = self::containedPath($siteDirectory, $entry['location']);
            if ($input === false || $target === false || file_exists($target)) {
                continue;
            }
            if (!is_file($input) || is_link($input)) {
                continue;
            }
            try {
                $image = new HAXCMSImageResize($input);
                $W = $image->getSourceWidth();
                $H = $image->getSourceHeight();
                if ($W < 1 || $H < 1) {
                    continue;
                }
                $type = self::imageType($entry['location']);
                if (isset($entry['rect'])) {
                    $rect = $entry['rect'];
                    $left = (int) min($W - 1, round($rect['x'] * $W));
                    $top = (int) min($H - 1, round($rect['y'] * $H));
                    $width = (int) max(1, min($W - $left, round($rect['w'] * $W)));
                    $height = (int) max(1, min($H - $top, round($rect['h'] * $H)));
                    // gumlet's operations don't stack: cut first, then size the cut
                    $image->freecrop($width, $height, $left, $top);
                    $image = HAXCMSImageResize::createFromBytes($image->getImageAsString($type, 100));
                }
                $image->resizeToWidth($entry['width'], false);
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $image->save($target, $type);
                $written[] = $entry['location'];
            } catch (\Throwable $e) {
                // unreadable photo: leave it to the CSS fallback
            }
        }
        return $written;
    }

    private static function imageType($location)
    {
        $ext = strtolower(pathinfo($location, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            return IMAGETYPE_PNG;
        }
        if ($ext === 'webp') {
            return IMAGETYPE_WEBP;
        }
        return IMAGETYPE_JPEG;
    }
}
