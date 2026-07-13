<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';

return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';

    $body = $context->body;
    if (!is_array($body)) {
        $body = array();
    }

    if (empty($body['html']) && isset($_GET['html']) && $_GET['html'] !== '') {
        $body['html'] = $_GET['html'];
    }

    if (!isset($body['html']) || $body['html'] === '' || $body['html'] === null) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'missing `html` param', 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $html = (string) $body['html'];

    // type=link: fetch from URL first
    if (isset($body['type']) && $body['type'] === 'link' && $html !== '') {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->request('GET', trim($html));
            $html     = (string) $response->getBody();
        } catch (\Exception $e) {
            $html = '';
        }
    }

    try {
        // Use DOMDocument to pretty-print HTML
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = true;
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $prettyHtml = $dom->saveHTML();
        // Clean up the XML encoding declaration added by loadHTML
        $prettyHtml = str_replace('<?xml encoding="UTF-8">', '', (string) $prettyHtml);
        $prettyHtml = trim($prettyHtml);

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $prettyHtml)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'HTML pretty-print failed: ' . $e->getMessage(), 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
