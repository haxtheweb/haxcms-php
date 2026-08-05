<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertDrupalBookToSite')) {
    function haxcmsImportConvertDrupalBookToSite($context)
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

        // Discover book nodes via Drupal JSON:API
        // Try node--book type first, then node--page
        $bookNodes = array();
        $nodeTypes = array('book', 'page', 'article');

        foreach ($nodeTypes as $nodeType) {
            $url = $baseUrl . '/jsonapi/node/' . $nodeType . '?include=book&page[limit]=50';
            try {
                $resp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, [
                    'headers' => ['Accept' => 'application/vnd.api+json'],
                ]);
                $data  = json_decode((string) $resp->getBody(), true);
                if (is_array($data) && isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
                    $bookNodes = $data['data'];
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Also try generic JSON:API node endpoint
        if (empty($bookNodes)) {
            try {
                $resp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $baseUrl . '/jsonapi/node?page[limit]=50', [
                    'headers' => ['Accept' => 'application/vnd.api+json'],
                ]);
                $data  = json_decode((string) $resp->getBody(), true);
                if (is_array($data) && isset($data['data'])) {
                    $bookNodes = $data['data'];
                }
            } catch (\Exception $e) {
                SiteRouteUtils::sendFormattedResponse(
                    array('status' => 400, 'data' => array('error' => 'Unable to access Drupal JSON:API at ' . $baseUrl . ': ' . $e->getMessage(), 'items' => array(), 'filename' => null, 'files' => array())),
                    array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                    $context->routeSuffix, $apiBasePath
                );
                return;
            }
        }

        if (empty($bookNodes)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'No content found at ' . $baseUrl, 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $items = array();
        $order = 0;

        foreach ($bookNodes as $node) {
            $attrs   = isset($node['attributes']) ? $node['attributes'] : array();
            $title   = isset($attrs['title']) ? (string) $attrs['title'] : 'Node';
            $body    = '';
            if (isset($attrs['body'])) {
                $bodyField = $attrs['body'];
                $body = isset($bodyField['processed']) ? (string) $bodyField['processed']
                      : (isset($bodyField['value']) ? (string) $bodyField['value'] : '');
            }
            $slug = isset($attrs['path']['alias']) ? ltrim((string) $attrs['path']['alias'], '/')
                  : (isset($attrs['drupal_internal__nid']) ? 'node-' . $attrs['drupal_internal__nid'] : SiteRouteUtils::cleanSlug($title));

            $items[] = array(
                'id'          => SiteRouteUtils::generateUUID(),
                'title'       => $title,
                'order'       => $order++,
                'parent'      => $parentId,
                'slug'        => $slug,
                'contents'    => $body,
                'description' => '',
                'metadata'    => array(),
            );
        }

        // Try to get site name
        $siteName = 'drupal-import';
        try {
            $siteResp = SsrfGuard::safeGuzzleRequest($client, 'GET', $baseUrl . '/jsonapi', ['headers' => ['Accept' => 'application/vnd.api+json']]);
            $siteData = json_decode((string) $siteResp->getBody(), true);
            if (isset($siteData['meta']['links']['self']['href'])) {
                $siteName = 'drupal-import';
            }
        } catch (\Exception $e) {
            // fallback
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $siteName, 'files' => array())),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
