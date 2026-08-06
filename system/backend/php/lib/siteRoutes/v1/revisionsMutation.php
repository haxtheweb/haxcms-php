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
    $idOrSlug = $context->getParam('idOrSlug', '');
    $revisionId = $context->getParam('revisionId', '');
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken)) {
        $siteToken = '';
    }
    // D63: resolve slug to UUID before delegating (Node canonical).
    // Previously this set node.id to the raw idOrSlug without resolution.
    // Now it resolves via findItemByIdOrSlug first and uses the resolved UUID,
    // returning 404 if not found.
    $resolvedItemId = $idOrSlug;
    if (isset($context->site) && $idOrSlug !== '') {
        $resolvedItem = SiteRouteUtils::findItemByIdOrSlug($context->site, $idOrSlug);
        if (!$resolvedItem) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Item not found for idOrSlug "' . $idOrSlug . '"',
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
        if (isset($resolvedItem->id) && is_string($resolvedItem->id) && $resolvedItem->id !== '') {
            $resolvedItemId = $resolvedItem->id;
        }
    }
    $payload = array(
        'site' => array('name' => $siteName),
        'node' => array('id' => $resolvedItemId),
        'hash' => $revisionId,
        'site_token' => $siteToken,
    );
    $operations = new Operations();
    $operations->params = $payload;
    $operations->rawParams = $payload;
    $result = $operations->restoreNodeRevision();
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