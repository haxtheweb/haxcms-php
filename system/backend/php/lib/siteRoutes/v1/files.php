<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../EntityRegistry.php';
include_once dirname(__FILE__) . '/../../FileStorage.php';
include_once dirname(__FILE__) . '/../../FilesDataStore.php';
if (!function_exists('haxcmsSiteFileCanonicalPath')) {
    function haxcmsSiteFileCanonicalPath($relativePath = '')
    {
        $normalizedPath = ltrim(SiteRouteUtils::normalizePathForResponse((string) $relativePath), '/');
        if ($normalizedPath == '') {
            return 'files';
        }
        if (strpos($normalizedPath, 'files/') === 0) {
            return $normalizedPath;
        }
        return 'files/' . $normalizedPath;
    }
}
if (!function_exists('haxcmsSiteFileUuidFromHash')) {
    function haxcmsSiteFileUuidFromHash($hash = '')
    {
        $normalizedHash = strtolower((string) $hash);
        if (strlen($normalizedHash) < 32) {
            return '';
        }
        return
            substr($normalizedHash, 0, 8) . '-' .
            substr($normalizedHash, 8, 4) . '-' .
            substr($normalizedHash, 12, 4) . '-' .
            substr($normalizedHash, 16, 4) . '-' .
            substr($normalizedHash, 20, 12);
    }
}
if (!function_exists('haxcmsSiteFileUuidSiteName')) {
    function haxcmsSiteFileUuidSiteName($site)
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->name) &&
            is_string($site->manifest->metadata->site->name) &&
            $site->manifest->metadata->site->name != ''
        ) {
            return $site->manifest->metadata->site->name;
        }
        if (isset($site) && isset($site->name) && is_string($site->name) && $site->name != '') {
            return $site->name;
        }
        return 'site';
    }
}
if (!function_exists('haxcmsSiteDeterministicFileUuid')) {
    function haxcmsSiteDeterministicFileUuid($site, $relativePath = '', $fileSize = 0)
    {
        $canonicalPath = haxcmsSiteFileCanonicalPath($relativePath);
        $canonicalSize = (is_numeric($fileSize) && intval($fileSize) > 0) ? intval($fileSize) : 0;
        $identityString = haxcmsSiteFileUuidSiteName($site) . ':' . $canonicalPath . ':' . $canonicalSize;
        return haxcmsSiteFileUuidFromHash(hash('sha256', $identityString));
    }
}
if (!function_exists('haxcmsBuildFileRecord')) {
    function haxcmsBuildFileRecord($site, $file)
    {
        $relativePath = isset($file['relativePath']) ? (string) $file['relativePath'] : '';
        $apiPath = 'files/' . $relativePath;
        $baseFileUrl = $apiPath;
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->sitesDirectory) &&
            isset($site->manifest->metadata->site->name)
        ) {
            $baseFileUrl =
                SiteRouteUtils::normalizeBasePath($GLOBALS['HAXCMS']->basePath) .
                $GLOBALS['HAXCMS']->sitesDirectory . '/' .
                $site->manifest->metadata->site->name . '/' .
                $apiPath;
        }
        $dateCreated = 0;
        if (isset($file['stats']) && is_array($file['stats']) && isset($file['stats']['mtime'])) {
            $dateCreated = intval($file['stats']['mtime']);
        }
        $absolutePath = isset($file['absolutePath']) ? $file['absolutePath'] : '';
        $mimetype = '';
        if (function_exists('mime_content_type') && is_file($absolutePath)) {
            $mimetype = (string) @mime_content_type($absolutePath);
        }
        if ($mimetype == '') {
            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            if ($extension == 'jpg' || $extension == 'jpeg') {
                $mimetype = 'image/jpeg';
            }
            else if ($extension == 'png') {
                $mimetype = 'image/png';
            }
            else if ($extension == 'gif') {
                $mimetype = 'image/gif';
            }
            else if ($extension == 'svg') {
                $mimetype = 'image/svg+xml';
            }
            else if ($extension == 'pdf') {
                $mimetype = 'application/pdf';
            }
            else if ($extension == 'md') {
                $mimetype = 'text/markdown';
            }
        }
        return array(
            'path' => $apiPath,
            'fullUrl' => $baseFileUrl . ($dateCreated > 0 ? ((strpos($baseFileUrl, '?') === false ? '?t=' : '&t=') . $dateCreated) : ''),
            'url' => $apiPath,
            'mimetype' => $mimetype,
            'name' => basename($apiPath),
            'uuid' => haxcmsSiteDeterministicFileUuid($site, $apiPath, isset($file['stats']['size']) ? intval($file['stats']['size']) : 0),
            'size' => isset($file['stats']['size']) ? intval($file['stats']['size']) : 0,
            'dateCreated' => $dateCreated,
        );
    }
}
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/files'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $siteDirectory = SiteRouteUtils::getSiteDirectory($site);
    $siteFilePath = $siteDirectory . '/files';
    // #3043: GET v1/files/:fileUuid detail handler. Now resolves via
    // EntityRegistry->getStorage('file')->load($uuid) which is O(1) from the
    // files.json uuid index (stable UUID). This replaces the old O(n)
    // directory walk + n-hash recompute, and is the fix for the sepia 'File
    // not found for fileUuid' bug (the deterministic UUID shifts when file
    // size changes; files.json gives stable persisted UUIDs).
    if (isset($context->params['fileUuid']) && $context->params['fileUuid'] != '') {
        $fileUuid = strtolower(trim((string) $context->params['fileUuid']));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $fileUuid)) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'File uuid is required and must be a valid UUID'),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $registry = new EntityRegistry($site);
        FileStorage::registerOn($registry);
        $entity = $registry->getStorage('file')->load($fileUuid);
        if ($entity === null) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Requested file was not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $matchingRecord = $entity->getFields();
        $detailFields = SiteRouteUtils::getCsvQuery('fields');
        $outputRecord = SiteRouteUtils::projectRecord($matchingRecord, $detailFields);
        SiteRouteUtils::sendFormattedResponse(
            $outputRecord,
            array(
                'allowedFormats' => array('json', 'md', 'yaml', 'xml'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    // #3043: list reads from files.json. Before returning, auto-index any
    // on-disk files missing from the index (reconcileMissingFromDisk) and
    // flag orphans (records whose disk file is gone) into a non-destructive
    // 'orphans' array — files.json is NOT mutated for orphans.
    $dataStore = new FilesDataStore($site);
    $dataStore->reconcileMissingFromDisk();
    $orphans = $dataStore->flagOrphans();
    // Build a set of orphan uuids so we can exclude them from the main files
    // list (orphans go only in the 'orphans' array, non-destructive).
    $orphanUuids = array();
    foreach ($orphans as $orphan) {
        $uuid = isset($orphan['uuid']) ? strtolower((string) $orphan['uuid']) : '';
        if ($uuid !== '') {
            $orphanUuids[$uuid] = true;
        }
    }
    $allRecords = $dataStore->getRecords();
    $records = array();
    foreach ($allRecords as $record) {
        $uuid = isset($record['uuid']) ? strtolower((string) $record['uuid']) : '';
        if ($uuid !== '' && isset($orphanUuids[$uuid])) {
            continue;
        }
        $records[] = $record;
    }
    $filterType = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.type', '')));
    $filterExtension = strtolower(ltrim(trim((string) SiteRouteUtils::getQueryValue('filter.extension', '')), '.'));
    $filterStartsWith = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.startsWith', '')));
    $filterNameContains = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.nameContains', '')));
    $filterFilename = strtolower(trim((string) SiteRouteUtils::getQueryValue('filename', '')));
    $records = array_values(array_filter($records, function ($record) use ($filterType, $filterExtension, $filterStartsWith, $filterNameContains, $filterFilename) {
        $mimetype = strtolower(isset($record['mimetype']) ? (string) $record['mimetype'] : '');
        $name = strtolower(isset($record['name']) ? (string) $record['name'] : '');
        $path = strtolower(isset($record['path']) ? (string) $record['path'] : '');
        if ($filterType != '' && strpos($mimetype, $filterType) !== 0) {
            return false;
        }
        if ($filterExtension != '' && !preg_match('/\.' . preg_quote($filterExtension, '/') . '$/', $name)) {
            return false;
        }
        if ($filterStartsWith != '' && strpos($path, $filterStartsWith) !== 0) {
            return false;
        }
        if ($filterNameContains != '' && strpos($name, $filterNameContains) === false) {
            return false;
        }
        if ($filterFilename != '' && strpos($path, $filterFilename) === false && strpos($name, $filterFilename) === false) {
            return false;
        }
        return true;
    }));
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'path');
    $paged = SiteRouteUtils::paginateRecords($records, 25, 500);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], SiteRouteUtils::getCsvQuery('fields'));
    $outputOrphans = SiteRouteUtils::projectCollection($orphans, SiteRouteUtils::getCsvQuery('fields'));
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'files' => $outputRecords,
            'orphans' => $outputOrphans,
            'links' => array('self' => $apiBasePath . '/v1/files'),
        ),
        array(
            'allowedFormats' => array('json', 'md', 'yaml', 'xml'),
            'defaultFormat' => 'json',
        ),
        $context->routeSuffix,
        $apiBasePath
    );
};
