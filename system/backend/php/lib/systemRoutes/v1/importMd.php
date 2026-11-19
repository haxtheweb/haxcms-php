<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';
include_once dirname(__FILE__) . '/../../MarkdownImport.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

/**
 * POST /system/api/v1/actions/import-md
 * Convert an uploaded Markdown (.md, .markdown) or plain text (.txt) file
 * into a HAXcms site schema (items array). Plain text is read as Markdown,
 * so `#` headings in a .txt become pages too. Mirrors haxcms-nodejs
 * importMd.js.
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
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.md';
    $pattern  = '/\.(md|markdown|txt)$/i';
    if (!preg_match($pattern, $filename)) {
        $send(400, array('error' => 'Invalid file type. Expected .md, .markdown or .txt, got: ' . $filename, 'items' => array(), 'filename' => $filename));
        return;
    }
    $bytes = @file_get_contents($file['tmp_name']);
    if ($bytes === false) {
        $send(400, array('error' => 'Unable to read uploaded file: the file could not be opened', 'items' => array(), 'filename' => $filename));
        return;
    }
    if ($bytes === '') {
        $send(400, array('error' => 'Uploaded file is empty', 'items' => array(), 'filename' => $filename));
        return;
    }
    // a NUL byte means binary data, not a text document
    if (strpos($bytes, "\0") !== false) {
        $send(400, array('error' => 'Uploaded file is not a valid text file.', 'items' => array(), 'filename' => $filename));
        return;
    }

    $body     = $context->body;
    $method   = (is_array($body) && isset($body['method'])) ? (string) $body['method'] : 'site';
    $type     = (is_array($body) && isset($body['type']))   ? (string) $body['type']   : '';
    $parentId = (is_array($body) && isset($body['parentId']) && $body['parentId'] !== 'null' && $body['parentId'] !== '')
        ? (string) $body['parentId']
        : null;

    try {
        $split = MarkdownImport::splitFrontMatter(MarkdownImport::decodeText($bytes));
        // Markdown may carry raw HTML; haxcmsImportHtmlToItems sanitizes it
        // like any other import
        $items = haxcmsImportHtmlToItems(MarkdownImport::render($split['body']), array(
            'titleValue' => $split['title'] !== '' ? $split['title'] : preg_replace($pattern, '', $filename),
            'method'     => $method,
            'type'       => $type,
            'parentId'   => $parentId,
        ));
        $send(200, array('items' => $items, 'filename' => $filename));
    } catch (\Throwable $e) {
        $send(400, array('error' => 'Error processing Markdown import: ' . $e->getMessage(), 'items' => array(), 'filename' => $filename));
    }
};
