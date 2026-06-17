<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }
    unset($body['jwt']);
    unset($body['user_token']);
    unset($body['site_token']);
    $siteName = '';
    if (
        isset($context->site) &&
        isset($context->site->manifest) &&
        isset($context->site->manifest->metadata) &&
        isset($context->site->manifest->metadata->site) &&
        isset($context->site->manifest->metadata->site->name)
    ) {
        $siteName = (string) $context->site->manifest->metadata->site->name;
    }
    if (!isset($body['site']) || !is_array($body['site'])) {
        $body['site'] = array();
    }
    if (!isset($body['site']['name']) || $body['site']['name'] === '') {
        $body['site']['name'] = $siteName;
    }
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken)) {
        $siteToken = '';
    }
    $body['site_token'] = $siteToken;
    $idOrSlug = $context->getParam('idOrSlug', '');
    if (!isset($body['node']) || !is_array($body['node'])) {
        $body['node'] = array();
    }
    if (!isset($body['node']['id']) || $body['node']['id'] === '') {
        $body['node']['id'] = $idOrSlug;
    }
    $operations = new Operations();
    $operations->params = $body;
    $operations->rawParams = $body;
    $result = $operations->saveNode();
    if (is_array($result) && isset($result['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => intval($result['__failed']['status']),
                'message' => $result['__failed']['message'],
            ),
            array(
                'statusCode' => intval($result['__failed']['status']),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    if (is_array($result) && isset($result['status']) && isset($result['data'])) {
        SiteRouteUtils::sendFormattedResponse(
            $result,
            array(
                'statusCode' => 200,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => $result),
        array(
            'statusCode' => 200,
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
            'envelope' => false,
        ),
        $context->routeSuffix,
        $context->apiBasePath
    );
};