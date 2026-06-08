<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/blocks'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $normalizeEnabledBlocks = function ($input) {
        if (!is_array($input)) {
            return null;
        }
        $output = array();
        foreach ($input as $value) {
            if (!is_string($value)) {
                return null;
            }
            $tag = strtolower(trim($value));
            if ($tag == '' || preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1) {
                return null;
            }
            $output[] = $tag;
        }
        $output = array_values(array_unique($output));
        sort($output);
        return $output;
    };
    $readEnabledBlocksSetting = function () use ($normalizeEnabledBlocks) {
        if (
            !isset($GLOBALS['HAXCMS']) ||
            !is_object($GLOBALS['HAXCMS']) ||
            !isset($GLOBALS['HAXCMS']->configDirectory)
        ) {
            return null;
        }
        $filePath = rtrim($GLOBALS['HAXCMS']->configDirectory, '/') . '/settings/enabledBlocks.json';
        if (!file_exists($filePath)) {
            return null;
        }
        $decoded = json_decode(file_get_contents($filePath), true);
        return $normalizeEnabledBlocks($decoded);
    };
    $getAutoloaderList = function () {
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->config) &&
            isset($GLOBALS['HAXCMS']->config->appStore) &&
            isset($GLOBALS['HAXCMS']->config->appStore->autoloader) &&
            is_array($GLOBALS['HAXCMS']->config->appStore->autoloader)
        ) {
            $list = array();
            foreach ($GLOBALS['HAXCMS']->config->appStore->autoloader as $tag) {
                $cleanTag = strtolower(trim((string) $tag));
                if ($cleanTag != '') {
                    $list[] = $cleanTag;
                }
            }
            if (count($list) > 0) {
                return array_values(array_unique($list));
            }
        }
        return array('grid-plate');
    };
    $parseImportPath = function ($value) {
        if (is_string($value)) {
            return $value;
        }
        if (is_object($value)) {
            if (isset($value->path) && is_string($value->path) && $value->path != '') {
                return $value->path;
            }
            if (isset($value->import) && is_string($value->import) && $value->import != '') {
                return $value->import;
            }
        }
        if (is_array($value)) {
            if (isset($value['path']) && is_string($value['path']) && $value['path'] != '') {
                return $value['path'];
            }
            if (isset($value['import']) && is_string($value['import']) && $value['import'] != '') {
                return $value['import'];
            }
        }
        return '';
    };
    $parsePackageName = function ($importPath = '') {
        $cleanImport = trim((string) $importPath);
        if ($cleanImport == '') {
            return '';
        }
        $parts = array_values(array_filter(explode('/', $cleanImport), function ($part) {
            return $part !== '';
        }));
        if (count($parts) == 0) {
            return '';
        }
        if (strpos($parts[0], '@') === 0 && isset($parts[1])) {
            return $parts[0] . '/' . $parts[1];
        }
        return $parts[0];
    };
    $buildSchemaFragments = function ($tag, $importPath) {
        return array(
            'haxProperties' => array(
                'gizmo' => array(
                    'title' => $tag,
                    'tag' => $tag,
                    'icon' => 'icons:extension',
                ),
                'settings' => array(
                    'configure' => array(),
                    'advanced' => array(),
                    'developer' => array(),
                ),
                'source' => $importPath,
            ),
            'haxSchema' => array(
                'api' => '1',
                'canScale' => true,
                'canPosition' => true,
                'canEditSource' => true,
            ),
            'haxElementSchema' => array(
                'tag' => $tag,
                'properties' => array(),
                'content' => '',
            ),
        );
    };
    $getBlockSchemaLinks = function ($tag) use ($apiBasePath) {
        $encoded = rawurlencode($tag);
        return array(
            'haxProperties' => $apiBasePath . '/v1/schemas?filter.kind=haxProperties&filter.webcomponentName=' . $encoded,
            'haxSchema' => $apiBasePath . '/v1/schemas?filter.kind=haxSchema&filter.webcomponentName=' . $encoded,
            'haxElementSchema' => $apiBasePath . '/v1/schemas?filter.kind=haxElementSchema&filter.webcomponentName=' . $encoded,
        );
    };
    $countTagUsageInHtml = function ($tag, $html = '') {
        $cleanTag = strtolower(trim((string) $tag));
        if ($cleanTag == '') {
            return 0;
        }
        if (!is_string($html) || $html == '') {
            return 0;
        }
        $count = preg_match_all('/<' . preg_quote($cleanTag, '/') . '\b/i', $html, $matches);
        return is_numeric($count) ? intval($count) : 0;
    };
    $getWcMap = function () use ($site) {
        $wcMap = new stdClass();
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'getWCRegistryJson')
        ) {
            $wcMap = $GLOBALS['HAXCMS']->getWCRegistryJson($site);
        }
        if (!is_object($wcMap)) {
            $siteDirectory = SiteRouteUtils::getSiteDirectory($site);
            $wcPath = rtrim($siteDirectory, '/') . '/wc-registry.json';
            if (file_exists($wcPath)) {
                $decoded = json_decode(file_get_contents($wcPath));
                if (is_object($decoded)) {
                    $wcMap = $decoded;
                }
            }
        }
        if (!is_object($wcMap)) {
            $wcMap = new stdClass();
        }
        return $wcMap;
    };
    $wcMapHasTag = function ($wcMap, $tag) {
        if (is_object($wcMap)) {
            return isset($wcMap->{$tag});
        }
        if (is_array($wcMap)) {
            return array_key_exists($tag, $wcMap);
        }
        return false;
    };
    $buildBlockRecord = function (
        $tag,
        $usageCount,
        $usageItemIds,
        $include,
        $enabledBlockSet,
        $wcMap,
        $usedInDetails = null
    ) use ($apiBasePath, $parseImportPath, $parsePackageName, $buildSchemaFragments, $getBlockSchemaLinks) {
        $importPath = '';
        if (is_object($wcMap) && isset($wcMap->{$tag})) {
            $importPath = $parseImportPath($wcMap->{$tag});
        }
        else if (is_array($wcMap) && array_key_exists($tag, $wcMap)) {
            $importPath = $parseImportPath($wcMap[$tag]);
        }
        $hasExplicitEnabledList = is_array($enabledBlockSet) && count($enabledBlockSet) > 0;
        $enabled = $hasExplicitEnabledList ? in_array($tag, $enabledBlockSet, true) : true;
        $schemaLinks = $getBlockSchemaLinks($tag);
        $record = array(
            'tag' => $tag,
            'enabled' => $enabled,
            'usageCount' => intval($usageCount),
            'used' => intval($usageCount) > 0,
            'usedIn' => $usageItemIds,
            'import' => $importPath,
            'package' => $parsePackageName($importPath),
            'links' => array(
                'self' => $apiBasePath . '/v1/blocks/' . rawurlencode($tag),
                'customElement' => $apiBasePath . '/v1/custom-elements/' . rawurlencode($tag),
                'usage' => $apiBasePath . '/v1/blocks/' . rawurlencode($tag) . '/usage',
            ),
            'related' => array(
                array('rel' => 'entity', 'type' => 'block', 'href' => $apiBasePath . '/v1/entities#block'),
                array('rel' => 'schema', 'type' => 'haxProperties', 'href' => $schemaLinks['haxProperties']),
                array('rel' => 'schema', 'type' => 'haxSchema', 'href' => $schemaLinks['haxSchema']),
                array('rel' => 'schema', 'type' => 'haxElementSchema', 'href' => $schemaLinks['haxElementSchema']),
            ),
        );
        $schemaFragments = $buildSchemaFragments($tag, $importPath);
        if (in_array('haxProperties', $include, true)) {
            $record['haxProperties'] = $schemaFragments['haxProperties'];
        }
        if (in_array('haxSchema', $include, true)) {
            $record['haxSchema'] = $schemaFragments['haxSchema'];
        }
        if (is_array($usedInDetails)) {
            $record['usedInDetails'] = $usedInDetails;
        }
        return $record;
    };
    $buildBlockUsageRecords = function ($items, $webcomponentName) use ($site, $apiBasePath, $countTagUsageInHtml) {
        $records = array();
        foreach ($items as $item) {
            $html = SiteRouteUtils::getItemContent($site, $item);
            $usageCount = $countTagUsageInHtml($webcomponentName, $html);
            if ($usageCount < 1) {
                continue;
            }
            $record = SiteRouteUtils::itemToSummary($item, $apiBasePath);
            $record['usageCount'] = $usageCount;
            $record['links']['block'] = $apiBasePath . '/v1/blocks/' . rawurlencode($webcomponentName);
            $record['links']['blockUsage'] = $apiBasePath . '/v1/blocks/' . rawurlencode($webcomponentName) . '/usage';
            $records[] = $record;
        }
        return $records;
    };
    $buildBlockUsageDetails = function ($items, $webcomponentName) use ($site, $apiBasePath, $countTagUsageInHtml) {
        $details = array();
        foreach ($items as $item) {
            $html = SiteRouteUtils::getItemContent($site, $item);
            $usageCount = $countTagUsageInHtml($webcomponentName, $html);
            if ($usageCount < 1) {
                continue;
            }
            $summary = SiteRouteUtils::itemToSummary($item, $apiBasePath);
            $details[] = array(
                'id' => $summary['id'],
                'slug' => $summary['slug'],
                'title' => $summary['title'],
                'location' => $summary['location'],
                'usageCount' => $usageCount,
                'links' => array(
                    'self' => $summary['links']['self'],
                    'content' => $summary['links']['content'],
                ),
            );
        }
        return $details;
    };
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $isUsageRoute = preg_match('/\/usage$/', $routeSuffix) === 1;
    $webcomponentName = isset($context->params['webcomponentName'])
        ? strtolower(trim((string) $context->params['webcomponentName']))
        : '';
    $include = SiteRouteUtils::getCsvQuery('include');
    $fields = SiteRouteUtils::getCsvQuery('fields');
    $wcMap = $getWcMap();
    $autoloader = $getAutoloaderList();
    $enabledBlocks = $readEnabledBlocksSetting();
    $enabledBlockSet = is_array($enabledBlocks) ? $enabledBlocks : array();
    if ($isUsageRoute) {
        if ($webcomponentName == '') {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Block not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $routeSuffix,
                $apiBasePath
            );
            return;
        }
        $filteredItems = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site);
        $usageTotals = SiteRouteUtils::collectCustomElementUsage($site, $filteredItems);
        $known = $wcMapHasTag($wcMap, $webcomponentName) ||
            array_key_exists($webcomponentName, $usageTotals) ||
            in_array($webcomponentName, $autoloader, true);
        if (!$known) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Block "' . $webcomponentName . '" not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $routeSuffix,
                $apiBasePath
            );
            return;
        }
        $records = $buildBlockUsageRecords($filteredItems, $webcomponentName);
        $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), '-usageCount');
        $paged = SiteRouteUtils::paginateRecords($records, 25, 500);
        $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
        SiteRouteUtils::sendFormattedResponse(
            array(
                'block' => $webcomponentName,
                'count' => count($outputRecords),
                'total' => $paged['page']['total'],
                'page' => $paged['page'],
                'items' => $outputRecords,
                'links' => array(
                    'self' => $apiBasePath . '/v1/blocks/' . rawurlencode($webcomponentName) . '/usage',
                    'block' => $apiBasePath . '/v1/blocks/' . rawurlencode($webcomponentName),
                ),
            ),
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    if ($webcomponentName != '') {
        $orderedItems = SiteRouteUtils::getOrderedItems($site);
        $usageDetails = $buildBlockUsageDetails($orderedItems, $webcomponentName);
        $usageItemIds = array();
        $usageCount = 0;
        foreach ($usageDetails as $detail) {
            if (isset($detail['id']) && trim((string) $detail['id']) != '') {
                $usageItemIds[] = (string) $detail['id'];
            }
            $usageCount += isset($detail['usageCount']) ? intval($detail['usageCount']) : 0;
        }
        $usageItemIds = array_values(array_unique($usageItemIds));
        $known = $wcMapHasTag($wcMap, $webcomponentName) ||
            in_array($webcomponentName, $autoloader, true) ||
            count($usageDetails) > 0;
        if (!$known) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Block "' . $webcomponentName . '" not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $routeSuffix,
                $apiBasePath
            );
            return;
        }
        $record = $buildBlockRecord(
            $webcomponentName,
            $usageCount,
            $usageItemIds,
            $include,
            $enabledBlockSet,
            $wcMap,
            $usageDetails
        );
        $outputRecord = SiteRouteUtils::projectRecord($record, $fields);
        SiteRouteUtils::sendFormattedResponse(
            $outputRecord,
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $filteredItems = SiteRouteUtils::applyItemFilters(SiteRouteUtils::getOrderedItems($site), $site);
    $usage = SiteRouteUtils::collectCustomElementUsage($site, $filteredItems);
    $filterTag = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.tag', '')));
    $tagSet = array();
    foreach ($usage as $tag => $count) {
        $cleanTag = strtolower(trim((string) $tag));
        if ($cleanTag != '') {
            $tagSet[$cleanTag] = true;
        }
    }
    foreach ($autoloader as $tag) {
        $cleanTag = strtolower(trim((string) $tag));
        if ($cleanTag != '') {
            $tagSet[$cleanTag] = true;
        }
    }
    if ($filterTag != '') {
        $tagSet[$filterTag] = true;
    }
    $records = array();
    foreach (array_keys($tagSet) as $tag) {
        $records[] = $buildBlockRecord(
            $tag,
            array_key_exists($tag, $usage) ? intval($usage[$tag]) : 0,
            array(),
            $include,
            $enabledBlockSet,
            $wcMap
        );
    }
    if ($filterTag != '') {
        $records = array_values(array_filter($records, function ($record) use ($filterTag) {
            return strpos((string) $record['tag'], $filterTag) !== false;
        }));
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), '-usageCount');
    $paged = SiteRouteUtils::paginateRecords($records, 100, 2000);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'blocks' => $outputRecords,
            'links' => array(
                'self' => $apiBasePath . '/v1/blocks',
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $routeSuffix,
        $apiBasePath
    );
};
