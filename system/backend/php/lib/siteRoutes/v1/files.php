<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
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
    // D45: GET v1/files/:fileUuid detail handler. Mirrors Node files.js
    // fileDetail (files.js:1077-1116) + resolveRequestedFilePath: validates
    // the UUID format, collects all site files, computes each file's
    // deterministic UUID, and returns the matching record (or 404). This
    // reuses collectSiteFiles so the security boundary (files within the
    // site's files/ directory only) is identical to the list endpoint.
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
        $detailFiles = SiteRouteUtils::collectSiteFiles($site, $siteFilePath, '');
        $matchingRecord = null;
        foreach ($detailFiles as $detailFile) {
            $candidateRecord = haxcmsBuildFileRecord($site, $detailFile);
            if (strtolower((string) $candidateRecord['uuid']) === $fileUuid) {
                $matchingRecord = $candidateRecord;
                break;
            }
        }
        if ($matchingRecord === null) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Requested file was not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
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
    $files = SiteRouteUtils::collectSiteFiles($site, $siteFilePath, SiteRouteUtils::getQueryValue('filename', ''));
    $records = array();
    foreach ($files as $file) {
        $records[] = haxcmsBuildFileRecord($site, $file);
    }
    $filterType = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.type', '')));
    $filterExtension = strtolower(ltrim(trim((string) SiteRouteUtils::getQueryValue('filter.extension', '')), '.'));
    $filterStartsWith = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.startsWith', '')));
    $filterNameContains = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.nameContains', '')));
    $records = array_values(array_filter($records, function ($record) use ($filterType, $filterExtension, $filterStartsWith, $filterNameContains) {
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
        return true;
    }));
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'path');
    $paged = SiteRouteUtils::paginateRecords($records, 25, 500);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], SiteRouteUtils::getCsvQuery('fields'));
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'files' => $outputRecords,
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
