<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/ExportConverters.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/items'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    // Pure item helpers (buildItemNavigationMap, buildHaxElementSchemaFromHtml,
    // buildItemJsonLd) live on SiteRouteUtils so they have a directly testable
    // public seam; this route just delegates to them (no behavior change).
    $orderedItems = SiteRouteUtils::getOrderedItems($site);
    $navigationMap = SiteRouteUtils::buildItemNavigationMap($orderedItems, $apiBasePath);
    $includes = SiteRouteUtils::getCsvQuery('include');
    $fields = SiteRouteUtils::getCsvQuery('fields');
    $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
    $siteLanguage = SiteRouteUtils::getSiteLanguage($site);
    if (isset($context->params['idOrSlug']) && $context->params['idOrSlug'] != '') {
        $item = SiteRouteUtils::findItemByIdOrSlug($site, $context->params['idOrSlug']);
        if (!$item) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Item not found for idOrSlug "' . $context->params['idOrSlug'] . '"'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        if (SiteRouteUtils::isAnonymousSiteApiRequest($context) && !SiteRouteUtils::isItemVisibleToAnonymous($item)) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Item not found for idOrSlug "' . $context->params['idOrSlug'] . '"'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $record = SiteRouteUtils::itemToSummary($item, $apiBasePath);
        if (isset($record['id']) && array_key_exists((string) $record['id'], $navigationMap)) {
            $record['links']['previous'] = $navigationMap[(string) $record['id']]['previous'];
            $record['links']['next'] = $navigationMap[(string) $record['id']]['next'];
            $record['links']['parent'] = $navigationMap[(string) $record['id']]['parent'];
            $record['links']['children'] = $navigationMap[(string) $record['id']]['children'];
        }
        $lookupValue = SiteRouteUtils::getItemLookupValue($item);
        $exportBase = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '/export';
        $record['exports'] = array();
        foreach (ExportConverters::getItemExportFormats() as $exportFormat) {
            $record['exports'][$exportFormat] = $exportBase . '/' . $exportFormat;
        }
        $record['links']['exportDocx'] = $record['exports']['docx'];
        $record['links']['exportPdf'] = $record['exports']['pdf'];
        $record['links']['haxElementSchema'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=haxElementSchema';
        $record['links']['jsonld'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=jsonld';
        if (in_array('content', $includes, true) || in_array('haxElementSchema', $includes, true)) {
            $content = SiteRouteUtils::getItemContent($site, $item);
            if (in_array('content', $includes, true)) {
                $record['content'] = $content;
            }
            if (in_array('haxElementSchema', $includes, true)) {
                $record['haxElementSchema'] = SiteRouteUtils::buildHaxElementSchemaFromHtml($content);
            }
        }
        $record['jsonld'] = SiteRouteUtils::buildItemJsonLd($record, $siteBasePath, $siteLanguage);
        if (count($fields) > 0) {
            $record = SiteRouteUtils::projectRecord($record, $fields);
        }
        SiteRouteUtils::sendFormattedResponse(
            $record,
            array(
                'allowedFormats' => array('json', 'md', 'yaml', 'xml', 'html'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $filteredItems = SiteRouteUtils::applyItemFilters($orderedItems, $site, $context);
    $records = array();
    foreach ($filteredItems as $item) {
        $record = SiteRouteUtils::itemToSummary($item, $apiBasePath);
        if (isset($record['id']) && array_key_exists((string) $record['id'], $navigationMap)) {
            $record['links']['previous'] = $navigationMap[(string) $record['id']]['previous'];
            $record['links']['next'] = $navigationMap[(string) $record['id']]['next'];
            $record['links']['parent'] = $navigationMap[(string) $record['id']]['parent'];
            $record['links']['children'] = $navigationMap[(string) $record['id']]['children'];
        }
        $lookupValue = SiteRouteUtils::getItemLookupValue($item);
        $exportBase = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '/export';
        $record['exports'] = array();
        foreach (ExportConverters::getItemExportFormats() as $exportFormat) {
            $record['exports'][$exportFormat] = $exportBase . '/' . $exportFormat;
        }
        $record['links']['exportDocx'] = $record['exports']['docx'];
        $record['links']['exportPdf'] = $record['exports']['pdf'];
        $record['links']['haxElementSchema'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=haxElementSchema';
        $record['links']['jsonld'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=jsonld';
        if (in_array('content', $includes, true) || in_array('haxElementSchema', $includes, true)) {
            $content = SiteRouteUtils::getItemContent($site, $item);
            if (in_array('content', $includes, true)) {
                $record['content'] = $content;
            }
            if (in_array('haxElementSchema', $includes, true)) {
                $record['haxElementSchema'] = SiteRouteUtils::buildHaxElementSchemaFromHtml($content);
            }
        }
        if (in_array('jsonld', $includes, true)) {
            $record['jsonld'] = SiteRouteUtils::buildItemJsonLd($record, $siteBasePath, $siteLanguage);
        }
        $records[] = $record;
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'order');
    $paged = SiteRouteUtils::paginateRecords($records, 25, 200);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'items' => $outputRecords,
            'links' => array(
                'self' => $apiBasePath . '/v1/items',
            ),
        ),
        array(
            'allowedFormats' => array('json', 'md', 'yaml', 'xml', 'html'),
            'defaultFormat' => 'json',
        ),
        $context->routeSuffix,
        $apiBasePath
    );
};
