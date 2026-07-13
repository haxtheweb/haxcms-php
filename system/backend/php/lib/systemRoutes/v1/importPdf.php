<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';

    $fileKey = null;
    foreach (array('upload', 'file', 'file-upload') as $key) {
        if (isset($_FILES[$key]) && is_array($_FILES[$key]) && isset($_FILES[$key]['tmp_name']) && $_FILES[$key]['tmp_name'] !== '') {
            $fileKey = $key;
            break;
        }
    }

    if ($fileKey === null) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'No file uploaded', 'items' => array(), 'filename' => null)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.pdf';

    if (!preg_match('/\.pdf$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .pdf, got: ' . $filename, 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath    = $file['tmp_name'];
    $firstBytes = @file_get_contents($tmpPath, false, null, 0, 4);
    if ($firstBytes === false || substr($firstBytes, 0, 4) !== '%PDF') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid PDF', 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $body     = $context->body;
    $method   = (is_array($body) && isset($body['method'])) ? (string) $body['method'] : 'site';
    $type     = (is_array($body) && isset($body['type']))   ? (string) $body['type']   : '';
    $parentId = (is_array($body) && isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
        ? (string) $body['parentId']
        : null;

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($tmpPath);
        $pages  = $pdf->getPages();
        $html   = '';
        foreach ($pages as $page) {
            $text = $page->getText();
            // Split into paragraphs on double-newline
            $paragraphs = preg_split('/\n{2,}/', trim($text));
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if ($para !== '') {
                    $html .= '<p>' . nl2br(htmlspecialchars($para, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>' . "\n";
                }
            }
        }
        if ($html === '') {
            $html = '<p></p>';
        }

        $items = haxcmsImportHtmlToItems($html, array(
            'titleValue' => preg_replace('/\.pdf$/i', '', $filename),
            'method'     => $method,
            'type'       => $type,
            'parentId'   => $parentId,
        ));
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $filename)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error processing PDF import: ' . $e->getMessage(), 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
