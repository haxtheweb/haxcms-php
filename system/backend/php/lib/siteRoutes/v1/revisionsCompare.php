<?php
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
// haxtheweb/issues#3191: compare two revisions of one item
// (GET v1/items/:idOrSlug/revisions/:revisionId/compare/:compareRevisionId)
return function ($context) {
    if (!isset($context->site) || !isset($context->site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/items/:idOrSlug/revisions/:revisionId/compare/:compareRevisionId'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    $siteName = '';
    if (
        isset($context->site->manifest->metadata) &&
        isset($context->site->manifest->metadata->site) &&
        isset($context->site->manifest->metadata->site->name)
    ) {
        $siteName = (string) $context->site->manifest->metadata->site->name;
    }
    $idOrSlug = $context->getParam('idOrSlug', '');
    $revisionId = $context->getParam('revisionId', '');
    $compareRevisionId = $context->getParam('compareRevisionId', '');
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken)) {
        $siteToken = '';
    }
    $resolvedItemId = $idOrSlug;
    if ($idOrSlug !== '') {
        $resolvedItem = SiteRouteUtils::findItemByIdOrSlug($context->site, $idOrSlug);
        if (!$resolvedItem) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Item not found for idOrSlug "' . $idOrSlug . '"'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        if (isset($resolvedItem->id) && is_string($resolvedItem->id) && $resolvedItem->id !== '') {
            $resolvedItemId = (string) $resolvedItem->id;
        }
    }
    $body = array(
        'site' => array('name' => $siteName),
        'node' => array('id' => $resolvedItemId),
        'hash' => $revisionId,
        'compareHash' => $compareRevisionId,
        'site_token' => $siteToken,
    );
    $operations = new Operations();
    $operations->params = $body;
    $operations->rawParams = $body;
    $result = $operations->compareNodeRevisions();
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
};
