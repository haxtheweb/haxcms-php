<?php
/**
 * Materialize inline base64 images (e.g. mammoth docx HTML) as site files.
 *
 * Mirrors haxcms-nodejs/src/lib/materializeInlineImages.js (#2945 / #3043):
 * optimize to web-friendly JPG, create via HAXCMSFile::save, then associate
 * identity only through FileEntity (getPath / getUuid).
 */
include_once dirname(__FILE__) . '/SanitizeContent.php';
include_once dirname(__FILE__) . '/HAXCMSFile.php';
include_once dirname(__FILE__) . '/EntityRegistry.php';
include_once dirname(__FILE__) . '/FileStorage.php';
include_once dirname(__FILE__) . '/FileEntity.php';
use \Gumlet\ImageResize;

class MaterializeInlineImages
{
    /** Largest recommended display size (files.js IMAGE_SCALE_PRESETS.xl). */
    const MAX_WIDTH = 1200;
    const MAX_HEIGHT = 900;
    /** Docx imports are not web-optimized; force JPEG quality 90. */
    const JPEG_QUALITY = 90;

    private static $extensionByMimeCache = null;

    /**
     * @param mixed $html
     * @param mixed $site HAXCMSSite (or test double with manifest + siteDirectory)
     * @param array $options Optional keys: pageTitle (string)
     * @return array{html:mixed,uuids:array}
     */
    public static function materialize($html, $site, $options = array())
    {
        if (!is_string($html)) {
            return array('html' => $html, 'uuids' => array());
        }
        if (!is_array($options)) {
            $options = array();
        }
        $pageTitle = isset($options['pageTitle']) ? $options['pageTitle'] : '';
        $nameBase = self::fileNameBaseFromPageTitle($pageTitle);

        if (!class_exists('DOMDocument')) {
            return array('html' => $html, 'uuids' => array());
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="__hax_mii_root">' . $html . '</div>';
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $wrapped,
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return array('html' => $html, 'uuids' => array());
        }

        $root = $dom->getElementById('__hax_mii_root');
        if (!$root) {
            return array('html' => $html, 'uuids' => array());
        }

        $images = array();
        $savedPaths = array();
        $imgNodes = $root->getElementsByTagName('img');
        // Snapshot nodes first — live NodeList mutates as we replace.
        $snapshot = array();
        foreach ($imgNodes as $img) {
            $snapshot[] = $img;
        }
        foreach ($snapshot as $img) {
            if (self::isInertMarkup($img)) {
                continue;
            }
            $src = trim((string) $img->getAttribute('src'));
            if ($src === '' || stripos($src, 'data:') !== 0) {
                continue;
            }
            $images[] = array('img' => $img, 'src' => $src);
            if (!array_key_exists($src, $savedPaths)) {
                $savedPaths[$src] = self::saveImage($src, $site, $nameBase);
            }
        }

        if (count($images) === 0) {
            return array('html' => $html, 'uuids' => array());
        }

        $entities = self::loadSavedEntities($savedPaths, $site);
        $uuids = array();

        foreach ($images as $image) {
            $img = $image['img'];
            $src = $image['src'];
            $alt = SanitizeContent::escapeHTMLAttribute($img->getAttribute('alt'));
            $entity = isset($entities[$src]) ? $entities[$src] : null;
            if ($entity instanceof FileEntity) {
                $uuid = $entity->getUuid();
                if (is_string($uuid) && $uuid !== '' && !in_array($uuid, $uuids, true)) {
                    $uuids[] = $uuid;
                }
                $source = SanitizeContent::escapeHTMLAttribute($entity->getPath());
                $replacement = $dom->createElement('media-image');
                $replacement->setAttribute('source', html_entity_decode($source, ENT_QUOTES, 'UTF-8'));
                $replacement->setAttribute('alt', html_entity_decode($alt, ENT_QUOTES, 'UTF-8'));
            }
            else {
                $replacement = $dom->createElement('place-holder');
                $replacement->setAttribute('type', 'image');
                $replacement->setAttribute('text', html_entity_decode($alt, ENT_QUOTES, 'UTF-8'));
            }
            if ($img->parentNode) {
                $img->parentNode->replaceChild($replacement, $img);
            }
        }

        return array(
            'html' => self::getInnerHtml($root),
            'uuids' => $uuids,
        );
    }

