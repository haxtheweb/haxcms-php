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
    if (isset($context->params) && is_array($context->params)) {
        $operations->params = array_merge($operations->params, $context->params);
    }
    $route = isset($context->routeSuffix) ? $context->routeSuffix : '';
    $response = null;
    $status = 200;
    if ($route == 'integrations/app-store') {
        $response = $operations->generateAppStore();
    } else {
        $status = 404;
        $response = array('status' => 404, 'message' => 'Unknown integrations route');
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (is_array($response) && isset($response['__failed'])) {
        http_response_code($response['__failed']['status']);
        print json_encode($response['__failed']);
    } else {
        print json_encode($response);
    }
};
