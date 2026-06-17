<?php
include_once dirname(__FILE__) . '/../../Operations.php';
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
if (!function_exists('haxcmsResolveRequestedFilePathFromUuid')) {
    function haxcmsResolveRequestedFilePathFromUuid($context, $fileUuid = '')
    {
        $rawToken = trim(rawurldecode((string) $fileUuid));
        if ($rawToken == '') {
            return '';
        }
        $site = isset($context->site) ? $context->site : null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawToken) === 1) {
            $siteDirectory = SiteRouteUtils::getSiteDirectory($site);
            if ($siteDirectory == '') {
                return '';
            }
            $siteFilePath = $siteDirectory . '/files';
            $files = SiteRouteUtils::collectSiteFiles($site, $siteFilePath, '');
            foreach ($files as $file) {
                $relativePath = isset($file['relativePath']) ? (string) $file['relativePath'] : '';
                if ($relativePath == '') {
                    continue;
                }
                $apiPath = 'files/' . $relativePath;
                $candidateUuid = haxcmsSiteDeterministicFileUuid(
                    $site,
                    $apiPath,
                    isset($file['stats']['size']) ? intval($file['stats']['size']) : 0
                );
                if ($candidateUuid != '' && strcasecmp($candidateUuid, $rawToken) === 0) {
                    return $apiPath;
                }
            }
            return '';
        }
        return haxcmsSiteFileCanonicalPath($rawToken);
    }
}
return function ($context) {
    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }
    unset($body['jwt']);
    unset($body['user_token']);
    unset($body['site_token']);
    $siteName = '';
    if (
        isset($context->site) &&
        isset($context->site->manifest) &&
        isset($context->site->manifest->metadata) &&
        isset($context->site->manifest->metadata->site) &&
        isset($context->site->manifest->metadata->site->name)
    ) {
        $siteName = (string) $context->site->manifest->metadata->site->name;
    }
    $fileUuid = $context->getParam('fileUuid', '');
    $method = strtoupper((string) $context->method);
    $operations = new Operations();
    $result = null;
    if ($method === 'POST') {
        if (!isset($body['site']) || !is_array($body['site'])) {
            $body['site'] = array();
        }
        if (!isset($body['site']['name']) || $body['site']['name'] === '') {
            $body['site']['name'] = $siteName;
        }
        $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
        if (!is_string($siteToken)) {
            $siteToken = '';
        }
        $body['site_token'] = $siteToken;
        if (isset($body['nodeId']) && (!isset($body['node']) || !isset($body['node']['id']))) {
            if (!isset($body['node']) || !is_array($body['node'])) {
                $body['node'] = array();
            }
            $body['node']['id'] = $body['nodeId'];
        }
        $operations->params = $body;
        $operations->rawParams = array_merge($body, $_FILES);
        $result = $operations->saveFile();
    } else if ($method === 'PATCH' || $method === 'DELETE') {
        if (!isset($body['siteName']) || $body['siteName'] === '') {
            $body['siteName'] = $siteName;
        }
        $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
        if (!is_string($siteToken)) {
            $siteToken = '';
        }
        $body['site_token'] = $siteToken;
        if ($method === 'DELETE') {
            if (!isset($body['operation']) || $body['operation'] === '') {
                $body['operation'] = 'delete';
            }
        }
        if (!isset($body['path']) || $body['path'] === '') {
            if ($fileUuid !== '') {
                $body['path'] = haxcmsResolveRequestedFilePathFromUuid($context, $fileUuid);
            }
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->fileOperation();
    } else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unsupported method for /v1/files'),
            array('statusCode' => 405, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    if (is_array($result) && isset($result['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => intval($result['__failed']['status']),
                'message' => $result['__failed']['message'],
            ),
            array(
                'statusCode' => intval($result['__failed']['status']),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    if (is_array($result) && isset($result['status']) && isset($result['data'])) {
        SiteRouteUtils::sendFormattedResponse(
            $result,
            array(
                'statusCode' => 200,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => $result),
        array(
            'statusCode' => 200,
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
            'envelope' => false,
        ),
        $context->routeSuffix,
        $context->apiBasePath
    );
};