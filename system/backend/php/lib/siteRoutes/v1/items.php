<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
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
    $buildItemNavigationMap = function ($orderedItems) use ($apiBasePath) {
        $itemById = array();
        foreach ($orderedItems as $item) {
            if (isset($item->id)) {
                $itemById[(string) $item->id] = $item;
            }
        }
        $navigationMap = array();
        for ($i = 0; $i < count($orderedItems); $i++) {
            $item = $orderedItems[$i];
            if (!isset($item->id)) {
                continue;
            }
            $previousItem = $i > 0 ? $orderedItems[$i - 1] : null;
            $nextItem = ($i + 1) < count($orderedItems) ? $orderedItems[$i + 1] : null;
            $previousLookupValue = SiteRouteUtils::getItemLookupValue($previousItem);
            $nextLookupValue = SiteRouteUtils::getItemLookupValue($nextItem);
            $parentLookupValue = '';
            if (isset($item->parent) && $item->parent != '') {
                $parentId = (string) $item->parent;
                if (array_key_exists($parentId, $itemById)) {
                    $parentLookupValue = SiteRouteUtils::getItemLookupValue($itemById[$parentId]);
                }
                else {
                    $parentLookupValue = $parentId;
                }
            }
            $navigationMap[(string) $item->id] = array(
                'previous' => $previousLookupValue != '' ? $apiBasePath . '/v1/items/' . rawurlencode($previousLookupValue) : null,
                'next' => $nextLookupValue != '' ? $apiBasePath . '/v1/items/' . rawurlencode($nextLookupValue) : null,
                'parent' => $parentLookupValue != '' ? $apiBasePath . '/v1/items/' . rawurlencode($parentLookupValue) : null,
                'children' => $apiBasePath . '/v1/items?filter.parent=' . rawurlencode((string) $item->id),
            );
        }
        return $navigationMap;
    };
    $buildHaxElementSchemaFromHtml = function ($html = '') {
        $tags = SiteRouteUtils::extractCustomElementTagsFromHtml($html);
        $schema = array();
        foreach ($tags as $tag => $count) {
            $schema[] = array(
                'tag' => $tag,
                'properties' => array(),
                'content' => '',
            );
        }
        return $schema;
    };
    $buildItemJsonLd = function ($record, $siteBasePath, $siteLanguage) {
        $itemSlug = isset($record['slug']) ? (string) $record['slug'] : '';
        $itemId = isset($record['id']) ? (string) $record['id'] : '';
        $canonicalPath = SiteRouteUtils::buildCanonicalPagePath($siteBasePath, $itemSlug != '' ? $itemSlug : $itemId);
        $metadata = isset($record['metadata']) && is_array($record['metadata']) ? $record['metadata'] : array();
        return array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => (isset($record['links']['self']) ? $record['links']['self'] : $canonicalPath) . '#webpage',
            'url' => isset($record['links']['self']) ? $record['links']['self'] : $canonicalPath,
            'mainEntityOfPage' => $canonicalPath,
            'name' => isset($record['title']) ? $record['title'] : $itemSlug,
            'description' => isset($record['description']) ? $record['description'] : '',
            'inLanguage' => $siteLanguage,
            'identifier' => $itemId,
            'keywords' => isset($record['tags']) ? $record['tags'] : array(),
            'datePublished' => array_key_exists('created', $metadata) ? SiteRouteUtils::toIsoDateFromUnixTime($metadata['created']) : null,
            'dateModified' => array_key_exists('updated', $metadata) ? SiteRouteUtils::toIsoDateFromUnixTime($metadata['updated']) : null,
        );
    };
    $orderedItems = SiteRouteUtils::getOrderedItems($site);
    $navigationMap = $buildItemNavigationMap($orderedItems);
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
        $record = SiteRouteUtils::itemToSummary($item, $apiBasePath);
        if (isset($record['id']) && array_key_exists((string) $record['id'], $navigationMap)) {
            $record['links']['previous'] = $navigationMap[(string) $record['id']]['previous'];
            $record['links']['next'] = $navigationMap[(string) $record['id']]['next'];
            $record['links']['parent'] = $navigationMap[(string) $record['id']]['parent'];
            $record['links']['children'] = $navigationMap[(string) $record['id']]['children'];
        }
        $lookupValue = SiteRouteUtils::getItemLookupValue($item);
        $record['links']['exportDocx'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '/export/docx';
        $record['links']['exportPdf'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '/export/pdf';
        $record['links']['haxElementSchema'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=haxElementSchema';
        $record['links']['jsonld'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=jsonld';
        $record['exports'] = array(
            'docx' => $record['links']['exportDocx'],
            'pdf' => $record['links']['exportPdf'],
        );
        if (in_array('content', $includes, true) || in_array('haxElementSchema', $includes, true)) {
            $content = SiteRouteUtils::getItemContent($site, $item);
            if (in_array('content', $includes, true)) {
                $record['content'] = $content;
            }
            if (in_array('haxElementSchema', $includes, true)) {
                $record['haxElementSchema'] = $buildHaxElementSchemaFromHtml($content);
            }
        }
        $record['jsonld'] = $buildItemJsonLd($record, $siteBasePath, $siteLanguage);
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
    $filteredItems = SiteRouteUtils::applyItemFilters($orderedItems, $site);
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
        $record['links']['exportDocx'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '/export/docx';
        $record['links']['exportPdf'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '/export/pdf';
        $record['links']['haxElementSchema'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=haxElementSchema';
        $record['links']['jsonld'] = $apiBasePath . '/v1/items/' . rawurlencode($lookupValue) . '?include=jsonld';
        $record['exports'] = array(
            'docx' => $record['links']['exportDocx'],
            'pdf' => $record['links']['exportPdf'],
        );
        if (in_array('content', $includes, true) || in_array('haxElementSchema', $includes, true)) {
            $content = SiteRouteUtils::getItemContent($site, $item);
            if (in_array('content', $includes, true)) {
                $record['content'] = $content;
            }
            if (in_array('haxElementSchema', $includes, true)) {
                $record['haxElementSchema'] = $buildHaxElementSchemaFromHtml($content);
            }
        }
        if (in_array('jsonld', $includes, true)) {
            $record['jsonld'] = $buildItemJsonLd($record, $siteBasePath, $siteLanguage);
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
