<?php
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
        $publicRoutes = array(
            'v1',
            'v1/openapi',
            'v1/openapi.json',
            'v1/openapi.yaml',
            'v1/session',
            'v1/session/login',
            'v1/session/logout',
            'v1/session/refresh',
            'v1/session/connection-settings',
            'v1/session/connection-test',
            'v1/integrations/app-store',
            'v1/integrations/app-store/providers/:provider/search',
        );
        if (in_array($route, $publicRoutes, true)) {
            return 'public';
        }
        $authenticatedRoutes = array(
            'v1/haxiamAddUserAccess',
        );
        if (in_array($route, $authenticatedRoutes, true)) {
            return 'authenticated';
        }
        $dashboardReadRoutes = array(
            'v1/skeletons',
            'v1/skeletons/:skeletonName',
            'v1/themes',
            'v1/configuration/api-keys',
            'v1/configuration/media',
            'v1/blocks',
        );
        if (
            $normalizedMethod === 'GET' &&
            in_array($route, $dashboardReadRoutes, true)
        ) {
            return 'authenticated';
        }
        $adminRoutes = array(
            'v1/configuration/api-keys',
            'v1/configuration/media',
            'v1/configuration/schema-files/operations',
            'v1/blocks',
            'v1/skeletons',
            'v1/skeletons/:skeletonName',
            'v1/themes',
        );
        if (in_array($route, $adminRoutes, true)) {
            return 'admin';
        }
        return 'authenticated';
    }
}
