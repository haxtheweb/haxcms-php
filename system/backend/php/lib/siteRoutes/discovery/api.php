<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $absoluteApiBasePath = isset($context->absoluteApiBasePath) ? $context->absoluteApiBasePath : $apiBasePath;
    $buildLinkMap = function ($basePath) {
        return array(
            'self' => $basePath,
            'openapi' => $basePath . '/openapi',
            'openapiJson' => $basePath . '/openapi.json',
            'openapiYaml' => $basePath . '/openapi.yaml',
            'entities' => $basePath . '/v1/entities',
            'schemas' => $basePath . '/v1/schemas',
            'site' => $basePath . '/v1/site',
        );
    };
    SiteRouteUtils::sendFormattedResponse(
        array(
            'name' => 'HAXcms Site API',
            'version' => SiteRouteUtils::getVersion(),
            'mode' => 'read-only',
            'links' => $buildLinkMap($apiBasePath),
            'absoluteLinks' => $buildLinkMap($absoluteApiBasePath),
            'supports' => array(
                'formats' => array(
                    'application/json',
                    'text/markdown',
                    'application/yaml',
                    'application/xml',
                    'text/html',
                ),
                'modes' => array('bundle', 'concat'),
                'queryGrammar' => array(
                    'filter.*',
                    'page.limit',
                    'page.offset',
                    'sort',
                    'fields',
                    'include',
                    'format',
                    'mode',
                ),
            ),
            'openapi' => array(
                'source' => 'siteRoutes/openapi/site-spec.yaml',
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
