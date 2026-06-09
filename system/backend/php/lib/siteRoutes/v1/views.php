<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/views'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $normalizeStoredViews = function () use ($site, $apiBasePath) {
        $source = array();
        if (
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->views) &&
            is_array($site->manifest->metadata->site->views)
        ) {
            $source = $site->manifest->metadata->site->views;
        }
        else if (
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->displays) &&
            is_array($site->manifest->metadata->site->displays)
        ) {
            $source = $site->manifest->metadata->site->displays;
        }
        else {
            $source = array(
                array(
                    'id' => 'recent',
                    'title' => 'Recent content',
                    'description' => 'Latest updated pages',
                    'query' => array('source' => 'items', 'sort' => '-metadata.updated'),
                    'display' => array('type' => 'list'),
                ),
                array(
                    'id' => 'tags',
                    'title' => 'Tags',
                    'description' => 'Tag frequency summary',
                    'query' => array('source' => 'tags'),
                    'display' => array('type' => 'facet'),
                ),
                array(
                    'id' => 'search',
                    'title' => 'Search',
                    'description' => 'Search results view',
                    'query' => array('source' => 'search', 'q' => ''),
                    'display' => array('type' => 'list'),
                ),
            );
        }
        $records = array();
        $counter = 1;
        foreach ($source as $view) {
            if (!is_array($view) && !is_object($view)) {
                $counter++;
                continue;
            }
            $value = is_object($view) ? (array) $view : $view;
            $id = '';
            if (isset($value['id']) && trim((string) $value['id']) != '') {
                $id = trim((string) $value['id']);
            }
            else if (isset($value['viewId']) && trim((string) $value['viewId']) != '') {
                $id = trim((string) $value['viewId']);
            }
            else if (isset($value['machineName']) && trim((string) $value['machineName']) != '') {
                $id = trim((string) $value['machineName']);
            }
            else if (isset($value['name']) && trim((string) $value['name']) != '') {
                $id = trim((string) $value['name']);
            }
            else {
                $id = 'view-' . $counter;
            }
            if ($id == '') {
                $counter++;
                continue;
            }
            $title = isset($value['title']) && trim((string) $value['title']) != ''
                ? trim((string) $value['title'])
                : (
                    isset($value['name']) && trim((string) $value['name']) != ''
                        ? trim((string) $value['name'])
                        : $id
                );
            $query = array();
            if (isset($value['query']) && is_array($value['query'])) {
                $query = $value['query'];
            }
            else if (isset($value['query']) && is_object($value['query'])) {
                $query = (array) $value['query'];
            }
            $display = array('type' => 'list');
            if (isset($value['display']) && is_array($value['display'])) {
                $display = $value['display'];
            }
            else if (isset($value['display']) && is_object($value['display'])) {
                $display = (array) $value['display'];
            }
            $records[] = array(
                'id' => $id,
                'title' => $title,
                'description' => isset($value['description']) ? (string) $value['description'] : '',
                'query' => $query,
                'display' => $display,
                'links' => array(
                    'self' => $apiBasePath . '/v1/views/' . rawurlencode($id),
                    'results' => $apiBasePath . '/v1/views/' . rawurlencode($id) . '/results',
                    'displayResults' => $apiBasePath . '/v1/displays/' . rawurlencode($id) . '/results',
                ),
            );
            $counter++;
        }
        return $records;
    };
    $applyViewQueryFilters = function ($items, $viewQuery = array()) {
        $records = $items;
        if (!is_array($viewQuery)) {
            return $records;
        }
        if (isset($viewQuery['region']) && trim((string) $viewQuery['region']) != '') {
            $regionValue = trim((string) $viewQuery['region']);
            $records = array_values(array_filter($records, function ($item) use ($regionValue) {
                return (
                    isset($item->metadata) &&
                    is_object($item->metadata) &&
                    isset($item->metadata->region) &&
                    (string) $item->metadata->region === $regionValue
                );
            }));
        }
        if (isset($viewQuery['tags']) && trim((string) $viewQuery['tags']) != '') {
            $tagList = array_map('strtolower', array_values(array_filter(array_map('trim', explode(',', (string) $viewQuery['tags'])))));
            $records = array_values(array_filter($records, function ($item) use ($tagList) {
                $itemTags = array();
                if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags)) {
                    $itemTags = array_map('strtolower', SiteRouteUtils::normalizeTagList($item->metadata->tags));
                }
                foreach ($tagList as $tag) {
                    if (in_array($tag, $itemTags, true)) {
                        return true;
                    }
                }
                return false;
            }));
        }
        return $records;
    };
    $resolveViewResults = function ($view) use ($site, $apiBasePath, $applyViewQueryFilters) {
        $viewQuery = (isset($view['query']) && is_array($view['query'])) ? $view['query'] : array();
        $source = isset($viewQuery['source']) ? (string) $viewQuery['source'] : 'items';
        if ($source === 'tags') {
            $tagMap = array();
            $items = SiteRouteUtils::getOrderedItems($site);
            foreach ($items as $item) {
                $tags = SiteRouteUtils::normalizeTagList(
                    (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags))
                        ? $item->metadata->tags
                        : array()
                );
                foreach ($tags as $tag) {
                    if (!array_key_exists($tag, $tagMap)) {
                        $tagMap[$tag] = 0;
                    }
                    $tagMap[$tag] += 1;
                }
            }
            $records = array();
            foreach ($tagMap as $tag => $count) {
                $records[] = array('tag' => $tag, 'count' => $count);
            }
            return SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), '-count');
        }
        if ($source === 'search') {
            $query = trim((string) SiteRouteUtils::getQueryValue('q', ''));
            if ($query == '' && isset($viewQuery['q'])) {
                $query = trim((string) $viewQuery['q']);
            }
            if ($query == '') {
                return array();
            }
            $queryLower = strtolower($query);
            $items = SiteRouteUtils::getOrderedItems($site);
            $records = array();
            foreach ($items as $item) {
                $body = SiteRouteUtils::getItemContent($site, $item);
                $tags = SiteRouteUtils::normalizeTagList(
                    (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags))
                        ? $item->metadata->tags
                        : array()
                );
                $haystack = implode(' ', array(
                    isset($item->title) ? (string) $item->title : '',
                    isset($item->description) ? (string) $item->description : '',
                    isset($item->slug) ? (string) $item->slug : '',
                    implode(' ', $tags),
                    (string) $body,
                ));
                if (strpos(strtolower($haystack), $queryLower) === false) {
                    continue;
                }
                $records[] = SiteRouteUtils::itemToSummary($item, $apiBasePath);
            }
            return $records;
        }
        $items = SiteRouteUtils::getOrderedItems($site);
        $items = $applyViewQueryFilters($items, $viewQuery);
        $items = SiteRouteUtils::applyItemFilters($items, $site);
        $records = array();
        foreach ($items as $item) {
            $records[] = SiteRouteUtils::itemToSummary($item, $apiBasePath);
        }
        $viewSort = isset($viewQuery['sort']) ? (string) $viewQuery['sort'] : 'order';
        $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), $viewSort);
        $paged = SiteRouteUtils::paginateRecords($records, 25, 200);
        return SiteRouteUtils::projectCollection($paged['records'], SiteRouteUtils::getCsvQuery('fields'));
    };
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $viewId = isset($context->params['viewId']) ? trim((string) $context->params['viewId']) : '';
    $isResultsRoute = preg_match('/\/results$/', $routeSuffix) === 1;
    $fields = SiteRouteUtils::getCsvQuery('fields');
    $views = $normalizeStoredViews();
    if (!$isResultsRoute && $viewId == '') {
        $records = SiteRouteUtils::sortRecords($views, SiteRouteUtils::getQueryValue('sort', ''), 'id');
        $paged = SiteRouteUtils::paginateRecords($records, 50, 500);
        $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
        SiteRouteUtils::sendFormattedResponse(
            array(
                'count' => count($outputRecords),
                'total' => $paged['page']['total'],
                'page' => $paged['page'],
                'views' => $outputRecords,
                'links' => array(
                    'self' => $apiBasePath . '/v1/views',
                ),
            ),
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    if ($viewId == '') {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'View not found'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $target = null;
    foreach ($views as $record) {
        if (isset($record['id']) && (string) $record['id'] === $viewId) {
            $target = $record;
            break;
        }
    }
    if (!is_array($target)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'View "' . $viewId . '" not found'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (!$isResultsRoute) {
        $outputRecord = SiteRouteUtils::projectRecord($target, $fields);
        SiteRouteUtils::sendFormattedResponse(
            $outputRecord,
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $results = $resolveViewResults($target);
    SiteRouteUtils::sendFormattedResponse(
        array(
            'view' => $target,
            'count' => is_array($results) ? count($results) : 0,
            'results' => $results,
        ),
        array(
            'allowedFormats' => array('json', 'md', 'yaml', 'xml'),
            'defaultFormat' => 'json',
        ),
        $routeSuffix,
        $apiBasePath
    );
};
