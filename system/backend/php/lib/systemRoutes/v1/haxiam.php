<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    $body = file_get_contents('php://input');
    if ($body) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $operations->params = $decoded;
            $operations->rawParams = $decoded;
        }
    }
    if (isset($_GET) && is_array($_GET)) {
        $operations->params = array_merge($operations->params, $_GET);
        $operations->rawParams = array_merge($operations->rawParams, $_GET);
    }
    if (isset($_POST) && is_array($_POST)) {
        $operations->params = array_merge($operations->params, $_POST);
        $operations->rawParams = array_merge($operations->rawParams, $_POST);
    }
    if (isset($context->params) && is_array($context->params)) {
        $operations->params = array_merge($operations->params, $context->params);
    }
    $activeUser = $GLOBALS['HAXCMS']->getActiveUserName();
    $operations->params['user_token'] = $GLOBALS['HAXCMS']->getRequestToken($activeUser);
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
