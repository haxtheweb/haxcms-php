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
        $userName = self::resolveBearerUserName();
        if (is_null($userName) || $userName === '') {
            $result['status'] = 401;
            $result['message'] = 'Missing or invalid Bearer token';
            return $result;
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
