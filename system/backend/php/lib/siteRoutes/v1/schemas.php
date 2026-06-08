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
    $filterKind = trim((string) SiteRouteUtils::getQueryValue('filter.kind', ''));
    $filterWebcomponentName = trim((string) SiteRouteUtils::getQueryValue('filter.webcomponentName', ''));
    $webcomponentTag = $filterWebcomponentName != '' ? strtolower($filterWebcomponentName) : '*';
    $haxPropertiesSchema = array(
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
    $haxSchema = array(
        'tag' => $webcomponentTag,
        'api' => '1',
        'canScale' => true,
        'canPosition' => true,
        'canEditSource' => true,
    );
    $schemas = array(
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
            'schema' => array('type' => 'object', 'additionalProperties' => true),
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
                    'format' => array('type' => 'string', 'enum' => array('json', 'md', 'yaml', 'xml', 'html', 'xapi')),
                    'mode' => array('type' => 'string', 'enum' => array('bundle', 'concat')),
                ),
            ),
        ),
    );
    if ($filterKind != '') {
        $schemas = array_values(array_filter($schemas, function ($schema) use ($filterKind) {
            return isset($schema['kind']) && $schema['kind'] === $filterKind;
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
