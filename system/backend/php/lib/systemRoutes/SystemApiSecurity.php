<?php
include_once dirname(__FILE__) . '/../siteRoutes/SiteRouteUtils.php';
class SystemApiSecurity
{
    public static function validateSystemApiAccess($context, $route, $method)
    {
        $security = self::getRouteSecurity($route, $method);
        if ($security === 'public') {
            return array(
                'allowed' => true,
                'status' => 200,
                'message' => '',
            );
        }
        // 'site-token-only' routes (e.g. integrations/app-store) declare
        // siteTokenHeader without bearerAuth. The router does not require a
        // bearer JWT; the handler validates the X-HAXCMS-Site-Token against
        // the server-side active user. This mirrors the original
        // handler-validated behavior and Node's open-route + handler pattern.
        if ($security === 'site-token-only') {
            return array(
                'allowed' => true,
                'status' => 200,
                'message' => '',
            );
        }
        // Resolve authenticated userName from Bearer (primary) or Basic (fallback).
        // Basic-auth fallback mirrors the NodeJS system route handler and shares
        // the login rate-limit counters (429 + Retry-After on brute-force lockout).
        $userName = '';
        $bearer = $GLOBALS['HAXCMS']->getBearerTokenFromRequest();
        if ($bearer !== '') {
            $userName = $GLOBALS['HAXCMS']->getBearerTokenUserName($bearer);
            if ($userName === '') {
                // D1b status-code parity: bearer present but invalid/expired is a
                // refreshable 403 (matches site API + Node), not 401.
                return array(
                    'allowed' => false,
                    'status' => 403,
                    'message' => 'Invalid bearer token',
                );
            }
        }
        else {
            $basic = $GLOBALS['HAXCMS']->authenticateBasicAuthorization();
            if (is_array($basic) && !empty($basic['blocked'])) {
                return array(
                    'allowed' => false,
                    'status' => 429,
                    'message' => 'Too many failed login attempts. Please try again later.',
                    'retryAfterSeconds' => isset($basic['retryAfterSeconds']) ? (int) $basic['retryAfterSeconds'] : 0,
                );
            }
            if (is_array($basic) && !empty($basic['authenticated']) && !empty($basic['userName'])) {
                $userName = $basic['userName'];
            }
            else {
                return array(
                    'allowed' => false,
                    'status' => 401,
                    'message' => 'Authentication required',
                );
            }
        }
        if ($security === 'authenticated') {
            if (isset($GLOBALS['HAXCMS']->config->iam) && $GLOBALS['HAXCMS']->config->iam) {
                $authorization = $GLOBALS['HAXCMS']->validateIAMRouteAuthorization(true);
                if (is_array($authorization) && isset($authorization['allowed']) && !$authorization['allowed']) {
                    return array(
                        'allowed' => false,
                        'status' => isset($authorization['status']) ? (int) $authorization['status'] : 403,
                        'message' => isset($authorization['message']) && $authorization['message'] != '' ? $authorization['message'] : 'Access denied',
                    );
                }
            }
            return array(
                'allowed' => true,
                'status' => 200,
                'message' => '',
            );
        }
        if ($security === 'admin') {
            if ($userName !== $GLOBALS['HAXCMS']->superUser->name) {
                return array(
                    'allowed' => false,
                    'status' => 403,
                    'message' => 'Admin access required',
                );
            }
            if (isset($GLOBALS['HAXCMS']->config->iam) && $GLOBALS['HAXCMS']->config->iam) {
                $authorization = $GLOBALS['HAXCMS']->validateIAMRouteAuthorization(true);
                if (is_array($authorization) && isset($authorization['allowed']) && !$authorization['allowed']) {
                    return array(
                        'allowed' => false,
                        'status' => isset($authorization['status']) ? (int) $authorization['status'] : 403,
                        'message' => isset($authorization['message']) && $authorization['message'] != '' ? $authorization['message'] : 'Access denied',
                    );
                }
            }
            return array(
                'allowed' => true,
                'status' => 200,
                'message' => '',
            );
        }
        return array(
            'allowed' => false,
            'status' => 403,
            'message' => 'Unknown security level',
        );
    }
    /**
     * Resolve the configured sites directory (default '_sites').
     */
    private static function getSitesDirectory()
    {
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->sitesDirectory) &&
            is_string($GLOBALS['HAXCMS']->sitesDirectory) &&
            $GLOBALS['HAXCMS']->sitesDirectory !== ''
        ) {
            return $GLOBALS['HAXCMS']->sitesDirectory;
        }
        return '_sites';
    }
    /**
     * True when the request URL is site-scoped (under /{sitesDirectory}/).
     * Parity with Node isSiteScopedSystemApiRoutePattern (req.route.path starts
     * with /{sitesDirectory}/); PHP checks the full request path since one
     * router serves both root and site-scoped system API requests.
     */
    private static function isSiteScopedSystemApiRequest($context)
    {
        $sitesDirectory = self::getSitesDirectory();
        if ($sitesDirectory === '') {
            return false;
        }
        $requestPath = '';
        if (is_object($context) && isset($context->requestPath) && is_string($context->requestPath)) {
            $requestPath = $context->requestPath;
        }
        if ($requestPath === '') {
            return false;
        }
        return strpos($requestPath, '/' . $sitesDirectory . '/') !== false;
    }
    /**
     * True when the referer is the main dashboard (NOT site-scoped).
     * Parity with Node isDashboardRefererRequest: a missing/non-string referer
     * is NOT a dashboard referer (returns false), so a site-scoped admin
     * request with no referer is blocked. Only a referer that does NOT contain
     * /{sitesDirectory}/ counts as dashboard.
     */
    private static function isDashboardRefererRequest()
    {
        $sitesDirectory = self::getSitesDirectory();
        if ($sitesDirectory === '') {
            return true;
        }
        if (!isset($_SERVER['HTTP_REFERER']) || !is_string($_SERVER['HTTP_REFERER']) || $_SERVER['HTTP_REFERER'] === '') {
            return false;
        }
        return strpos((string) $_SERVER['HTTP_REFERER'], '/' . $sitesDirectory . '/') === false;
    }
    /**
     * Gate system v1 admin routes against site-scoped dashboard access.
     * Parity with Node validateSystemV1RouteAccess: an admin route reached via
     * a site-scoped URL from a site-scoped referer is blocked (the site tenant
     * dashboard must not drive system admin operations). Returns true when
     * access is allowed, false when blocked.
     */
    public static function validateSystemV1RouteAccess($context, $route)
    {
        if (!in_array($route, SystemRoutesMap::getSystemV1AdminRoutes(), true)) {
            return true;
        }
        if (self::isSiteScopedSystemApiRequest($context) && !self::isDashboardRefererRequest()) {
            return false;
        }
        return true;
    }
    private static function getRouteSecurity($route, $method)
    {
        $normalizedMethod = strtoupper((string) $method);
        if ($normalizedMethod === 'OPTIONS') {
            return 'public';
        }
        // C1/Q6: spec-driven base policy. Read the security declaration from
        // system-spec.yaml at runtime and fail-closed to 'authenticated' for
        // any route not declared in the spec. Mirrors Node
        // getSystemApiRouteAuthPolicy (app.js:1785). This retires the
        // hand-maintained public/authenticated route lists.
        $basePolicy = SiteRouteUtils::getSystemApiRouteAuthPolicy($route, $normalizedMethod);
        // PHP admin tier (Q1: no Node equivalent): elevate non-GET methods of
        // admin routes to 'admin' so the superUser check fires. GET stays at
        // the spec-driven base policy (authenticated/authenticated-user) since
        // the spec declares bearerAuth for dashboard reads. The admin route
        // list is the single source of truth for which routes require admin.
        if (
            $normalizedMethod !== 'GET' &&
            $normalizedMethod !== 'HEAD' &&
            in_array($route, SystemRoutesMap::getSystemV1AdminRoutes(), true)
        ) {
            return 'admin';
        }
        // The spec-driven reader may return 'authenticated-user' for routes
        // that declare userTokenHeader. The X-HAXCMS-User-Token header is
        // enforced separately by enforceSystemApiUserTokenHeader (which
        // resolves :param placeholders to concrete spec paths for per-value
        // paths like /site/import/haxcms). The base access check here treats
        // 'authenticated-user' as 'authenticated' to avoid a double check.
        if ($basePolicy === 'authenticated-user') {
            return 'authenticated';
        }
        return $basePolicy;
    }
    /**
     * F2/Q14+F3: enforce the X-HAXCMS-Site-Token header on the app-store
     * provider-search GET route in the router (not the handler) for a
     * consistent 403 envelope. The current spec declares security: [] for
     * this route; Q14 requires a site token. The spec will be updated by the
     * node-backend and synced at merge; until then this explicit router-level
     * check enforces the decision. siteName validation stays in the handler.
     * Returns null when the route does not require the check or the supplied
     * token is valid; returns a 403 payload (status + message) when the header
     * is missing or invalid.
     */
    public static function enforceProviderSearchSiteToken($routeName, $method, $context)
    {
        $normalizedMethod = strtoupper((string) $method);
        if ($normalizedMethod !== 'GET') {
            return null;
        }
        if ($routeName !== 'v1/integrations/app-store/providers/:provider/search') {
            return null;
        }
        $siteToken = '';
        if (is_object($context) && method_exists($context, 'getHeader')) {
            $headerValue = $context->getHeader('X-HAXCMS-Site-Token');
            if (is_string($headerValue)) {
                $siteToken = $headerValue;
            }
        }
        if ($siteToken === '') {
            return array(
                'status' => 403,
                'message' => 'X-HAXCMS-Site-Token header is required for this endpoint',
            );
        }
        // Resolve siteName from the query param for token validation. If
        // siteName is missing, defer to the handler's siteName validation (F3).
        $siteName = isset($_GET['siteName']) ? (string) $_GET['siteName'] : '';
        if ($siteName === '') {
            return null;
        }
        $validToken = false;
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'validateSiteToken')
        ) {
            $validToken = $GLOBALS['HAXCMS']->validateSiteToken($siteName, $siteToken);
        }
        else {
            $validToken = SiteRouteUtils::validateSiteToken($siteName, $siteToken);
        }
        if (!$validToken) {
            return array(
                'status' => 403,
                'message' => 'Invalid X-HAXCMS-Site-Token header',
            );
        }
        return null;
    }
    /**
     * Canonical system READ operations that declare userTokenHeader in the
     * OpenAPI spec (security alignment). Both backends enforce the
     * X-HAXCMS-User-Token header on these reads. Skeleton/theme/block reads
     * stay bearer-only (D10) and are NOT listed here. Map of parameterized
     * route => allowed methods.
     */
    public static function getSystemReadUserTokenRoutes()
    {
        return array(
            'v1/sites' => array('GET'),
            'v1/sites/:siteName' => array('GET', 'POST'),
            'v1/status' => array('GET', 'POST'),
            'v1/system/version' => array('GET', 'POST'),
            'v1/entities' => array('GET', 'POST'),
            'v1/schemas' => array('GET', 'POST'),
            'v1/configuration/api-keys' => array('GET'),
            'v1/configuration/media' => array('GET'),
            'v1/session/user' => array('GET', 'POST'),
        );
    }
    /**
     * True when the route+method is one of the canonical system READ operations
     * that require the X-HAXCMS-User-Token header (security alignment with the
     * OpenAPI spec + NodeJS parity). Handles the parameterized :siteName form
     * and the actual resolved path form (e.g. v1/sites/mysite).
     */
    public static function isSystemReadUserTokenRoute($route, $method)
    {
        $normalizedMethod = strtoupper((string) $method);
        $routeString = (string) $route;
        $readRoutes = self::getSystemReadUserTokenRoutes();
        if (isset($readRoutes[$routeString]) && in_array($normalizedMethod, $readRoutes[$routeString], true)) {
            return true;
        }
        if (preg_match('/^v1\/sites\/[^\/]+$/', $routeString) === 1) {
            if (in_array($normalizedMethod, $readRoutes['v1/sites/:siteName'], true)) {
                return true;
            }
        }
        return false;
    }
    /**
     * Enforce the X-HAXCMS-User-Token header for canonical system READ routes.
     * Returns null when the route does not require the header or the supplied
     * token is valid. Returns a 403 failure payload (status + message) when the
     * header is missing or invalid, matching the Node site-API enforcement
     * strings + D1 envelope for cross-repo parity.
     */
    public static function enforceSystemReadUserTokenHeader($route, $method, $headerUserToken)
    {
        if (!self::isSystemReadUserTokenRoute($route, $method)) {
            return null;
        }
        $token = is_string($headerUserToken) ? $headerUserToken : '';
        if ($token === '') {
            return array(
                'status' => 403,
                'message' => 'X-HAXCMS-User-Token header is required for this endpoint',
            );
        }
        $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
        if (!is_string($tokenUser) || $tokenUser === '') {
            $tokenUser = $GLOBALS['HAXCMS']->getActiveUserName();
        }
        if (!$GLOBALS['HAXCMS']->validateRequestToken($token, $tokenUser)) {
            return array(
                'status' => 403,
                'message' => 'Invalid X-HAXCMS-User-Token header',
            );
        }
        return null;
    }
    /**
     * Cached spec-derived map of METHOD:routeKey => requiresUserToken (bool).
     * Parses system-spec.yaml once per request via SiteRouteUtils::parseYaml,
     * converting each /system/api/v1/.../{param} path to the PHP route-key
     * form v1/.../:param (mirror Node convertOpenApiPathToSystemRoute) and
     * reading each operation's security array for userTokenHeader presence.
     * Parity with Node readSystemApiAuthPoliciesFromOpenApiSpec +
     * normalizeSiteApiSecurityPolicy (app.js:1622,1734).
     */
    private static $systemUserTokenPolicyMap = null;
    public static function getSystemUserTokenPolicyMap()
    {
        if (self::$systemUserTokenPolicyMap !== null) {
            return self::$systemUserTokenPolicyMap;
        }
        $map = array();
        $specPath = dirname(__FILE__) . '/openapi/system-spec.yaml';
        if (!file_exists($specPath)) {
            self::$systemUserTokenPolicyMap = $map;
            return $map;
        }
        $specContents = file_get_contents($specPath);
        if (!is_string($specContents) || $specContents === '') {
            self::$systemUserTokenPolicyMap = $map;
            return $map;
        }
        $parsedSpec = SiteRouteUtils::parseYaml($specContents);
        if (!is_array($parsedSpec) || !isset($parsedSpec['paths']) || !is_array($parsedSpec['paths'])) {
            self::$systemUserTokenPolicyMap = $map;
            return $map;
        }
        $methods = array('get', 'post', 'put', 'patch', 'delete');
        foreach ($parsedSpec['paths'] as $openApiPath => $pathConfig) {
            if (strpos((string) $openApiPath, '/system/api/v1') !== 0) {
                continue;
            }
            if (!is_array($pathConfig)) {
                continue;
            }
            $routeKey = self::convertOpenApiPathToSystemRouteKey($openApiPath);
            if ($routeKey === '') {
                continue;
            }
            $pathLevelRequiresUserToken = self::securityRequiresUserToken(
                isset($pathConfig['security']) ? $pathConfig['security'] : null
            );
            foreach ($methods as $method) {
                if (!array_key_exists($method, $pathConfig)) {
                    continue;
                }
                $operation = $pathConfig[$method];
                if (!is_array($operation)) {
                    continue;
                }
                if (array_key_exists('security', $operation)) {
                    $requiresUserToken = self::securityRequiresUserToken($operation['security']);
                }
                else {
                    $requiresUserToken = $pathLevelRequiresUserToken;
                }
                $lookupKey = strtoupper($method) . ':' . $routeKey;
                $map[$lookupKey] = $requiresUserToken;
            }
        }
        self::$systemUserTokenPolicyMap = $map;
        return $map;
    }
    /**
     * Convert an OpenAPI path (/system/api/v1/.../{param}) to the PHP
     * route-key form (v1/.../:param) used by SystemRoutesMap. Mirrors Node
     * convertOpenApiPathToSystemRoute (app.js:1724) but preserves the v1/
     * prefix to match PHP route map keys.
     */
    private static function convertOpenApiPathToSystemRouteKey($openApiPath)
    {
        $route = (string) $openApiPath;
        if (strpos($route, '/system/api/v1') !== 0) {
            return '';
        }
        $route = preg_replace('#^/system/api/?#', '', $route);
        $route = preg_replace('#^/#', '', $route);
        $route = preg_replace('/\{([A-Za-z0-9_]+)\}/', ':$1', $route);
        return is_string($route) ? $route : '';
    }
    /**
     * Determine whether a security config array declares userTokenHeader.
     * Mirrors Node normalizeSiteApiSecurityPolicy (app.js:1622): an empty
     * array or a requirement with no keys is public (false); a requirement
     * with siteTokenHeader is authenticated-site (false); otherwise true if
     * any requirement has userTokenHeader.
     */
    private static function securityRequiresUserToken($securityConfig)
    {
        if (!is_array($securityConfig) || count($securityConfig) === 0) {
            return false;
        }
        $requiresUserToken = false;
        foreach ($securityConfig as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            if (count($requirement) === 0) {
                return false;
            }
            if (array_key_exists('siteTokenHeader', $requirement)) {
                return false;
            }
            if (array_key_exists('userTokenHeader', $requirement)) {
                $requiresUserToken = true;
            }
        }
        return $requiresUserToken;
    }
    /**
     * Spec-driven X-HAXCMS-User-Token enforcement for all system v1 routes.
     * Looks up the policy for route+method in the cached spec-derived map;
     * if the route does not require the header, returns null. Otherwise reads
     * the X-HAXCMS-User-Token header from the context, validates it via
     * HAXCMS::validateRequestToken, and returns a 403 payload with the
     * NodeJS-identical message strings on missing/invalid. Returns null on
     * success. Parity with Node enforceSystemApiUserTokenPolicy (app.js:1806).
     *
     * For parameterized routes (e.g. v1/site/import/:platform) where the spec
     * declares concrete paths (e.g. /site/import/haxcms) rather than a
     * {platform} path, the :param placeholders are resolved from the
     * context's route params and the concrete key is looked up. This ensures
     * every route that declares userTokenHeader in the spec is enforced
     * (full-parity coverage for all ~46 routes), even when the spec uses
     * per-value paths instead of a parameterized path.
     */
    public static function enforceSystemApiUserTokenHeader($routeName, $method, $context)
    {
        $policyMap = self::getSystemUserTokenPolicyMap();
        $requiresUserToken = self::routeRequiresUserToken($policyMap, $routeName, $method, $context);
        if (!$requiresUserToken) {
            return null;
        }
        $token = '';
        if (is_object($context) && method_exists($context, 'getHeader')) {
            $headerValue = $context->getHeader('X-HAXCMS-User-Token');
            if (is_string($headerValue)) {
                $token = $headerValue;
            }
        }
        if ($token === '') {
            return array(
                'status' => 403,
                'message' => 'X-HAXCMS-User-Token header is required for this endpoint',
            );
        }
        $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
        if (!is_string($tokenUser) || $tokenUser === '') {
            $tokenUser = $GLOBALS['HAXCMS']->getActiveUserName();
        }
        if (!$GLOBALS['HAXCMS']->validateRequestToken($token, $tokenUser)) {
            return array(
                'status' => 403,
                'message' => 'Invalid X-HAXCMS-User-Token header',
            );
        }
        return null;
    }
    /**
     * Look up whether a route+method requires the X-HAXCMS-User-Token header
     * in the spec-derived policy map. Tries the direct METHOD:routeKey first;
     * if not found and the route has :param placeholders, resolves them from
     * the context's route params and looks up the concrete key (handles specs
     * that declare per-value paths like /site/import/haxcms instead of a
     * parameterized /site/import/{platform}).
     */
    private static function routeRequiresUserToken($policyMap, $routeName, $method, $context)
    {
        $lookupKey = strtoupper((string) $method) . ':' . (string) $routeName;
        if (isset($policyMap[$lookupKey])) {
            return (bool) $policyMap[$lookupKey];
        }
        $route = (string) $routeName;
        if (strpos($route, ':') === false) {
            return false;
        }
        $params = array();
        if (is_object($context) && isset($context->params) && is_array($context->params)) {
            $params = $context->params;
        }
        if (count($params) === 0) {
            return false;
        }
        $concreteRoute = $route;
        foreach ($params as $paramName => $paramValue) {
            if (!is_string($paramName) || $paramName === '') {
                continue;
            }
            $concreteRoute = str_replace(':' . $paramName, (string) $paramValue, $concreteRoute);
        }
        if ($concreteRoute === $route) {
            return false;
        }
        $concreteKey = strtoupper((string) $method) . ':' . $concreteRoute;
        if (isset($policyMap[$concreteKey])) {
            return (bool) $policyMap[$concreteKey];
        }
        return false;
    }
}
