<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

/**
 * Convert a PDF into a simplified semantic HTML string using a font-size
 * heuristic — a faithful port of the Node.js pdfToSemanticHtml.js converter.
 *
 * PDF has no "heading" semantic; the base spec stores positioned glyphs with
 * font and size. Node infers headings by weighting font sizes across all text
 * runs (most-used size = body; sizes > body*1.15 = h1/h2/h3 candidates) and
 * promoting a line only when it also looks like a heading. This replicates
 * that algorithm with Smalot\PdfParser's getDataTm() (text-matrix + font id
 * + font size when Config::setDataTmFontInfoHasToBeIncluded is true), so the
 * shared hierarchy builder receives real <h1>/<h2>/<h3> tags the same way the
 * Node import does.
 *
 * Constants mirror pdfToSemanticHtml.js exactly so behavior stays in parity.
 */
if (!function_exists('haxcmsSystemConvertPdfToSemanticHtml')) {
    function haxcmsSystemConvertPdfToSemanticHtml($tmpPath)
    {
        $DEFAULT_FONT_SIZE = 12.0;
        $HEADING_TOLERANCE = 0.75;
        $Y_ALIGNMENT_TOLERANCE = 2.0;
        $MAX_HEADING_WORDS = 16;
        $MAX_HEADING_LENGTH = 140;
        // Below this size (either dimension) an extracted image is almost
        // always a decorative artifact or gradient fill. Mirrors Node's
        // MIN_IMAGE_DIMENSION in pdfToSemanticHtml.js.
        $MIN_IMAGE_DIMENSION = 16.0;

        // Enable font id + size in getDataTm() rows (shape: [Tm, text, fontId, fontSize]).
        $config = new \Smalot\PdfParser\Config();
        $config->setDataTmFontInfoHasToBeIncluded(true);
        $parser = new \Smalot\PdfParser\Parser(array(), $config);
        $pdf    = $parser->parseFile($tmpPath);
        $pages  = $pdf->getPages();

        // Stage 1: per page, normalize text runs into visual lines (grouped by y).
        $pageLines = array();
        foreach ($pages as $page) {
            $tmData = $page->getDataTm();
            $runs   = array();
            foreach ($tmData as $row) {
                if (!is_array($row) || count($row) < 4) {
                    continue;
                }
                $matrix   = $row[0];
                $text     = (string) $row[1];
                $fontSize = (float) $row[3];
                if (!is_array($matrix) || count($matrix) < 6) {
                    continue;
                }
                $x       = (float) $matrix[4];
                $y       = (float) $matrix[5];
                $vScale  = (float) $matrix[3];
                // Effective rendered size = declared font size × vertical scale
                // (the text matrix already folds in the CTM). Mirrors Node's
                // getItemFontSize fallback to transform[3].
                $effSize = $fontSize * abs($vScale);
                $clean   = trim(preg_replace('/\s+/u', ' ', $text));
                if ($clean === '') {
                    continue;
                }
                $runs[] = array(
                    'text'    => $clean,
                    'x'       => $x,
                    'y'       => $y,
                    'effSize' => $effSize,
                );
            }

            // Group runs into rows by y (within tolerance), then concat each row
            // left-to-right by x, tracking the max effective size on the line.
            $rows = array();
            foreach ($runs as $r) {
                $matched = false;
                foreach ($rows as &$row) {
                    if (abs($row['y'] - $r['y']) <= $Y_ALIGNMENT_TOLERANCE) {
                        $row['items'][] = $r;
                        $matched = true;
                        break;
                    }
                }
                unset($row);
                if (!$matched) {
                    $rows[] = array('y' => $r['y'], 'items' => array($r));
                }
            }
            foreach ($rows as &$row) {
                usort($row['items'], function ($a, $b) {
                    return $a['x'] <=> $b['x'];
                });
                $lineText = '';
                $maxEff   = 0.0;
                $prev     = null;
                foreach ($row['items'] as $it) {
                    if ($prev) {
                        $gap = $it['x'] - ($prev['x'] + $it['effSize'] * 0.5);
                        if ($gap > $it['effSize'] * 0.2 && !preg_match('/ $/', $lineText)) {
                            $lineText .= ' ';
                        }
                    }
                    $lineText .= $it['text'];
                    if ($it['effSize'] > $maxEff) {
                        $maxEff = $it['effSize'];
                    }
                    $prev = $it;
                }
                $row['text'] = trim(preg_replace('/\s+/u', ' ', $lineText));
                $row['effSize'] = $maxEff;
            }
            unset($row);

            // PDF origin is bottom-left; higher y = higher on page, so sort
            // descending to read top-to-bottom.
            usort($rows, function ($a, $b) {
                return $b['y'] <=> $a['y'];
            });
            foreach ($rows as $row) {
                if ($row['text'] !== '') {
                    $pageLines[] = array('text' => $row['text'], 'effSize' => $row['effSize']);
                }
            }

            // Images are appended after this page's text content (same
            // per-page placement convention as Node's pdfToSemanticHtml.js),
            // not interleaved with specific lines.
            $pageImages = haxcmsSystemExtractPdfPageImages($page, $MIN_IMAGE_DIMENSION);
            if (count($pageImages) > 0) {
                $pageLines[] = array('text' => '', 'effSize' => 0, 'images' => $pageImages);
            }
        }

        if (count($pageLines) === 0) {
            return '<p></p>';
        }

        // Stage 2: font stats → body size → heading candidates (Node parity).
        $stats = array();
        foreach ($pageLines as $line) {
            if (isset($line['images'])) {
                continue;
            }
            $size = haxcmsSystemNormalizePdfFontSize($line['effSize']);
            $stats[$size] = (isset($stats[$size]) ? $stats[$size] : 0) + max(1, strlen($line['text']));
        }
        $bodySize   = $DEFAULT_FONT_SIZE;
        $maxWeight  = 0;
        foreach ($stats as $size => $weight) {
            if ($weight > $maxWeight) {
                $maxWeight = $weight;
                $bodySize  = (float) $size;
            }
        }
        $candidates = array();
        foreach (array_keys($stats) as $size) {
            if ((float) $size > $bodySize * 1.15) {
                $candidates[] = (float) $size;
            }
        }
        rsort($candidates);
        $headingSizes = array();
        if (isset($candidates[0])) {
            $headingSizes['h1'] = $candidates[0];
        }
        if (isset($candidates[1])) {
            $headingSizes['h2'] = $candidates[1];
        }
        if (isset($candidates[2])) {
            $headingSizes['h3'] = $candidates[2];
        }

        // Stage 3: emit semantic HTML. Paragraphs merge across consecutive lines
        // that don't end a sentence (Node's shouldMergeParagraph). Headings only
        // emit when the size matches AND looksLikeHeading passes.
        $htmlParts = array();
        $paragraphBuffer = '';
        foreach ($pageLines as $line) {
            if (isset($line['images'])) {
                if ($paragraphBuffer !== '') {
                    $htmlParts[] = '<p>' . haxcmsSystemEscapePdfHtml($paragraphBuffer) . '</p>';
                    $paragraphBuffer = '';
                }
                foreach ($line['images'] as $imgTag) {
                    $htmlParts[] = $imgTag;
                }
                continue;
            }
            $info = haxcmsSystemGetPdfLineInfo($line, $headingSizes, $HEADING_TOLERANCE, $MAX_HEADING_WORDS, $MAX_HEADING_LENGTH);
            if ($info['type'] === 'paragraph') {
                if ($paragraphBuffer === '') {
                    $paragraphBuffer = $info['content'];
                } elseif (haxcmsSystemShouldMergePdfParagraph($paragraphBuffer, $info['content'])) {
                    $paragraphBuffer .= ' ' . $info['content'];
                } else {
                    $htmlParts[] = '<p>' . haxcmsSystemEscapePdfHtml($paragraphBuffer) . '</p>';
                    $paragraphBuffer = $info['content'];
                }
            } else {
                if ($paragraphBuffer !== '') {
                    $htmlParts[] = '<p>' . haxcmsSystemEscapePdfHtml($paragraphBuffer) . '</p>';
                    $paragraphBuffer = '';
                }
                $htmlParts[] = '<' . $info['type'] . '>' . haxcmsSystemEscapePdfHtml($info['content']) . '</' . $info['type'] . '>';
            }
        }
        if ($paragraphBuffer !== '') {
            $htmlParts[] = '<p>' . haxcmsSystemEscapePdfHtml($paragraphBuffer) . '</p>';
        }
        $html = implode("\n", $htmlParts);
        if ($html === '') {
            $html = '<p></p>';
        }
        return $html;
    }
}

