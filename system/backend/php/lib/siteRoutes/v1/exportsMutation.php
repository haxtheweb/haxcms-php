<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for export endpoint'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $format = isset($context->params['format']) ? strtolower(trim((string) $context->params['format'])) : '';
    $SITE_EXPORT_FORMATS = array('zip', 'markdown', 'pdf', 'docx', 'epub', 'html', 'skeleton');
    if (!in_array($format, $SITE_EXPORT_FORMATS, true)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Unsupported site export format "' . $format . '"',
                'supportedFormats' => $SITE_EXPORT_FORMATS,
            ),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
    $systemApiBasePath = preg_replace(
        '/\/x\/api$/',
        '/' . (isset($GLOBALS['HAXCMS']) && is_object($GLOBALS['HAXCMS']) && isset($GLOBALS['HAXCMS']->systemRequestBase) ? trim((string) $GLOBALS['HAXCMS']->systemRequestBase, '/') : 'system/api'),
        (string) $apiBasePath
    );
    $siteName = (isset($site->manifest->metadata->site->name) && is_string($site->manifest->metadata->site->name))
        ? (string) $site->manifest->metadata->site->name
        : '';
    $descriptors = array(
        'markdown' => array(
            'rel' => 'download',
            'mediaType' => 'text/markdown',
            'href' => $apiBasePath . '/v1/content?mode=concat&format=md',
        ),
        'zip' => array(
            'rel' => 'download',
            'mediaType' => 'application/zip',
            'href' => $systemApiBasePath . '/v1/sites/' . rawurlencode($siteName) . '/download',
            'authenticatedEndpoint' => $systemApiBasePath . '/v1/sites/' . rawurlencode($siteName) . '/download',
            'method' => 'POST',
        ),
        'pdf' => array(
            'rel' => 'download',
            'mediaType' => 'application/pdf',
            'href' => $apiBasePath . '/v1/site/export/pdf',
        ),
        'docx' => array(
            'rel' => 'download',
            'mediaType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'href' => $apiBasePath . '/v1/site/export/docx',
        ),
        'epub' => array(
            'rel' => 'download',
            'mediaType' => 'application/epub+zip',
            'href' => $apiBasePath . '/v1/site/export/epub',
        ),
        'html' => array(
            'rel' => 'download',
            'mediaType' => 'text/html',
            'href' => $apiBasePath . '/v1/site/export/html',
        ),
        'skeleton' => array(
            'rel' => 'download',
            'mediaType' => 'application/json',
            'href' => $systemApiBasePath . '/v1/sites/' . rawurlencode($siteName) . '/download-skeleton',
            'authenticatedEndpoint' => $systemApiBasePath . '/v1/sites/' . rawurlencode($siteName) . '/download-skeleton',
            'method' => 'POST',
        ),
    );
    SiteRouteUtils::sendFormattedResponse(
        array(
            'format' => $format,
            'supportedFormats' => $SITE_EXPORT_FORMATS,
            'export' => $descriptors[$format],
            'links' => array(
                'self' => $apiBasePath . '/v1/site/export/' . rawurlencode($format),
                'site' => $apiBasePath . '/v1/site',
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};