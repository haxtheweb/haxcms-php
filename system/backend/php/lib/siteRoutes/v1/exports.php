<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
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
    $SITE_EXPORT_FORMATS = array('zip', 'markdown', 'pdf', 'docx', 'epub', 'skeleton');
    $ITEM_EXPORT_FORMATS = array('pdf', 'docx');
    $normalizeFormatValue = function ($value = '') {
        return strtolower(trim((string) $value));
    };
    $getSystemApiBasePath = function ($apiBasePath = '/x/api') {
        $systemRequestBase = 'system/api';
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->systemRequestBase) &&
            trim((string) $GLOBALS['HAXCMS']->systemRequestBase) != ''
        ) {
            $systemRequestBase = trim((string) $GLOBALS['HAXCMS']->systemRequestBase, '/');
        }
        return preg_replace('/\/x\/api$/', '/' . $systemRequestBase, (string) $apiBasePath);
    };
    $buildSiteExportDetails = function ($format = '') use ($site, $apiBasePath, $getSystemApiBasePath) {
        $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
        $systemApiBasePath = $getSystemApiBasePath($apiBasePath);
        $descriptors = array(
            'markdown' => array(
                'rel' => 'download',
                'mediaType' => 'text/markdown',
                'href' => $apiBasePath . '/v1/content?mode=concat&format=md',
            ),
            'zip' => array(
                'rel' => 'download',
                'mediaType' => 'application/zip',
                'href' => $siteBasePath . '?download-site=true',
                'authenticatedEndpoint' => $systemApiBasePath . '/downloadSite',
            ),
            'pdf' => array(
                'rel' => 'service',
                'mediaType' => 'application/pdf',
                'href' => 'https://open-apis.hax.cloud/api/services/media/format/htmlToPdf',
                'method' => 'POST',
                'source' => $apiBasePath . '/v1/content?mode=concat',
            ),
            'docx' => array(
                'rel' => 'service',
                'mediaType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'href' => 'https://open-apis.hax.cloud/api/services/media/format/htmlToDocx',
                'method' => 'POST',
                'source' => $apiBasePath . '/v1/content?mode=concat',
            ),
            'epub' => array(
                'rel' => 'service',
                'mediaType' => 'application/epub+zip',
                'href' => 'https://open-apis.hax.cloud/api/apps/haxcms/siteToEpub',
                'method' => 'POST',
                'source' => $siteBasePath . 'site.json',
            ),
            'skeleton' => array(
                'rel' => 'download',
                'mediaType' => 'application/json',
                'href' => $systemApiBasePath . '/downloadSiteSkeleton',
                'authenticatedEndpoint' => $systemApiBasePath . '/downloadSiteSkeleton',
                'method' => 'POST',
            ),
        );
        return array_key_exists($format, $descriptors) ? $descriptors[$format] : null;
    };
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $format = isset($context->params['format']) ? $normalizeFormatValue($context->params['format']) : '';
    if ($format == '') {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Export format is required'),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (strpos($routeSuffix, 'v1/site/export/') === 0) {
        if (!in_array($format, $SITE_EXPORT_FORMATS, true)) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Unsupported site export format "' . $format . '"',
                    'supportedFormats' => $SITE_EXPORT_FORMATS,
                ),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $routeSuffix,
                $apiBasePath
            );
            return;
        }
        SiteRouteUtils::sendFormattedResponse(
            array(
                'format' => $format,
                'supportedFormats' => $SITE_EXPORT_FORMATS,
                'export' => $buildSiteExportDetails($format),
                'links' => array(
                    'self' => $apiBasePath . '/v1/site/export/' . rawurlencode($format),
                    'site' => $apiBasePath . '/v1/site',
                ),
            ),
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $idOrSlug = isset($context->params['idOrSlug']) ? (string) $context->params['idOrSlug'] : '';
    $item = SiteRouteUtils::findItemByIdOrSlug($site, $idOrSlug);
    if (!$item) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Item not found for idOrSlug "' . $idOrSlug . '"'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (!in_array($format, $ITEM_EXPORT_FORMATS, true)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Unsupported item export format "' . $format . '"',
                'supportedFormats' => $ITEM_EXPORT_FORMATS,
            ),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $lookup = SiteRouteUtils::getItemLookupValue($item);
    $mediaType = $format == 'pdf'
        ? 'application/pdf'
        : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    SiteRouteUtils::sendFormattedResponse(
        array(
            'format' => $format,
            'supportedFormats' => $ITEM_EXPORT_FORMATS,
            'item' => array(
                'id' => isset($item->id) ? $item->id : null,
                'slug' => isset($item->slug) ? $item->slug : '',
                'title' => isset($item->title) ? $item->title : '',
            ),
            'export' => array(
                'rel' => 'service',
                'mediaType' => $mediaType,
                'href' => $format == 'pdf'
                    ? 'https://open-apis.hax.cloud/api/services/media/format/htmlToPdf'
                    : 'https://open-apis.hax.cloud/api/services/media/format/htmlToDocx',
                'method' => 'POST',
                'source' => $apiBasePath . '/v1/content/' . rawurlencode($lookup),
            ),
            'links' => array(
                'self' => $apiBasePath . '/v1/items/' . rawurlencode($lookup) . '/export/' . rawurlencode($format),
                'item' => $apiBasePath . '/v1/items/' . rawurlencode($lookup),
                'content' => $apiBasePath . '/v1/content/' . rawurlencode($lookup),
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $routeSuffix,
        $apiBasePath
    );
};
