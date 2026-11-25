<?php
include_once dirname(__FILE__) . '/HAXCMSImageResize.php';

/**
 * Image framing: one photo framed for each screen size
 * (haxtheweb/issues#2976).
 *
 * Elements that frame a photo (the tags in TAGS) are served from files cut
 * when the page is saved, under files/haxcms-managed/framing/:
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
 * Security (SEC-PATH): the photo is read and every cut written or removed
 * only through containedPath(), so all of it stays inside the site's
 * directory, and only plain files/ paths with still-image extensions are cut.
 *
 * Stability: a save never does unbounded image work. Only allowlisted tags
 * are cut; the photo's first bytes must be the image type its extension
 * names; photos over MAX_BYTES or MAX_PIXELS, or that would not fit in the
 * memory PHP has left, are left to the CSS fallback; one save cuts at most
 * MAX_PHOTOS photos and stops starting new files after MAX_SECONDS (the next
 * save carries on, since files already made are kept). When a framing
 * changes, the cuts of that photo no page uses any more are removed, so the
 * folder doesn't grow with every reframing.
 */
class ImageFraming
{
    const SIZES = array('sm' => 600, 'md' => 900, 'lg' => 1440, 'xl' => 1920);
    const FULL_WIDTHS = array(600, 1200, 1920, 2880);
    const FORMATS = array('jpg', 'jpeg', 'png', 'webp');
    const DIR = 'files/haxcms-managed/framing/';
    // the only elements whose photos are cut
    const TAGS = array('ddd-hero');
    // limits on the work one save may do
    const MAX_PHOTOS = 12;
    const MAX_BYTES = 26214400; // 25 MB
    const MAX_PIXELS = 40000000;
    const MAX_SECONDS = 20;
    // pages read when looking for cuts still in use; past this nothing is removed
    const MAX_PAGES = 5000;

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
     * a list of array(source, location, width, sizeId?, rect?).
     * Only the first MAX_PHOTOS photos are planned, unless $limited is false
     * (used when listing what is in use, never for cutting).
     */
    public static function framingPlan($html, $limited = true)
    {
        $plan = array();
        $seen = array();
        $photos = array();
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
            if (!($el instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($el->nodeName);
            if (!in_array($tag, self::TAGS, true)) {
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
            $photo = preg_split('/[?#]/', $source)[0];
            if (!isset($photos[$photo])) {
                if ($limited !== false && count($photos) >= self::MAX_PHOTOS) {
                    continue;
                }
                $photos[$photo] = true;
            }
            foreach (self::FULL_WIDTHS as $width) {
                $location = self::framingFullUrl($source, $width);
                if (!isset($seen[$location])) {
                    $seen[$location] = true;
                    $plan[] = array('source' => $source, 'location' => $location, 'width' => $width);
                }
            }
            $custom = self::parseFraming($el->hasAttribute('framing') ? $el->getAttribute('framing') : '');
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

    /** the image type a file's first bytes say it is: 'jpg', 'png', 'webp', 'gif' or null */
    public static function sniffImage($file)
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = fread($handle, 12);
        fclose($handle);
        if (!is_string($head)) {
            return null;
        }
        if (strlen($head) >= 3 && substr($head, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }
        if (strlen($head) >= 8 && substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'png';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        // simple-img variants may be cut from a gif's first frame; framing
        // never asks for one, since gif is not in FORMATS
        if (strlen($head) >= 6 && preg_match('/^GIF8[79]a$/', substr($head, 0, 6))) {
            return 'gif';
        }
        return null;
    }

    /** bytes PHP may still use, or null when memory is not limited */
    private static function memoryLeft()
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return null;
        }
        $bytes = (float) $limit;
        $unit = strtolower(substr($limit, -1));
        if ($unit === 'g') {
            $bytes *= 1073741824;
        } elseif ($unit === 'm') {
            $bytes *= 1048576;
        } elseif ($unit === 'k') {
            $bytes *= 1024;
        }
        return $bytes - memory_get_usage(true);
    }

    /**
     * array('width' => ..., 'height' => ...) of a photo that may be cut, or
     * null: not a regular file, too big (on disk, in pixels, or for the
     * memory PHP has left), not the image type its extension names, or
     * unreadable. Reads the header only.
     */
    public static function framingPhoto($input, $ext)
    {
        if (!is_file($input) || is_link($input)) {
            return null;
        }
        $size = @filesize($input);
        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }
        $type = self::sniffImage($input);
        if ($type === null || $type !== ($ext === 'jpeg' ? 'jpg' : $ext)) {
            return null;
        }
        $info = @getimagesize($input);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        // GD holds the whole photo decoded: running out of memory is a fatal
        // error no try can catch, so don't start what won't fit
        $left = self::memoryLeft();
        if ($left !== null && $info[0] * $info[1] * 6 + 33554432 > $left) {
            return null;
        }
        return array('width' => $info[0], 'height' => $info[1]);
    }

    /** every index.html under pages/ in the site, or null when there are too many */
    public static function framingPageFiles($siteDirectory)
    {
        $out = array();
        $root = self::containedPath($siteDirectory, 'pages');
        if ($root === false || !is_dir($root)) {
            return $out;
        }
        $stack = array(array($root, 0));
        while (count($stack) > 0) {
            list($dir, $depth) = array_pop($stack);
            $names = @scandir($dir);
            if ($names === false) {
                return null;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $dir . '/' . $name;
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    if ($depth >= 8) {
                        return null;
                    }
                    $stack[] = array($full, $depth + 1);
                } elseif ($name === 'index.html' && is_file($full)) {
                    $out[] = $full;
                    if (count($out) > self::MAX_PAGES) {
                        return null;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Remove the custom cuts of the photos named by $stems that no page in
     * the site uses any more. Only files in the framing folder whose names
     * are cuts of those photos are ever removed; width variants of the photo
     * are kept, and so is every location listed in $keep (the save in hand).
     * Returns the locations removed.
     */
    public static function cleanFramings($siteDirectory, $stems, $keep = array())
    {
        $removed = array();
        $dir = self::containedPath($siteDirectory, self::DIR);
        if ($dir === false || !is_dir($dir) || count($stems) === 0) {
            return $removed;
        }
        $pages = self::framingPageFiles($siteDirectory);
        // can't tell what is in use: remove nothing
        if ($pages === null) {
            return $removed;
        }
        $used = array();
        foreach ($keep as $location) {
            $used[$location] = true;
        }
        foreach ($pages as $page) {
            $html = @file_get_contents($page);
            if ($html === false) {
                return $removed;
            }
            if (strpos($html, 'framing') === false) {
                continue;
            }
            foreach (self::framingPlan($html, false) as $entry) {
                $used[$entry['location']] = true;
            }
        }
        $names = @scandir($dir);
        if ($names === false) {
            return $removed;
        }
        foreach ($names as $name) {
            if (!preg_match('/^(.+)-(sm|md|lg|xl)-[0-9a-f]{8}-[0-9]+w\.(jpg|jpeg|png|webp)$/', $name, $match)) {
                continue;
            }
            if (!in_array($match[1], $stems, true)) {
                continue;
            }
            $location = self::DIR . $name;
            if (isset($used[$location])) {
                continue;
            }
            $target = self::containedPath($siteDirectory, $location);
            if ($target === false || !is_file($target) || is_link($target)) {
                continue;
            }
            if (@unlink($target)) {
                $removed[] = $location;
            }
        }
        return $removed;
    }

    /**
     * Make the files the framed photos in `html` need, inside
     * `siteDirectory`, within the limits at the top of this class. Files
     * already there are kept; a photo that is missing, too big or not an
     * image is skipped (the element falls back to framing the original in
     * CSS). When a new custom cut is written, that photo's cuts no page uses
     * any more are removed. $options['maxSeconds'] overrides MAX_SECONDS.
     * Returns the locations written.
     */
    public static function cutFramings($siteDirectory, $html, $options = array())
    {
        $written = array();
        $plan = self::framingPlan($html);
        if (count($plan) === 0) {
            return $written;
        }
        if (self::containedPath($siteDirectory, self::DIR) === false) {
            return $written;
        }
        $maxSeconds = isset($options['maxSeconds']) && is_numeric($options['maxSeconds'])
            ? (float) $options['maxSeconds']
            : self::MAX_SECONDS;
        $deadline = microtime(true) + $maxSeconds;
        $photos = array();
        $reframed = array();
        foreach ($plan as $entry) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $input = self::containedPath($siteDirectory, preg_split('/[?#]/', $entry['source'])[0]);
            $target = self::containedPath($siteDirectory, $entry['location']);
            if ($input === false || $target === false || file_exists($target)) {
                continue;
            }
            $source = self::framingSource($entry['source']);
            if (!array_key_exists($input, $photos)) {
                $photos[$input] = self::framingPhoto($input, $source['ext']);
            }
            if ($photos[$input] === null) {
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
                if (isset($entry['rect']) && !in_array($source['stem'], $reframed, true)) {
                    $reframed[] = $source['stem'];
                }
            } catch (\Throwable $e) {
                // unreadable photo: leave it to the CSS fallback; never
                // leave a half-written file behind to be served
                if (is_file($target)) {
                    @unlink($target);
                }
            }
        }
        if (count($reframed) > 0) {
            try {
                $keep = array();
                foreach ($plan as $entry) {
                    $keep[] = $entry['location'];
                }
                self::cleanFramings($siteDirectory, $reframed, $keep);
            } catch (\Throwable $e) {
                // cleaning up is best effort
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
