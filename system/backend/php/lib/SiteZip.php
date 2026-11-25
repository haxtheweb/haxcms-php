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
     * @return array{items: array, files: array, filename: string, site: array}
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
        try {
            $root = self::siteRoot($zip);
            if ($root === null) {
                throw new HAXCMSSiteZipException('Invalid site.json structure', 'INVALID_SITE');
            }
            $siteJson = json_decode((string) $zip->getFromName($root . 'site.json'), true);
            if (!is_array($siteJson) && json_last_error() !== JSON_ERROR_NONE) {
                throw new HAXCMSSiteZipException('Invalid site.json structure', 'INVALID_SITE');
            }
            if (!is_array($siteJson) || !isset($siteJson['items']) || !is_array($siteJson['items'])) {
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
            'filename' => $filename,
            'site'     => array(
                'title'       => $title,
                'description' => isset($siteJson['description']) && is_string($siteJson['description']) ? $siteJson['description'] : '',
                'theme'       => $theme,
            ),
        );
    }
}
