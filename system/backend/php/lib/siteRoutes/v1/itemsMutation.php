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
    $method = strtoupper((string) $context->method);
    $operations = new Operations();
    $result = null;
    $resolvedItem = null;
    $resolvedItemId = $idOrSlug;
    if (($method === 'PATCH' || $method === 'DELETE') && $idOrSlug !== '' && isset($context->site)) {
        $resolvedItem = SiteRouteUtils::findItemByIdOrSlug($context->site, $idOrSlug);
        if (!$resolvedItem) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => 404,
                    'message' => 'Item not found for idOrSlug "' . $idOrSlug . '"',
                ),
                array(
                    'statusCode' => 404,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        if (isset($resolvedItem->id) && is_string($resolvedItem->id) && $resolvedItem->id !== '') {
            $resolvedItemId = $resolvedItem->id;
        }
    }
    if ($method === 'POST') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->createNode();
    } else if ($method === 'PATCH') {
        if (!isset($body['node']) || !is_array($body['node'])) {
            $body['node'] = array();
        }
        if (!isset($body['node']['id']) || $body['node']['id'] === '') {
            $body['node']['id'] = $resolvedItemId;
        }
        $operation = '';
        if (isset($body['operation']) && is_string($body['operation'])) {
            $operation = trim($body['operation']);
        }
        if (
            $operation === '' &&
            isset($body['node']['details']) &&
            is_array($body['node']['details']) &&
            isset($body['node']['details']['operation']) &&
            is_string($body['node']['details']['operation'])
        ) {
            $operation = trim($body['node']['details']['operation']);
        }
        if ($operation === '') {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => 400,
                    'message' => 'Operation is required',
                ),
                array(
                    'statusCode' => 400,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        if (!isset($body['node']['details']) || !is_array($body['node']['details'])) {
            $body['node']['details'] = array();
        }
        $body['node']['details']['operation'] = $operation;
        $operationDetailKeys = array(
            'parent',
            'order',
            'title',
            'description',
            'tags',
            'icon',
            'media',
            'image',
            'relatedItems',
            'locked',
            'published',
            'hideInMenu',
            'slug',
        );
        foreach ($operationDetailKeys as $detailKey) {
            if (array_key_exists($detailKey, $body) && !array_key_exists($detailKey, $body['node']['details'])) {
                $body['node']['details'][$detailKey] = $body[$detailKey];
            }
        }
        if (
            $operation === 'setMedia' &&
            !array_key_exists('image', $body['node']['details']) &&
            array_key_exists('media', $body['node']['details'])
        ) {
            $body['node']['details']['image'] = $body['node']['details']['media'];
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveNodeDetails();
    } else if ($method === 'DELETE') {
        if (!isset($body['node']) || !is_array($body['node'])) {
            $body['node'] = array();
        }
        if (!isset($body['node']['id']) || $body['node']['id'] === '') {
            $body['node']['id'] = $resolvedItemId;
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->deleteNode();
    } else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unsupported method for /v1/items'),
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