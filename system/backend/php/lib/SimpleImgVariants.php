<?php
include_once dirname(__FILE__) . '/ImageFraming.php';
include_once dirname(__FILE__) . '/HAXCMSImageResize.php';

/**
 * simple-img variants: the sized copy of a site photo that simple-img shows.
 *
 * When a page is saved, each simple-img on it whose photo lives in the site
 * gets the copy it asks for (size, fit, quality, rotation, format,
 * watermark) under files/haxcms-managed/simple-img/. simple-img works out
 * the same name from its attributes and shows that file, falling back to
 * the original when it is not there. These copies replace the image service
 * simple-img used to call on open-apis.hax.cloud, so the work happens
 * on-premises and the result is a plain file on any host, static ones too.
 *
 * The names are a contract shared with the front end
 * (@haxtheweb/simple-img/lib/SimpleImgVariants.js) and haxcms-nodejs
 * (src/lib/simpleImgVariants.js); all three test the same golden names.
 *
 * Security (SEC-PATH): the photo and the watermark are read, and every
 * variant written or removed, only through ImageFraming::containedPath(), so
 * all of it stays inside the site's directory; only plain files/ paths with
 * image extensions are read, and their first bytes must be the type the
 * extension names. Nothing is fetched from another host.
 *
 * Stability: a save never does unbounded image work. Photos over the
 * framing size and memory limits are skipped (simple-img shows the
 * original), sides over MAX_SIDE are never made, one save makes at most
 * MAX_VARIANTS variants and stops starting new ones after MAX_SECONDS (the
 * next save carries on, since files already made are kept). When a photo
 * gets a new variant, its variants no page uses any more are removed.
 */
class SimpleImgVariants
{
    const DIR = 'files/haxcms-managed/simple-img/';
    const SOURCE_FORMATS = array('jpg', 'jpeg', 'png', 'webp', 'gif');
    const WATERMARK_FORMATS = array('jpg', 'jpeg', 'png', 'webp');
    const FORMATS = array('jpg', 'png', 'webp', 'gif');
    const FITS = array('cover', 'contain', 'fill', 'inside', 'outside');
    const SPOTS = array('se', 'sw', 'ne', 'nw');
    const DEFAULT_WIDTH = 300;
    const DEFAULT_HEIGHT = 200;
    const DEFAULT_QUALITY = 80;
    const MAX_SIDE = 4000;
    // limits on the work one save may do
    const MAX_VARIANTS = 24;
    const MAX_SECONDS = 20;

    /**
     * array('path' => ..., 'stem' => ..., 'ext' => ...) for a plain files/
     * path with one of $formats, else null
     */
    public static function source($source, $formats = null)
    {
        if (!is_string($source)) {
            return null;
        }
        $allowed = is_array($formats) ? $formats : self::SOURCE_FORMATS;
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
        if (!in_array($ext, $allowed, true)) {
            return null;
        }
        $stemParts = array_slice($parts, 1, -1);
        $stemParts[] = substr($file, 0, $dot);
        return array('path' => $clean, 'stem' => implode('--', $stemParts), 'ext' => $ext);
    }

