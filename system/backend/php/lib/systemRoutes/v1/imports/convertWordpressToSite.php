<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertWordpressToSite')) {
    function haxcmsImportConvertWordpressToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        $repoUrl  = isset($body['repoUrl'])  ? trim((string) $body['repoUrl'])  : '';
        $method   = isset($body['method'])   ? (string) $body['method']   : 'site';
        $type     = isset($body['type'])     ? (string) $body['type']     : '';
        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;
        // D35: adapter param (parity with Node convertWordpressToSite). Only `pages` supported.
        $adapter       = isset($body['adapter']) ? (string) $body['adapter'] : 'pages';
        $validAdapters = array('pages');

        if ($repoUrl === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (!in_array($adapter, $validAdapters, true)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'unknown adapter `' . $adapter . '`; valid adapters: ' . implode(', ', $validAdapters), 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $baseUrl = rtrim($repoUrl, '/');
        $wpApi   = $baseUrl . '/wp-json/wp/v2';
        $client  = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);

        // Fetch pages with hierarchy
        $pages     = array();
        $page      = 1;
        $perPage   = 100;
        do {
            try {
                $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', $wpApi . '/pages', [
                    'headers' => ['Accept' => 'application/json'],
                    'query'   => array('per_page' => $perPage, 'page' => $page, 'orderby' => 'menu_order', 'order' => 'asc'),
                ]);
                $batch = json_decode((string) $resp->getBody(), true);
            } catch (\Exception $e) {
                SiteRouteUtils::sendFormattedResponse(
                    array('status' => 422, 'data' => array('error' => 'Unable to fetch WordPress pages: ' . $e->getMessage(), 'items' => array(), 'filename' => null, 'files' => array())),
                    array('statusCode' => 422, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                    $context->routeSuffix, $apiBasePath
                );
                return;
            }
            if (!is_array($batch) || count($batch) === 0) { break; }
            $pages = array_merge($pages, $batch);
            $page++;
        } while (count($batch) === $perPage);

        if (empty($pages)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 422, 'data' => array('error' => 'No pages found at ' . $baseUrl, 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 422, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Build a map from WP page ID to generated UUID
        $wpIdToUuid = array();
        foreach ($pages as $wpPage) {
            if (isset($wpPage['id'])) {
                $wpIdToUuid[(int) $wpPage['id']] = SiteRouteUtils::generateUUID();
            }
        }

        $items = array();
        $order = 0;
        foreach ($pages as $wpPage) {
            $wpId    = isset($wpPage['id'])   ? (int) $wpPage['id']   : 0;
            $wpPid   = isset($wpPage['parent']) ? (int) $wpPage['parent'] : 0;
            $title   = isset($wpPage['title']['rendered'])   ? (string) $wpPage['title']['rendered']   : 'Page';
            $content = isset($wpPage['content']['rendered']) ? (string) $wpPage['content']['rendered'] : '';
            $slug    = isset($wpPage['slug']) ? (string) $wpPage['slug'] : SiteRouteUtils::cleanSlug($title);

            $uuid         = isset($wpIdToUuid[$wpId]) ? $wpIdToUuid[$wpId] : SiteRouteUtils::generateUUID();
            $parentUuid   = ($wpPid !== 0 && isset($wpIdToUuid[$wpPid])) ? $wpIdToUuid[$wpPid] : $parentId;

            $items[] = array(
                'id'          => $uuid,
                'title'       => $title,
                'order'       => $order++,
                'parent'      => $parentUuid,
                'slug'        => $slug,
                'contents'    => $content,
                'description' => isset($wpPage['excerpt']['rendered']) ? (string) $wpPage['excerpt']['rendered'] : '',
                'metadata'    => array(),
            );
        }

        // Try to get site title
        $siteTitle = 'wordpress-import';
        try {
            $siteResp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $baseUrl . '/wp-json/', ['headers' => ['Accept' => 'application/json']]);
            $siteData  = json_decode((string) $siteResp->getBody(), true);
            if (isset($siteData['name'])) {
                $siteTitle = (string) $siteData['name'];
            }
        } catch (\Exception $e) {
            // fallback title
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $siteTitle, 'files' => array(), 'wordpress' => array('baseUrl' => $baseUrl, 'adapter' => $adapter, 'pageCount' => count($items)))),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
