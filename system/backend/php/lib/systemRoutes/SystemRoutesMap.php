<?php
class SystemRoutesMap
{
    public static function getRoutesForMethod($method = 'GET')
    {
        $normalizedMethod = strtoupper((string) $method);
        $routes = self::getRoutesMap();
        if (!array_key_exists($normalizedMethod, $routes)) {
            return array();
        }
        return $routes[$normalizedMethod];
    }
    public static function getRoutesMap()
    {
        return array(
            'GET' => array(
                '' => dirname(__FILE__) . '/discovery/api.php',
                'openapi' => dirname(__FILE__) . '/discovery/openapi.php',
                'openapi.json' => dirname(__FILE__) . '/discovery/openapi.php',
                'openapi.yaml' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/session' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/refresh' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/logout' => dirname(__FILE__) . '/v1/session.php',
                'v1/sites' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/status' => dirname(__FILE__) . '/v1/settings.php',
                'v1/system/version' => dirname(__FILE__) . '/v1/settings.php',
                'v1/entities' => dirname(__FILE__) . '/v1/settings.php',
                'v1/schemas' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/api-keys' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/media' => dirname(__FILE__) . '/v1/settings.php',
                'v1/blocks' => dirname(__FILE__) . '/v1/settings.php',
                'v1/skeletons' => dirname(__FILE__) . '/v1/settings.php',
                'v1/skeletons/:skeletonName' => dirname(__FILE__) . '/v1/settings.php',
                'v1/themes' => dirname(__FILE__) . '/v1/settings.php',
            ),
            'POST' => array(
                'v1/session/login' => dirname(__FILE__) . '/v1/session.php',
                'v1/session' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/logout' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/refresh' => dirname(__FILE__) . '/v1/session.php',
                'v1/sites' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName/clone' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName/archive' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName/download' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName/download-skeleton' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName/save-as-template' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/status' => dirname(__FILE__) . '/v1/settings.php',
                'v1/system/version' => dirname(__FILE__) . '/v1/settings.php',
                'v1/entities' => dirname(__FILE__) . '/v1/settings.php',
                'v1/schemas' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/api-keys' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/media' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/schema-files/operations' => dirname(__FILE__) . '/v1/settings.php',
                'v1/blocks' => dirname(__FILE__) . '/v1/settings.php',
                'v1/skeletons' => dirname(__FILE__) . '/v1/settings.php',
                'v1/themes' => dirname(__FILE__) . '/v1/settings.php',
            ),
            'PATCH' => array(
                'v1/configuration/api-keys' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/media' => dirname(__FILE__) . '/v1/settings.php',
                'v1/blocks' => dirname(__FILE__) . '/v1/settings.php',
                'v1/skeletons' => dirname(__FILE__) . '/v1/settings.php',
                'v1/skeletons/:skeletonName' => dirname(__FILE__) . '/v1/settings.php',
                'v1/themes' => dirname(__FILE__) . '/v1/settings.php',
            ),
            'PUT' => array(
                'v1/skeletons/:skeletonName' => dirname(__FILE__) . '/v1/settings.php',
            ),
            'DELETE' => array(
                'v1/skeletons/:skeletonName' => dirname(__FILE__) . '/v1/settings.php',
            ),
        );
    }
}
