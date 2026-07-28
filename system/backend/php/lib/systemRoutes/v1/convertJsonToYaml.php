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

    if (!isset($body['json']) || $body['json'] === null) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'missing `json` param', 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $jsonData = $body['json'];

    // type=link: fetch JSON from URL
    if (isset($body['type']) && $body['type'] === 'link' && is_string($jsonData) && $jsonData !== '') {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', trim($jsonData));
            $fetched  = (string) $response->getBody();
            $decoded  = json_decode($fetched, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                SiteRouteUtils::sendFormattedResponse(
                    array('status' => 400, 'data' => array('error' => 'Failed to fetch or parse JSON from link', 'contents' => '')),
                    array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                    $context->routeSuffix,
                    $apiBasePath
                );
                return;
            }
            $jsonData = $decoded;
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Failed to fetch JSON from link: ' . $e->getMessage(), 'contents' => '')),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
    }

    // If still a string, try to parse it as JSON
    if (is_string($jsonData)) {
        $decoded = json_decode($jsonData, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid JSON string provided', 'contents' => '')),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $jsonData = $decoded;
    }

    try {
        $yamlOutput = \Symfony\Component\Yaml\Yaml::dump($jsonData, 8, 2, \Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP);
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $yamlOutput)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'JSON to YAML conversion failed: ' . $e->getMessage(), 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
