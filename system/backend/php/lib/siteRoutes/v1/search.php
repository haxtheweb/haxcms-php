<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/search'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $query = trim((string) SiteRouteUtils::getQueryValue('q', ''));
    if ($query == '') {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Query parameter "q" is required'),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (strlen($query) > 256) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Query parameter "q" exceeds 256 characters'),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $normalizeSearchFields = function ($fields = array()) {
        $allowed = array('id', 'title', 'slug', 'description', 'tags', 'content', 'location');
        if (!is_array($fields) || count($fields) == 0) {
            return array('title', 'slug', 'description', 'tags', 'content');
        }
        $normalized = array();
        foreach ($fields as $field) {
            $value = strtolower(trim((string) $field));
            if ($value != '' && in_array($value, $allowed, true) && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        if (count($normalized) == 0) {
            return array('title', 'slug', 'description', 'tags', 'content');
        }
        return $normalized;
    };
    $getSearchFieldValue = function ($field, $item, $content = '') {
        if ($field == 'id') {
            return isset($item->id) ? (string) $item->id : '';
        }
        if ($field == 'title') {
            return isset($item->title) ? (string) $item->title : '';
        }
        if ($field == 'slug') {
            return isset($item->slug) ? (string) $item->slug : '';
        }
        if ($field == 'description') {
            return isset($item->description) ? (string) $item->description : '';
        }
        if ($field == 'location') {
            return isset($item->location) ? (string) $item->location : '';
        }
        if ($field == 'tags') {
            $tags = SiteRouteUtils::normalizeTagList(
                (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags))
                    ? $item->metadata->tags
                    : array()
            );
            return implode(' ', $tags);
        }
        if ($field == 'content') {
            return is_string($content) ? $content : '';
        }
        return '';
    };
    $findMatch = function ($value, $queryLower) {
        $source = (string) $value;
        if ($source == '') {
            return null;
        }
        $sourceLower = strtolower($source);
        $index = strpos($sourceLower, $queryLower);
        if ($index === false) {
            return null;
        }
        $snippetStart = max($index - 60, 0);
        $snippetEnd = min($index + strlen($queryLower) + 60, strlen($source));
        $snippet = preg_replace('/\s+/', ' ', substr($source, $snippetStart, $snippetEnd - $snippetStart));
        return array(
            'index' => intval($index),
            'length' => strlen($queryLower),
            'snippet' => trim((string) $snippet),
        );
    };
    $searchFields = $normalizeSearchFields(SiteRouteUtils::getCsvQuery('fields'));
    $queryLower = strtolower($query);
    $filteredItems = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site);
    $results = array();
    foreach ($filteredItems as $item) {
        $content = in_array('content', $searchFields, true) ? SiteRouteUtils::getItemContent($site, $item) : '';
        $matches = array();
        $score = 0;
        foreach ($searchFields as $field) {
            $fieldValue = $getSearchFieldValue($field, $item, $content);
            $match = $findMatch($fieldValue, $queryLower);
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
        $context->routeSuffix,
        $apiBasePath
    );
};
