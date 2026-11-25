<?php
/**
 * Read an uploaded HAXcms site zip (haxtheweb/issues#3405).
 *
 * A HAXcms site zip is what Download site gives: site.json (JSON Outline
 * Schema) with pages/, files/ and maybe custom/ beside it, either at the top
 * of the zip or inside one folder. Reading one gives the same result as
 * importing the site from its address (convertHaxcmsToSite): items with their
 * page contents, files staged under the bulk-import root, and a filename.
 *
 * Security (SEC-UPLOAD, SEC-DOS, SEC-PATH): the zip is measured under
 * BoundedZip's limits before anything reads it; only entries under the folder
 * that holds site.json are read; a path named by site.json is used only when
 * it stays inside that folder (no "..", no absolute path, no scheme). Nothing
 * is written but the staged copies, which createSite takes through its own
 * checks.
 *
 * Parity: haxcms-nodejs src/lib/siteZip.js (same results, same reasons).
 */
include_once dirname(__FILE__) . '/BoundedZip.php';
include_once dirname(__FILE__) . '/stageRemoteFile.php';

class HAXCMSSiteZipException extends \Exception
{
    /** @var string NOT_A_ZIP, INVALID_SITE or ZIP_BUDGET */
    public $reason;

    public function __construct($message, $codeName)
    {
        parent::__construct($message);
        $this->reason = $codeName;
    }
}

class HAXCMSSiteZip
{
    const MAX_SITE_ITEMS = 5000;
    const CUSTOM_BUILD = 'custom/build/custom.es6.js';
    const BOILERPLATE_CUSTOM_ES6 = '// custom comment script here';
    const DEFAULT_CONVERSION_MAX_UPLOAD_MB = 50;
    const MAX_CONVERSION_MAX_UPLOAD_MB = 1024;

    /**
     * A site zip can carry custom/build/custom.es6.js: the site's own
     * JavaScript, which runs for everyone who opens the site and for its
     * editors on this origin. So importing a site from a zip is off unless
     * the operator turns it on: config security.siteZipImport = true, or the
     * environment variable HAXCMS_SITE_ZIP_IMPORT=true. haxcms-browser turns
     * it on, because there HAXcms runs in the person's own tab.
     * @return bool
     */
    public static function enabled()
    {
        if (getenv('HAXCMS_SITE_ZIP_IMPORT') === 'true') {
            return true;
        }
        global $HAXCMS;
        return isset($HAXCMS) && is_object($HAXCMS)
            && isset($HAXCMS->config) && is_object($HAXCMS->config)
            && isset($HAXCMS->config->security) && is_object($HAXCMS->config->security)
            && isset($HAXCMS->config->security->siteZipImport)
            && $HAXCMS->config->security->siteZipImport === true;
    }

    /**
     * The conversion upload cap in bytes (haxcms-nodejs
     * getConversionUploadMaxBytes): config security.conversionMaxUploadMb or
     * HAXCMS_CONVERSION_MAX_UPLOAD_MB, default 50 MB, at most 1024 MB.
     * @return int
     */
    public static function maxUploadBytes()
    {
        $mb = self::DEFAULT_CONVERSION_MAX_UPLOAD_MB;
        $candidate = null;
        global $HAXCMS;
        if (isset($HAXCMS) && is_object($HAXCMS) && isset($HAXCMS->config) && is_object($HAXCMS->config)
            && isset($HAXCMS->config->security) && is_object($HAXCMS->config->security)
            && isset($HAXCMS->config->security->conversionMaxUploadMb)) {
            $candidate = $HAXCMS->config->security->conversionMaxUploadMb;
        }
        $env = getenv('HAXCMS_CONVERSION_MAX_UPLOAD_MB');
        if (is_string($env) && trim($env) !== '') {
            $candidate = trim($env);
        }
        if ($candidate !== null && is_numeric($candidate) && (float) $candidate > 0) {
            $mb = min((float) $candidate, self::MAX_CONVERSION_MAX_UPLOAD_MB);
        }
        return (int) floor($mb * 1024 * 1024);
    }

