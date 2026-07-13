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
            array('status' => 400, 'data' => array('error' => 'No file uploaded', 'contents' => null, 'filename' => null)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.html';

    if (!preg_match('/\.(html|htm)$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .html or .htm, got: ' . $filename, 'contents' => null, 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath = $file['tmp_name'];
    $html    = @file_get_contents($tmpPath);
    if ($html === false || trim($html) === '') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Uploaded file is empty or unreadable', 'contents' => null, 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    try {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();

        // Strip <html>/<head>/<body> wrappers and convert basic HTML elements
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            // Treat as fragment
            $body = $dom->documentElement;
        }

        $headingStyles = array(
            'H1' => array('bold' => true, 'size' => 28, 'spaceAfter' => 200),
            'H2' => array('bold' => true, 'size' => 24, 'spaceAfter' => 200),
            'H3' => array('bold' => true, 'size' => 20, 'spaceAfter' => 160),
            'H4' => array('bold' => true, 'size' => 18, 'spaceAfter' => 120),
            'H5' => array('bold' => true, 'size' => 16, 'spaceAfter' => 80),
            'H6' => array('bold' => true, 'size' => 14, 'spaceAfter' => 80),
        );

        $addTextRun = function ($section, $tagName, $textContent, $headingStyles) {
            $tagUpper = strtoupper($tagName);
            if (isset($headingStyles[$tagUpper])) {
                $textRun = $section->addTextRun();
                $textRun->addText($textContent, array(
                    'bold' => true,
                    'size' => $headingStyles[$tagUpper]['size'],
                ));
            } else {
                $section->addText($textContent !== '' ? $textContent : ' ');
            }
        };

        if ($body) {
            foreach ($body->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) { continue; }
                $tagName    = strtoupper($child->tagName);
                $textContent = trim($child->textContent);
                if ($textContent === '') { continue; }
                $addTextRun($section, $tagName, $textContent, $headingStyles);
            }
        }

        // Write to temp file and read as base64
        $tmpDocx = tempnam(sys_get_temp_dir(), 'haxcms_docx_') . '.docx';
        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tmpDocx);

        $docxContent = @file_get_contents($tmpDocx);
        @unlink($tmpDocx);

        if ($docxContent === false) {
            throw new \RuntimeException('Failed to read generated DOCX');
        }

        $docxFilename = preg_replace('/\.(html|htm)$/i', '.docx', $filename);
        $base64       = base64_encode($docxContent);

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $base64, 'filename' => $docxFilename)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error converting HTML to DOCX: ' . $e->getMessage(), 'contents' => null, 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
