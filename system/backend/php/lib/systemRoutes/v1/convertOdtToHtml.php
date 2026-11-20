<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../OdtToHtml.php';

/**
 * POST /system/api/v1/actions/odt-to-html
 * Convert an uploaded OpenDocument Text (.odt) file to HTML.
 * Mirrors haxcms-nodejs convertOdtToHtml.js.
 *
 * Expects multipart/form-data with a file field (upload, file or file-upload).
 * Returns { status: 200, data: { contents: html, filename: string } }.
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
        $send(400, array('error' => 'No file uploaded', 'contents' => '', 'filename' => null));
        return;
    }
    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.odt';
    if (!preg_match('/\.odt$/i', $filename)) {
        $send(400, array('error' => 'Invalid file type. Expected .odt, got: ' . $filename, 'contents' => '', 'filename' => $filename));
        return;
    }
    $tmpPath = $file['tmp_name'];
    $size    = @filesize($tmpPath);
    if ($size === false) {
        $send(400, array('error' => 'Unable to read uploaded file: the file could not be opened', 'contents' => '', 'filename' => $filename));
        return;
    }
    if ($size === 0) {
        $send(400, array('error' => 'Uploaded file is empty', 'contents' => '', 'filename' => $filename));
        return;
    }
    try {
        $send(200, array('contents' => OdtToHtml::convertFile($tmpPath), 'filename' => $filename));
    } catch (\Throwable $e) {
        $send(400, array('error' => 'Error processing ODT document: ' . $e->getMessage(), 'contents' => '', 'filename' => $filename));
    }
};