if (!function_exists('haxcmsSystemExtractPdfPageImages')) {
    /**
     * Extract DCTDecode (JPEG) image XObjects from a page as <img> tags.
     *
     * smalot/pdfparser's RawDataParser only decodes a filter whitelist
     * (ASCIIHexDecode, ASCII85Decode, LZWDecode, FlateDecode, RunLengthDecode)
     * — any other filter name, including DCTDecode, is left untouched in
     * getContent(). Since DCTDecode streams ARE complete, valid JPEG file
     * bytes per the PDF spec, that raw content can be used directly as a
     * data URI. Any other filter (FlateDecode raw samples, JPXDecode,
     * CCITTFaxDecode, JBIG2Decode, no filter) is skipped silently — v1 does
     * not attempt bitmap reconstruction from raw samples. Never throws: a
     * corrupt/unusual image object must not fail the whole import.
     */
    function haxcmsSystemExtractPdfPageImages($page, $minDimension)
    {
        $images = array();
        try {
            $xobjects = $page->getXObjects();
        } catch (\Exception $e) {
            return $images;
        }
        // Page::getXObjects() stores each XObject under both its original
        // resource name (e.g. "Im1") and a numeric-cleaned alias (e.g. "1"),
        // so the same object instance can appear twice in the table. Dedupe
        // by object identity to avoid emitting duplicate <img> tags.
        $seen = array();
        foreach ($xobjects as $xobject) {
            try {
                if (!($xobject instanceof \Smalot\PdfParser\XObject\Image)) {
                    continue;
                }
                $objectId = spl_object_id($xobject);
                if (isset($seen[$objectId])) {
                    continue;
                }
                $seen[$objectId] = true;
                $filter = haxcmsSystemGetPdfImageFilter($xobject);
                if ($filter !== 'DCTDecode') {
                    continue;
                }
                $width  = (float) $xobject->get('Width')->getContent();
                $height = (float) $xobject->get('Height')->getContent();
                if (($width > 0 && $width < $minDimension) || ($height > 0 && $height < $minDimension)) {
                    continue;
                }
                $content = $xobject->getContent();
                if (!is_string($content) || $content === '') {
                    continue;
                }
                $images[] = '<img src="data:image/jpeg;base64,' . base64_encode($content) . '">';
            } catch (\Exception $e) {
                continue;
            }
        }
        return $images;
    }
}

