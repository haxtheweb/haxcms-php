<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

if (!function_exists('haxcmsImportConvertHaxcmsToSite')) {
    function haxcmsImportConvertHaxcmsToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        if (!isset($body['repoUrl']) || $body['repoUrl'] === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $url = str_replace('/site.json', '', (string) $body['repoUrl']);
        $url = rtrim($url, '/');

        // Replace iam. with oer. in hostname
        $parsed = @parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid repoUrl', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $host = str_replace('iam.', 'oer.', $parsed['host']);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $path   = isset($parsed['path'])   ? $parsed['path']   : '';
        $base   = $scheme . '://' . $host . $path;

        $client     = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        $siteJson   = null;
        try {
            $response = $client->request('GET', $base . '/site.json');
            $siteJson = json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to fetch site.json: ' . $e->getMessage(), 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (!$siteJson || !isset($siteJson['items']) || !is_array($siteJson['items'])) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid site.json structure', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $downloads  = array();
        $siteFiles  = array();
        $siteFileKeys = array('theme/theme.css', 'theme/theme.html', 'custom/build/custom.es6.js');
        $boilerplateEs6 = '// custom comment script here';

        foreach ($siteJson['items'] as &$item) {
            if (isset($item['location']) && $item['location'] !== '') {
                try {
                    $resp = $client->request('GET', $base . '/' . $item['location']);
                    $item['contents'] = (string) $resp->getBody();
                } catch (\Exception $e) {
                    $item['contents'] = '';
                }
            }
            if (isset($item['metadata']['files']) && is_array($item['metadata']['files'])) {
                foreach ($item['metadata']['files'] as $fileEntry) {
                    if (isset($fileEntry['url'])) {
                        $downloads[$fileEntry['url']] = $base . '/' . $fileEntry['url'];
                    }
                }
            }
        }
        unset($item);

        foreach ($siteFileKeys as $filePath) {
            try {
                $resp = $client->request('GET', $base . '/' . $filePath);
                $text = trim((string) $resp->getBody());
                if ($text !== '') {
                    if ($filePath === 'custom/build/custom.es6.js' && $text === $boilerplateEs6) { continue; }
                    $siteFiles[$filePath] = $base . '/' . $filePath;
                }
            } catch (\Exception $e) {}
        }

        $filename = (isset($siteJson['metadata']['site']['name']) && $siteJson['metadata']['site']['name'] !== '')
            ? $siteJson['metadata']['site']['name']
            : basename($path);

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'items'     => $siteJson['items'],
                'filename'  => $filename,
                'files'     => $downloads,
                'siteFiles' => $siteFiles,
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
