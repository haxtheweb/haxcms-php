<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $absoluteApiBasePath = isset($context->absoluteApiBasePath) ? $context->absoluteApiBasePath : $apiBasePath;
    $buildLinkMap = function ($basePath) {
        return array(
            'self' => $basePath,
            'openapi' => $basePath . '/openapi',
            'openapiJson' => $basePath . '/openapi.json',
            'openapiYaml' => $basePath . '/openapi.yaml',
            'sites' => $basePath . '/sites',
            'session' => $basePath . '/session',
            'configuration' => $basePath . '/configuration',
            'integrations' => $basePath . '/integrations',
            'entities' => $basePath . '/entities',
            'schemas' => $basePath . '/schemas',
            'system' => $basePath . '/system',
        );
    };
    $mcpPolicy = function () {
        $haxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $enabled = ($haxcms && method_exists($haxcms, 'isMCPEnabled'))
            ? ($haxcms->isMCPEnabled() === TRUE)
            : FALSE;
        $readOnly = ($haxcms && method_exists($haxcms, 'isMCPReadOnly'))
            ? ($haxcms->isMCPReadOnly() !== FALSE)
            : TRUE;
        return array('enabled' => $enabled, 'readOnly' => $readOnly);
    };
    SiteRouteUtils::sendFormattedResponse(
        array(
            'name' => 'HAXcms System API',
            'version' => SiteRouteUtils::getVersion(),
            'mode' => 'admin',
            'links' => $buildLinkMap($apiBasePath),
            'absoluteLinks' => $buildLinkMap($absoluteApiBasePath),
            'supports' => array(
                'formats' => array(
                    'application/json',
                    'application/yaml',
                ),
            ),
            'openapi' => array(
                'source' => 'systemRoutes/openapi/system-spec.yaml',
                'routeDriven' => true,
            ),
            // The instance's MCP policy (config->mcp, defaulted in HAXCMS.php: disabled on
            // haxiam-managed deployments, read-only unless readOnly is set FALSE). Published
            // on this open route so MCP clients (haxcms-mcp) can refuse to operate, or stay
            // read-only, before they authenticate. Exposes only the two booleans.
            'mcp' => $mcpPolicy(),
        ),
        array(
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
        ),
        isset($context->routeSuffix) ? $context->routeSuffix : '',
        $apiBasePath
    );
};