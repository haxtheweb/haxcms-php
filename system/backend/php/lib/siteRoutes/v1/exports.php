<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/ExportConverters.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $sendTopLevelError = function ($statusCode, $message, $extra = array()) use ($routeSuffix, $apiBasePath) {
        $payload = array_merge(
            array(
                'message' => (string) $message,
            ),
            is_array($extra) ? $extra : array()
        );
        SiteRouteUtils::sendFormattedResponse(
            $payload,
            array(
                'statusCode' => intval($statusCode),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $routeSuffix,
            $apiBasePath
        );
    };
    if (!isset($site) || !isset($site->manifest)) {
        $sendTopLevelError(404, 'Unable to resolve site context for export endpoint');
        return;
    }
    $SITE_EXPORT_FORMATS = array('zip', 'markdown', 'pdf', 'docx', 'epub', 'html', 'skeleton');
    $ITEM_EXPORT_FORMATS = ExportConverters::getItemExportFormats();
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
                'href' => $systemApiBasePath . '/downloadSiteSkeleton',
                'authenticatedEndpoint' => $systemApiBasePath . '/downloadSiteSkeleton',
                'method' => 'POST',
            ),
        );
        return array_key_exists($format, $descriptors) ? $descriptors[$format] : null;
    };
    $format = isset($context->params['format']) ? $normalizeFormatValue($context->params['format']) : '';
    if ($format == '') {
        $sendTopLevelError(400, 'Export format is required');
        return;
    }
    if (strpos($routeSuffix, 'v1/site/export/') === 0) {
        if (!in_array($format, $SITE_EXPORT_FORMATS, true)) {
            $sendTopLevelError(
                400,
                'Unsupported site export format "' . $format . '"',
                array('supportedFormats' => $SITE_EXPORT_FORMATS)
            );
            return;
        }
        $ancestor = SiteRouteUtils::getQueryValue('filter.ancestor', '');
        $magic = SiteRouteUtils::getQueryValue('magic', '');
        $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
        $siteFileBaseName = ExportConverters::getSiteExportFileBaseName($site);
        if ($format == 'pdf' || $format == 'docx' || $format == 'epub') {
            try {
                if ($format == 'epub') {
                    $output = ExportConverters::buildSiteEpubString($site, $siteBasePath, $ancestor);
                }
                else {
                    $siteHtml = ExportConverters::buildSiteExportHtml($site, $ancestor, '');
                    if ($format == 'pdf') {
                        $output = ExportConverters::htmlToPdfString($siteHtml, $siteBasePath);
                    }
                    else {
                        $output = ExportConverters::htmlToDocxString($siteHtml);
                    }
                }
            }
            catch (Exception $e) {
                $sendTopLevelError(502, $e->getMessage());
                return;
            }
            ExportConverters::sendFileDownload(
                $output,
                ExportConverters::resolveExportMediaType($format),
                $siteFileBaseName . '.' . $format
            );
            return;
        }
        if ($format == 'html') {
            try {
                $siteHtml = ExportConverters::buildSiteExportHtml($site, $ancestor, $magic);
            }
            catch (Exception $e) {
                $sendTopLevelError(500, 'Unable to build site export HTML: ' . $e->getMessage());
                return;
            }
            ExportConverters::sendFileDownload(
                $siteHtml,
                'text/html; charset=utf-8',
                $siteFileBaseName . '.html'
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
        $sendTopLevelError(404, 'Item not found for idOrSlug "' . $idOrSlug . '"');
        return;
    }
    if (SiteRouteUtils::isAnonymousSiteApiRequest($context) && !SiteRouteUtils::isItemVisibleToAnonymous($item)) {
        $sendTopLevelError(404, 'Item not found for idOrSlug "' . $idOrSlug . '"');
        return;
    }
    if (!in_array($format, $ITEM_EXPORT_FORMATS, true)) {
        $sendTopLevelError(
            400,
            'Unsupported item export format "' . $format . '"',
            array('supportedFormats' => $ITEM_EXPORT_FORMATS)
        );
        return;
    }
    $lookup = SiteRouteUtils::getItemLookupValue($item);
    $fileBaseName = ExportConverters::getItemExportFileBaseName($item);
    $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
    if ($format == 'pdf' || $format == 'docx') {
        try {
            $content = SiteRouteUtils::getItemContent($site, $item);
            $itemHtml = ExportConverters::buildItemExportHtml($item, $content);
        }
        catch (Exception $e) {
            $sendTopLevelError(500, 'Unable to build item export HTML: ' . $e->getMessage());
            return;
        }
        try {
            if ($format == 'pdf') {
                $output = ExportConverters::htmlToPdfString($itemHtml, $siteBasePath);
            }
            else {
                $output = ExportConverters::htmlToDocxString($itemHtml);
            }
        }
        catch (Exception $e) {
            $sendTopLevelError(502, $e->getMessage());
            return;
        }
        ExportConverters::sendFileDownload(
            $output,
            ExportConverters::resolveExportMediaType($format),
            $fileBaseName . '.' . $format
        );
        return;
    }
    if ($format == 'html') {
        try {
            $content = SiteRouteUtils::getItemContent($site, $item);
            $itemHtml = ExportConverters::buildItemExportHtml($item, $content);
        }
        catch (Exception $e) {
            $sendTopLevelError(500, 'Unable to build item export HTML: ' . $e->getMessage());
            return;
        }
        ExportConverters::sendFileDownload(
            $itemHtml,
            'text/html; charset=utf-8',
            $fileBaseName . '.html'
        );
        return;
    }
    if ($format == 'md') {
        $content = SiteRouteUtils::getItemContent($site, $item);
        $markdown = ExportConverters::convertItemHtmlToMarkdown($item, $content);
        ExportConverters::sendFileDownload(
            $markdown,
            'text/markdown; charset=utf-8',
            $fileBaseName . '.md'
        );
        return;
    }
    if ($format == 'epub') {
        try {
            $output = ExportConverters::buildItemEpubString($site, $item, $siteBasePath);
        }
        catch (Exception $e) {
            $sendTopLevelError(502, $e->getMessage());
            return;
        }
        ExportConverters::sendFileDownload(
            $output,
            'application/epub+zip',
            $fileBaseName . '.epub'
        );
        return;
    }
    try {
        $content = SiteRouteUtils::getItemContent($site, $item);
        $record = SiteRouteUtils::itemToSummary($item, $apiBasePath);
        $record['content'] = $content;
        $serialized = SiteRouteUtils::serializePayload($record, $format);
    }
    catch (Exception $e) {
        $sendTopLevelError(500, 'Unable to build item export record: ' . $e->getMessage());
        return;
    }
    ExportConverters::sendFileDownload(
        $serialized,
        ExportConverters::resolveExportMediaType($format),
        $fileBaseName . '.' . $format
    );
};