if (!function_exists('haxcmsSystemGetPdfImageFilter')) {
    /**
     * Resolve an XObject's /Filter entry, which per PDF spec may be a single
     * name (ElementName) or an array of names applied in sequence
     * (ElementArray) — the image-coding filter is the last one applied.
     */
    function haxcmsSystemGetPdfImageFilter($xobject)
    {
        $filterElement = $xobject->get('Filter');
        if ($filterElement instanceof \Smalot\PdfParser\Element\ElementArray) {
            $filters = array();
            foreach ($filterElement->getContent() as $el) {
                $filters[] = (is_object($el) && method_exists($el, 'getContent')) ? (string) $el->getContent() : (string) $el;
            }
            return count($filters) > 0 ? end($filters) : null;
        }
        if (method_exists($filterElement, 'getContent')) {
            $content = $filterElement->getContent();
            if (is_string($content)) {
                return $content;
            }
        }
        return null;
    }
}

if (!function_exists('haxcmsSystemNormalizePdfFontSize')) {
    function haxcmsSystemNormalizePdfFontSize($size)
    {
        // Return a string with one decimal place so it can be used as an array
        // key (in the font-stats table) without triggering PHP 8.1+ "Implicit
        // conversion from float to int loses precision" — a float array key is
        // truncated to int, which would collapse 13.5 and 13.0 into the same
        // key and corrupt the heading-size stats. String keys preserve
        // precision; PHP coerces them back to float for the arithmetic
        // comparisons in getHeadingLevel/body-size selection. Mirrors Node's
        // Math.round(x*10)/10 which is naturally string-keyed in JS objects.
        return number_format((float) $size, 1, '.', '');
    }
}

if (!function_exists('haxcmsSystemGetPdfLineInfo')) {
    function haxcmsSystemGetPdfLineInfo($line, $headingSizes, $tolerance, $maxWords, $maxLength)
    {
        $rawText   = trim($line['text']);
        // List detection (mirrors Node getListMatch).
        $listMatch = haxcmsSystemGetPdfListMatch($rawText);
        if ($listMatch) {
            return array('type' => 'list', 'listType' => $listMatch['listType'], 'content' => $listMatch['content']);
        }
        $level = haxcmsSystemGetPdfHeadingLevel($line['effSize'], $headingSizes, $tolerance);
        if ($level !== null && haxcmsSystemPdfLooksLikeHeading($rawText, $maxWords, $maxLength)) {
            return array('type' => $level, 'content' => $rawText);
        }
        return array('type' => 'paragraph', 'content' => $rawText);
    }
}

