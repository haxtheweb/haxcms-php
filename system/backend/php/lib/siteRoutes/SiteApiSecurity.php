<?php
include_once dirname(__FILE__) . '/SiteRouteUtils.php';
class SiteApiSecurity
{
    public static function validateSiteApiAccess($context, $routeSuffix = '', $method = 'GET')
    {
        $policy = self::getRoutePolicy($routeSuffix, $method);
        $result = array(
            'allowed' => false,
            'status' => 401,
            'message' => 'Authentication required',
            'retryAfterSeconds' => 0,
            'userName' => null,
        );
        if ($policy === 'public') {
            $result['allowed'] = true;
            $result['status'] = 200;
            $result['message'] = '';
            return $result;
        }
        // Resolve authenticated userName from Bearer (primary) or Basic (fallback).
        // Distinguish "no credentials at all" (401, genuine logged-out state)
        // from "credentials present but invalid/expired" (403, refreshable).
        // Mirrors the NodeJS site API split in app.js validateSiteApiRouteAccess;
        // the Basic fallback shares the login rate-limit counters (429 + Retry-After)
        // and the site-token check below still applies, so basic-auth alone never
        // grants site-scoped access.
        $bearerToken = SiteRouteUtils::getBearerTokenFromRequest();
        $userName = null;
        if (!is_null($bearerToken)) {
            $userName = self::resolveBearerUserName();
            if (is_null($userName) || $userName === '') {
                // bearer was present but failed to decode / had no user -> invalid or expired
                $result['status'] = 403;
                $result['message'] = 'Invalid or expired Bearer token';
                return $result;
            }
        }
        else {
            $basic = null;
            if (
                isset($GLOBALS['HAXCMS']) &&
                is_object($GLOBALS['HAXCMS']) &&
                method_exists($GLOBALS['HAXCMS'], 'authenticateBasicAuthorization')
            ) {
                $basic = $GLOBALS['HAXCMS']->authenticateBasicAuthorization();
            }
            if (is_array($basic) && !empty($basic['blocked'])) {
                $result['status'] = 429;
                $result['message'] = 'Too many failed login attempts. Please try again later.';
                $result['retryAfterSeconds'] = isset($basic['retryAfterSeconds']) ? (int) $basic['retryAfterSeconds'] : 0;
                return $result;
            }
            if (is_array($basic) && !empty($basic['authenticated']) && !empty($basic['userName'])) {
                $userName = $basic['userName'];
            }
            else {
                $result['status'] = 401;
                $result['message'] = (is_array($basic) && !empty($basic['attempted']))
                    ? 'Invalid basic authorization credentials'
                    : 'Missing Bearer token';
                return $result;
            }
        }
        $result['userName'] = $userName;
        if ($policy === 'authenticated') {
            $result['allowed'] = true;
            $result['status'] = 200;
            $result['message'] = '';
            return $result;
        }
        if ($policy === 'authenticated-site') {
            $siteName = self::resolveSiteName($context);
            if ($siteName === '') {
                $result['status'] = 400;
                $result['message'] = 'Unable to resolve site name';
                return $result;
            }
            $siteToken = null;
            if (
                isset($context) &&
                is_object($context) &&
                method_exists($context, 'getHeader')
            ) {
                $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
            }
            if (is_null($siteToken) || $siteToken === '') {
                $result['status'] = 403;
                $result['message'] = 'Missing X-HAXCMS-Site-Token header';
                return $result;
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
                $result['status'] = 403;
                $result['message'] = 'Invalid site token';
                return $result;
            }
            $result['allowed'] = true;
            $result['status'] = 200;
            $result['message'] = '';
            return $result;
        }
        return $result;
    }
    private static function getRoutePolicy($routeSuffix, $method)
    {
        $suffix = trim((string) $routeSuffix, '/');
        $upperMethod = strtoupper((string) $method);
        if ($upperMethod === 'OPTIONS') {
            return 'public';
        }
        if (in_array($upperMethod, array('POST', 'PATCH', 'PUT', 'DELETE'), true)) {
            return 'authenticated-site';
        }
        $publicPatterns = array(
            '/^$/',
            '/^openapi(\\.json|\\.yaml)?$/',
            '/^v1$/',
            '/^v1\\/openapi(\\.json|\\.yaml)?$/',
            '/^v1\\/site$/',
            '/^v1\/items$/',
            '/^v1\/items\/[^\/]+$/',
            '/^v1\/content$/',
            '/^v1\/content\/[^\/]+$/',
            '/^v1\/files$/',
            '/^v1\/tags$/',
            '/^v1\/search$/',
            '/^v1\/custom-elements/',
            '/^v1\/blocks/',
            '/^v1\/regions/',
            '/^v1\/themes/',
            '/^v1\/reports/',
            '/^v1\/analytics$/',
            '/^v1\/views/',
            '/^v1\/displays/',
            '/^v1\/entities$/',
            '/^v1\/schemas$/',
            '/^v1\/site\/export\/[^\/]+$/',
            '/^v1\/items\/[^\/]+\/export\/[^\/]+$/',
        );
        foreach ($publicPatterns as $pattern) {
            if (preg_match($pattern, $suffix)) {
                return 'public';
            }
        }
        if (preg_match('/^v1\/items\/[^\/]+\/revisions/', $suffix)) {
            return 'authenticated-site';
        }
        return 'authenticated';
    }
    private static function resolveBearerUserName()
    {
        $token = SiteRouteUtils::getBearerTokenFromRequest();
        if (!$token) {
            return null;
        }
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'getBearerTokenUserName')
        ) {
            return $GLOBALS['HAXCMS']->getBearerTokenUserName();
        }
        if (!class_exists('JWT')) {
            $jwtPath = dirname(__FILE__) . '/../JWT.php';
            if (file_exists($jwtPath)) {
                include_once $jwtPath;
            }
        }
        if (!class_exists('JWT')) {
            return null;
        }
        try {
            if (
                isset($GLOBALS['HAXCMS']) &&
                is_object($GLOBALS['HAXCMS']) &&
                isset($GLOBALS['HAXCMS']->privateKey) &&
                isset($GLOBALS['HAXCMS']->salt)
            ) {
                $payload = JWT::decode($token, $GLOBALS['HAXCMS']->privateKey . $GLOBALS['HAXCMS']->salt);
                // Security best practice (N3): this fallback decode path must
                // enforce access-token expiry just like the primary
                // HAXCMS::getBearerTokenUserName path; otherwise a stolen token
                // could be replayed indefinitely when this branch is reached.
                // HAXCMS::validateAccessTokenClaims is private, so the same
                // exp/nbf/iat logic (60s skew leeway) is mirrored inline here.
                $now = time();
                $leeway = 60;
                if (!isset($payload->exp) || !is_numeric($payload->exp)) {
                    return null;
                }
                if ($now >= ((int) $payload->exp + $leeway)) {
                    return null;
                }
                if (isset($payload->nbf) && is_numeric($payload->nbf) && ($now + $leeway) < (int) $payload->nbf) {
                    return null;
                }
                if (isset($payload->iat) && is_numeric($payload->iat) && ($now + $leeway) < (int) $payload->iat) {
                    return null;
                }
                if (isset($payload->user) && $payload->user != '') {
                    return $GLOBALS['HAXCMS']->generateMachineName($payload->user);
                }
            }
        }
        catch (Exception $e) {}
        return null;
    }
    private static function resolveSiteName($context)
    {
        if (!isset($context) || !is_object($context) || !isset($context->site)) {
            return '';
        }
        $site = $context->site;
        if (
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->name)
        ) {
            return (string) $site->manifest->metadata->site->name;
        }
        if (isset($site->name)) {
            return (string) $site->name;
        }
        return '';
    }
}
