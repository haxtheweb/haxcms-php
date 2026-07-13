<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';

/**
 * Extract text content from a PPTX file and produce a structured HTML string.
 * Uses ZipArchive to read ppt/slides/slide*.xml files.
 */
function haxcmsSystemExtractPptxToHtml($tmpPath)
{
    if (!class_exists('ZipArchive')) {
        throw new \RuntimeException('ZipArchive extension is not available');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new \RuntimeException('Unable to open PPTX as ZIP archive');
    }

    $nsP  = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    $nsA  = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $html = '';

    // Collect and sort slide files
    $slideFiles = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $name, $m)) {
            $slideFiles[(int) $m[1]] = $name;
        }
    }
    ksort($slideFiles);

    foreach ($slideFiles as $slideNum => $slideName) {
        $slideXml = $zip->getFromName($slideName);
        if ($slideXml === false) {
            continue;
        }
        $doc = new DOMDocument();
        @$doc->loadXML($slideXml);
        $spTree = $doc->getElementsByTagNameNS($nsP, 'sp');
        foreach ($spTree as $sp) {
            // Determine if this shape is a title placeholder
            $isTitle = false;
            $nvSpPr  = $sp->getElementsByTagNameNS($nsP, 'nvSpPr')->item(0);
            if ($nvSpPr) {
                $nvPr = $nvSpPr->getElementsByTagNameNS($nsP, 'nvPr')->item(0);
                if ($nvPr) {
                    $ph = $nvPr->getElementsByTagNameNS($nsP, 'ph')->item(0);
                    if ($ph) {
                        $phType = $ph->getAttribute('type');
                        if ($phType === 'title' || $phType === 'ctrTitle') {
                            $isTitle = true;
                        }
                    }
                }
            }
            // Extract all text runs
            $tEls    = $sp->getElementsByTagNameNS($nsA, 't');
            $text    = '';
            foreach ($tEls as $t) {
                $text .= $t->nodeValue;
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            if ($isTitle) {
                $html .= '<h2>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>' . "\n";
            } else {
                $html .= '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' . "\n";
            }
        }
    }

    $zip->close();
    return trim($html);
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
