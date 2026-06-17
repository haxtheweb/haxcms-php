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
        $bearer = $GLOBALS['HAXCMS']->getBearerTokenFromRequest();
        if ($bearer === '') {
            return array(
                'allowed' => false,
                'status' => 401,
                'message' => 'Authentication required',
            );
        }
        $userName = $GLOBALS['HAXCMS']->getBearerTokenUserName($bearer);
        if ($userName === '') {
            return array(
                'allowed' => false,
                'status' => 401,
                'message' => 'Invalid bearer token',
            );
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
    private static function getRouteSecurity($route, $method)
    {
        $publicRoutes = array(
            'v1',
            'v1/openapi',
            'v1/openapi.json',
            'v1/openapi.yaml',
            'v1/session/login',
            'v1/session/logout',
            'v1/session/refresh',
            'v1/session/connection-settings',
            'v1/session/connection-test',
        );
        if (in_array($route, $publicRoutes, true)) {
            return 'public';
        }
        $adminRoutes = array(
            'v1/configuration/api-keys',
            'v1/configuration/media',
            'v1/configuration/schema-files/operations',
            'v1/blocks',
            'v1/skeletons',
            'v1/skeletons/:skeletonName',
            'v1/themes',
            'v1/haxiamAddUserAccess',
        );
        if (in_array($route, $adminRoutes, true)) {
            return 'admin';
        }
        return 'authenticated';
    }
}
