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
    $activeUser = $GLOBALS['HAXCMS']->getActiveUserName();
    $userToken = $GLOBALS['HAXCMS']->getRequestToken($activeUser);
    $operations->params['user_token'] = $userToken;
    $operations->rawParams['user_token'] = $userToken;
    $route = $context->routeSuffix;
    $method = $context->method;
    $response = null;
    if ($route === 'v1/status') {
        $response = $operations->systemStatus();
    }
    else if ($route === 'v1/system/version') {
        $response = array(
            'status' => 200,
            'data' => array(
                'version' => $GLOBALS['HAXCMS']->getHAXCMSVersion(),
            ),
        );
    }
    else if ($route === 'v1/entities') {
        $response = array(
            'status' => 200,
            'data' => array(
                'entities' => array(
                    array(
                        'name' => 'site',
                        'endpoints' => array($apiBasePath . '/v1/sites'),
                    ),
                    array(
                        'name' => 'user',
                        'endpoints' => array($apiBasePath . '/v1/session'),
                    ),
                    array(
                        'name' => 'block',
                        'endpoints' => array($apiBasePath . '/v1/blocks'),
                    ),
                    array(
                        'name' => 'theme',
                        'endpoints' => array($apiBasePath . '/v1/themes'),
                    ),
                    array(
                        'name' => 'skeleton',
                        'endpoints' => array($apiBasePath . '/v1/skeletons'),
                    ),
                ),
            ),
        );
    }
    else if ($route === 'v1/schemas') {
        $response = array(
            'status' => 200,
            'data' => array(
                'schemas' => array(
                    array(
                        'id' => 'json-outline-schema',
                        'title' => 'JSON Outline Schema',
                    ),
                    array(
                        'id' => 'hax-properties',
                        'title' => 'HAX Properties',
                    ),
                    array(
                        'id' => 'hax-schema',
                        'title' => 'HAX Schema',
                    ),
                ),
            ),
        );
    }
    else if ($route === 'v1/configuration/api-keys') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->getApiKeys();
        }
        else {
            $response = $operations->saveApiKeys();
        }
    }
    else if ($route === 'v1/configuration/media') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->getMediaSettings();
        }
        else {
            $response = $operations->saveMediaSettings();
        }
    }
    else if ($route === 'v1/configuration/schema-files/operations') {
        $response = $operations->schemaFileOperation();
    }
    else if ($route === 'v1/blocks') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->systemBlocksList();
        }
        else {
            $response = $operations->saveEnabledBlocks();
        }
    }
    else if ($route === 'v1/skeletons') {
        if ($method === 'GET') {
            $response = $operations->skeletonsList();
        }
        else if ($method === 'POST') {
            $response = $operations->schemaFileOperation();
        }
        else {
            $response = $operations->saveEnabledSkeletons();
        }
    }
    else if ($route === 'v1/skeletons/:skeletonName') {
        if ($method === 'GET') {
            $operations->params['name'] = $context->params['skeletonName'];
            $operations->rawParams['name'] = $context->params['skeletonName'];
            $response = $operations->getSkeleton();
        }
        else if ($method === 'PATCH' || $method === 'PUT') {
            $operations->params['name'] = $context->params['skeletonName'];
            $operations->rawParams['name'] = $context->params['skeletonName'];
            if (is_array($context->body)) {
                $operations->params = array_merge($operations->params, $context->body);
                $operations->rawParams = array_merge($operations->rawParams, $context->body);
            }
            $response = $operations->schemaFileOperation();
        }
        else if ($method === 'DELETE') {
            $operations->params['name'] = $context->params['skeletonName'];
            $operations->rawParams['name'] = $context->params['skeletonName'];
            if (!isset($operations->params['action'])) {
                $operations->params['action'] = 'delete';
            }
            if (!isset($operations->rawParams['action'])) {
                $operations->rawParams['action'] = 'delete';
            }
            $response = $operations->schemaFileOperation();
        }
        else {
            $response = array('status' => 405, 'data' => 'Method not allowed');
        }
    }
    else if ($route === 'v1/themes') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->themesList();
        }
        else {
            $response = $operations->saveEnabledThemes();
        }
    }
    else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unknown settings route'),
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