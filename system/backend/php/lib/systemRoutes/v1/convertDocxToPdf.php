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
            array('status' => 400, 'data' => array('error' => 'No file uploaded')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'document.docx';

    if (!preg_match('/\.docx$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .docx, got: ' . $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath = $file['tmp_name'];

    try {
        // Load DOCX and extract text content
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension is not available');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new \RuntimeException('Unable to open DOCX as ZIP archive');
        }
        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xmlContent === false) {
            throw new \RuntimeException('Unable to read word/document.xml from DOCX');
        }

        // Convert DOCX XML to HTML using the existing utility
        include_once dirname(__FILE__) . '/imports/importUtils.php';
        $html = haxcmsImportConvertDocxXmlToHtml($xmlContent);

        // Convert HTML to PDF using Dompdf
        $dompdf = new \Dompdf\Dompdf(array(
            'isRemoteEnabled' => false,
            'defaultFont'     => 'serif',
        ));
        $dompdf->loadHtml($html !== '' ? $html : '<p></p>');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfContent = $dompdf->output();

        $pdfFilename = preg_replace('/\.docx$/i', '.pdf', $filename);

        // Return raw binary PDF with attachment disposition
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . addslashes($pdfFilename) . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private');
        print $pdfContent;
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error converting DOCX to PDF: ' . $e->getMessage())),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
