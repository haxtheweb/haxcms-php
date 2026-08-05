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
    /**
     * System v1 admin routes gated by the site-scoped dashboard referer check
     * (parity with Node SystemV1AdminRoutes + validateSystemV1RouteAccess).
     * A site-scoped request (URL under /{sitesDirectory}/) whose referer is
     * also site-scoped is blocked from these admin routes unless the referer
     * is the main dashboard. Listed with the v1/ prefix used by the route map
     * keys. Node drops its configuration/* and system/status/status aliases
     * in Phase 3 (D3); PHP never registered them, so the logical set matches.
     */
    public static function getSystemV1AdminRoutes()
    {
        return array(
            'v1/sites',
            'v1/sites/:siteName',
            'v1/sites/:siteName/clone',
            'v1/sites/:siteName/archive',
            'v1/sites/:siteName/download',
            'v1/sites/:siteName/download-skeleton',
            'v1/sites/:siteName/save-as-template',
            'v1/status',
            'v1/system/version',
            'v1/entities',
            'v1/schemas',
            'v1/configuration/api-keys',
            'v1/configuration/media',
            'v1/configuration/schema-files/operations',
            'v1/blocks',
            'v1/skeletons',
            'v1/skeletons/:skeletonName',
            'v1/themes',
        );
    }
    public static function getRoutesMap()
    {
        return array(
            'GET' => array(
                'v1' => dirname(__FILE__) . '/discovery/api.php',
                'v1/openapi' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/openapi.json' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/openapi.yaml' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/session' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/login' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/logout' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/connection-settings' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/connection-test' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/user' => dirname(__FILE__) . '/v1/session.php',
                'v1/sites' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/sites/:siteName' => dirname(__FILE__) . '/v1/lifecycle.php',
                'v1/status' => dirname(__FILE__) . '/v1/settings.php',
                'v1/system/version' => dirname(__FILE__) . '/v1/settings.php',
                'v1/entities' => dirname(__FILE__) . '/v1/settings.php',
                'v1/schemas' => dirname(__FILE__) . '/v1/settings.php',
                'v1/integrations/app-store' => dirname(__FILE__) . '/v1/integrations.php',
                'v1/integrations/app-store/providers/:provider/search' => dirname(__FILE__) . '/v1/integrations.php',
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
                'v1/session/connection-test' => dirname(__FILE__) . '/v1/session.php',
                'v1/session/user' => dirname(__FILE__) . '/v1/session.php',
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
                'v1/integrations/app-store/providers/:provider/search' => dirname(__FILE__) . '/v1/integrations.php',
                'v1/configuration/api-keys' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/media' => dirname(__FILE__) . '/v1/settings.php',
                'v1/configuration/schema-files/operations' => dirname(__FILE__) . '/v1/settings.php',
                'v1/blocks' => dirname(__FILE__) . '/v1/settings.php',
                'v1/skeletons' => dirname(__FILE__) . '/v1/settings.php',
                'v1/themes' => dirname(__FILE__) . '/v1/settings.php',
                'v1/haxiamAddUserAccess' => dirname(__FILE__) . '/v1/haxiam.php',
                'v1/actions/docx-to-html' => dirname(__FILE__) . '/v1/convertDocxToHtml.php',
                // Group A: text/data conversions
                'v1/actions/md-to-html' => dirname(__FILE__) . '/v1/convertMdToHtml.php',
                'v1/actions/html-to-md' => dirname(__FILE__) . '/v1/convertHtmlToMd.php',
                'v1/actions/json-to-yaml' => dirname(__FILE__) . '/v1/convertJsonToYaml.php',
                'v1/actions/yaml-to-json' => dirname(__FILE__) . '/v1/convertYamlToJson.php',
                'v1/actions/pretty-html' => dirname(__FILE__) . '/v1/convertPrettyHtml.php',
                // Group B: file upload to items
                'v1/actions/import-docx' => dirname(__FILE__) . '/v1/importDocx.php',
                'v1/actions/import-html' => dirname(__FILE__) . '/v1/importHtml.php',
                'v1/actions/import-pptx' => dirname(__FILE__) . '/v1/importPptx.php',
                'v1/actions/import-xlsx' => dirname(__FILE__) . '/v1/importXlsx.php',
                'v1/actions/import-pdf' => dirname(__FILE__) . '/v1/importPdf.php',
                // Group C: binary document conversions
                'v1/actions/pptx-to-html' => dirname(__FILE__) . '/v1/convertPptxToHtml.php',
                'v1/actions/xlsx-to-csv' => dirname(__FILE__) . '/v1/convertXlsxToCsv.php',
                'v1/actions/pdf-to-html' => dirname(__FILE__) . '/v1/convertPdfToHtml.php',
                'v1/actions/html-to-docx' => dirname(__FILE__) . '/v1/convertHtmlToDocx.php',
                'v1/actions/html-to-pdf' => dirname(__FILE__) . '/v1/convertHtmlToPdf.php',
                'v1/actions/docx-to-pdf' => dirname(__FILE__) . '/v1/convertDocxToPdf.php',
                // Site import dispatcher (platform is a URL param)
                'v1/site/import/:platform' => dirname(__FILE__) . '/v1/siteImport.php',
                // connection-settings POST
                'v1/session/connection-settings' => dirname(__FILE__) . '/v1/session.php',
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
