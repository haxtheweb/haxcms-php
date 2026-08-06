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
    $operations = new Operations();
    $idOrSlug = $context->getParam('idOrSlug', '');
    $result = null;
    if ($idOrSlug === '') {
        if (!isset($body['operation']) || !is_string($body['operation']) || trim($body['operation']) === '') {
            $body['operation'] = 'replace';
        }
        else {
            $body['operation'] = trim($body['operation']);
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->siteSearch();
    }
    else {
        // D63: resolve slug to UUID before delegating (Node canonical).
        // Previously this set body.node.id to the raw idOrSlug without
        // resolution. Now it resolves via findItemByIdOrSlug first and
        // uses the resolved UUID, returning 404 if not found.
        $resolvedItem = null;
        if (isset($context->site) && $idOrSlug !== '') {
            $resolvedItem = SiteRouteUtils::findItemByIdOrSlug($context->site, $idOrSlug);
        }
        if (!$resolvedItem) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Content not found for idOrSlug "' . $idOrSlug . '"',
                ),
                array(
                    'statusCode' => 404,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        if (!isset($body['node']) || !is_array($body['node'])) {
            $body['node'] = array();
        }
        // B2: map top-level body/content/schema/details into node.* mirroring
        // Node content.js updateContent (282-329). Spec-conformant requests
        // that send top-level fields now work instead of silently no-op'ing.
        $bodyContent = '';
        if (isset($body['body']) && is_string($body['body'])) {
            $bodyContent = $body['body'];
        }
        else if (isset($body['content']) && is_string($body['content'])) {
            $bodyContent = $body['content'];
        }
        else if (isset($body['node']['body']) && is_string($body['node']['body'])) {
            $bodyContent = $body['node']['body'];
        }
        if ($bodyContent === '') {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Content body is required',
                ),
                array(
                    'statusCode' => 400,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        $schema = array();
        if (isset($body['schema']) && is_array($body['schema'])) {
            $schema = $body['schema'];
        }
        else if (isset($body['node']['schema']) && is_array($body['node']['schema'])) {
            $schema = $body['node']['schema'];
        }
        $body['node']['id'] = (string) $resolvedItem->id;
        $body['node']['body'] = $bodyContent;
        $body['node']['schema'] = $schema;
        if (
            array_key_exists('details', $body) &&
            is_array($body['details'])
        ) {
            $body['node']['details'] = $body['details'];
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveNode();
    }
    if (is_array($result) && isset($result['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => $result['__failed']['message'],
            ),
            array(
                'statusCode' => intval($result['__failed']['status']),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
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