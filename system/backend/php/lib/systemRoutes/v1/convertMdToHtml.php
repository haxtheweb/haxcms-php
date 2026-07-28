<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../SsrfGuard.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';

    $body = $context->body;
    if (!is_array($body)) {
        $body = array();
    }

    // Also support query param fallback
    if (empty($body['md']) && isset($_GET['md']) && $_GET['md'] !== '') {
        $body['md'] = $_GET['md'];
    }

    if (!isset($body['md']) || $body['md'] === '' || $body['md'] === null) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'missing `md` param', 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $mdText = (string) $body['md'];

    // type=link: fetch the URL first
    if (isset($body['type']) && $body['type'] === 'link' && $mdText !== '') {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', trim($mdText));
            $mdText   = (string) $response->getBody();
        } catch (\Exception $e) {
            $mdText = '';
        }
    }

    try {
        $html = \Michelf\Markdown::defaultTransform($mdText);
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $html)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Markdown to HTML conversion failed: ' . $e->getMessage(), 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