    /**
     * Clean a page title into a filesystem-safe base name (Node parity).
     *
     * @param mixed $pageTitle
     * @return string
     */
    public static function fileNameBaseFromPageTitle($pageTitle)
    {
        $base = '';
        if (is_string($pageTitle)) {
            $base = strtolower(trim($pageTitle));
            $base = str_replace(' ', '-', $base);
            $base = preg_replace('/[^\w\-\/]+/u', '-', $base);
            $base = preg_replace('/\/+/', '-', $base);
            $base = preg_replace('/-+/', '-', $base);
            $base = trim($base, '-');
        }
        if ($base === '' || $base === null) {
            $base = 'page';
        }
        if (strlen($base) > 64) {
            $base = rtrim(substr($base, 0, 64), '-');
        }
        if ($base === '') {
            $base = 'page';
        }
        return $base;
    }

    /**
     * Resize to xl max, convert non-JPG to JPG at quality 90.
     *
     * @param string $bytes
     * @param string $extension Incoming extension (png/jpg/…)
     * @return array{bytes:string,extension:string}
     */
    public static function optimizeInlineImageBuffer($bytes, $extension)
    {
        try {
            if (!class_exists('Gumlet\\ImageResize') && !class_exists('ImageResize')) {
                return array('bytes' => $bytes, 'extension' => $extension);
            }
            $image = ImageResize::createFromString($bytes);
            $image->resizeToBestFit(self::MAX_WIDTH, self::MAX_HEIGHT, false);
            $tmp = tempnam(sys_get_temp_dir(), 'hax-mii-opt-');
            if ($tmp === false) {
                return array('bytes' => $bytes, 'extension' => $extension);
            }
            $outPath = $tmp . '.jpg';
            @unlink($tmp);
            $image->save($outPath, IMAGETYPE_JPEG, self::JPEG_QUALITY);
            $out = @file_get_contents($outPath);
            @unlink($outPath);
            if ($out === false || $out === '') {
                return array('bytes' => $bytes, 'extension' => $extension);
            }
            return array('bytes' => $out, 'extension' => 'jpg');
        }
        catch (Exception $e) {
            return array('bytes' => $bytes, 'extension' => $extension);
        }
    }

    /**
     * @param DOMNode $node
     * @return bool
     */
    private static function isInertMarkup($node)
    {
        for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'template') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param DOMElement $element
     * @return string
     */
    private static function getInnerHtml($element)
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    /**
     * Build MIME→extension map from HAXCMSFile allow-list (first ext wins).
     *
     * @return array
     */
    private static function extensionByMime()
    {
        if (self::$extensionByMimeCache !== null) {
            return self::$extensionByMimeCache;
        }
        $map = array();
        $exts = HAXCMSFile::imageExtensions();
        foreach ($exts as $ext) {
            $mimes = HAXCMSFile::getAllowedMimeByExtension($ext);
            if (!is_array($mimes)) {
                continue;
            }
            foreach ($mimes as $mime) {
                $mt = strtolower((string) $mime);
                if ($mt !== '' && !isset($map[$mt])) {
                    $map[$mt] = $ext;
                }
            }
        }
        self::$extensionByMimeCache = $map;
        return $map;
    }

