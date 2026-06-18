<?php
include_once dirname(__FILE__) . '/../../routes/RoutesMap.php';
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
if (!function_exists('haxcmsSystemSettingsInvokeAsPost')) {
    function haxcmsSystemSettingsInvokeAsPost($operationCallback)
    {
        $savedMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = call_user_func($operationCallback);
        if ($savedMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        }
        else {
            $_SERVER['REQUEST_METHOD'] = $savedMethod;
        }
        return $response;
    }
}
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    $queryParams = array();
    if (
        isset($GLOBALS['HAXCMS']) &&
        is_object($GLOBALS['HAXCMS']) &&
        isset($GLOBALS['HAXCMS']->safeGet) &&
        is_array($GLOBALS['HAXCMS']->safeGet)
    ) {
        $queryParams = $GLOBALS['HAXCMS']->safeGet;
    }
    else if (is_array($_GET)) {
        $queryParams = $_GET;
    }
    if (count($queryParams) > 0) {
        $operations->params = array_merge($operations->params, $queryParams);
        $operations->rawParams = array_merge($operations->rawParams, $queryParams);
    }
    if (is_array($context->body)) {
        $operations->params = array_merge($operations->params, $context->body);
        $operations->rawParams = array_merge($operations->rawParams, $context->body);
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
    $route = $context->routeSuffix;
    $method = $context->method;
    $response = null;
    if ($route === 'v1/status') {
        $savedMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = $operations->systemStatus();
        $_SERVER['REQUEST_METHOD'] = $savedMethod;
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
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'getApiKeys')
            );
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveApiKeys')
            );
        }
    }
    else if ($route === 'v1/configuration/media') {
        if ($method === 'GET' || $method === 'POST') {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'getMediaSettings')
            );
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveMediaSettings')
            );
        }
    }
    else if ($route === 'v1/configuration/schema-files/operations') {
        $response = haxcmsSystemSettingsInvokeAsPost(
            array($operations, 'schemaFileOperation')
        );
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
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'schemaFileOperation')
            );
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveEnabledSkeletons')
            );
        }
    }
    else if (
        $route === 'v1/skeletons/:skeletonName' ||
        preg_match('/^v1\\/skeletons\\/[^\\/]+$/', $route) === 1
    ) {
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
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveEnabledThemes')
            );
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
    // If the response already has a top-level status key (legacy v1 route shape),
    // emit it as-is to avoid double-wrapping. Otherwise wrap in a data envelope.
    if (!is_array($response) || !isset($response['status'])) {
        $response = array('status' => 200, 'data' => $response);
    }
    // Prevent double-wrapping: if response already has status and data, assume it is already enveloped
    else if (isset($response['data']) && !isset($response['__failed']) && !isset($response['__noencode'])) {
        // response is already enveloped (e.g. from getApiKeys, getMediaSettings), keep as-is
    }
    else {
        // response has status but no data (e.g. systemBlocksList, themesList, skeletonsList) —
        // NodeJS v1 returns flat shape, so wrap its body as data to keep the envelope consistent
        $response = array('status' => $response['status'], 'data' => $response);
        // Remove the original status from the nested data so it isn't duplicated
        if (isset($response['data']['status'])) {
            unset($response['data']['status']);
        }
    }
    SiteRouteUtils::sendFormattedResponse(
        $response,
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};