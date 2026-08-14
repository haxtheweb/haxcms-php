<?php
include_once dirname(__FILE__) . '/../../operations/OperationsMethodMap.php';
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../SystemApiSecurity.php';
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    if (is_array($context->body)) {
        $operations->params = $context->body;
        $operations->rawParams = $context->body;
    }
    unset($operations->params['jwt']);
    unset($operations->params['user_token']);
    unset($operations->params['site_token']);
    unset($operations->rawParams['jwt']);
    unset($operations->rawParams['user_token']);
    unset($operations->rawParams['site_token']);
    $route = $context->routeSuffix;
    $method = $context->method;
    $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
    if (!is_string($tokenUser) || $tokenUser === '') {
        $tokenUser = $GLOBALS['HAXCMS']->getActiveUserName();
    }
    $serverUserToken = $GLOBALS['HAXCMS']->getRequestToken($tokenUser);
    $headerUserToken = '';
    if (isset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']) && is_string($_SERVER['HTTP_X_HAXCMS_USER_TOKEN'])) {
        $headerUserToken = $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'];
    }
    // D10/D59 + system-read userToken directive: canonical system READ routes
    // (listSites, siteInfoGet/Post) feed the client X-HAXCMS-User-Token header
    // into params['user_token'] so the handler validateRequestToken check
    // validates the actual client header. Site lifecycle writes keep the
    // server-resolved token (writes already enforce userToken from the last
    // pass and are not changed here).
    $userToken = SystemApiSecurity::isSystemReadUserTokenRoute($route, $method)
        ? $headerUserToken
        : $serverUserToken;
    $operations->params['user_token'] = $userToken;
    $operations->rawParams['user_token'] = $userToken;
    // X-HAXCMS-User-Token enforcement is now handled centrally by
    // SystemApiRouter::dispatch() via the spec-derived policy map
    // (getSystemUserTokenPolicyMap), which covers every route declaring
    // userTokenHeader in system-spec.yaml. The token selection logic above
    // still feeds the client header (or server token for write routes) into
    // params['user_token'] for the handlers' internal validateRequestToken
    // checks.
    if (isset($context->params['siteName'])) {
        $operations->params['site'] = array('name' => $context->params['siteName']);
        $operations->rawParams['site'] = array('name' => $context->params['siteName']);
        if ($context->method === 'POST' && is_array($context->body)) {
            $operations->params['site'] = array_merge($operations->params['site'], $context->body);
            $operations->rawParams['site'] = array_merge($operations->rawParams['site'], $context->body);
        }
    }
    $response = null;
    if ($route === 'v1/sites') {
        if ($method === 'GET') {
            $response = $operations->listSites();
        }
        else {
            $response = $operations->createSite();
            if (
                is_array($response) &&
                isset($response['data']) &&
                is_object($response['data'])
            ) {
                $createdSite = $response['data'];
                if (
                    (!isset($createdSite->slug) || !is_string($createdSite->slug) || $createdSite->slug === '') &&
                    isset($createdSite->location) &&
                    is_string($createdSite->location) &&
                    $createdSite->location !== ''
                ) {
                    $createdSite->slug = $createdSite->location;
                }
                if (
                    (!isset($createdSite->slug) || !is_string($createdSite->slug) || $createdSite->slug === '') &&
                    isset($createdSite->metadata) &&
                    is_object($createdSite->metadata) &&
                    isset($createdSite->metadata->site) &&
                    is_object($createdSite->metadata->site) &&
                    isset($createdSite->metadata->site->name) &&
                    is_string($createdSite->metadata->site->name) &&
                    $createdSite->metadata->site->name !== ''
                ) {
                    $createdSite->slug =
                        $GLOBALS['HAXCMS']->basePath .
                        trim((string) $GLOBALS['HAXCMS']->sitesDirectory, '/') .
                        '/' .
                        $createdSite->metadata->site->name .
                        '/';
                }
                if (!isset($response['id']) && isset($createdSite->id)) {
                    $response['id'] = $createdSite->id;
                }
                if (
                    !isset($response['slug']) &&
                    isset($createdSite->slug) &&
                    is_string($createdSite->slug) &&
                    $createdSite->slug !== ''
                ) {
                    $response['slug'] = $createdSite->slug;
                }
                if (
                    !isset($response['link']) &&
                    isset($createdSite->metadata) &&
                    is_object($createdSite->metadata) &&
                    isset($createdSite->metadata->site) &&
                    is_object($createdSite->metadata->site) &&
                    isset($createdSite->metadata->site->name) &&
                    is_string($createdSite->metadata->site->name) &&
                    $createdSite->metadata->site->name !== ''
                ) {
                    $response['link'] =
                        $GLOBALS['HAXCMS']->basePath .
                        $GLOBALS['HAXCMS']->sitesDirectory .
                        '/' .
                        $createdSite->metadata->site->name .
                        '/';
                }
            }
        }
    }
    else if (
        $route === 'v1/sites/:siteName' ||
        preg_match('/^v1\\/sites\\/[^\\/]+$/', $route) === 1
    ) {
        if ($method === 'GET' || $method === 'POST') {
            $siteName = $context->params['siteName'];
            $loadedSite = $GLOBALS['HAXCMS']->loadSite($siteName);
            if (!$loadedSite || !isset($loadedSite->manifest)) {
                $response = array(
                    '__failed' => array(
                        'status' => 404,
                        'message' => 'Site not found',
                    )
                );
            }
            else {
                $manifest = $loadedSite->manifest;
                $encodedSiteName = rawurlencode($siteName);
                $basePath = isset($GLOBALS['HAXCMS']->basePath) ? $GLOBALS['HAXCMS']->basePath : '/';
                $systemBase = isset($GLOBALS['HAXCMS']->systemRequestBase) ? $GLOBALS['HAXCMS']->systemRequestBase : 'system/api/';
                $normalizePath = function ($pathValue) {
                    $normalized = ($pathValue === null) ? '' : (string)$pathValue;
                    if ($normalized === '') {
                        return '/';
                    }
                    $normalized = preg_replace('#/+#', '/', $normalized);
                    if (strlen($normalized) > 0 && $normalized[0] !== '/') {
                        $normalized = '/' . $normalized;
                    }
                    if (strlen($normalized) > 1 && substr($normalized, -1) === '/') {
                        $normalized = substr($normalized, 0, -1);
                    }
                    return $normalized;
                };
                $systemApiBase = $normalizePath($basePath . '/' . $systemBase . 'v1');
                $sitesDirectory = isset($GLOBALS['HAXCMS']->sitesDirectory) ? $GLOBALS['HAXCMS']->sitesDirectory : '_sites';
                $sitesDirectory = trim($sitesDirectory, '/');
                $normalizedBasePath = $normalizePath($basePath);
                $links = array(
                    'self' => $systemApiBase . '/sites/' . $encodedSiteName,
                    'clone' => $systemApiBase . '/sites/' . $encodedSiteName . '/clone',
                    'archive' => $systemApiBase . '/sites/' . $encodedSiteName . '/archive',
                    'download' => $systemApiBase . '/sites/' . $encodedSiteName . '/download',
                    'downloadSkeleton' => $systemApiBase . '/sites/' . $encodedSiteName . '/download-skeleton',
                    'saveAsTemplate' => $systemApiBase . '/sites/' . $encodedSiteName . '/save-as-template',
                    'siteApi' => $normalizedBasePath . '/' . $sitesDirectory . '/' . $encodedSiteName . '/x/api',
                );
                $siteMetadata = isset($manifest->metadata->site) ? $manifest->metadata->site : null;
                $pageCount = 0;
                if (isset($manifest->items)) {
                    $pageCount = count($manifest->items);
                }
                $toIsoDate = function ($value) {
                    if (!is_numeric($value) || (int)$value <= 0) {
                        return null;
                    }
                    return gmdate('Y-m-d\TH:i:s.000\Z', (int)$value);
                };
                $location = $normalizedBasePath . '/' . $sitesDirectory . '/' . $encodedSiteName . '/';
                $response = array(
                    'status' => 200,
                    'data' => array(
                        'id' => isset($manifest->id) ? $manifest->id : null,
                        'name' => $siteName,
                        'title' => isset($manifest->title) ? $manifest->title : $siteName,
                        'description' => isset($manifest->description) ? $manifest->description : '',
                        'location' => $location,
                        'metadata' => array(
                            'pageCount' => $pageCount,
                            'created' => ($siteMetadata && isset($siteMetadata->created)) ? $toIsoDate($siteMetadata->created) : null,
                            'updated' => ($siteMetadata && isset($siteMetadata->updated)) ? $toIsoDate($siteMetadata->updated) : null,
                        ),
                        'links' => $links,
                    ),
                );
            }
        }
        else {
            $response = array('status' => 405, 'data' => 'Method not allowed');
        }
    }
    else if (
        $route === 'v1/sites/:siteName/clone' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/clone$/', $route) === 1
    ) {
        // security (F2/IDOR-001): object-level authorization before clone
        if (!isset($context->params['siteName']) || !$GLOBALS['HAXCMS']->userCanAccessSite($context->params['siteName'])) {
            $response = array('__failed' => array('status' => 403, 'message' => 'Access denied to site'));
        }
        else {
            $response = $operations->cloneSite();
        }
    }
    else if (
        $route === 'v1/sites/:siteName/archive' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/archive$/', $route) === 1
    ) {
        // security (F2/IDOR-001): object-level authorization before archive
        if (!isset($context->params['siteName']) || !$GLOBALS['HAXCMS']->userCanAccessSite($context->params['siteName'])) {
            $response = array('__failed' => array('status' => 403, 'message' => 'Access denied to site'));
        }
        else {
            $response = $operations->archiveSite();
        }
    }
    else if (
        $route === 'v1/sites/:siteName/download' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/download$/', $route) === 1
    ) {
        // security (F2/IDOR-001): object-level authorization before download
        if (!isset($context->params['siteName']) || !$GLOBALS['HAXCMS']->userCanAccessSite($context->params['siteName'])) {
            $response = array('__failed' => array('status' => 403, 'message' => 'Access denied to site'));
        }
        else {
            $response = $operations->downloadSite();
        }
    }
    else if (
        $route === 'v1/sites/:siteName/download-skeleton' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/download-skeleton$/', $route) === 1
    ) {
        // security (F2/IDOR-001): object-level authorization before download-skeleton
        if (!isset($context->params['siteName']) || !$GLOBALS['HAXCMS']->userCanAccessSite($context->params['siteName'])) {
            $response = array('__failed' => array('status' => 403, 'message' => 'Access denied to site'));
        }
        else {
            $response = $operations->downloadSiteSkeleton();
        }
    }
    else if (
        $route === 'v1/sites/:siteName/save-as-template' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/save-as-template$/', $route) === 1
    ) {
        // security (F2/IDOR-001): object-level authorization before save-as-template
        if (!isset($context->params['siteName']) || !$GLOBALS['HAXCMS']->userCanAccessSite($context->params['siteName'])) {
            $response = array('__failed' => array('status' => 403, 'message' => 'Access denied to site'));
        }
        else {
            $response = $operations->saveSiteAsTemplate();
        }
    }
    else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unknown lifecycle route'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (is_array($response) && isset($response['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => isset($response['__failed']['message']) ? $response['__failed']['message'] : 'Request failed'),
            array('statusCode' => isset($response['__failed']['status']) ? $response['__failed']['status'] : 500, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (!is_array($response) || !isset($response['status'])) {
        $response = array('status' => 200, 'data' => $response);
    }
    SiteRouteUtils::sendFormattedResponse(
        $response,
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
