<?php
include_once dirname(__FILE__) . '/SiteApiRequestContext.php';
include_once dirname(__FILE__) . '/SiteRoutesMap.php';
include_once dirname(__FILE__) . '/SiteRouteUtils.php';
class SiteApiRouter
{
    public static function dispatch($site)
    {
        $context = SiteApiRequestContext::fromSite($site);
        if (!$context->isSiteApiRequest()) {
            return false;
        }
        if ($context->method == 'OPTIONS') {
            self::sendOptionsResponse();
            return true;
        }
        $routes = SiteRoutesMap::getRoutesForMethod($context->method);
        $match = self::matchRoute($context->routeSuffix, $routes);
        if (!$match) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Unknown site API route',
                    'route' => $context->routeSuffix,
                ),
                array(
                    'statusCode' => 404,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                is_string($context->routeSuffix) ? $context->routeSuffix : '',
                $context->apiBasePath
            );
            return true;
        }
        if (!isset($match['file']) || !is_string($match['file']) || !file_exists($match['file'])) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Site API handler file missing',
                ),
                array(
                    'statusCode' => 500,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                is_string($context->routeSuffix) ? $context->routeSuffix : '',
                $context->apiBasePath
            );
            return true;
        }
        $handler = include $match['file'];
        if (!is_callable($handler)) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Site API handler is not callable',
                ),
                array(
                    'statusCode' => 500,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                is_string($context->routeSuffix) ? $context->routeSuffix : '',
                $context->apiBasePath
            );
            return true;
        }
        $context->setRouteParams(isset($match['params']) ? $match['params'] : array());
        call_user_func($handler, $context);
        return true;
    }
    public static function matchRoute($routeSuffix, $routes = array())
    {
        $targetRoute = trim((string) $routeSuffix, '/');
        foreach ($routes as $pattern => $routeFile) {
            $matchedParams = self::matchPattern($pattern, $targetRoute);
            if ($matchedParams === false) {
                continue;
            }
            return array(
                'file' => $routeFile,
                'params' => $matchedParams,
            );
        }
        $fallbackMatch = self::matchSpecialCaseRoutes($targetRoute, $routes);
        if (is_array($fallbackMatch)) {
            return $fallbackMatch;
        }
        return null;
    }
    private static function matchSpecialCaseRoutes($targetRoute, $routes = array())
    {
        $specialCases = array(
            array(
                'pattern' => '/^v1\/items\/(.+)\/export\/([^\/]+)$/',
                'route' => 'v1/items/:idOrSlug/export/:format',
                'paramMap' => array('idOrSlug' => 1, 'format' => 2),
            ),
            array(
                'pattern' => '/^v1\/items\/(.+)$/',
                'route' => 'v1/items/:idOrSlug',
                'paramMap' => array('idOrSlug' => 1),
            ),
            array(
                'pattern' => '/^v1\/content\/(.+)$/',
                'route' => 'v1/content/:idOrSlug',
                'paramMap' => array('idOrSlug' => 1),
            ),
        );
        foreach ($specialCases as $case) {
            if (!isset($case['route']) || !array_key_exists($case['route'], $routes)) {
                continue;
            }
            $matched = array();
            if (preg_match($case['pattern'], $targetRoute, $matched) !== 1) {
                continue;
            }
            $params = array();
            foreach ($case['paramMap'] as $name => $index) {
                if (!isset($matched[$index])) {
                    continue;
                }
                $params[$name] = urldecode((string) $matched[$index]);
            }
            return array(
                'file' => $routes[$case['route']],
                'params' => $params,
            );
        }
        return null;
    }
    private static function matchPattern($pattern, $targetRoute)
    {
        $trimmedPattern = trim((string) $pattern, '/');
        $trimmedRoute = trim((string) $targetRoute, '/');
        if ($trimmedPattern == '' && $trimmedRoute == '') {
            return array();
        }
        $patternParts = $trimmedPattern == '' ? array() : explode('/', $trimmedPattern);
        $routeParts = $trimmedRoute == '' ? array() : explode('/', $trimmedRoute);
        if (count($patternParts) !== count($routeParts)) {
            return false;
        }
        $params = array();
        for ($i = 0; $i < count($patternParts); $i++) {
            $patternPart = $patternParts[$i];
            $routePart = $routeParts[$i];
            if (substr($patternPart, 0, 1) == ':') {
                $paramName = substr($patternPart, 1);
                if ($paramName == '') {
                    return false;
                }
                $params[$paramName] = urldecode($routePart);
                continue;
            }
            if ($patternPart !== $routePart) {
                return false;
            }
        }
        return $params;
    }
    private static function sendOptionsResponse()
    {
        http_response_code(200);
        header('Allow: GET, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');
        print json_encode(array('status' => 200, 'data' => array('methods' => array('GET', 'OPTIONS'))));
    }
}
