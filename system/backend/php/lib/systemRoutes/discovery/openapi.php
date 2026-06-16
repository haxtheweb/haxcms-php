<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : 'openapi';
    $specPath = dirname(__FILE__) . '/../openapi/system-spec.yaml';
    $openapi = array();
    if (!file_exists($specPath)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Failed to load system OpenAPI specification',
            ),
            array(
                'statusCode' => 500,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $specContents = file_get_contents($specPath);
    $parsedSpec = SiteRouteUtils::parseYaml($specContents);
    if (!is_array($parsedSpec)) {
        $parsedSpec = json_decode($specContents, true);
    }
    if (!is_array($parsedSpec)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Invalid system OpenAPI specification',
            ),
            array(
                'statusCode' => 500,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $openapi = $parsedSpec;
    if (!isset($openapi['info']) || !is_array($openapi['info'])) {
        $openapi['info'] = array();
    }
    $openapi['info']['version'] = SiteRouteUtils::getVersion();
    $serverBasePath = preg_replace('/\/system\/api$/', '/', $apiBasePath);
    if (!is_string($serverBasePath) || $serverBasePath == '') {
        $serverBasePath = '/';
    }
    if (substr($serverBasePath, -1) != '/') {
        $serverBasePath .= '/';
    }
    $openapi['servers'] = array(
        array(
            'url' => $serverBasePath,
            'description' => 'HAXcms system base URL',
        ),
    );
    $allowedFormats = array('json', 'yaml');
    $format = SiteRouteUtils::detectResponseFormat($allowedFormats, 'json', $routeSuffix);
    if ($format == 'yaml') {
        http_response_code(200);
        header('Content-Type: application/yaml; charset=utf-8');
        print SiteRouteUtils::toYaml($openapi);
        return;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    print json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};