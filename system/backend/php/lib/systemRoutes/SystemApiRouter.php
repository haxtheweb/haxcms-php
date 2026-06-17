<?php
include_once dirname(__FILE__) . '/SystemApiRequestContext.php';
include_once dirname(__FILE__) . '/SystemRoutesMap.php';
include_once dirname(__FILE__) . '/SystemApiSecurity.php';
include_once dirname(__FILE__) . '/../siteRoutes/SiteRouteUtils.php';
class SystemApiRouter
{
    public static function dispatch()
    {
        $context = SystemApiRequestContext::create();
        if (!$context->isSystemApiRequest()) {
            return false;
        }
        if ($context->method == 'OPTIONS') {
            self::sendOptionsResponse();
            return true;
        }
        $allRoutes = SystemRoutesMap::getRoutesMap();
        $allowedMethods = array();
        foreach ($allRoutes as $allowedMethod => $methodRoutes) {
            if (self::matchRoute($context->routeSuffix, $methodRoutes) !== null) {
                $allowedMethods[] = strtoupper((string) $allowedMethod);
            }
        }
        $routes = SystemRoutesMap::getRoutesForMethod($context->method);
        $match = self::matchRoute($context->routeSuffix, $routes);
        if (!$match) {
            if (count($allowedMethods) > 0) {
                sort($allowedMethods);
                header('Allow: ' . implode(', ', $allowedMethods));
                SiteRouteUtils::sendFormattedResponse(
                    array(
                        'message' => 'Method not allowed',
                        'route' => $context->routeSuffix,
                        'methods' => $allowedMethods,
                    ),
                    array(
                        'statusCode' => 405,
                        'allowedFormats' => array('json'),
                        'defaultFormat' => 'json',
                    ),
                    is_string($context->routeSuffix) ? $context->routeSuffix : '',
                    $context->apiBasePath
                );
                return true;
            }
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Unknown system API route',
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
        $routeName = $match['route'];
        $security = SystemApiSecurity::validateSystemApiAccess($context, $routeName, $context->method);
        if (!$security['allowed']) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => $security['message']),
                array(
                    'statusCode' => $security['status'],
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
                array('message' => 'System API handler file missing'),
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
                array('message' => 'System API handler is not callable'),
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
                'route' => $pattern,
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
        header('Allow: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');
        print json_encode(array('status' => 200, 'data' => array('methods' => array('GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'))));
    }
}
