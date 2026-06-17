<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    if (isset($context->body) && is_array($context->body)) {
        $operations->params = $context->body;
        $operations->rawParams = $context->body;
    }
    if (isset($context->params) && is_array($context->params)) {
        $operations->params = array_merge($operations->params, $context->params);
    }
    unset($operations->params['jwt']);
    unset($operations->params['user_token']);
    unset($operations->params['site_token']);
    unset($operations->rawParams['jwt']);
    unset($operations->rawParams['user_token']);
    unset($operations->rawParams['site_token']);
    $activeUser = $GLOBALS['HAXCMS']->getActiveUserName();
    $operations->params['user_token'] = $GLOBALS['HAXCMS']->getRequestToken($activeUser);
    $operations->rawParams['user_token'] = $operations->params['user_token'];
    $response = $operations->haxiamAddUserAccess();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    if (is_array($response) && isset($response['__failed'])) {
        http_response_code($response['__failed']['status']);
        print json_encode($response['__failed']);
    } else {
        print json_encode($response);
    }
};
