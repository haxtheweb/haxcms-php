<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

// createSite's build.files contract is "local staged paths only" (its
// isValidBulkImportTmpPath validator rejects URL schemes by design), so the
// converter downloads each referenced file into the bulk-import staging
// root and hands createSite the staged path instead of the remote URL.
// Mirrors the haxcms-nodejs convertHaxcmsToSite.js staging helpers.
if (!function_exists('haxcms_import_get_staging_root')) {
    function haxcms_import_get_staging_root() {
        global $HAXCMS;
        if (!isset($HAXCMS) || !isset($HAXCMS->configDirectory)) {
            return false;
        }
        $root = $HAXCMS->configDirectory . '/tmp/imports';
        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }
        if (!is_dir($root)) {
            return false;
        }
        return $root;
    }
}

// Fetch a remote file via SsrfGuard::safeGuzzleRequest (SSRF-guarded,
// redirects disabled) and stage it under the bulk-import root. Reuses the
// caller's Guzzle $client. Returns the absolute staged path, or false on
// any fetch/write failure or empty body (the file is simply skipped).
if (!function_exists('haxcms_import_stage_remote_file')) {
    function haxcms_import_stage_remote_file($client, $url, $relPath) {
        $root = haxcms_import_get_staging_root();
        if ($root === false) {
            return false;
        }
        try {
            $resp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $url);
            $body  = (string) $resp->getBody();
        } catch (\Exception $e) {
            return false;
        }
        if ($body === '') {
            return false;
        }
        $ext      = pathinfo($relPath, PATHINFO_EXTENSION);
        $extPart  = ($ext !== '') ? '.' . $ext : '';
        $staged   = $root . '/haxbi_' . uniqid() . $extPart;
        if (@file_put_contents($staged, $body) === false) {
            return false;
        }
        return $staged;
    }
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
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $base . '/site.json');
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
            if (isset($item['slug'])) {
                // Security (SEC-16): sanitize remote-sourced slug.
                $item['slug'] = SiteRouteUtils::cleanSlug((string) $item['slug']);
            }
            if (isset($item['location']) && $item['location'] !== '') {
                try {
                    $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', $base . '/' . $item['location']);
                    $item['contents'] = (string) $resp->getBody();
                } catch (\Exception $e) {
                    $item['contents'] = '';
                }
            }
            if (isset($item['metadata']['files']) && is_array($item['metadata']['files'])) {
                foreach ($item['metadata']['files'] as $fileEntry) {
                    if (isset($fileEntry['url'])) {
                        $stagedPath = haxcms_import_stage_remote_file($client, $base . '/' . $fileEntry['url'], $fileEntry['url']);
                        if ($stagedPath !== false) {
                            $downloads[$fileEntry['url']] = $stagedPath;
                        }
                    }
                }
            }
        }
        unset($item);

        foreach ($siteFileKeys as $filePath) {
            try {
                $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', $base . '/' . $filePath);
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
