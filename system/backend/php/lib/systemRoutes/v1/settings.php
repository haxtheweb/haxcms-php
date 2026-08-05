<?php
include_once dirname(__FILE__) . '/../../routes/RoutesMap.php';
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
if (!function_exists('haxcmsSystemSettingsInvokeAsPost')) {
    function haxcmsSystemSettingsInvokeAsPost($operationCallback)
    {
        $savedMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = call_user_func($operationCallback);
        if ($savedMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        }
        else {
            $_SERVER['REQUEST_METHOD'] = $savedMethod;
        }
        return $response;
    }
}
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    $queryParams = array();
    if (
        isset($GLOBALS['HAXCMS']) &&
        is_object($GLOBALS['HAXCMS']) &&
        isset($GLOBALS['HAXCMS']->safeGet) &&
        is_array($GLOBALS['HAXCMS']->safeGet)
    ) {
        $queryParams = $GLOBALS['HAXCMS']->safeGet;
    }
    else if (is_array($_GET)) {
        $queryParams = $_GET;
    }
    if (count($queryParams) > 0) {
        $operations->params = array_merge($operations->params, $queryParams);
        $operations->rawParams = array_merge($operations->rawParams, $queryParams);
    }
    if (is_array($context->body)) {
        $operations->params = array_merge($operations->params, $context->body);
        $operations->rawParams = array_merge($operations->rawParams, $context->body);
    }
    unset($operations->params['jwt']);
    unset($operations->params['user_token']);
    unset($operations->params['site_token']);
    unset($operations->rawParams['jwt']);
    unset($operations->rawParams['user_token']);
    unset($operations->rawParams['site_token']);
    $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
    if (!is_string($tokenUser) || $tokenUser === '') {
        $tokenUser = $GLOBALS['HAXCMS']->getActiveUserName();
    }
    $userToken = $GLOBALS['HAXCMS']->getRequestToken($tokenUser);
    $operations->params['user_token'] = $userToken;
    $operations->rawParams['user_token'] = $userToken;
    $route = $context->routeSuffix;
    $method = $context->method;
    $response = null;
    if ($route === 'v1/status') {
        $savedMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = $operations->systemStatus();
        $_SERVER['REQUEST_METHOD'] = $savedMethod;
    }
    else if ($route === 'v1/system/version') {
        $response = array(
            'status' => 200,
            'data' => array(
                'version' => $GLOBALS['HAXCMS']->getHAXCMSVersion(),
            ),
        );
    }
    else if ($route === 'v1/entities') {
        $entities = array(
            array(
                'name' => 'site',
                'description' => 'System-level site lifecycle resources and site metadata.',
                'primaryKey' => 'siteName',
                'endpoints' => array(
                    $apiBasePath . '/sites',
                    $apiBasePath . '/sites/{siteName}',
                    $apiBasePath . '/sites/{siteName}/clone',
                    $apiBasePath . '/sites/{siteName}/archive',
                    $apiBasePath . '/sites/{siteName}/download',
                    $apiBasePath . '/sites/{siteName}/download-skeleton',
                    $apiBasePath . '/sites/{siteName}/save-as-template',
                ),
                'auth' => 'authenticated-user',
                'supportedOperations' => array('read', 'create', 'update'),
            ),
            array(
                'name' => 'theme',
                'description' => 'System theme catalog and enabled state configuration.',
                'primaryKey' => 'machineName',
                'endpoints' => array($apiBasePath . '/themes'),
                'auth' => 'authenticated-user',
                'supportedOperations' => array('read', 'update'),
            ),
            array(
                'name' => 'block',
                'description' => 'System block catalog and enabled block configuration.',
                'primaryKey' => 'tag',
                'endpoints' => array($apiBasePath . '/blocks'),
                'auth' => 'authenticated-user',
                'supportedOperations' => array('read', 'update'),
            ),
            array(
                'name' => 'skeleton',
                'description' => 'System skeleton catalog, detail, and enabled skeleton configuration.',
                'primaryKey' => 'skeletonName',
                'endpoints' => array(
                    $apiBasePath . '/skeletons',
                    $apiBasePath . '/skeletons/{skeletonName}',
                ),
                'auth' => 'authenticated-user',
                'supportedOperations' => array('read', 'update'),
            ),
            array(
                'name' => 'integration',
                'description' => 'System integration providers and app store manifest.',
                'primaryKey' => 'id',
                'endpoints' => array($apiBasePath . '/integrations/app-store'),
                'auth' => 'public',
                'supportedOperations' => array('read'),
            ),
            array(
                'name' => 'configuration',
                'description' => 'System configuration resources for settings and schema files.',
                'primaryKey' => 'id',
                'endpoints' => array(
                    $apiBasePath . '/configuration/api-keys',
                    $apiBasePath . '/configuration/media',
                    $apiBasePath . '/configuration/skeletons',
                ),
                'auth' => 'authenticated-user',
                'supportedOperations' => array('read', 'update'),
            ),
        );
        $response = array(
            'status' => 200,
            'data' => array(
                'count' => count($entities),
                'entities' => $entities,
                'links' => array(
                    'self' => $apiBasePath . '/entities',
                    'schemas' => $apiBasePath . '/schemas',
                    'sites' => $apiBasePath . '/sites',
                    'configuration' => $apiBasePath . '/configuration',
                    'integrations' => $apiBasePath . '/integrations',
                    'system' => $apiBasePath . '/system',
                ),
            ),
        );
    }
    else if ($route === 'v1/schemas') {
        $schemas = array(
            array(
                'id' => 'json-outline-schema',
                'title' => 'JSON Outline Schema',
                'version' => '1.0.0',
                'kind' => 'jsonOutlineSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('site', 'site-template', 'skeleton'),
                'links' => array(
                    'spec' => 'https://github.com/haxtheweb/json-outline-schema',
                ),
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
                'appliesTo' => array('site', 'skeleton'),
                'links' => array(
                    'spec' => 'https://github.com/haxtheweb/json-outline-schema',
                ),
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
                'id' => 'app-store-schema',
                'title' => 'HAX App Store Schema',
                'version' => '1.0.0',
                'kind' => 'appStoreSchema',
                'mediaType' => 'application/json',
                'appliesTo' => array('integration'),
                'links' => array(
                    'endpoint' => $apiBasePath . '/integrations/app-store',
                    'spec' => 'https://github.com/haxtheweb/appstore-spec',
                ),
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'apps' => array('type' => 'array', 'items' => array('type' => 'object')),
                        'stax' => array('type' => 'array', 'items' => array('type' => 'object')),
                        'autoloader' => array('type' => array('array', 'object')),
                    ),
                ),
            ),
            array(
                'id' => 'theme-configuration',
                'title' => 'Theme Configuration Settings',
                'version' => '1.0.0',
                'kind' => 'themeConfiguration',
                'mediaType' => 'application/json',
                'appliesTo' => array('configuration'),
                'links' => array(
                    'endpoint' => $apiBasePath . '/themes',
                ),
                'schema' => array(
                    'type' => 'object',
                    'additionalProperties' => array('type' => 'boolean'),
                ),
            ),
            array(
                'id' => 'block-configuration',
                'title' => 'Block Configuration Settings',
                'version' => '1.0.0',
                'kind' => 'blockConfiguration',
                'mediaType' => 'application/json',
                'appliesTo' => array('configuration'),
                'links' => array(
                    'endpoint' => $apiBasePath . '/blocks',
                ),
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'enabledBlocks' => array(
                            'type' => 'array',
                            'items' => array('type' => 'string'),
                        ),
                    ),
                ),
            ),
            array(
                'id' => 'skeleton-configuration',
                'title' => 'Skeleton Configuration Settings',
                'version' => '1.0.0',
                'kind' => 'skeletonConfiguration',
                'mediaType' => 'application/json',
                'appliesTo' => array('configuration'),
                'links' => array(
                    'endpoint' => $apiBasePath . '/skeletons',
                ),
                'schema' => array(
                    'type' => 'object',
                    'additionalProperties' => array('type' => 'boolean'),
                ),
            ),
        );
        // D23: filter.kind support.
        // PHP converts dots to underscores in $_GET, so check filter_kind.
        // Also parse the raw query string to support filter.kind with the dot preserved.
        $filterKind = '';
        if (is_array($operations->params) && isset($operations->params['filter_kind'])) {
            $filterKind = trim((string) $operations->params['filter_kind']);
        }
        if ($filterKind === '' && isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING'])) {
            $pairs = explode('&', $_SERVER['QUERY_STRING']);
            foreach ($pairs as $pair) {
                $items = explode('=', $pair, 2);
                if (urldecode($items[0]) === 'filter.kind' && isset($items[1])) {
                    $filterKind = trim(urldecode($items[1]));
                    break;
                }
            }
        }
        if ($filterKind !== '') {
            $schemas = array_values(array_filter($schemas, function ($schema) use ($filterKind) {
                return isset($schema['kind']) && (string) $schema['kind'] === $filterKind;
            }));
        }
        $response = array(
            'status' => 200,
            'data' => array(
                'count' => count($schemas),
                'schemas' => $schemas,
                'links' => array(
                    'self' => $apiBasePath . '/schemas',
                    'entities' => $apiBasePath . '/entities',
                ),
            ),
        );
    }
    else if ($route === 'v1/configuration/api-keys') {
        if ($method === 'GET' || $method === 'POST') {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'getApiKeys')
            );
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveApiKeys')
            );
        }
    }
    else if ($route === 'v1/configuration/media') {
        if ($method === 'GET' || $method === 'POST') {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'getMediaSettings')
            );
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveMediaSettings')
            );
        }
    }
    else if ($route === 'v1/configuration/schema-files/operations') {
        $response = haxcmsSystemSettingsInvokeAsPost(
            array($operations, 'schemaFileOperation')
        );
    }
    else if ($route === 'v1/blocks') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->systemBlocksList();
        }
        else {
            $response = $operations->saveEnabledBlocks();
        }
    }
    else if ($route === 'v1/skeletons') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->skeletonsList();
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveEnabledSkeletons')
            );
        }
    }
    else if (
        $route === 'v1/skeletons/:skeletonName' ||
        preg_match('/^v1\\/skeletons\\/[^\\/]+$/', $route) === 1
    ) {
        if ($method === 'GET') {
            $operations->params['name'] = $context->params['skeletonName'];
            $operations->rawParams['name'] = $context->params['skeletonName'];
            $response = $operations->getSkeleton();
        }
        else if ($method === 'PATCH' || $method === 'PUT') {
            $operations->params['name'] = $context->params['skeletonName'];
            $operations->rawParams['name'] = $context->params['skeletonName'];
            if (is_array($context->body)) {
                $operations->params = array_merge($operations->params, $context->body);
                $operations->rawParams = array_merge($operations->rawParams, $context->body);
            }
            // D26: PATCH/PUT on skeletons/:skeletonName infers rename if no
            // action is explicitly provided (matches Node inference behavior).
            if (!isset($operations->params['action'])) {
                $operations->params['action'] = 'rename';
            }
            if (!isset($operations->rawParams['action'])) {
                $operations->rawParams['action'] = 'rename';
            }
            $response = $operations->schemaFileOperation();
        }
        else if ($method === 'DELETE') {
            $operations->params['name'] = $context->params['skeletonName'];
            $operations->rawParams['name'] = $context->params['skeletonName'];
            if (!isset($operations->params['action'])) {
                $operations->params['action'] = 'delete';
            }
            if (!isset($operations->rawParams['action'])) {
                $operations->rawParams['action'] = 'delete';
            }
            $response = $operations->schemaFileOperation();
        }
        else {
            $response = array('status' => 405, 'data' => 'Method not allowed');
        }
    }
    else if ($route === 'v1/themes') {
        if ($method === 'GET' || $method === 'POST') {
            $response = $operations->themesList();
        }
        else {
            $response = haxcmsSystemSettingsInvokeAsPost(
                array($operations, 'saveEnabledThemes')
            );
        }
    }
    else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unknown settings route'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (is_array($response) && isset($response['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => isset($response['__failed']['message']) ? $response['__failed']['message'] : 'Request failed'),
            array('statusCode' => isset($response['__failed']['status']) ? $response['__failed']['status'] : 500, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    // If the response already has a top-level status key (legacy v1 route shape),
    // emit it as-is to avoid double-wrapping. Otherwise wrap in a data envelope.
    if (!is_array($response) || !isset($response['status'])) {
        $response = array('status' => 200, 'data' => $response);
    }
    // Prevent double-wrapping: if response already has status and data, assume it is already enveloped
    else if (isset($response['data']) && !isset($response['__failed']) && !isset($response['__noencode'])) {
        // response is already enveloped (e.g. from getApiKeys, getMediaSettings), keep as-is
    }
    else {
        // response has status but no data (e.g. systemBlocksList, themesList, skeletonsList) —
        // NodeJS v1 returns flat shape, so wrap its body as data to keep the envelope consistent
        $response = array('status' => $response['status'], 'data' => $response);
        // Remove the original status from the nested data so it isn't duplicated
        if (isset($response['data']['status'])) {
            unset($response['data']['status']);
        }
    }
    SiteRouteUtils::sendFormattedResponse(
        $response,
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};