<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertPloneToSite')) {
    function haxcmsImportConvertPloneToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        $repoUrl  = isset($body['repoUrl'])  ? trim((string) $body['repoUrl'])  : '';
        $method   = isset($body['method'])   ? (string) $body['method']   : 'site';
        $type     = isset($body['type'])     ? (string) $body['type']     : '';
        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;

        if ($repoUrl === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $baseUrl = rtrim($repoUrl, '/');
        $client  = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];

        // Fetch site root info
        $siteTitle = 'plone-import';
        try {
            $siteResp = SsrfGuard::safeGuzzleRequest($client, 'GET', $baseUrl . '/@site', ['headers' => $headers]);
            $siteData = json_decode((string) $siteResp->getBody(), true);
            if (isset($siteData['title'])) {
                $siteTitle = (string) $siteData['title'];
            }
        } catch (\Exception $e) {
            // non-fatal
        }

        // Search for all content
        $searchUrl = $baseUrl . '/@search?portal_type:list=Document&portal_type:list=Page&sort_on=getObjPositionInParent&b_size=100';
        try {
            $searchResp = SsrfGuard::safeGuzzleRequest($client, 'GET', $searchUrl, ['headers' => $headers]);
            $searchData = json_decode((string) $searchResp->getBody(), true);
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to search Plone site: ' . $e->getMessage(), 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (!is_array($searchData) || !isset($searchData['items'])) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid Plone search response', 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $items = array();
        $order = 0;

        foreach ($searchData['items'] as $result) {
            $itemUrl = isset($result['@id']) ? (string) $result['@id'] : '';
            $title   = isset($result['title']) ? (string) $result['title'] : 'Page';
            $content = '';

            // Fetch full item to get body content
            if ($itemUrl !== '') {
                try {
                    $itemResp = SsrfGuard::safeGuzzleRequest($client, 'GET', $itemUrl, ['headers' => $headers]);
                    $itemData = json_decode((string) $itemResp->getBody(), true);
                    if (isset($itemData['text']['data'])) {
                        $content = (string) $itemData['text']['data'];
                    } elseif (isset($itemData['text'])) {
                        $content = is_string($itemData['text']) ? $itemData['text'] : '';
                    }
                    if (isset($itemData['title'])) {
                        $title = (string) $itemData['title'];
                    }
                } catch (\Exception $e) {
                    // continue with empty content
                }
            }

            // Determine slug from URL
            $slug = '';
            if ($itemUrl !== '') {
                $relative = str_replace($baseUrl, '', $itemUrl);
                $slug     = trim($relative, '/');
            }
            if ($slug === '') {
                $slug = SiteRouteUtils::cleanSlug($title);
            }

            $items[] = array(
                'id'          => SiteRouteUtils::generateUUID(),
                'title'       => $title,
                'order'       => $order++,
                'parent'      => $parentId,
                'slug'        => $slug,
                'contents'    => $content,
                'description' => isset($result['description']) ? (string) $result['description'] : '',
                'metadata'    => array(),
            );
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $siteTitle, 'files' => array())),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
