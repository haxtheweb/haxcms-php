<?php
class SiteRoutesMap
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
                'v1' => dirname(__FILE__) . '/discovery/api.php',
                'v1/openapi' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/openapi.json' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/openapi.yaml' => dirname(__FILE__) . '/discovery/openapi.php',
                'v1/site' => dirname(__FILE__) . '/v1/site.php',
                'v1/site/export/:format' => dirname(__FILE__) . '/v1/exports.php',
                'v1/entities' => dirname(__FILE__) . '/v1/entities.php',
                'v1/schemas' => dirname(__FILE__) . '/v1/schemas.php',
                'v1/items' => dirname(__FILE__) . '/v1/items.php',
                'v1/items/:idOrSlug' => dirname(__FILE__) . '/v1/items.php',
                'v1/items/:idOrSlug/export/:format' => dirname(__FILE__) . '/v1/exports.php',
                'v1/items/:idOrSlug/revisions' => dirname(__FILE__) . '/v1/revisions.php',
                'v1/items/:idOrSlug/revisions/:revisionId' => dirname(__FILE__) . '/v1/revisions.php',
                'v1/content' => dirname(__FILE__) . '/v1/content.php',
                'v1/content/:idOrSlug' => dirname(__FILE__) . '/v1/content.php',
                'v1/files' => dirname(__FILE__) . '/v1/files.php',
                'v1/tags' => dirname(__FILE__) . '/v1/tags.php',
                'v1/search' => dirname(__FILE__) . '/v1/search.php',
                'v1/custom-elements' => dirname(__FILE__) . '/v1/customElements.php',
                'v1/custom-elements/:webcomponentName' => dirname(__FILE__) . '/v1/customElements.php',
                'v1/blocks' => dirname(__FILE__) . '/v1/blocks.php',
                'v1/blocks/:webcomponentName' => dirname(__FILE__) . '/v1/blocks.php',
                'v1/blocks/:webcomponentName/usage' => dirname(__FILE__) . '/v1/blocks.php',
                'v1/regions' => dirname(__FILE__) . '/v1/regions.php',
                'v1/regions/:regionName' => dirname(__FILE__) . '/v1/regions.php',
                'v1/themes' => dirname(__FILE__) . '/v1/themes.php',
                'v1/themes/active' => dirname(__FILE__) . '/v1/themes.php',
                'v1/themes/:themeName' => dirname(__FILE__) . '/v1/themes.php',
                'v1/reports' => dirname(__FILE__) . '/v1/reports.php',
                'v1/reports/:report' => dirname(__FILE__) . '/v1/reports.php',
                'v1/analytics' => dirname(__FILE__) . '/v1/analytics.php',
                'v1/views' => dirname(__FILE__) . '/v1/views.php',
                'v1/views/:viewId' => dirname(__FILE__) . '/v1/views.php',
                'v1/views/:viewId/results' => dirname(__FILE__) . '/v1/views.php',
                'v1/displays' => dirname(__FILE__) . '/v1/views.php',
                'v1/displays/:viewId/results' => dirname(__FILE__) . '/v1/views.php',
            ),
            'POST' => array(
                'v1/items' => dirname(__FILE__) . '/v1/itemsMutation.php',
                'v1/items/:idOrSlug/revisions/:revisionId/restore' => dirname(__FILE__) . '/v1/revisionsMutation.php',
                'v1/files' => dirname(__FILE__) . '/v1/filesMutation.php',
                'v1/site/export/:format' => dirname(__FILE__) . '/v1/exportsMutation.php',
            ),
            'PATCH' => array(
                'v1/items/:idOrSlug' => dirname(__FILE__) . '/v1/itemsMutation.php',
                'v1/content/:idOrSlug' => dirname(__FILE__) . '/v1/contentMutation.php',
                'v1/site' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/site/appearance' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/site/platform' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/site/blocks' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/site/editor' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/site/seo' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/site/outline' => dirname(__FILE__) . '/v1/siteMutation.php',
                'v1/files/:fileUuid' => dirname(__FILE__) . '/v1/filesMutation.php',
            ),
            'DELETE' => array(
                'v1/items/:idOrSlug' => dirname(__FILE__) . '/v1/itemsMutation.php',
                'v1/files/:fileUuid' => dirname(__FILE__) . '/v1/filesMutation.php',
            ),
        );
    }
}
