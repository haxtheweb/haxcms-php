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
                // w:val is a namespaced attribute; getAttribute('val') looks up a
                // non-namespaced 'val' (which never exists) and always returns ''.
                // Use getAttributeNS so Heading1..Heading6 are detected.
                $styleVal = $pStyle->getAttributeNS($ns, 'val');
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
                // Security (SEC-05): escape XML-decoded text before concatenating
                // into HTML so a crafted DOCX cannot inject markup/XSS.
                $runText .= htmlspecialchars($t->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

/**
 * Validate that a string is an http/https URL.
 */
function haxcmsSystemValidUrl($str)
{
    $s = trim((string) $str);
    if ($s === '' || !preg_match('#^https?://#i', $s)) {
        return false;
    }
    return filter_var($s, FILTER_VALIDATE_URL) !== false;
}

/**
 * Map a stand-alone media URL to the HAX web component that handles it.
 * Returns the component HTML string, or null when the URL is not a
 * recognized single-line media reference (youtube/vimeo/twitch + .mp4 ->
 * video-player, .mp3/.wav/... -> audio-player, .jpg/.png/... -> img,
 * .gif -> a11y-gif-player, .pdf -> pdf-browser-viewer).
 */
function haxcmsSystemUrlMediaComponent($url)
{
    if (!haxcmsSystemValidUrl($url)) {
        return null;
    }
    $lower = strtolower($url);
    $u = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // video
    if (
        strpos($url, 'youtube.com') !== false ||
        strpos($url, 'youtu.be') !== false ||
        strpos($url, 'youtube-nocookie.com') !== false ||
        strpos($url, 'vimeo.com') !== false ||
        strpos($url, 'twitch.tv') !== false ||
        strpos($lower, '.mp4') !== false
    ) {
        return '<video-player source="' . $u . '"></video-player>';
    }
    // audio
    if (
        strpos($lower, '.mp3') !== false ||
        strpos($lower, '.midi') !== false ||
        strpos($lower, '.mid') !== false ||
        strpos($lower, '.m4a') !== false ||
        strpos($lower, '.wav') !== false ||
        strpos($lower, '.ogg') !== false ||
        strpos($lower, '.flac') !== false ||
        strpos($lower, '.aac') !== false
    ) {
        return '<audio-player source="' . $u . '"></audio-player>';
    }
    // image
    if (
        strpos($lower, '.jpg') !== false ||
        strpos($lower, '.jpeg') !== false ||
        strpos($lower, '.png') !== false ||
        strpos($lower, '.webp') !== false
    ) {
        return '<img src="' . $u . '" loading="lazy" decoding="async" fetchpriority="high" alt="" />';
    }
    // gif
    if (strpos($lower, '.gif') !== false) {
        return '<a11y-gif-player src="' . $u . '" style="width: 300px;"><simple-img width="300" src="' . $u . '"></simple-img></a11y-gif-player>';
    }
    // pdf
    if (strpos($lower, '.pdf') !== false) {
        return '<pdf-browser-viewer file="' . $u . '" width="100%"></pdf-browser-viewer>';
    }
    return null;
}

/**
 * Convert a single parsed element into HTML, applying token processing
 * for stand-alone media URLs and [token] placeholders so a docx line
 * such as `[https://youtube.com/watch?v=...]` becomes a <video-player>.
 * Mirrors the open-api / NodeJS htmlFromEl convention.
 */
function haxcmsSystemHtmlFromEl($textValue, $fallbackHtml)
{
    $textValue = trim((string) $textValue);
    // stand-alone media URL on its own line
    $mediaComponent = haxcmsSystemUrlMediaComponent($textValue);
    if ($mediaComponent !== null) {
        return $mediaComponent;
    }
    // [token] wrapper convention
    if ($textValue !== '' && substr($textValue, 0, 1) === '[' && substr($textValue, -1) === ']') {
        $tmp = explode(':', $textValue);
        if (count($tmp) > 1) {
            $type = str_replace('[', '', array_shift($tmp));
            $text = trim(str_replace(']', '', implode(':', $tmp)));
            switch ($type) {
                case 'math':
                case 'mathjax':
                    return '<lrn-math>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</lrn-math>';
                case 'video':
                case 'audio':
                case 'document':
                case 'text':
                case 'image':
                    return '<place-holder type="' . $type . '" text="' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></place-holder>';
            }
        }
        // strip the brackets and re-test as a media URL
        $inner = trim(str_replace('[', '', str_replace(']', '', $textValue)));
        $innerComponent = haxcmsSystemUrlMediaComponent($inner);
        if ($innerComponent !== null) {
            return $innerComponent;
        }
        return '<place-holder type="text" text="' . htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></place-holder>';
    }
    // !tag-name developer shortcut for inserting a specific element
    if ($textValue !== '' && substr($textValue, 0, 1) === '!' && strpos($textValue, '-') !== false) {
        $tag = trim(str_replace('!', '', $textValue));
        // only allow custom-element style tag names (letters, digits, hyphen)
        if (preg_match('/^[a-zA-Z0-9-]+$/', $tag)) {
            return '<' . $tag . '></' . $tag . '>';
        }
    }
    // default: keep the element HTML, strip tabs, inline [math:...] -> <lrn-math>
    $content = str_replace("\t", '', (string) $fallbackHtml);
    $content = preg_replace('/\[math:(.*?)\]/', '<lrn-math>$1</lrn-math>', trim($content));
    return $content;
}

/**
 * Process the raw HTML from the docx converter, walking the wrapper
 * children and applying token processing to each element so single-line
 * media URLs / [token] placeholders become the right web component.
 */
function haxcmsSystemProcessDocxHtml($html)
{
    if ((string) $html === '') {
        return '';
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    @$dom->loadHTML('<?xml encoding="UTF-8"?><div id="docx-import-wrapper">' . $html . '</div>');
    $wrapper = $dom->getElementById('docx-import-wrapper');
    if (!$wrapper) {
        return $html;
    }
    $content = '';
    foreach ($wrapper->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $content .= haxcmsSystemHtmlFromEl(trim($child->textContent), $dom->saveHTML($child));
        }
    }
    return $content !== '' ? $content : $html;
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

    $file = $_FILES[$fileKey];
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
    $html = '';
    $error = '';

    // Validate ZIP magic number (DOCX files are ZIP archives starting with PK\x03\x04)
    $firstBytes = @file_get_contents($tmpPath, false, null, 0, 4);
    if (
        $firstBytes === false || strlen($firstBytes) < 4 ||
        ord($firstBytes[0]) !== 0x50 || ord($firstBytes[1]) !== 0x4B ||
        ord($firstBytes[2]) !== 0x03 || ord($firstBytes[3]) !== 0x04
    ) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid .docx file (missing ZIP signature). If this is a .doc file, convert it to .docx first.')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $xmlIndex = $zip->locateName('word/document.xml');
            if ($xmlIndex !== false) {
                $xmlString = $zip->getFromIndex($xmlIndex);
                if ($xmlString !== false) {
                    $html = haxcmsSystemProcessDocxHtml(haxcmsSystemConvertDocxXmlToHtml($xmlString));
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
            array('status' => 400, 'data' => array('error' => $error)),
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
