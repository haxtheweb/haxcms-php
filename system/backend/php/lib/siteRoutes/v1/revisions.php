<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $body = array();
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
    // E9: resolve slug to UUID before delegating (Node canonical). The restore
    // path was already fixed; this covers the GET list/single revision paths
    // which previously passed the raw idOrSlug and 404'd on slug lookups.
    $resolvedItemId = $idOrSlug;
    if (isset($context->site) && $idOrSlug !== '') {
        $resolvedItem = SiteRouteUtils::findItemByIdOrSlug($context->site, $idOrSlug);
        if ($resolvedItem && isset($resolvedItem->id) && is_string($resolvedItem->id) && $resolvedItem->id !== '') {
            $resolvedItemId = (string) $resolvedItem->id;
        }
    }
    if ($revisionId !== '') {
        $body = array(
            'site' => array('name' => $siteName),
            'node' => array('id' => $resolvedItemId),
            'hash' => $revisionId,
            'site_token' => $siteToken,
        );
    } else {
        $body = array(
            'site' => array('name' => $siteName),
            'node' => array('id' => $resolvedItemId),
            'site_token' => $siteToken,
        );
    }
    $operations = new Operations();
    $operations->params = $body;
    $operations->rawParams = $body;
    $result = null;
    if ($revisionId !== '') {
        $result = $operations->getNodeRevision();
    } else {
        $result = $operations->getNodeRevisions();
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