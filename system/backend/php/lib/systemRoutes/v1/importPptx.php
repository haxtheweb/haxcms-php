<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';
include_once dirname(__FILE__) . '/../../PptxToHtml.php';

/**
 * PPTX to HTML for the import and pptx-to-html routes.
 *
 * function_exists-guarded so this file can be re-executed (require) to hand
 * back its route closure even after another file (convertPptxToHtml.php
 * include_once's it) has already loaded it in the same process.
 */
if (!function_exists('haxcmsSystemExtractPptxToHtml')) {
    /**
     * One <h1> per slide then its content, pictures as data URIs that
     * createNode / addPage turn into files (lib/PptxToHtml.php, the twin of
     * haxcms-nodejs src/lib/pptxToHtml.js).
     */
    function haxcmsSystemExtractPptxToHtml($tmpPath)
    {
        return PptxToHtml::convertFile($tmpPath);
    }
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
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.pptx';

    if (!preg_match('/\.pptx$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .pptx, got: ' . $filename, 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath   = $file['tmp_name'];
    $firstBytes = @file_get_contents($tmpPath, false, null, 0, 4);
    if ($firstBytes === false || strlen($firstBytes) < 4 || substr($firstBytes, 0, 2) !== 'PK') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid .pptx file (missing ZIP signature)', 'items' => array(), 'filename' => $filename)),
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
        $html  = haxcmsSystemExtractPptxToHtml($tmpPath);
        $items = haxcmsImportHtmlToItems($html, array(
            'titleValue' => preg_replace('/\.pptx$/i', '', $filename),
            'method'     => $method,
            'type'       => $type,
            'parentId'   => $parentId,
            // the converter writes only its own escaped tags (like the PDF
            // and ODT importers); its data URI images reach createNode/addPage,
            // which materialize them and sanitize before storing
            'skipSanitize' => true,
        ));
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $filename)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error processing PPTX import: ' . $e->getMessage(), 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