if (!function_exists('haxcmsSystemGetPdfHeadingLevel')) {
    function haxcmsSystemGetPdfHeadingLevel($effSize, $headingSizes, $tolerance)
    {
        $normalized = haxcmsSystemNormalizePdfFontSize($effSize);
        foreach (array('h1', 'h2', 'h3') as $level) {
            if (isset($headingSizes[$level]) && abs($normalized - $headingSizes[$level]) <= $tolerance) {
                return $level;
            }
        }
        return null;
    }
}

if (!function_exists('haxcmsSystemPdfLooksLikeHeading')) {
    function haxcmsSystemPdfLooksLikeHeading($text, $maxWords, $maxLength)
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) === 0) {
            return false;
        }
        if (count($words) > $maxWords) {
            return false;
        }
        if (mb_strlen($text) > $maxLength) {
            return false;
        }
        if (preg_match('/[.!?]$/u', $text)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('haxcmsSystemGetPdfListMatch')) {
    function haxcmsSystemGetPdfListMatch($text)
    {
        // Unordered: leading bullet/dash markers.
        if (preg_match('/^[-\x{2022}\x{25CF}\x{25AA}\x{25E6}]\s+/u', $text)) {
            return array(
                'listType' => 'ul',
                'content'  => preg_replace('/^[-\x{2022}\x{25CF}\x{25AA}\x{25E6}]\s+/u', '', $text),
            );
        }
        // Ordered: leading "N." or "N)" (optionally parenthesized).
        if (preg_match('/^\(?\d+[\.)]\s+/u', $text)) {
            return array(
                'listType' => 'ol',
                'content'  => preg_replace('/^\(?\d+[\.)]\s+/u', '', $text),
            );
        }
        return null;
    }
}

if (!function_exists('haxcmsSystemShouldMergePdfParagraph')) {
    function haxcmsSystemShouldMergePdfParagraph($currentText, $nextText)
    {
        if (!preg_match('/[.!?:;]$/u', $currentText)) {
            return true;
        }
        if (preg_match('/^[a-z]/u', $nextText)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('haxcmsSystemEscapePdfHtml')) {
    function haxcmsSystemEscapePdfHtml($value)
    {
        // Smalot splits words on character-positioning gaps, producing artifacts
        // like "Action Item s" or "OptionalStudy Resources". The most visible
        // and safest to fix is a space immediately before a single trailing
        // letter at the end of a token ("Item s" -> "Items"). Collapse that
        // pattern repeatedly so mid-word splits heal too ("w illlearn" is left
        // alone — that is a missing space, not an extra one, and we can't safely
        // guess word boundaries there). Legitimate single-letter words ("a",
        // "I") are not at risk because the pattern requires a preceding token.
        $cleaned = preg_replace('/(\p{L}) (\p{L})(?=\s|$)/u', '$1$2', $value);
        return htmlspecialchars($cleaned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.pdf';

    if (!preg_match('/\.pdf$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .pdf, got: ' . $filename, 'items' => array(), 'filename' => $filename)),
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
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid PDF', 'items' => array(), 'filename' => $filename)),
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
        $html = haxcmsSystemConvertPdfToSemanticHtml($tmpPath);

        // The PDF converter produces trusted, programmatically-generated markup
        // (not arbitrary untrusted HTML), including inline `<img src="data:...">`
        // tags for extracted page images. Skip the centralized sanitize-on-import
        // step here so those tags survive in this route's JSON response; the
        // content is still sanitized later, when a client round-trips an item
        // through createNode/addPage (which run MaterializeInlineImages::materialize()
        // followed by SanitizeContent::sanitizeHTMLForStorage()).
        $items = haxcmsImportHtmlToItems($html, array(
            'titleValue'   => preg_replace('/\.pdf$/i', '', $filename),
            'method'       => $method,
            'type'         => $type,
            'parentId'     => $parentId,
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
            array('status' => 400, 'data' => array('error' => 'Error processing PDF import: ' . $e->getMessage(), 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
