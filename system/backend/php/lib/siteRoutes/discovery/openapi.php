<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : 'openapi';
    $specPath = dirname(__FILE__) . '/../openapi/site-spec.yaml';
    $openapi = array();
    if (!file_exists($specPath)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Failed to load site OpenAPI specification',
            ),
            array(
                'statusCode' => 500,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $specContents = file_get_contents($specPath);
    $parsedSpec = SiteRouteUtils::parseYaml($specContents);
    if (!is_array($parsedSpec)) {
        $parsedSpec = json_decode($specContents, true);
    }
    if (!is_array($parsedSpec)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Invalid site OpenAPI specification',
            ),
            array(
                'statusCode' => 500,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $openapi = $parsedSpec;
    if (!isset($openapi['info']) || !is_array($openapi['info'])) {
        $openapi['info'] = array();
    }
    // info.version stays the spec's own API contract version; the running
    // HAXcms version is never exposed to unauthenticated callers.
    // D54: produce an absolute servers[] URL matching Node's getServerBaseUrl
    // (openapi.js:93-101) so both backends emit ${protocol}://${host}${basePath}
    // instead of a relative path. Falls back to the relative basePath when the
    // host is unavailable (CLI edge case) so the spec is still valid.
    $haxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
    $protocol = ($haxcms && isset($haxcms->protocol)) ? $haxcms->protocol : 'http';
    $host = ($haxcms && isset($haxcms->domain)) ? (string) $haxcms->domain : '';
    $basePath = ($haxcms && isset($haxcms->basePath)) ? (string) $haxcms->basePath : '/';
    if (strlen($basePath) > 0 && $basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
    if (substr($basePath, -1) !== '/') {
        $basePath .= '/';
    }
    if ($host !== '') {
        $serverBaseUrl = $protocol . '://' . $host . $basePath;
    }
    else {
        $serverBaseUrl = $basePath;
    }
    // haxtheweb/issues#3104: every path in the spec starts with /x/api, so
    // the server URL must be the base of the site this request came through
    // (e.g. https://host/sites/<name>/), not the HAXcms install base, or an
    // agent calling the spec from a multisite install hits the wrong URL.
    if (
        isset($context->absoluteApiBasePath) &&
        is_string($context->absoluteApiBasePath) &&
        preg_match('/^(https?:\/\/[^\/]+.*?)\/x\/api$/i', $context->absoluteApiBasePath, $serverMatch) === 1
    ) {
        $serverBaseUrl = rtrim($serverMatch[1], '/') . '/';
    }
    $openapi['servers'] = array(
        array(
            'url' => $serverBaseUrl,
            'description' => 'HAXcms site base URL',
        ),
    );
    // haxtheweb/issues#3104: ?profile=public returns only the operations
    // that work without logging in, so agents don't plan around endpoints
    // that answer 401. It is opt-in: the editor bootstraps its operation
    // registry from this spec using cookies only (no bearer), so it looks
    // anonymous here and must always get the full spec by default.
    $requestedProfile = isset($_GET['profile']) ? strtolower(trim((string) $_GET['profile'])) : '';
    if ($requestedProfile === 'public') {
        $openapi = SiteRouteUtils::filterOpenApiToPublicOperations($openapi);
    }
    $allowedFormats = array('json', 'yaml');
    $format = SiteRouteUtils::detectResponseFormat($allowedFormats, 'json', $routeSuffix);
    if ($format == 'yaml') {
        http_response_code(200);
        header('Content-Type: application/yaml; charset=utf-8');
        print SiteRouteUtils::toYaml($openapi);
        return;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    print json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
