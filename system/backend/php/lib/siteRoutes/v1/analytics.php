<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/analytics'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $xapiSchemaLink = $apiBasePath . '/v1/schemas?filter.kind=xapi';
    SiteRouteUtils::sendFormattedResponse(
        array(
            'mode' => 'read-only',
            'xapi' => array(
                'supported' => true,
                'schema' => $xapiSchemaLink,
                'statementFormats' => array('application/xapi+json', 'application/json'),
                'notes' => array(
                    'xAPI statement payloads are defined through the linked schema descriptor.',
                ),
            ),
            'notes' => array(
                'This endpoint currently reports analytics capability metadata only.',
                'xAPI schema discovery is available through /x/api/v1/schemas.',
            ),
            'links' => array(
                'self' => $apiBasePath . '/v1/analytics',
                'reports' => $apiBasePath . '/v1/reports',
                'xapiSchema' => $xapiSchemaLink,
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
