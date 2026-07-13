<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';

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
            array('status' => 400, 'data' => array('error' => 'No file uploaded', 'contents' => '', 'filename' => null)),
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
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .pdf, got: ' . $filename, 'contents' => '', 'filename' => $filename)),
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
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid PDF', 'contents' => '', 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($tmpPath);
        $pages  = $pdf->getPages();
        $html   = '';
        foreach ($pages as $page) {
            $text = $page->getText();
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
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $html, 'filename' => $filename)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error converting PDF to HTML: ' . $e->getMessage(), 'contents' => '', 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
