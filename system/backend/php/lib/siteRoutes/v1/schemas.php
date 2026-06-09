<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/schemas'),
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
    $normalizeTagName = function ($value = '') {
        return strtolower(trim((string) $value));
    };
    $getWebcomponentImportPath = function ($webcomponentName = '') use ($site, $normalizeTagName, $parseImportPath) {
        $targetTag = $normalizeTagName($webcomponentName);
        if ($targetTag == '') {
            return '';
        }
        $wcMap = null;
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'getWCRegistryJson')
        ) {
            $wcMap = $GLOBALS['HAXCMS']->getWCRegistryJson($site);
        }
        if (is_object($wcMap)) {
            if (isset($wcMap->{$targetTag})) {
                return $parseImportPath($wcMap->{$targetTag});
            }
            foreach ($wcMap as $key => $value) {
                if ($normalizeTagName($key) === $targetTag) {
                    return $parseImportPath($value);
                }
            }
        }
        if (is_array($wcMap)) {
            if (array_key_exists($targetTag, $wcMap)) {
                return $parseImportPath($wcMap[$targetTag]);
            }
            foreach ($wcMap as $key => $value) {
                if ($normalizeTagName($key) === $targetTag) {
                    return $parseImportPath($value);
                }
            }
        }
        return '';
    };
    $getPathWithoutExtension = function ($filePath = '') {
        $cleanPath = (string) $filePath;
        $extension = pathinfo($cleanPath, PATHINFO_EXTENSION);
        if ($extension == '') {
            return $cleanPath;
        }
        return substr($cleanPath, 0, -1 * (strlen($extension) + 1));
    };
    $getHaxPropertiesSearchRoots = function () use ($site) {
        $rootCandidates = array();
        if (isset($site->siteDirectory) && is_string($site->siteDirectory) && $site->siteDirectory != '') {
            $rootCandidates[] = rtrim($site->siteDirectory, '/') . '/build/es6/node_modules';
            $rootCandidates[] = rtrim($site->siteDirectory, '/') . '/node_modules';
        }
        $rootCandidates[] = dirname(__FILE__) . '/../../public/build/es6/node_modules';
        $rootCandidates[] = dirname(__FILE__) . '/../../../node_modules';
        $rootCandidates[] = rtrim(getcwd(), '/') . '/src/public/build/es6/node_modules';
        $rootCandidates[] = rtrim(getcwd(), '/') . '/node_modules';
        $roots = array();
        foreach ($rootCandidates as $candidate) {
            $normalized = str_replace('\\', '/', (string) $candidate);
            if ($normalized == '' || in_array($normalized, $roots, true)) {
                continue;
            }
            if (is_dir($normalized)) {
                $roots[] = $normalized;
            }
        }
        return $roots;
    };
    $buildHaxPropertiesCandidatePaths = function ($searchRoot, $importPath, $webcomponentName) use (
        $parsePackageName,
        $normalizeTagName,
        $getPathWithoutExtension
    ) {
        $candidates = array();
        $packageName = $parsePackageName($importPath);
        $cleanRoot = rtrim((string) $searchRoot, '/');
        $cleanImport = ltrim((string) $importPath, '/');
        $importFilePath = $cleanRoot . '/' . $cleanImport;
        $importDirectory = dirname($importFilePath);
        $importBaseName = pathinfo($importFilePath, PATHINFO_FILENAME);
        $importFilePathNoExt = $getPathWithoutExtension($importFilePath);
        $packageRoot = $packageName != '' ? ($cleanRoot . '/' . $packageName) : '';
        $tag = $normalizeTagName($webcomponentName);
        $candidates[] = $importFilePathNoExt . '.haxProperties.json';
        $candidates[] = $importDirectory . '/' . $importBaseName . '.haxProperties.json';
        $candidates[] = $importDirectory . '/lib/' . $importBaseName . '.haxProperties.json';
        if ($packageRoot != '') {
            $candidates[] = $packageRoot . '/lib/' . $importBaseName . '.haxProperties.json';
            if ($tag != '') {
                $candidates[] = $packageRoot . '/lib/' . $tag . '.haxProperties.json';
                $candidates[] = $packageRoot . '/' . $tag . '.haxProperties.json';
            }
        }
        $uniqueCandidates = array();
        foreach ($candidates as $candidate) {
            $normalized = str_replace('\\', '/', (string) $candidate);
            if ($normalized != '' && !in_array($normalized, $uniqueCandidates, true)) {
                $uniqueCandidates[] = $normalized;
            }
        }
        return $uniqueCandidates;
    };
    $readJsonFile = function ($filePath = '') {
        if (!is_file($filePath)) {
            return null;
        }
        $decoded = json_decode(file_get_contents($filePath), true);
        return is_array($decoded) ? $decoded : null;
    };
    $loadWebcomponentHaxProperties = function ($webcomponentName = '') use (
        $getWebcomponentImportPath,
        $getHaxPropertiesSearchRoots,
        $buildHaxPropertiesCandidatePaths,
        $readJsonFile
    ) {
        $importPath = $getWebcomponentImportPath($webcomponentName);
        if ($importPath == '') {
            return null;
        }
        $roots = $getHaxPropertiesSearchRoots();
        foreach ($roots as $root) {
            $candidates = $buildHaxPropertiesCandidatePaths($root, $importPath, $webcomponentName);
            foreach ($candidates as $candidatePath) {
                if (!is_file($candidatePath)) {
                    continue;
                }
                $parsed = $readJsonFile($candidatePath);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }
        return null;
    };
    $buildHaxSchemaFromProperties = function ($webcomponentTag, $haxProperties) {
        if (!is_array($haxProperties)) {
            return array(
                'tag' => $webcomponentTag,
                'type' => 'object',
                'properties' => array(
                    'api' => array('type' => 'string'),
                    'canScale' => array('type' => 'boolean'),
                    'canPosition' => array('type' => 'boolean'),
                    'canEditSource' => array('type' => 'boolean'),
                ),
            );
        }
        $api = array_key_exists('api', $haxProperties) ? $haxProperties['api'] : '1';
        $canScale = array_key_exists('canScale', $haxProperties) ? boolval($haxProperties['canScale']) : true;
        $canPosition = array_key_exists('canPosition', $haxProperties) ? boolval($haxProperties['canPosition']) : true;
        $canEditSource = array_key_exists('canEditSource', $haxProperties) ? boolval($haxProperties['canEditSource']) : true;
        return array(
            'tag' => $webcomponentTag,
            'api' => $api,
            'canScale' => $canScale,
            'canPosition' => $canPosition,
            'canEditSource' => $canEditSource,
        );
    };
    $buildHaxPropertiesSchema = function ($webcomponentTag, $haxProperties) {
        if (!is_array($haxProperties)) {
            return array(
                'tag' => $webcomponentTag,
                'type' => 'object',
                'properties' => array(
                    'gizmo' => array('type' => 'object'),
                    'settings' => array(
                        'type' => 'object',
                        'properties' => array(
                            'configure' => array('type' => 'array'),
                            'advanced' => array('type' => 'array'),
                            'developer' => array('type' => 'array'),
                        ),
                    ),
                ),
            );
        }
        $schema = $haxProperties;
        $schema['tag'] = $webcomponentTag;
        return $schema;
    };
    $buildSchemaDescriptors = function ($webcomponentName = '', $webcomponentHaxProperties = null) use (
        $apiBasePath,
        $buildHaxPropertiesSchema,
        $buildHaxSchemaFromProperties
    ) {
        $webcomponentTag = trim((string) $webcomponentName) != '' ? trim((string) $webcomponentName) : '*';
        $haxPropertiesSchema = $buildHaxPropertiesSchema($webcomponentTag, $webcomponentHaxProperties);
        $haxSchema = $buildHaxSchemaFromProperties($webcomponentTag, $webcomponentHaxProperties);
        return array(
            array(
                'id' => 'json-outline-schema',
                'title' => 'JSON Outline Schema',
                'version' => '1.0.0',
                'kind' => 'jsonOutlineSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('site', 'item', 'content'),
                'links' => array('spec' => 'https://github.com/haxtheweb/json-outline-schema'),
                'schema' => array(
                    'type' => 'object',
                    'required' => array('id', 'title', 'items'),
                    'properties' => array(
                        'id' => array('type' => 'string'),
                        'title' => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                        'metadata' => array('type' => 'object'),
                        'items' => array('type' => 'array', 'items' => array('type' => 'object')),
                    ),
                ),
            ),
            array(
                'id' => 'json-outline-schema-item',
                'title' => 'JSON Outline Schema Item',
                'version' => '1.0.0',
                'kind' => 'jsonOutlineSchemaItem',
                'mediaType' => 'application/json',
                'appliesTo' => array('item', 'content'),
                'links' => array('spec' => 'https://github.com/haxtheweb/json-outline-schema'),
                'schema' => array(
                    'type' => 'object',
                    'required' => array('id', 'title', 'slug', 'location'),
                    'properties' => array(
                        'id' => array('type' => 'string'),
                        'title' => array('type' => 'string'),
                        'slug' => array('type' => 'string'),
                        'location' => array('type' => 'string'),
                        'parent' => array('type' => array('string', 'null')),
                        'indent' => array('type' => 'number'),
                        'order' => array('type' => 'number'),
                        'description' => array('type' => 'string'),
                        'metadata' => array('type' => 'object'),
                    ),
                ),
            ),
            array(
                'id' => 'oer-schema',
                'title' => 'OER Schema',
                'version' => '0.3.4',
                'kind' => 'oerSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('site', 'item', 'content'),
                'links' => array('spec' => 'https://github.com/open-curriculum/oerschema'),
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        '@context' => array('type' => array('string', 'object')),
                        '@type' => array('type' => 'string'),
                        'name' => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                        'uri' => array('type' => 'string'),
                        'sameAs' => array('type' => 'string'),
                        'forCourse' => array('type' => array('string', 'object')),
                        'hasLearningObjective' => array('type' => array('array', 'object')),
                    ),
                    'additionalProperties' => true,
                ),
            ),
            array(
                'id' => 'hax-properties',
                'title' => 'HAX Properties',
                'version' => '1.0.0',
                'kind' => 'haxProperties',
                'mediaType' => 'application/json',
                'appliesTo' => array('block', 'customElement'),
                'links' => array('spec' => 'https://github.com/haxtheweb/hax-schema'),
                'schema' => $haxPropertiesSchema,
            ),
            array(
                'id' => 'hax-element-schema',
                'title' => 'HAX Element Schema',
                'version' => '1.0.0',
                'kind' => 'haxElementSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('block', 'customElement'),
                'links' => array('spec' => 'https://github.com/haxtheweb/hax-element-schema'),
                'schema' => array(
                    'tag' => $webcomponentTag,
                    'type' => 'object',
                    'properties' => array(
                        'tag' => array('type' => 'string'),
                        'properties' => array('type' => 'array'),
                        'slots' => array('type' => 'array'),
                    ),
                ),
            ),
            array(
                'id' => 'hax-schema',
                'title' => 'HAX Schema',
                'version' => '1.0.0',
                'kind' => 'haxSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('block', 'customElement'),
                'links' => array('spec' => 'https://github.com/haxtheweb/hax-schema'),
                'schema' => $haxSchema,
            ),
            array(
                'id' => 'app-store-schema',
                'title' => 'HAX App Store Schema',
                'version' => '1.0.0',
                'kind' => 'appStoreSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('customElement', 'block'),
                'links' => array('spec' => 'https://github.com/haxtheweb/appstore-spec'),
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'details' => array('type' => 'object'),
                        'connection' => array('type' => 'object'),
                    ),
                ),
            ),
            array(
                'id' => 'view-display-schema',
                'title' => 'View/Display Schema',
                'version' => '1.0.0',
                'kind' => 'viewSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('view'),
                'links' => array('views' => $apiBasePath . '/v1/views'),
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'string'),
                        'title' => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                        'query' => array('type' => 'object'),
                        'display' => array('type' => 'object'),
                    ),
                ),
            ),
            array(
                'id' => 'xapi-statement-schema',
                'title' => 'xAPI Statement',
                'version' => '1.0.3',
                'kind' => 'xapi',
                'mediaType' => 'application/xapi+json',
                'appliesTo' => array('analytics'),
                'links' => array('spec' => 'https://github.com/adlnet/xAPI-Spec/blob/master/xAPI-Data.md#statement'),
                'schema' => array(
                    'type' => 'object',
                    'required' => array('actor', 'verb', 'object'),
                    'properties' => array(
                        'id' => array('type' => 'string'),
                        'actor' => array('type' => 'object'),
                        'verb' => array('type' => 'object'),
                        'object' => array('type' => 'object'),
                        'result' => array('type' => 'object'),
                        'context' => array('type' => 'object'),
                        'timestamp' => array('type' => 'string'),
                    ),
                    'additionalProperties' => true,
                ),
            ),
            array(
                'id' => 'query-contract-schema',
                'title' => 'Site API Query Contract',
                'version' => '1.0.0',
                'kind' => 'queryContract',
                'mediaType' => 'application/json',
                'appliesTo' => array('items', 'content', 'files', 'search', 'reports', 'views'),
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'filter.*' => array('type' => 'object'),
                        'page.limit' => array('type' => 'number'),
                        'page.offset' => array('type' => 'number'),
                        'sort' => array('type' => 'string'),
                        'fields' => array('type' => 'string'),
                        'include' => array('type' => 'string'),
                        'format' => array(
                            'type' => 'string',
                            'enum' => array('json', 'md', 'yaml', 'xml', 'html', 'xapi'),
                        ),
                        'mode' => array(
                            'type' => 'string',
                            'enum' => array('bundle', 'concat'),
                        ),
                    ),
                ),
            ),
        );
    };
    $filterKind = trim((string) SiteRouteUtils::getQueryValue('filter.kind', ''));
    $filterWebcomponentName = trim((string) SiteRouteUtils::getQueryValue('filter.webcomponentName', ''));
    $webcomponentHaxProperties = null;
    if ($filterWebcomponentName != '') {
        $webcomponentHaxProperties = $loadWebcomponentHaxProperties($filterWebcomponentName);
    }
    $schemas = $buildSchemaDescriptors($filterWebcomponentName, $webcomponentHaxProperties);
    if ($filterKind != '') {
        $schemas = array_values(array_filter($schemas, function ($schema) use ($filterKind) {
            return isset($schema['kind']) && (string) $schema['kind'] === $filterKind;
        }));
    }
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($schemas),
            'schemas' => $schemas,
            'links' => array(
                'self' => $apiBasePath . '/v1/schemas',
                'entities' => $apiBasePath . '/v1/entities',
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};