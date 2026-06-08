<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/regions'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $getRegionName = function ($item) {
        if (
            isset($item->metadata) &&
            is_object($item->metadata) &&
            isset($item->metadata->region) &&
            trim((string) $item->metadata->region) != ''
        ) {
            return trim((string) $item->metadata->region);
        }
        return 'default';
    };
    $regionName = isset($context->params['regionName']) ? trim((string) $context->params['regionName']) : '';
    if ($regionName != '') {
        $filteredItems = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site);
        $regionItems = array_values(array_filter($filteredItems, function ($item) use ($regionName, $getRegionName) {
            return $getRegionName($item) === $regionName;
        }));
        if (count($regionItems) == 0) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Region "' . $regionName . '" not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $fields = SiteRouteUtils::getCsvQuery('fields');
        $itemRecords = array();
        foreach ($regionItems as $item) {
            $itemRecords[] = SiteRouteUtils::itemToSummary($item, $apiBasePath);
        }
        $itemRecords = SiteRouteUtils::sortRecords($itemRecords, SiteRouteUtils::getQueryValue('sort', ''), 'order');
        $paged = SiteRouteUtils::paginateRecords($itemRecords, 25, 200);
        $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
        SiteRouteUtils::sendFormattedResponse(
            array(
                'region' => array(
                    'name' => $regionName,
                    'count' => count($regionItems),
                ),
                'page' => $paged['page'],
                'items' => $outputRecords,
                'links' => array(
                    'self' => $apiBasePath . '/v1/regions/' . rawurlencode($regionName),
                ),
            ),
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $filteredItems = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site);
    $regionMap = array();
    foreach ($filteredItems as $item) {
        $name = $getRegionName($item);
        if (!array_key_exists($name, $regionMap)) {
            $regionMap[$name] = 0;
        }
        $regionMap[$name] += 1;
    }
    $records = array();
    foreach ($regionMap as $name => $count) {
        $records[] = array(
            'name' => $name,
            'count' => $count,
            'links' => array(
                'self' => $apiBasePath . '/v1/regions/' . rawurlencode($name),
            ),
        );
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'name');
    $paged = SiteRouteUtils::paginateRecords($records, 100, 1000);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], SiteRouteUtils::getCsvQuery('fields'));
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'regions' => $outputRecords,
            'links' => array(
                'self' => $apiBasePath . '/v1/regions',
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
