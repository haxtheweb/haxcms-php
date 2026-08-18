<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $sendTopLevelError = function ($statusCode, $message) use ($routeSuffix, $apiBasePath) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => (string) $message,
            ),
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
        $sendTopLevelError(404, 'Unable to resolve site context for /x/api/v1/search');
        return;
    }
    $query = trim((string) SiteRouteUtils::getQueryValue('q', ''));
    if ($query == '') {
        $sendTopLevelError(400, 'Query parameter "q" is required');
        return;
    }
    if (strlen($query) > 256) {
        $sendTopLevelError(400, 'Query parameter "q" exceeds 256 characters');
        return;
    }
    // Pure search helpers (normalizeSearchFields, getSearchFieldValue,
    // findMatch) live on SiteRouteUtils so they have a directly testable
    // public seam; this route just delegates to them (no behavior change).
    $searchFields = SiteRouteUtils::normalizeSearchFields(SiteRouteUtils::getCsvQuery('fields'));
    $queryLower = strtolower($query);
    $filteredItems = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site, $context);
    $results = array();
    foreach ($filteredItems as $item) {
        $content = in_array('content', $searchFields, true) ? SiteRouteUtils::getItemContent($site, $item) : '';
        $matches = array();
        $score = 0;
        foreach ($searchFields as $field) {
            $fieldValue = SiteRouteUtils::getSearchFieldValue($field, $item, $content);
            $match = SiteRouteUtils::findMatch($fieldValue, $queryLower);
            if (!is_array($match)) {
                continue;
            }
            $matches[] = array(
                'field' => $field,
                'index' => $match['index'],
                'length' => $match['length'],
                'snippet' => $match['snippet'],
            );
            $score++;
        }
        if (count($matches) == 0) {
            continue;
        }
        $lookup = SiteRouteUtils::getItemLookupValue($item);
        $results[] = array(
            'id' => isset($item->id) ? $item->id : null,
            'title' => isset($item->title) ? $item->title : '',
            'slug' => isset($item->slug) ? $item->slug : '',
            'location' => isset($item->location) ? $item->location : '',
            'score' => $score,
            'snippet' => $matches[0]['snippet'],
            'matches' => $matches,
            'links' => array(
                'item' => $apiBasePath . '/v1/items/' . rawurlencode($lookup),
                'content' => $apiBasePath . '/v1/content/' . rawurlencode($lookup),
            ),
        );
    }
    $sorted = SiteRouteUtils::sortRecords($results, SiteRouteUtils::getQueryValue('sort', ''), '-score');
    $paged = SiteRouteUtils::paginateRecords($sorted, 25, 200);
    $outputFields = SiteRouteUtils::getCsvQuery('fields');
    $outputResults = SiteRouteUtils::projectCollection($paged['records'], $outputFields);
    SiteRouteUtils::sendFormattedResponse(
        array(
            'query' => $query,
            'fields' => $searchFields,
            'count' => count($outputResults),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'results' => $outputResults,
            'links' => array('self' => $apiBasePath . '/v1/search'),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $routeSuffix,
        $apiBasePath
    );
};
