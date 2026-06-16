<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }
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
    $fileUuid = $context->getParam('fileUuid', '');
    $method = strtoupper((string) $context->method);
    $operations = new Operations();
    $result = null;
    if ($method === 'POST') {
        if (!isset($body['site']) || !is_array($body['site'])) {
            $body['site'] = array();
        }
        if (!isset($body['site']['name']) || $body['site']['name'] === '') {
            $body['site']['name'] = $siteName;
        }
        $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
        if (!isset($body['site_token']) || $body['site_token'] === '') {
            $body['site_token'] = $siteToken;
        }
        if (isset($body['nodeId']) && (!isset($body['node']) || !isset($body['node']['id']))) {
            if (!isset($body['node']) || !is_array($body['node'])) {
                $body['node'] = array();
            }
            $body['node']['id'] = $body['nodeId'];
        }
        $operations->params = $body;
        $operations->rawParams = array_merge($body, $_FILES);
        $result = $operations->saveFile();
    } else if ($method === 'PATCH' || $method === 'DELETE') {
        if (!isset($body['siteName']) || $body['siteName'] === '') {
            $body['siteName'] = $siteName;
        }
        $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
        if (!isset($body['site_token']) || $body['site_token'] === '') {
            $body['site_token'] = $siteToken;
        }
        if ($method === 'DELETE') {
            if (!isset($body['operation']) || $body['operation'] === '') {
                $body['operation'] = 'delete';
            }
        }
        if (!isset($body['path']) || $body['path'] === '') {
            if ($fileUuid !== '') {
                $body['path'] = 'files/' . $fileUuid;
            }
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->fileOperation();
    } else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unsupported method for /v1/files'),
            array('statusCode' => 405, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
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