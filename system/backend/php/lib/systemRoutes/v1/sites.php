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
    $route = isset($context->routeSuffix) ? $context->routeSuffix : '';
    $method = isset($context->method) ? strtoupper($context->method) : 'GET';
    $response = null;
    $status = 200;
    if ($route == 'sites' && $method == 'GET') {
        $response = $operations->listSites();
    } elseif ($route == 'sites' && $method == 'POST') {
        $response = $operations->createSite();
    } elseif (preg_match('/^sites\/([^\/]+)$/', $route, $matches) && $method == 'GET') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->listSites();
    } elseif (preg_match('/^sites\/([^\/]+)\/clone$/', $route, $matches) && $method == 'POST') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->cloneSite();
    } elseif (preg_match('/^sites\/([^\/]+)\/archive$/', $route, $matches) && $method == 'POST') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->archiveSite();
    } elseif (preg_match('/^sites\/([^\/]+)\/download$/', $route, $matches) && $method == 'POST') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->downloadSite();
    } elseif (preg_match('/^sites\/([^\/]+)\/download-skeleton$/', $route, $matches) && $method == 'POST') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->downloadSiteSkeleton();
    } elseif (preg_match('/^sites\/([^\/]+)\/save-as-template$/', $route, $matches) && $method == 'POST') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->saveSiteAsTemplate();
    } elseif (preg_match('/^sites\/([^\/]+)$/', $route, $matches) && $method == 'DELETE') {
        $operations->params['siteName'] = $matches[1];
        $response = $operations->archiveSite();
    } else {
        $status = 404;
        $response = array('status' => 404, 'message' => 'Unknown sites route');
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
