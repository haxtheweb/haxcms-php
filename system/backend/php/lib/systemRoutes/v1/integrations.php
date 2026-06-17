<?php
include_once dirname(__FILE__) . '/../../Operations.php';
if (!function_exists('haxcmsResolveIntegrationsSiteToken')) {
    function haxcmsResolveIntegrationsSiteToken()
    {
        if (
            isset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']) &&
            is_string($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'])
        ) {
            return trim($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, 'X-HAXCMS-Site-Token') === 0) {
                        return trim((string) $value);
                    }
                }
            }
        }
        return '';
    }
}
return function ($context) {
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    if (isset($context->body) && is_array($context->body)) {
        $operations->params = $context->body;
        $operations->rawParams = $context->body;
    }
    if (isset($_GET) && is_array($_GET)) {
        $operations->params = array_merge($operations->params, $_GET);
        $operations->rawParams = array_merge($operations->rawParams, $_GET);
    }
    if (isset($context->params) && is_array($context->params)) {
        $operations->params = array_merge($operations->params, $context->params);
        $operations->rawParams = array_merge($operations->rawParams, $context->params);
    }
    unset($operations->params['jwt']);
    unset($operations->params['user_token']);
    unset($operations->params['site_token']);
    unset($operations->params['appstore_token']);
    unset($operations->rawParams['jwt']);
    unset($operations->rawParams['user_token']);
    unset($operations->rawParams['site_token']);
    unset($operations->rawParams['appstore_token']);
    $siteToken = haxcmsResolveIntegrationsSiteToken();
    if ($siteToken !== '') {
        $operations->params['site_token'] = $siteToken;
        $operations->rawParams['site_token'] = $siteToken;
    }
    $route = isset($context->routeSuffix) ? $context->routeSuffix : '';
    $response = null;
    $status = 200;
    if ($route == 'v1/integrations/app-store' || $route == 'integrations/app-store') {
        $response = $operations->generateAppStore();
    }
    else if (
      $route == 'v1/integrations/app-store/providers/:provider/search' ||
      $route == 'integrations/app-store/providers/:provider/search' ||
      preg_match('/^(?:v1\\/)?integrations\\/app-store\\/providers\\/[^\\/]+\\/search$/', $route) === 1
    ) {
        $response = $operations->appStoreSearch();
    }
    else {
        $status = 404;
        $response = array('status' => 404, 'message' => 'Unknown integrations route');
    }
    if (
      is_array($response) &&
      !isset($response['__failed']) &&
      isset($response['status']) &&
      is_numeric($response['status'])
    ) {
      $status = (int) $response['status'];
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (is_array($response) && isset($response['__failed'])) {
        $failedStatus = isset($response['__failed']['status']) ? (int) $response['__failed']['status'] : 500;
        http_response_code($failedStatus);
        if (isset($response['__failed']['message'])) {
            print json_encode($response['__failed']['message']);
        }
        else {
            print json_encode($response['__failed']);
        }
    }
    else {
        print json_encode($response);
    }
};
