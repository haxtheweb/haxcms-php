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
    // Security (entity listing scope): the system-scope part of the entity
    // registry (and the endpoints it names) is only listed to a caller that
    // is signed in and sends its X-HAXCMS-User-Token. Everyone else gets the
    // site scope only, whatever ?scope= asks for, so an unauthenticated
    // request can not fingerprint the build from the system entities. A
    // signed-in caller without a valid user token gets the same 403 messages
    // as the authenticated-user routes. CLI mode is trusted. Kept identical
    // in haxcms-nodejs (src/siteRoutes/v1/entities.js).
    $authenticated = isset($context->auth) &&
        is_array($context->auth) &&
        !empty($context->auth['authenticated']);
    $fullListing = false;
    if ($authenticated) {
        $haxcms = isset($GLOBALS['HAXCMS']) && is_object($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $isCli = ($haxcms && method_exists($haxcms, 'isCLI'))
            ? ($haxcms->isCLI() === true)
            : (PHP_SAPI === 'cli');
        if ($isCli) {
            $fullListing = true;
        } else {
            $userToken = method_exists($context, 'getHeader') ? $context->getHeader('X-HAXCMS-User-Token') : null;
            $userName = isset($context->auth['userName']) ? (string) $context->auth['userName'] : '';
            $message = '';
            if (is_null($userToken) || $userToken === '') {
                $message = 'X-HAXCMS-User-Token header is required for this endpoint';
            } elseif (
                !$haxcms ||
                !method_exists($haxcms, 'validateRequestToken') ||
                !$haxcms->validateRequestToken($userToken, $userName)
            ) {
                $message = 'Invalid X-HAXCMS-User-Token header';
            }
            if ($message !== '') {
                SiteRouteUtils::sendFormattedResponse(
                    array('message' => $message),
                    array('statusCode' => 403, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                    $context->routeSuffix,
                    $apiBasePath
                );
                return;
            }
            $fullListing = true;
        }
    }
    $registry = new EntityRegistry($site);
    $scope = 'site';
    if ($fullListing) {
        $scope = '';
        if (isset($_GET['scope']) && is_string($_GET['scope'])) {
            $scope = trim($_GET['scope']);
        }
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
