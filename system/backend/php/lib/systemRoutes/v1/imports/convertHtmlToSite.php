<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

if (!function_exists('haxcmsImportConvertHtmlToSite')) {
    function haxcmsImportConvertHtmlToSite($context)
    {
        $apiBasePath  = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body         = $context->body;
        if (!is_array($body)) { $body = array(); }
        $html         = '';
        $filename     = null;
        $contentType  = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';

        // Multipart: uploaded file
        $fileKey = null;
        foreach (array('upload', 'file', 'file-upload') as $key) {
            if (isset($_FILES[$key]) && is_array($_FILES[$key]) && isset($_FILES[$key]['tmp_name']) && $_FILES[$key]['tmp_name'] !== '') {
                $fileKey = $key;
                break;
            }
        }

        if ($fileKey !== null) {
            $file     = $_FILES[$fileKey];
            $filename = isset($file['name']) ? (string) $file['name'] : 'file.html';
            if (!preg_match('/\.(html|htm)$/i', $filename)) {
                SiteRouteUtils::sendFormattedResponse(
                    array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .html or .htm, got: ' . $filename, 'items' => array(), 'filename' => $filename)),
                    array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                    $context->routeSuffix, $apiBasePath
                );
                return;
            }
            $html = (string) @file_get_contents($file['tmp_name']);
        } elseif (isset($body['repoUrl']) && $body['repoUrl'] !== '') {
            try {
                $client   = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
                $response = $client->request('GET', (string) $body['repoUrl']);
                $html     = (string) $response->getBody();
                $parts    = explode('/', $body['repoUrl']);
                $filename = end($parts) ?: 'import.html';
            } catch (\Exception $e) {
                SiteRouteUtils::sendFormattedResponse(
                    array('status' => 400, 'data' => array('error' => 'Unable to fetch URL: ' . $e->getMessage(), 'items' => array(), 'filename' => null)),
                    array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                    $context->routeSuffix, $apiBasePath
                );
                return;
            }
        } else {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (trim($html) === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Empty HTML content', 'items' => array(), 'filename' => $filename)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $method   = isset($body['method'])   ? (string) $body['method']   : 'site';
        $type     = isset($body['type'])     ? (string) $body['type']     : '';
        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;
        $titleValue = $filename ? preg_replace('/\.(html|htm)$/i', '', $filename) : 'import';

        $items = haxcmsImportHtmlToItems($html, array(
            'titleValue' => $titleValue,
            'method'     => $method,
            'type'       => $type,
            'parentId'   => $parentId,
        ));

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $filename)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
