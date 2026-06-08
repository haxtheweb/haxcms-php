<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/entities'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $entities = array(
        array(
            'name' => 'site',
            'description' => 'Site-level metadata and API capability summary.',
            'primaryKey' => 'id',
            'endpoints' => array(
                $apiBasePath . '/v1/site',
                $apiBasePath . '/v1/site/export/{format}',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'item',
            'description' => 'Outline item metadata records and hierarchy.',
            'primaryKey' => 'id',
            'endpoints' => array(
                $apiBasePath . '/v1/items',
                $apiBasePath . '/v1/items/{idOrSlug}',
                $apiBasePath . '/v1/items/{idOrSlug}/export/{format}',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'content',
            'description' => 'Page body/content representations and transformed variants.',
            'primaryKey' => 'id',
            'endpoints' => array(
                $apiBasePath . '/v1/content',
                $apiBasePath . '/v1/content/{idOrSlug}',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'file',
            'description' => 'File assets available in the site files directory.',
            'primaryKey' => 'path',
            'endpoints' => array($apiBasePath . '/v1/files'),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'tag',
            'description' => 'Tag facet values and usage counts.',
            'primaryKey' => 'tag',
            'endpoints' => array($apiBasePath . '/v1/tags'),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'customElement',
            'description' => 'Custom element metadata available to the site.',
            'primaryKey' => 'tag',
            'endpoints' => array(
                $apiBasePath . '/v1/custom-elements',
                $apiBasePath . '/v1/custom-elements/{webcomponentName}',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'block',
            'description' => 'Block usage and schema details for custom element tags.',
            'primaryKey' => 'tag',
            'endpoints' => array(
                $apiBasePath . '/v1/blocks',
                $apiBasePath . '/v1/blocks/{webcomponentName}',
                $apiBasePath . '/v1/blocks/{webcomponentName}/usage',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'region',
            'description' => 'Region-level grouping of site items and content.',
            'primaryKey' => 'name',
            'endpoints' => array(
                $apiBasePath . '/v1/regions',
                $apiBasePath . '/v1/regions/{regionName}',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'theme',
            'description' => 'Theme metadata records, including active and available themes.',
            'primaryKey' => 'machineName',
            'endpoints' => array(
                $apiBasePath . '/v1/themes',
                $apiBasePath . '/v1/themes/{themeName}',
                $apiBasePath . '/v1/themes/active',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'report',
            'description' => 'Report datasets used by dashboards.',
            'primaryKey' => 'id',
            'endpoints' => array(
                $apiBasePath . '/v1/reports',
                $apiBasePath . '/v1/reports/{report}',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'analytics',
            'description' => 'Analytics capability metadata and xAPI schema links.',
            'primaryKey' => 'id',
            'endpoints' => array($apiBasePath . '/v1/analytics'),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
        array(
            'name' => 'view',
            'description' => 'Saved display/view definitions and resolved results.',
            'primaryKey' => 'id',
            'endpoints' => array(
                $apiBasePath . '/v1/views',
                $apiBasePath . '/v1/views/{viewId}',
                $apiBasePath . '/v1/views/{viewId}/results',
                $apiBasePath . '/v1/displays',
                $apiBasePath . '/v1/displays/{viewId}/results',
            ),
            'supportedOperations' => array('read'),
            'auth' => 'public',
        ),
    );
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($entities),
            'entities' => $entities,
            'links' => array(
                'self' => $apiBasePath . '/v1/entities',
                'site' => $apiBasePath . '/v1/site',
                'schemas' => $apiBasePath . '/v1/schemas',
                'openapi' => $apiBasePath . '/openapi',
                'openapiJson' => $apiBasePath . '/openapi.json',
                'openapiYaml' => $apiBasePath . '/openapi.yaml',
            ),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