    private static function optional($value, $type)
    {
        if ($value === null) {
            return true;
        }
        if ($type === 'string') {
            return is_string($value);
        }
        return is_int($value) || is_float($value);
    }

    private static function isPlainObject($value)
    {
        return is_array($value) && ($value === array() || array_keys($value) !== range(0, count($value) - 1));
    }

    /**
     * True when site.json is a HAXcms site outline: a list of pages, each
     * with a safe unique id, its HTML under pages/, and any files it names
     * under files/. Anything else is refused whole, before a page or file is
     * read. Parity: haxcms-nodejs isSiteOutline.
     * @param mixed $site decoded site.json (associative arrays)
     * @return bool
     */
    public static function isSiteOutline($site)
    {
        if (!self::isPlainObject($site) || !isset($site['items']) || !is_array($site['items'])
            || count($site['items']) === 0 || count($site['items']) > self::MAX_SITE_ITEMS
            || !self::optional(isset($site['title']) ? $site['title'] : null, 'string')
            || !self::optional(isset($site['description']) ? $site['description'] : null, 'string')
            || (isset($site['metadata']) && !self::isPlainObject($site['metadata']))) {
            return false;
        }
        $ids = array();
        foreach ($site['items'] as $item) {
            if (!self::isPlainObject($item) || !isset($item['id']) || !is_string($item['id'])) {
                return false;
            }
            if (!preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/', $item['id']) || isset($ids[$item['id']])) {
                return false;
            }
            $ids[$item['id']] = true;
            $location = self::siteRelativePath(isset($item['location']) ? $item['location'] : null);
            if (!preg_match('/^pages\/.+\.html$/', $location)) {
                return false;
            }
            foreach (array('title' => 'string', 'slug' => 'string', 'parent' => 'string', 'indent' => 'number', 'order' => 'number') as $key => $type) {
                if (array_key_exists($key, $item) && !self::optional($item[$key], $type)) {
                    return false;
                }
            }
            if (array_key_exists('metadata', $item) && !self::isPlainObject($item['metadata'])) {
                return false;
            }
            if (isset($item['metadata']) && array_key_exists('files', $item['metadata'])) {
                $files = $item['metadata']['files'];
                if (!is_array($files)) {
                    return false;
                }
                foreach ($files as $file) {
                    if (!self::isPlainObject($file) || !isset($file['url'])
                        || !preg_match('/^files\/./', self::siteRelativePath($file['url']))) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * A relative path from site.json that stays inside the site folder, or ''.
     * @param mixed $value
     * @return string
     */
    public static function siteRelativePath($value)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 1024) {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value) || strpos($value, '\\') !== false) {
            return '';
        }
        $cleaned = explode('#', explode('?', $value)[0])[0];
        $cleaned = preg_replace('/^\.\//', '', $cleaned);
        if ($cleaned === '' || $cleaned[0] === '/') {
            return '';
        }
        $parts = array();
        foreach (explode('/', $cleaned) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (count($parts) === 0) {
                    return '';
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /**
     * The folder in the zip that holds site.json: '' at the top, 'name/' one
     * folder down, or null when there is none.
     * @param \ZipArchive $zip
     * @return string|null
     */
    private static function siteRoot($zip)
    {
        if ($zip->locateName('site.json') !== false) {
            return '';
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $parts = explode('/', (string) $name);
            if (count($parts) === 2 && $parts[1] === 'site.json' && $parts[0] !== '') {
                return $parts[0] . '/';
            }
        }
        return null;
    }

    /**
     * @param string $path the uploaded file
     * @return array{items: array, files: array, siteFiles: array, filename: string, site: array}
     * @throws HAXCMSSiteZipException
     */
    public static function read($path)
    {
        if (!is_string($path) || $path === '' || !BoundedZip::hasZipSignature($path)) {
            throw new HAXCMSSiteZipException('Uploaded file is not a valid .zip file.', 'NOT_A_ZIP');
        }
        try {
            BoundedZip::assertWithinLimits($path, 'zip');
        } catch (BoundedZipException $e) {
            throw new HAXCMSSiteZipException(
                $e->getMessage(),
                $e->reason === 'ZIP_BUDGET' ? 'ZIP_BUDGET' : 'NOT_A_ZIP'
            );
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new HAXCMSSiteZipException('Uploaded file is not a valid .zip file.', 'NOT_A_ZIP');
        }
        $files = array();
        $siteFiles = array();
        try {
            $root = self::siteRoot($zip);
            if ($root === null) {
                throw new HAXCMSSiteZipException('Invalid site.json structure', 'INVALID_SITE');
            }
            $siteJson = json_decode((string) $zip->getFromName($root . 'site.json'), true);
            if (!self::isSiteOutline($siteJson)) {
                throw new HAXCMSSiteZipException('Invalid site.json structure', 'INVALID_SITE');
            }
            $read = function ($relative) use ($zip, $root) {
                $safe = HAXCMSSiteZip::siteRelativePath($relative);
                if ($safe === '') {
                    return null;
                }
                $data = $zip->getFromName($root . $safe);
                return $data === false ? null : $data;
            };
            foreach ($siteJson['items'] as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['location']) && $item['location'] !== '') {
                    $page = $read($item['location']);
                    $siteJson['items'][$i]['contents'] = $page === null ? '' : $page;
                }
                if (isset($item['metadata']['files']) && is_array($item['metadata']['files'])) {
                    foreach ($item['metadata']['files'] as $fileEntry) {
                        if (!is_array($fileEntry) || !isset($fileEntry['url']) || !is_string($fileEntry['url'])) {
                            continue;
                        }
                        $url = $fileEntry['url'];
                        if (isset($files[$url])) {
                            continue;
                        }
                        $bytes = $read($url);
                        if ($bytes === null) {
                            continue;
                        }
                        $staged = haxcms_import_stage_bytes($bytes, $url);
                        if ($staged !== false) {
                            $files[$url] = $staged;
                        }
                    }
                }
            }
            // the site's own code (a custom theme): carried as a staged file
            // that createSite writes to custom/build/custom.es6.js
            $code = $zip->getFromName($root . self::CUSTOM_BUILD);
            if (is_string($code)) {
                $text = trim($code);
                if ($text !== '' && $text !== self::BOILERPLATE_CUSTOM_ES6) {
                    $staged = haxcms_import_stage_bytes($code, 'custom.es6.js');
                    if ($staged !== false) {
                        $siteFiles[self::CUSTOM_BUILD] = $staged;
                    }
                }
            }
        } catch (\Throwable $e) {
            haxcms_import_unlink_staged_files(array_values($files));
            throw $e;
        } finally {
            $zip->close();
        }

        $metadata = isset($siteJson['metadata']) && is_array($siteJson['metadata']) ? $siteJson['metadata'] : array();
        $siteMeta = isset($metadata['site']) && is_array($metadata['site']) ? $metadata['site'] : array();
        $theme = (isset($metadata['theme']) && is_array($metadata['theme'])
            && isset($metadata['theme']['element']) && is_string($metadata['theme']['element']))
            ? $metadata['theme']['element']
            : null;
        $title = isset($siteJson['title']) && is_string($siteJson['title']) ? $siteJson['title'] : '';
        if (isset($siteMeta['name']) && is_string($siteMeta['name']) && $siteMeta['name'] !== '') {
            $filename = $siteMeta['name'];
        } elseif ($title !== '') {
            $filename = $title;
        } else {
            $filename = rtrim($root, '/') !== '' ? rtrim($root, '/') : 'imported-site';
        }
        return array(
            'items'    => $siteJson['items'],
            'files'    => $files,
            'siteFiles' => $siteFiles,
            'filename' => $filename,
            'site'     => array(
                'title'       => $title,
                'description' => isset($siteJson['description']) && is_string($siteJson['description']) ? $siteJson['description'] : '',
                'theme'       => $theme,
            ),
        );
    }
}
