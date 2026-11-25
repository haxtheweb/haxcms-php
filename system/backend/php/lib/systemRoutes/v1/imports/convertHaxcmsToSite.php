<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';
// files are staged locally so createSite can ingest them as file entities
include_once dirname(__FILE__) . '/../../../stageRemoteFile.php';
// an uploaded site zip is read here (haxtheweb/issues#3405)
include_once dirname(__FILE__) . '/../../../SiteZip.php';

if (!function_exists('haxcmsImportConvertHaxcmsToSite')) {
    // $client is an optional Guzzle client (tests inject a handler-backed one)
    function haxcmsImportConvertHaxcmsToSite($context, $client = null)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        // an uploaded site zip (what Download site gives) instead of an address
        $upload = haxcmsImportUploadedFile();
        if ($upload !== null) {
            haxcmsImportConvertHaxcmsZipToSite($context, $upload);
            return;
        }

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

        if ($client === null) {
            $client = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        }
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
        // Security (SEC-SSRF): files staged below are handed to createSite in
        // a later request (which removes them once ingested). If this import
        // fails part-way, remove what was staged instead of leaking it.
        try {
            $siteFiles  = array();
            // theme/ left the site boilerplate (haxtheweb/issues#3337) and is not a
            // createSite siteFiles target; only the custom build carries over.
            $siteFileKeys = array('custom/build/custom.es6.js');
            $boilerplateEs6 = '// custom comment script here';

            foreach ($siteJson['items'] as &$item) {
                if (isset($item['slug'])) {
                    // Security (SEC-PATH): sanitize remote-sourced slug.
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
                        // the untouched boilerplate custom build carries nothing over
                        if ($text === $boilerplateEs6) { continue; }
                        $siteFiles[$filePath] = $base . '/' . $filePath;
                    }
                } catch (\Exception $e) {}
            }

            $filename = (isset($siteJson['metadata']['site']['name']) && $siteJson['metadata']['site']['name'] !== '')
                ? $siteJson['metadata']['site']['name']
                : basename($path);
        } catch (\Throwable $e) {
            haxcms_import_unlink_staged_files(array_values($downloads));
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 500, 'data' => array('error' => 'Unable to import site', 'items' => array(), 'filename' => null)),
                array('statusCode' => 500, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

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

if (!function_exists('haxcmsImportUploadedFile')) {
    // the first uploaded file of the request (any field name, like
    // haxcms-nodejs's req.files[0]), or null
    function haxcmsImportUploadedFile()
    {
        foreach ($_FILES as $file) {
            if (isset($file['tmp_name']) && is_string($file['tmp_name'])
                && $file['tmp_name'] !== '' && (!isset($file['error']) || $file['error'] === UPLOAD_ERR_OK)) {
                return $file;
            }
        }
        return null;
    }
}

if (!function_exists('haxcmsImportConvertHaxcmsZipToSite')) {
    /**
     * The same import from an uploaded HAXcms site zip. Returns the URL
     * import's shape plus `site` (title, description, theme); the site's
     * custom build comes back in siteFiles as a staged file, which createSite
     * writes only while site zip import is on.
     * Parity: haxcms-nodejs convertHaxcmsZipToSite.
     */
    function haxcmsImportConvertHaxcmsZipToSite($context, $file)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        // off unless the operator turns it on (HAXCMSSiteZip::enabled)
        if (!HAXCMSSiteZip::enabled()) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 403, 'data' => array('error' => 'site-zip-import is disabled', 'reason' => 'DISABLED', 'items' => array(), 'filename' => null)),
                array('statusCode' => 403, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }
        // Security (SEC-DOS): the conversion upload cap applies before the zip is read
        $maxBytes = HAXCMSSiteZip::maxUploadBytes();
        $size = @filesize($file['tmp_name']);
        if ($size !== false && $size > $maxBytes) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'File exceeds the maximum upload size of ' . round($maxBytes / (1024 * 1024)) . ' MB', 'reason' => 'ZIP_BUDGET', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }
        try {
            $read = HAXCMSSiteZip::read($file['tmp_name']);
        } catch (HAXCMSSiteZipException $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => $e->getMessage(), 'reason' => $e->reason, 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        } catch (\Throwable $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid .zip file.', 'reason' => 'NOT_A_ZIP', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'items'     => $read['items'],
                'filename'  => $read['filename'],
                'files'     => (object) $read['files'],
                'siteFiles' => (object) $read['siteFiles'],
                'site'      => $read['site'],
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
