<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';

/**
 * Convert DOCX word/document.xml into a simplified HTML string using DOMDocument.
 */
function haxcmsSystemConvertDocxXmlToHtml($xmlString)
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->loadXML($xmlString);
    $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $html = '';
    $paragraphs = $doc->getElementsByTagNameNS($ns, 'p');
    foreach ($paragraphs as $p) {
        $tag = 'p';
        $pPr = $p->getElementsByTagNameNS($ns, 'pPr')->item(0);
        if ($pPr) {
            $pStyle = $pPr->getElementsByTagNameNS($ns, 'pStyle')->item(0);
            if ($pStyle) {
                $styleVal = $pStyle->getAttribute('val');
                if ($styleVal === 'Heading1') {
                    $tag = 'h1';
                } elseif ($styleVal === 'Heading2') {
                    $tag = 'h2';
                } elseif ($styleVal === 'Heading3') {
                    $tag = 'h3';
                } elseif ($styleVal === 'Heading4') {
                    $tag = 'h4';
                } elseif ($styleVal === 'Heading5') {
                    $tag = 'h5';
                } elseif ($styleVal === 'Heading6') {
                    $tag = 'h6';
                }
            }
        }
        $textContent = '';
        $runs = $p->getElementsByTagNameNS($ns, 'r');
        foreach ($runs as $r) {
            $rPr = $r->getElementsByTagNameNS($ns, 'rPr')->item(0);
            $bold = false;
            $italic = false;
            $underline = false;
            $strike = false;
            if ($rPr) {
                $bold = $rPr->getElementsByTagNameNS($ns, 'b')->length > 0;
                $italic = $rPr->getElementsByTagNameNS($ns, 'i')->length > 0;
                $underline = $rPr->getElementsByTagNameNS($ns, 'u')->length > 0;
                $strike = $rPr->getElementsByTagNameNS($ns, 'strike')->length > 0;
            }
            $tEls = $r->getElementsByTagNameNS($ns, 't');
            $runText = '';
            foreach ($tEls as $t) {
                $runText .= $t->nodeValue;
            }
            if ($runText !== '') {
                if ($bold) {
                    $runText = '<strong>' . $runText . '</strong>';
                }
                if ($italic) {
                    $runText = '<em>' . $runText . '</em>';
                }
                if ($underline) {
                    $runText = '<em>' . $runText . '</em>';
                }
                if ($strike) {
                    $runText = '<del>' . $runText . '</del>';
                }
                $textContent .= $runText;
            }
        }
        if ($textContent !== '') {
            $html .= '<' . $tag . '>' . $textContent . '</' . $tag . '>' . "\n";
        } elseif ($tag === 'p') {
            $html .= '<p></p>' . "\n";
        }
    }
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
            array('status' => 400, 'message' => 'No file uploaded'),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $file = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'document.docx';
    if (!preg_match('/\.(docx|doc)$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'message' => 'Invalid file type. Expected .docx or .doc, got: ' . $filename),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath = $file['tmp_name'];
    $html = '';
    $error = '';

    // Detect if the file is actually HTML disguised as .docx
    $firstBytes = @file_get_contents($tmpPath, false, null, 0, 512);
    $looksLikeHtml = false;
    if ($firstBytes !== false) {
        $trimmed = ltrim(strtolower($firstBytes));
        $looksLikeHtml = (
            strpos($trimmed, '<!doctype') === 0 ||
            strpos($trimmed, '<html') === 0 ||
            strpos($trimmed, '<head') === 0 ||
            strpos($trimmed, '<body') === 0
        );
    }

    if ($looksLikeHtml) {
        $raw = @file_get_contents($tmpPath);
        if ($raw === false) {
            $error = 'Unable to read uploaded file';
        } else {
            $html = trim($raw);
        }
    } elseif (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $xmlIndex = $zip->locateName('word/document.xml');
            if ($xmlIndex !== false) {
                $xmlString = $zip->getFromIndex($xmlIndex);
                if ($xmlString !== false) {
                    $html = haxcmsSystemConvertDocxXmlToHtml($xmlString);
                } else {
                    $error = 'Unable to read word/document.xml from uploaded DOCX';
                }
            } else {
                $error = 'Uploaded file does not contain a valid Word document structure';
            }
            $zip->close();
        } else {
            $error = 'Unable to open uploaded file as a ZIP archive (invalid DOCX)';
        }
    } else {
        $error = 'ZipArchive extension is not available on this server';
    }

    if ($error !== '') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'message' => $error),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => array('contents' => $html, 'filename' => $filename)),
        array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
        $context->routeSuffix,
        $apiBasePath
    );
};
