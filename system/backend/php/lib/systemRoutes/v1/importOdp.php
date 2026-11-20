<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';
include_once dirname(__FILE__) . '/../../OdtToHtml.php';

/**
 * POST /system/api/v1/actions/import-odp
 * Convert an uploaded OpenDocument Presentation (.odp) file into a HAXcms site
 * schema (items array). Mirrors haxcms-nodejs importOdp.js.
 *
 * Expects multipart/form-data with a file field (upload, file or file-upload).
 * Also accepts form fields: method (site|branch|page), type (course|portfolio|''), parentId.
 * Returns { status: 200, data: { items: [...], filename: string } }.
 */
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $send = function ($code, $data) use ($context, $apiBasePath) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => $code, 'data' => $data),
            array('statusCode' => $code, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    };

    $fileKey = null;
    foreach (array('upload', 'file', 'file-upload') as $key) {
        if (isset($_FILES[$key]) && is_array($_FILES[$key]) && isset($_FILES[$key]['tmp_name']) && $_FILES[$key]['tmp_name'] !== '') {
            $fileKey = $key;
            break;
        }
    }
    if ($fileKey === null) {
        $send(400, array('error' => 'No file uploaded', 'items' => array(), 'filename' => null));
        return;
    }
    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.odp';
    if (!preg_match('/\.odp$/i', $filename)) {
        $send(400, array('error' => 'Invalid file type. Expected .odp, got: ' . $filename, 'items' => array(), 'filename' => $filename));
        return;
    }
    $tmpPath = $file['tmp_name'];
    $size    = @filesize($tmpPath);
    if ($size === false) {
        $send(400, array('error' => 'Unable to read uploaded file: the file could not be opened', 'items' => array(), 'filename' => $filename));
        return;
    }
    if ($size === 0) {
        $send(400, array('error' => 'Uploaded file is empty', 'items' => array(), 'filename' => $filename));
        return;
    }

    $body     = $context->body;
    $method   = (is_array($body) && isset($body['method'])) ? (string) $body['method'] : 'site';
    $type     = (is_array($body) && isset($body['type']))   ? (string) $body['type']   : '';
    $parentId = (is_array($body) && isset($body['parentId']) && $body['parentId'] !== 'null' && $body['parentId'] !== '')
        ? (string) $body['parentId']
        : null;

    try {
        $html = OdtToHtml::convertOdpFile($tmpPath);
        // The converter writes only its own escaped tags (like the PDF
        // importer), so skip the sanitize-on-import step and let its inline
        // data URI images reach createNode/addPage, which run
        // MaterializeInlineImages::materialize() then
        // SanitizeContent::sanitizeHTMLForStorage() before storing.
        $items = haxcmsImportHtmlToItems($html, array(
            'titleValue'   => preg_replace('/\.odp$/i', '', $filename),
            'method'       => $method,
            'type'         => $type,
            'parentId'     => $parentId,
            'skipSanitize' => true,
        ));
        $send(200, array('items' => $items, 'filename' => $filename));
    } catch (\Throwable $e) {
        $send(400, array('error' => 'Error processing ODP import: ' . $e->getMessage(), 'items' => array(), 'filename' => $filename));
    }
};
