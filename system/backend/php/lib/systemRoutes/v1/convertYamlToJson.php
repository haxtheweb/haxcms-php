<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';

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

    if (!isset($body['yaml']) || $body['yaml'] === null || trim((string) $body['yaml']) === '') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'missing `yaml` param', 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $yamlData = (string) $body['yaml'];

    // type=link: fetch YAML from URL
    if (isset($body['type']) && $body['type'] === 'link' && $yamlData !== '') {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->request('GET', trim($yamlData));
            $yamlData = (string) $response->getBody();
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Failed to fetch YAML from link: ' . $e->getMessage(), 'contents' => '')),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
    }

    if (trim($yamlData) === '') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid or empty YAML content', 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    try {
        $parsed     = \Symfony\Component\Yaml\Yaml::parse($yamlData);
        $jsonString = json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $jsonString)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'YAML to JSON conversion failed: ' . $e->getMessage(), 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
