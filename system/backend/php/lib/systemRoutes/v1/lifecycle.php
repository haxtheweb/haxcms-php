<?php
include_once dirname(__FILE__) . '/../../routes/RoutesMap.php';
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
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
    $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
    if (!is_string($tokenUser) || $tokenUser === '') {
        $tokenUser = $GLOBALS['HAXCMS']->getActiveUserName();
    }
    $userToken = $GLOBALS['HAXCMS']->getRequestToken($tokenUser);
    $operations->params['user_token'] = $userToken;
    $operations->rawParams['user_token'] = $userToken;
    if (isset($context->params['siteName'])) {
        $operations->params['site'] = array('name' => $context->params['siteName']);
        $operations->rawParams['site'] = array('name' => $context->params['siteName']);
        if ($context->method === 'POST' && is_array($context->body)) {
            $operations->params['site'] = array_merge($operations->params['site'], $context->body);
            $operations->rawParams['site'] = array_merge($operations->rawParams['site'], $context->body);
        }
    }
    $route = $context->routeSuffix;
    $method = $context->method;
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
            }
        }
    }
    else if (
        $route === 'v1/sites/:siteName' ||
        preg_match('/^v1\\/sites\\/[^\\/]+$/', $route) === 1
    ) {
        if ($method === 'GET' || $method === 'POST') {
            $response = array('status' => 200, 'data' => array('siteName' => $context->params['siteName']));
        }
        else {
            $response = array('status' => 405, 'data' => 'Method not allowed');
        }
    }
    else if (
        $route === 'v1/sites/:siteName/clone' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/clone$/', $route) === 1
    ) {
        $response = $operations->cloneSite();
    }
    else if (
        $route === 'v1/sites/:siteName/archive' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/archive$/', $route) === 1
    ) {
        $response = $operations->archiveSite();
    }
    else if (
        $route === 'v1/sites/:siteName/download' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/download$/', $route) === 1
    ) {
        $response = $operations->downloadSite();
    }
    else if (
        $route === 'v1/sites/:siteName/download-skeleton' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/download-skeleton$/', $route) === 1
    ) {
        $response = $operations->downloadSiteSkeleton();
    }
    else if (
        $route === 'v1/sites/:siteName/save-as-template' ||
        preg_match('/^v1\\/sites\\/[^\\/]+\\/save-as-template$/', $route) === 1
    ) {
        $response = $operations->saveSiteAsTemplate();
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
