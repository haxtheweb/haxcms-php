<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/content'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $modeValue = trim((string) SiteRouteUtils::getQueryValue('mode', 'bundle'));
    $mode = $modeValue == 'concat' ? 'concat' : 'bundle';
    $fields = SiteRouteUtils::getCsvQuery('fields');
    $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
    $buildContentLinks = function ($itemLookupValue = '', $itemSlug = '') use ($apiBasePath, $siteBasePath) {
        $canonicalPagePath = SiteRouteUtils::buildCanonicalPagePath($siteBasePath, $itemSlug);
        return array(
            'self' => $apiBasePath . '/v1/content/' . rawurlencode($itemLookupValue),
            'item' => $apiBasePath . '/v1/items/' . rawurlencode($itemLookupValue),
            'page' => $canonicalPagePath,
            'json' => $canonicalPagePath . '.json',
            'md' => $canonicalPagePath . '.md',
            'yaml' => $canonicalPagePath . '.yaml',
            'xml' => $canonicalPagePath . '.xml',
            'html' => $canonicalPagePath . '.html',
        );
    };
    $buildConcatMarkdown = function ($records) {
        $sections = array();
        foreach ($records as $record) {
            $sections[] = '# ' . (isset($record['title']) && $record['title'] != '' ? $record['title'] : (isset($record['slug']) ? $record['slug'] : 'Untitled'));
            $sections[] = '';
            $sections[] = isset($record['body']) ? (string) $record['body'] : '';
            $sections[] = '';
        }
        return trim(implode("\n", $sections));
    };
    $buildConcatHtml = function ($records) {
        $sections = array();
        foreach ($records as $record) {
            $sections[] = '<article data-item-id="' . SiteRouteUtils::escapeHtmlValue(isset($record['id']) ? $record['id'] : '') . '"><h2>' .
                SiteRouteUtils::escapeHtmlValue(isset($record['title']) ? $record['title'] : 'Untitled') .
                '</h2>' . (isset($record['body']) ? (string) $record['body'] : '') . '</article>';
        }
        return implode("\n", $sections);
    };
    if (isset($context->params['idOrSlug']) && $context->params['idOrSlug'] != '') {
        $item = SiteRouteUtils::findItemByIdOrSlug($site, $context->params['idOrSlug']);
        if (!$item) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Content not found for idOrSlug "' . $context->params['idOrSlug'] . '"'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $body = SiteRouteUtils::getItemContent($site, $item);
        $record = SiteRouteUtils::contentToRecord($item, $body);
        $record['mode'] = $mode;
        $lookupValue = SiteRouteUtils::getItemLookupValue($item);
        $record['links'] = $buildContentLinks($lookupValue, isset($item->slug) ? (string) $item->slug : $lookupValue);
        if (count($fields) > 0) {
            $record = SiteRouteUtils::projectRecord($record, $fields);
        }
        $rawByFormat = array();
        if ($mode == 'concat') {
            $rawByFormat['md'] = isset($record['body']) ? (string) $record['body'] : '';
            $rawByFormat['html'] = isset($record['body']) ? (string) $record['body'] : '';
        }
        SiteRouteUtils::sendFormattedResponse(
            $record,
            array(
                'allowedFormats' => array('json', 'md', 'yaml', 'xml', 'html'),
                'defaultFormat' => 'json',
                'rawByFormat' => $rawByFormat,
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $orderedItems = SiteRouteUtils::getOrderedItems($site);
    $filteredItems = SiteRouteUtils::applyItemFilters($orderedItems, $site);
    $records = array();
    foreach ($filteredItems as $item) {
        $body = SiteRouteUtils::getItemContent($site, $item);
        $record = SiteRouteUtils::contentToRecord($item, $body);
        $lookupValue = SiteRouteUtils::getItemLookupValue($item);
        $itemSlug = isset($item->slug) ? (string) $item->slug : $lookupValue;
        $record['links'] = $buildContentLinks($lookupValue, $itemSlug);
        $records[] = $record;
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'title');
    $paged = SiteRouteUtils::paginateRecords($records, 25, 200);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
    $responseData = array(
        'mode' => $mode,
        'count' => count($outputRecords),
        'total' => $paged['page']['total'],
        'page' => $paged['page'],
        'content' => $mode == 'concat' ? $buildConcatMarkdown($outputRecords) : $outputRecords,
        'links' => array(
            'self' => $apiBasePath . '/v1/content',
        ),
    );
    $rawByFormat = array();
    if ($mode == 'concat') {
        $rawByFormat['md'] = $buildConcatMarkdown($outputRecords);
        $rawByFormat['html'] = $buildConcatHtml($outputRecords);
    }
    SiteRouteUtils::sendFormattedResponse(
        $responseData,
        array(
            'allowedFormats' => array('json', 'md', 'yaml', 'xml', 'html'),
            'defaultFormat' => 'json',
            'rawByFormat' => $rawByFormat,
        ),
        $context->routeSuffix,
        $apiBasePath
    );
};
