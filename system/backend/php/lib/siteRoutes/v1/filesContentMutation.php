<?php
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../EntityRegistry.php';
include_once dirname(__FILE__) . '/../../FileStorage.php';
/**
 * POST /x/api/v1/files/{fileUuid}/content (replaceFileContent),
 * haxtheweb/issues#1149: replace an image's contents with an uploaded image
 * (a crop made in the browser) while keeping its uuid, path and name.
 * Mirrors replaceFileContent in haxcms-nodejs (src/siteRoutes/v1/files.js).
 */
return function ($context) {
    $sendError = function ($statusCode, $message) use ($context) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => $message),
            array(
                'statusCode' => $statusCode,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
    };
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
    $fileUuid = trim((string) $context->getParam('fileUuid', ''));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $fileUuid) !== 1) {
        $sendError(400, 'File uuid is required and must be a valid UUID');
        return;
    }
    $registry = new EntityRegistry($context->site);
    $fileStorage = FileStorage::registerOn($registry);
    $entity = $fileStorage->load($fileUuid);
    if ($entity === null) {
        $sendError(404, 'Requested file was not found');
        return;
    }
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken)) {
        $siteToken = '';
    }
    $operations = new Operations();
    // Security (SEC-ROUTE): the site name is forced to the resolved site and
    // the path comes from the uuid lookup, never from the request body.
    $operations->params = array(
        'siteName' => $siteName,
        'site_token' => $siteToken,
        'path' => (string) $entity->getPath(),
    );
    $operations->rawParams = $_FILES;
    $result = $operations->replaceFileContent();
    if (is_array($result) && isset($result['__failed'])) {
        $sendError(intval($result['__failed']['status']), $result['__failed']['message']);
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
