<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/custom-elements'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
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
                'slots' => array(),
            ),
        );
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
    $buildCustomElementRecords = function ($include = array()) use (
        $getWcMap,
        $apiBasePath,
        $parseImportPath,
        $parsePackageName,
        $buildSchemaFragments
    ) {
        $wcMap = $getWcMap();
        $records = array();
        foreach ($wcMap as $key => $value) {
            $tag = trim((string) $key);
            if ($tag == '') {
                continue;
            }
            $importPath = $parseImportPath($value);
            $record = array(
                'tag' => $tag,
                'import' => $importPath,
                'package' => $parsePackageName($importPath),
                'description' => '',
                'links' => array(
                    'self' => $apiBasePath . '/v1/custom-elements/' . rawurlencode($tag),
                    'blocks' => $apiBasePath . '/v1/blocks/' . rawurlencode($tag),
                ),
            );
            $schemaFragments = $buildSchemaFragments($tag, $importPath);
            if (in_array('haxProperties', $include, true)) {
                $record['haxProperties'] = $schemaFragments['haxProperties'];
            }
            if (in_array('haxSchema', $include, true)) {
                $record['haxSchema'] = $schemaFragments['haxSchema'];
            }
            if (in_array('haxElementSchema', $include, true)) {
                $record['haxElementSchema'] = $schemaFragments['haxElementSchema'];
            }
            $records[] = $record;
        }
        return $records;
    };
    $include = SiteRouteUtils::getCsvQuery('include');
    $fields = SiteRouteUtils::getCsvQuery('fields');
    $webcomponentName = isset($context->params['webcomponentName']) ? trim((string) $context->params['webcomponentName']) : '';
    if ($webcomponentName != '') {
        $records = $buildCustomElementRecords($include);
        $target = null;
        foreach ($records as $record) {
            if (strtolower((string) $record['tag']) === strtolower($webcomponentName)) {
                $target = $record;
                break;
            }
        }
        if (!is_array($target)) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Custom element "' . $webcomponentName . '" not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $outputRecord = SiteRouteUtils::projectRecord($target, $fields);
        SiteRouteUtils::sendFormattedResponse(
            $outputRecord,
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $filterTag = strtolower(trim((string) SiteRouteUtils::getQueryValue('filter.tag', '')));
    $records = $buildCustomElementRecords($include);
    if ($filterTag != '') {
        $records = array_values(array_filter($records, function ($record) use ($filterTag) {
            return strpos(strtolower((string) $record['tag']), $filterTag) !== false;
        }));
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'tag');
    $paged = SiteRouteUtils::paginateRecords($records, 100, 2000);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'customElements' => $outputRecords,
            'links' => array('self' => $apiBasePath . '/v1/custom-elements'),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
