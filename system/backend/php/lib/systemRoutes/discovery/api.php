<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $absoluteApiBasePath = isset($context->absoluteApiBasePath) ? $context->absoluteApiBasePath : $apiBasePath;
    $buildLinkMap = function ($basePath) {
        return array(
            'self' => $basePath,
            'openapi' => $basePath . '/openapi',
            'openapiJson' => $basePath . '/openapi.json',
            'openapiYaml' => $basePath . '/openapi.yaml',
            'sites' => $basePath . '/sites',
            'session' => $basePath . '/session',
            'configuration' => $basePath . '/configuration',
            'integrations' => $basePath . '/integrations',
            'entities' => $basePath . '/entities',
            'schemas' => $basePath . '/schemas',
            'system' => $basePath . '/system',
        );
    };
    SiteRouteUtils::sendFormattedResponse(
        array(
            'name' => 'HAXcms System API',
            'version' => SiteRouteUtils::getVersion(),
            'mode' => 'admin',
            'links' => $buildLinkMap($apiBasePath),
            'absoluteLinks' => $buildLinkMap($absoluteApiBasePath),
            'supports' => array(
                'formats' => array(
                    'application/json',
                    'application/yaml',
                ),
            ),
            'openapi' => array(
                'source' => 'systemRoutes/openapi/system-spec.yaml',
                'routeDriven' => true,
            ),
        ),
        array(
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
        ),
        isset($context->routeSuffix) ? $context->routeSuffix : '',
        $apiBasePath
    );
};