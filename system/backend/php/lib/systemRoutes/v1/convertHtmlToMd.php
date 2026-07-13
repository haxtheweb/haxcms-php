<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

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

    // type=link: fetch the URL first
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
        $converter = new \League\HTMLToMarkdown\HtmlConverter(array(
            'strip_tags'      => false,
            'header_style'    => 'atx',
            'hard_break'      => false,
            'bold_style'      => '**',
            'italic_style'    => '_',
            'list_item_style' => '-',
        ));
        $markdown = $converter->convert($html);
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $markdown)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'HTML to Markdown conversion failed: ' . $e->getMessage(), 'contents' => '')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
