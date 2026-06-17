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
    $route = $context->routeSuffix;
    $method = $context->method;
    $response = null;
    if ($route === 'v1/session/login') {
        $response = $operations->login();
        // If login returns a flat {status, jwt} envelope, emit it directly
        if (is_array($response) && isset($response['status']) && isset($response['jwt']) && !isset($response['__failed'])) {
            header('Content-Type: application/json');
            print json_encode($response);
            exit;
        }
    }
    else if ($route === 'v1/session') {
        if ($method === 'GET') {
            $response = array('status' => 200, 'data' => $GLOBALS['HAXCMS']->getJWT());
        }
        else {
            $response = array('status' => 200, 'data' => $GLOBALS['HAXCMS']->getJWT());
        }
    }
    else if ($route === 'v1/session/logout') {
        $response = $operations->logout();
    }
    else if ($route === 'v1/session/refresh') {
        $response = $operations->refreshAccessToken();
    }
    else if ($route === 'v1/session/connection-settings') {
        $response = $operations->connectionSettings();
        // connectionSettings returns __noencode with JS content; emit it directly
        if (is_array($response) && isset($response['__noencode'])) {
            $contentType = isset($response['__noencode']['contentType']) ? $response['__noencode']['contentType'] : 'application/javascript';
            header('Content-Type: ' . $contentType);
            print $response['__noencode']['message'];
            exit;
        }
    }
    else if ($route === 'v1/session/connection-test') {
        $response = $operations->connectionTest();
        // If the operation returns a flat jwt/token structure, emit it directly
        if (is_array($response) && isset($response['jwt']) && !isset($response['__failed'])) {
            header('Content-Type: application/json');
            print json_encode($response);
            exit;
        }
    }
    else if ($route === 'v1/session/user') {
        $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
        $userToken = $GLOBALS['HAXCMS']->getRequestToken($tokenUser);
        $operations->params['user_token'] = $userToken;
        $operations->rawParams['user_token'] = $userToken;
        $response = $operations->getUserData();
    }
    else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unknown session route'),
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
    if (is_array($response) && isset($response['__noencode'])) {
        $response = array('status' => $response['__noencode']['status'], 'data' => $response['__noencode']['message']);
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