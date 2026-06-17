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
    $routeSuffix = trim((string) $context->routeSuffix, '/');
    $operations = new Operations();
    $result = null;
    if (substr($routeSuffix, -11) === '/appearance') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveAppearanceSettings();
    } else if (substr($routeSuffix, -10) === '/platform') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->savePlatformSettings();
    } else if (substr($routeSuffix, -7) === '/blocks') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveAllowedBlocks();
    } else if (substr($routeSuffix, -7) === '/editor') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveEditorSettings();
    } else if (substr($routeSuffix, -4) === '/seo') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveSeoSettings();
    } else if (substr($routeSuffix, -8) === '/outline') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveOutline();
    } else {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveManifest();
    }
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