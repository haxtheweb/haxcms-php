<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertElmslnToSite')) {
    function haxcmsImportConvertElmslnToSite($context)
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

        $baseUrl   = rtrim($repoUrl, '/');
        $siteJsonUrl = $baseUrl . '/site.json';
        $client    = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);

        // Fetch site.json (JSON Outline Schema)
        try {
            $resp     = SsrfGuard::safeGuzzleRequest($client, 'GET', $siteJsonUrl, ['headers' => ['Accept' => 'application/json']]);
            $siteJson = json_decode((string) $resp->getBody(), true);
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to fetch site.json from ' . $baseUrl . ': ' . $e->getMessage(), 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (!is_array($siteJson) || !isset($siteJson['items'])) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid site.json structure at ' . $baseUrl, 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $siteTitle   = isset($siteJson['title']) ? (string) $siteJson['title'] : 'elmsln-import';
        $sourceItems = $siteJson['items'];

        // Map old IDs to new UUIDs
        $oldToNew = array();
        foreach ($sourceItems as $si) {
            if (isset($si['id'])) {
                $oldToNew[(string) $si['id']] = SiteRouteUtils::generateUUID();
            }
        }

        $items = array();
        $downloads = array();
        $order = 0;
        foreach ($sourceItems as $si) {
            $oldId    = isset($si['id'])     ? (string) $si['id']     : '';
            $newId    = isset($oldToNew[$oldId]) ? $oldToNew[$oldId] : SiteRouteUtils::generateUUID();
            $oldPid   = isset($si['parent']) ? (string) $si['parent'] : '';
            $newPid   = ($oldPid !== '' && isset($oldToNew[$oldPid])) ? $oldToNew[$oldPid] : $parentId;
            $title    = isset($si['title'])  ? (string) $si['title']  : 'Page';
            $slug     = isset($si['slug'])   ? (string) $si['slug']   : SiteRouteUtils::cleanSlug($title);
            $location = isset($si['location']) ? (string) $si['location'] : '';
            $content  = '';

            // Download page content from the remote site
            if ($location !== '') {
                try {
                    $pageUrl  = $baseUrl . '/' . ltrim($location, '/');
                    $pageResp = SsrfGuard::safeGuzzleRequest($client, 'GET', $pageUrl);
                    $content  = (string) $pageResp->getBody();
                } catch (\Exception $e) {
                    // leave content empty
                }
            }

            $items[] = array(
                'id'          => $newId,
                'title'       => $title,
                'order'       => isset($si['order']) ? (int) $si['order'] : $order,
                'parent'      => $newPid,
                'slug'        => $slug,
                'contents'    => $content,
                'description' => isset($si['description']) ? (string) $si['description'] : '',
                'metadata'    => isset($si['metadata']) && is_array($si['metadata']) ? $si['metadata'] : array(),
            );
            if (isset($si['metadata']) && is_array($si['metadata']) && isset($si['metadata']['files']) && is_array($si['metadata']['files'])) {
                foreach ($si['metadata']['files'] as $file) {
                    if (is_array($file) && isset($file['url']) && is_string($file['url']) && $file['url'] !== '') {
                        $downloads[$file['url']] = $baseUrl . '/' . $file['url'];
                    }
                }
            }
            $order++;
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $siteTitle, 'files' => $downloads)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
