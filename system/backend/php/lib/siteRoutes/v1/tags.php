<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/tags'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $includeItems = in_array('items', SiteRouteUtils::getCsvQuery('include'), true);
    $tagFilter = array_map('strtolower', SiteRouteUtils::getCsvQuery('filter.tags'));
    $items = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site);
    $byTag = array();
    foreach ($items as $item) {
        $itemTags = SiteRouteUtils::normalizeTagList(
            (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags))
                ? $item->metadata->tags
                : array()
        );
        foreach ($itemTags as $tag) {
            if (!array_key_exists($tag, $byTag)) {
                $byTag[$tag] = array(
                    'tag' => $tag,
                    'count' => 0,
                    'items' => array(),
                );
            }
            $byTag[$tag]['count'] += 1;
            if ($includeItems && isset($item->id)) {
                $byTag[$tag]['items'][] = $item->id;
            }
        }
    }
    $records = array_values($byTag);
    if (count($tagFilter) > 0) {
        $records = array_values(array_filter($records, function ($record) use ($tagFilter) {
            return in_array(strtolower((string) $record['tag']), $tagFilter, true);
        }));
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), '-count');
    $paged = SiteRouteUtils::paginateRecords($records, 100, 1000);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], SiteRouteUtils::getCsvQuery('fields'));
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'tags' => $outputRecords,
            'links' => array('self' => $apiBasePath . '/v1/tags'),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