    /** a whole number from an attribute value (parseInt rules), or null */
    private static function toInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? (int) $value : null;
        }
        if (!preg_match('/^\s*([+-]?\d+)/', (string) $value, $m)) {
            return null;
        }
        return (int) $m[1];
    }

    private static function toWord($value)
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    /**
     * The variant a simple-img asks for, from its attribute values, or null
     * when none is made: a source outside the site, a side over MAX_SIDE, a
     * rotation that is not a quarter turn, or a watermark that is not a site
     * photo.
     */
    public static function variant($attrs)
    {
        $a = is_array($attrs) ? $attrs : array();
        $get = function ($key) use ($a) {
            return array_key_exists($key, $a) ? $a[$key] : null;
        };
        $source = self::source($get('src'));
        if (!$source) {
            return null;
        }
        $width = self::toInt($get('width'));
        if ($width === null || $width < 1) {
            $width = self::DEFAULT_WIDTH;
        }
        $height = self::toInt($get('height'));
        if ($height === null || $height < 1) {
            $height = self::DEFAULT_HEIGHT;
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            return null;
        }
        $quality = self::toInt($get('quality'));
        if ($quality === null || $quality < 1 || $quality > 100) {
            $quality = self::DEFAULT_QUALITY;
        }
        $rotate = self::toInt($get('rotate'));
        $rotate = $rotate === null ? 0 : (($rotate % 360) + 360) % 360;
        if ($rotate % 90 !== 0) {
            return null;
        }
        $fit = self::toWord($get('fit'));
        if (!in_array($fit, self::FITS, true)) {
            $fit = 'cover';
        }
        $format = self::toWord($get('format'));
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        if (!in_array($format, self::FORMATS, true)) {
            $format = 'jpg';
        }
        $watermark = '';
        $mark = $get('watermark');
        if (is_string($mark) && trim($mark) !== '') {
            $markSource = self::source(trim($mark), self::WATERMARK_FORMATS);
            if (!$markSource) {
                return null;
            }
            $watermark = $markSource['path'];
        }
        $wmspot = self::toWord($get('wmspot'));
        if (!in_array($wmspot, self::SPOTS, true)) {
            $wmspot = 'se';
        }
        $key = implode('|', array(
            $source['path'], $width, $height, $quality, $rotate, $fit, $format, $watermark, $wmspot,
        ));
        return array(
            'source' => $source['path'],
            'stem' => $source['stem'],
            'ext' => $source['ext'],
            'location' => self::DIR . $source['stem'] . '-' . ImageFraming::fnv1a($key) . '.' . $format,
            'width' => $width,
            'height' => $height,
            'quality' => $quality,
            'rotate' => $rotate,
            'fit' => $fit,
            'format' => $format,
            'watermark' => $watermark,
            'wmspot' => $wmspot,
        );
    }

    /** the site path of the variant a simple-img asks for, or null */
    public static function variantUrl($attrs)
    {
        $variant = self::variant($attrs);
        return $variant ? $variant['location'] : null;
    }

    /**
     * Every variant the simple-img tags in this HTML ask for, one per file.
     * Only the first MAX_VARIANTS are planned, unless $limited is false (used
     * when listing what is in use, never for making files).
     */
    public static function plan($html, $limited = true)
    {
        $plan = array();
        $seen = array();
        if (!is_string($html) || strpos($html, 'simple-img') === false || !class_exists('DOMDocument')) {
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
        foreach ($xpath->query('//simple-img') as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }
            $attrs = array();
            foreach (array('src', 'width', 'height', 'quality', 'rotate', 'fit', 'format', 'watermark', 'wmspot') as $name) {
                $attrs[$name] = $el->hasAttribute($name) ? $el->getAttribute($name) : null;
            }
            $variant = self::variant($attrs);
            if (!$variant || isset($seen[$variant['location']])) {
                continue;
            }
            if ($limited !== false && count($plan) >= self::MAX_VARIANTS) {
                break;
            }
            $seen[$variant['location']] = true;
            $plan[] = $variant;
        }
        return $plan;
    }

    /**
     * Remove the variants of the photos named by $stems that no page in the
     * site uses any more. Only files in the variant folder whose names are
     * variants of those photos are ever removed, and never a location listed
     * in $keep (the save in hand). Returns the locations removed.
     */
    public static function clean($siteDirectory, $stems, $keep = array())
    {
        $removed = array();
        $dir = ImageFraming::containedPath($siteDirectory, self::DIR);
        if ($dir === false || !is_dir($dir) || count($stems) === 0) {
            return $removed;
        }
        $pages = ImageFraming::framingPageFiles($siteDirectory);
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
            foreach (self::plan($html, false) as $entry) {
                $used[$entry['location']] = true;
            }
        }
        $names = @scandir($dir);
        if ($names === false) {
            return $removed;
        }
        foreach ($names as $name) {
            if (!preg_match('/^(.+)-[0-9a-f]{8}\.(jpg|png|webp|gif)$/', $name, $match)) {
                continue;
            }
            if (!in_array($match[1], $stems, true)) {
                continue;
            }
            $location = self::DIR . $name;
            if (isset($used[$location])) {
                continue;
            }
            $target = ImageFraming::containedPath($siteDirectory, $location);
            if ($target === false || !is_file($target) || is_link($target)) {
                continue;
            }
            if (@unlink($target)) {
                $removed[] = $location;
            }
        }
        return $removed;
    }

    /** a truecolor canvas that keeps alpha, filled with $fill (or transparent) */
    private static function canvas($width, $height, $opaqueBlack)
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $fill = $opaqueBlack
            ? imagecolorallocatealpha($img, 0, 0, 0, 0)
            : imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $width, $height, $fill);
        return $img;
    }

    /** $src drawn at $w x $h onto a new canvas */
    private static function scaled($src, $w, $h)
    {
        $out = self::canvas($w, $h, false);
        imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        return $out;
    }

    /**
     * $src sized to $W x $H the way sharp's resize fits do: cover (fill the
     * box, crop the middle), contain (fit in the box, black bars), fill
     * (stretch), inside (fit in the box), outside (cover the box, no crop)
     */
    private static function fit($src, $W, $H, $fit)
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($fit === 'fill') {
            return self::scaled($src, $W, $H);
        }
        if ($fit === 'inside' || $fit === 'contain') {
            $scale = min($W / $sw, $H / $sh);
        } else {
            $scale = max($W / $sw, $H / $sh);
        }
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        if ($fit === 'inside' || $fit === 'outside') {
            return self::scaled($src, $w, $h);
        }
        $out = self::canvas($W, $H, $fit === 'contain');
        $x = (int) floor(($W - $w) / 2);
        $y = (int) floor(($H - $h) / 2);
        imagecopyresampled($out, $src, $x, $y, 0, 0, $w, $h, $sw, $sh);
        return $out;
    }

    /** write one variant to $target; throws when it can't be made */
    private static function make($input, $mark, $variant, $target)
    {
        $loaded = new HAXCMSImageResize($input);
        $img = $loaded->getSourceImage();
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        if ($variant['rotate']) {
            // GD turns counter-clockwise; simple-img's rotate is clockwise
            $img = imagerotate($img, 360 - $variant['rotate'], 0);
            if ($img === false) {
                throw new \RuntimeException('rotate failed');
            }
        }
        $out = self::fit($img, $variant['width'], $variant['height'], $variant['fit']);
        if ($mark) {
            $markImage = (new HAXCMSImageResize($mark))->getSourceImage();
            $mw = imagesx($markImage);
            $mh = imagesy($markImage);
            $ow = imagesx($out);
            $oh = imagesy($out);
            // as sharp: a watermark bigger than the variant can't be placed
            if ($mw > $ow || $mh > $oh) {
                throw new \RuntimeException('watermark larger than the variant');
            }
            $x = ($variant['wmspot'] === 'sw' || $variant['wmspot'] === 'nw') ? 0 : $ow - $mw;
            $y = ($variant['wmspot'] === 'ne' || $variant['wmspot'] === 'nw') ? 0 : $oh - $mh;
            imagealphablending($out, true);
            imagecopy($out, $markImage, $x, $y, 0, 0, $mw, $mh);
            imagealphablending($out, false);
        }
        switch ($variant['format']) {
            case 'png':
                $ok = imagepng($out, $target, 6);
                break;
            case 'webp':
                $ok = imagewebp($out, $target, IMG_WEBP_LOSSLESS);
                break;
            case 'gif':
                $ok = imagegif($out, $target);
                break;
            default:
                // a jpeg has no alpha: flatten onto black, as sharp does
                $flat = self::canvas(imagesx($out), imagesy($out), true);
                imagealphablending($flat, true);
                imagecopy($flat, $out, 0, 0, 0, 0, imagesx($out), imagesy($out));
                imageinterlace($flat, true);
                $ok = imagejpeg($flat, $target, $variant['quality']);
                break;
        }
        if (!$ok) {
            throw new \RuntimeException('write failed');
        }
    }

    /**
     * Make the variants the simple-img tags in $html ask for, inside
     * $siteDirectory, within the limits at the top of this class. Files
     * already there are kept; a photo that is missing, too big or not an
     * image is skipped (simple-img shows the original). When a photo gets a
     * new variant, its variants no page uses any more are removed.
     * $options['maxSeconds'] overrides MAX_SECONDS. Returns the locations
     * written.
     */
    public static function cut($siteDirectory, $html, $options = array())
    {
        $written = array();
        $plan = self::plan($html);
        if (count($plan) === 0 || !function_exists('imagecreatetruecolor')) {
            return $written;
        }
        if (ImageFraming::containedPath($siteDirectory, self::DIR) === false) {
            return $written;
        }
        $maxSeconds = isset($options['maxSeconds']) && is_numeric($options['maxSeconds'])
            ? (float) $options['maxSeconds']
            : self::MAX_SECONDS;
        $deadline = microtime(true) + $maxSeconds;
        $photos = array();
        $changed = array();
        $checkPhoto = function ($location, $ext) use ($siteDirectory, &$photos) {
            $file = ImageFraming::containedPath($siteDirectory, $location);
            if ($file === false) {
                return null;
            }
            if (!array_key_exists($file, $photos)) {
                $photos[$file] = ImageFraming::framingPhoto($file, $ext) ? $file : null;
            }
            return $photos[$file];
        };
        foreach ($plan as $variant) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $target = ImageFraming::containedPath($siteDirectory, $variant['location']);
            if ($target === false || file_exists($target)) {
                continue;
            }
            $input = $checkPhoto($variant['source'], $variant['ext']);
            if (!$input) {
                continue;
            }
            $mark = null;
            if ($variant['watermark'] !== '') {
                $markSource = self::source($variant['watermark'], self::WATERMARK_FORMATS);
                $mark = $markSource ? $checkPhoto($markSource['path'], $markSource['ext']) : null;
                if (!$mark) {
                    continue;
                }
            }
            try {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                self::make($input, $mark, $variant, $target);
                $written[] = $variant['location'];
                if (!in_array($variant['stem'], $changed, true)) {
                    $changed[] = $variant['stem'];
                }
            } catch (\Throwable $e) {
                // unreadable photo or a watermark bigger than the variant:
                // simple-img shows the original; never leave a half-written
                // file behind to be served
                if (is_file($target)) {
                    @unlink($target);
                }
            }
        }
        if (count($changed) > 0) {
            try {
                $keep = array();
                foreach ($plan as $entry) {
                    $keep[] = $entry['location'];
                }
                self::clean($siteDirectory, $changed, $keep);
            } catch (\Throwable $e) {
                // cleaning up is best effort
            }
        }
        return $written;
    }
}
