<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../EntityRegistry.php';
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
    // Source entity descriptors from the single merged entities.yaml registry.
    // Both /x/api/v1/entities and /system/api/v1/entities read from the same
    // EntityRegistry::getDefinitions(), so the API surface and the in-code
    // object are one shape. Optional ?scope=site|system filters the merged set.
    $registry = new EntityRegistry($site);
    $scope = '';
    if (isset($_GET['scope']) && is_string($_GET['scope'])) {
        $scope = trim($_GET['scope']);
    }
    $definitions = $registry->getDefinitions($scope !== '' ? $scope : null);
    $entities = array();
    foreach ($definitions as $definition) {
        $entities[] = $definition->toDescriptorArray();
    }
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