    /**
     * Save one data URI through HAXCMSFile (create). Returns saved path or null.
     *
     * @param string $src
     * @param mixed $site
     * @param string $nameBase
     * @return string|null
     */
    private static function saveImage($src, $site, $nameBase)
    {
        global $HAXCMS;
        if (!isset($HAXCMS) || !is_object($HAXCMS)) {
            return null;
        }
        if (!preg_match('/^data:([^;,]+)[^,]*;base64,(.+)$/is', $src, $match)) {
            return null;
        }
        $mime = strtolower(trim($match[1]));
        $extMap = self::extensionByMime();
        if (!isset($extMap[$mime])) {
            return null;
        }
        $extension = $extMap[$mime];
        $raw = base64_decode($match[2], true);
        if ($raw === false || $raw === '') {
            return null;
        }
        $optimized = self::optimizeInlineImageBuffer($raw, $extension);
        $bytes = $optimized['bytes'];
        $outputExtension = isset($optimized['extension']) ? $optimized['extension'] : 'jpg';
        $base = $nameBase !== '' ? $nameBase : 'page';
        $name = $base . '.' . $outputExtension;

        $stagingRoot = HAXCMSFile::getBulkImportStagingRootPath();
        if ($stagingRoot === false || $stagingRoot === null || $stagingRoot === '') {
            // Ensure staging root exists under config/tmp/imports
            if (!isset($HAXCMS->configDirectory) || !is_string($HAXCMS->configDirectory)) {
                return null;
            }
            $stagingRoot = rtrim($HAXCMS->configDirectory, '/') . '/tmp/imports';
            if (!is_dir($stagingRoot)) {
                @mkdir($stagingRoot, 0755, true);
            }
            $resolved = realpath($stagingRoot);
            if ($resolved === false) {
                return null;
            }
            $stagingRoot = $resolved;
        }
        $tmpPath = $stagingRoot . '/inline-image-' . self::randomId();
        if (@file_put_contents($tmpPath, $bytes) === false) {
            return null;
        }
        try {
            $file = new HAXCMSFile();
            $result = $file->save(
                array(
                    'name' => $name,
                    'tmp_name' => $tmpPath,
                    'size' => strlen($bytes),
                    'bulk-import' => true,
                ),
                $site
            );
            if (
                !is_array($result) ||
                !isset($result['status']) ||
                (int) $result['status'] !== 200 ||
                !isset($result['data']) ||
                !is_array($result['data']) ||
                !isset($result['data']['file']) ||
                !is_array($result['data']['file'])
            ) {
                return null;
            }
            $fileInfo = $result['data']['file'];
            // Path is only a locator for FileEntity load after collision rename.
            if (isset($fileInfo['path']) && is_string($fileInfo['path']) && $fileInfo['path'] !== '') {
                return $fileInfo['path'];
            }
            if (isset($fileInfo['url']) && is_string($fileInfo['url']) && $fileInfo['url'] !== '') {
                return $fileInfo['url'];
            }
            return null;
        }
        catch (Exception $e) {
            if (isset($GLOBALS['HAXCMS']) && method_exists($GLOBALS['HAXCMS'], 'logError')) {
                // best-effort log
            }
            return null;
        }
        finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Load FileEntity for each saved path AFTER all saves complete.
     *
     * @param array $savedPaths src => path|null
     * @param mixed $site
     * @return array src => FileEntity
     */
    private static function loadSavedEntities($savedPaths, $site)
    {
        $entities = array();
        $fileStorage = null;
        foreach ($savedPaths as $src => $savedPath) {
            if (!is_string($savedPath) || $savedPath === '') {
                continue;
            }
            if ($fileStorage === null) {
                $fileStorage = FileStorage::registerOn(new EntityRegistry($site));
            }
            $uuid = $fileStorage->getDataStore()->resolveUuidByPath($savedPath);
            if (!is_string($uuid) || $uuid === '') {
                continue;
            }
            $entity = $fileStorage->load($uuid);
            if ($entity instanceof FileEntity) {
                $entities[$src] = $entity;
            }
        }
        return $entities;
    }

    /**
     * @return string
     */
    private static function randomId()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(8));
        }
        return uniqid('', true);
    }
}
