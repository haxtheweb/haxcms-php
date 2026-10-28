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
        // An image taller than a page is painted on every page it spans (same
        // image object); remember extracted images so each imports once.
        // Mirrors Node's seenImages in pdfToSemanticHtml.js.
        $seenImages = array();

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
            // Images with a known position go in reading order between the
            // lines around them (a line whose baseline is below an image's
            // top edge comes after it); any without one keep the old place
            // after the page text. Node parity: linesToSemanticHtml.
            $pageImages = haxcmsSystemExtractPdfPageImages($page, $MIN_IMAGE_DIMENSION, $seenImages);
            $placedImages = array();
            $trailingImages = array();
            foreach ($pageImages as $image) {
                if ($image['top'] !== null) {
                    $placedImages[] = $image;
                } else {
                    $trailingImages[] = $image;
                }
            }
            usort($placedImages, function ($a, $b) {
                return $b['top'] <=> $a['top'];
            });
            // getDataTm() does not always apply a transform set before the
            // text objects (Chrome/Skia flips the page with a top-level cm),
            // which leaves line y values in a different space than the image
            // tops. Only interleave when every line sits inside the page box;
            // otherwise keep images after the page text.
            $pageTop = haxcmsSystemGetPdfPageTop($page);
            $positionsAgree = ($pageTop !== null);
            if ($positionsAgree) {
                foreach ($rows as $row) {
                    if ($row['y'] < -1 || $row['y'] > $pageTop + 1) {
                        $positionsAgree = false;
                        break;
                    }
                }
            }
            if (!$positionsAgree) {
                $trailingImages = array_merge($placedImages, $trailingImages);
                $placedImages = array();
            }
            foreach ($rows as $row) {
                if ($row['text'] === '') {
                    continue;
                }
                $before = array();
                while (count($placedImages) > 0 && $placedImages[0]['top'] > $row['y']) {
                    $image = array_shift($placedImages);
                    $before[] = $image['tag'];
                }
                if (count($before) > 0) {
                    $pageLines[] = array('text' => '', 'effSize' => 0, 'images' => $before);
                }
                $pageLines[] = array('text' => $row['text'], 'effSize' => $row['effSize']);
            }
            $after = array();
            foreach (array_merge($placedImages, $trailingImages) as $image) {
                $after[] = $image['tag'];
            }
            if (count($after) > 0) {
                $pageLines[] = array('text' => '', 'effSize' => 0, 'images' => $after);
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
     * Extract the DCTDecode (JPEG) images painted on a page.
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
     *
     * Returns a list of array('tag' => '<img ...>', 'top' => float|null), in
     * paint order. 'top' is the image's top edge in PDF user space (larger is
     * higher on the page) when the content stream could be read.
     *
     * $seenImages (by reference) holds hashes of images already extracted on
     * earlier pages: a photo taller than a page is painted on each page it
     * spans and must import once (Node parity: pdfToSemanticHtml.js).
     */
    function haxcmsSystemExtractPdfPageImages($page, $minDimension, &$seenImages = null)
    {
        $images = array();
        $inherited = false;
        $xobjects = haxcmsSystemGetPdfPageXObjects($page, $inherited);
        if (count($xobjects) === 0) {
            return $images;
        }
        // Which images this page actually paints, and where. Resources can
        // be inherited from the page tree (Dompdf puts every image of the
        // document there), so the dictionary alone over-reports.
        $paints = haxcmsSystemGetPdfImagePaints($page);
        $candidates = array();
        if ($paints === null || (count($paints) === 0 && !$inherited)) {
            // content stream unreadable, or no paints found for the page's
            // own resources: fall back to every image in those resources,
            // unpositioned (previous behavior). Inherited resources never
            // fall back: they list images that belong to other pages.
            foreach ($xobjects as $xobject) {
                $candidates[] = array('xobject' => $xobject, 'top' => null);
            }
        } else {
            foreach ($paints as $paint) {
                $name = $paint['name'];
                $xobject = null;
                if (isset($xobjects[$name])) {
                    $xobject = $xobjects[$name];
                } else {
                    $cleaned = preg_replace('/[^0-9\.\-_]/', '', $name);
                    if ($cleaned !== '' && isset($xobjects[$cleaned])) {
                        $xobject = $xobjects[$cleaned];
                    }
                }
                if ($xobject !== null) {
                    $candidates[] = array('xobject' => $xobject, 'top' => $paint['top']);
                }
            }
        }
        if (!is_array($seenImages)) {
            $seenImages = array();
        }
        // Page::getXObjects() stores each XObject under both its original
        // resource name (e.g. "Im1") and a numeric-cleaned alias (e.g. "1"),
        // so the same object instance can appear twice in the table. Dedupe
        // by object identity within the page, and by content across pages.
        $seenObjects = array();
        foreach ($candidates as $candidate) {
            $xobject = $candidate['xobject'];
            try {
                if (!($xobject instanceof \Smalot\PdfParser\XObject\Image)) {
                    continue;
                }
                $objectId = spl_object_id($xobject);
                if (isset($seenObjects[$objectId])) {
                    continue;
                }
                $seenObjects[$objectId] = true;
                $filter = haxcmsSystemGetPdfImageFilter($xobject);
                if ($filter !== 'DCTDecode' && $filter !== 'FlateDecode' && $filter !== null) {
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
                $hash = sha1($content);
                if (isset($seenImages[$hash])) {
                    continue;
                }
                $seenImages[$hash] = true;
                if ($filter === 'DCTDecode') {
                    // DCTDecode streams are complete JPEG files
                    $jpeg = haxcmsSystemDownscalePdfJpeg($content, $width, $height);
                } else {
                    // raw / Flate samples (PNGs embedded by Chrome and Dompdf)
                    $jpeg = haxcmsSystemDecodePdfRasterImage($xobject, $content, (int) $width, (int) $height);
                    if ($jpeg === null) {
                        continue;
                    }
                }
                $images[] = array(
                    'tag' => haxcmsSystemPdfImageTag(
                        $jpeg['data'],
                        $jpeg['width'],
                        $jpeg['height'],
                        isset($jpeg['mime']) ? $jpeg['mime'] : 'image/jpeg'
                    ),
                    'top' => $candidate['top'],
                );
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $images;
    }
}

if (!function_exists('haxcmsSystemGetPdfPageXObjects')) {
    /**
     * XObject table for a page, honoring PDF resource inheritance: when the
     * page has no XObject resources of its own, use the nearest ancestor in
     * the page tree that does (PDF 32000 7.7.3.4). smalot only reads the
     * page's own /Resources, which loses every image in Dompdf output.
     */
    function haxcmsSystemGetPdfPageXObjects($page, &$inherited = null)
    {
        $inherited = false;
        try {
            $own = $page->getXObjects();
            if (is_array($own) && count($own) > 0) {
                return $own;
            }
        } catch (\Throwable $e) {
            // fall through to the page tree
        }
        $node = $page;
        for ($depth = 0; $depth < 32; $depth++) {
            try {
                $parent = $node->get('Parent');
            } catch (\Throwable $e) {
                break;
            }
            if (!is_object($parent) || !method_exists($parent, 'get') || $parent === $node) {
                break;
            }
            try {
                $resources = $parent->get('Resources');
                if (is_object($resources) && method_exists($resources, 'has') && $resources->has('XObject')) {
                    $xobjectElement = $resources->get('XObject');
                    $elements = ($xobjectElement instanceof \Smalot\PdfParser\Header)
                        ? $xobjectElement->getElements()
                        : $xobjectElement->getHeader()->getElements();
                    $table = array();
                    foreach ($elements as $id => $xobject) {
                        $table[$id] = $xobject;
                        $cleaned = preg_replace('/[^0-9\.\-_]/', '', $id);
                        if ($cleaned !== '') {
                            $table[$cleaned] = $xobject;
                        }
                    }
                    $inherited = true;
                    return $table;
                }
            } catch (\Throwable $e) {
                // keep climbing
            }
            $node = $parent;
        }
        return array();
    }
}

if (!function_exists('haxcmsSystemGetPdfPageTop')) {
    /**
     * Upper y of a page's MediaBox (inherited from the page tree when the
     * page has none), or null when it cannot be read.
     */
    function haxcmsSystemGetPdfPageTop($page)
    {
        $node = $page;
        for ($depth = 0; $depth < 32 && is_object($node) && method_exists($node, 'get'); $depth++) {
            try {
                $box = $node->get('MediaBox');
                if (is_object($box) && method_exists($box, 'getContent')) {
                    $values = $box->getContent();
                    if (is_array($values) && count($values) === 4) {
                        $numbers = array();
                        foreach ($values as $value) {
                            $numbers[] = (float) ((is_object($value) && method_exists($value, 'getContent')) ? $value->getContent() : $value);
                        }
                        return max($numbers[1], $numbers[3]);
                    }
                }
                $parent = $node->get('Parent');
            } catch (\Throwable $e) {
                return null;
            }
            if ($parent === $node) {
                break;
            }
            $node = $parent;
        }
        return null;
    }
}

if (!function_exists('haxcmsSystemGetPdfImagePaints')) {
    /**
     * Walk a page's content stream tracking the current transform matrix
     * (q / Q / cm) and return every XObject paint (Do) with the top edge of
     * the unit square it is drawn into, in paint order. Returns null when the
     * content stream cannot be read. Node parity: getPageImageTops.
     */
    function haxcmsSystemGetPdfImagePaints($page)
    {
        try {
            $commands = $page->extractDecodedRawData();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($commands)) {
            return null;
        }
        $ctm = array(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
        $stack = array();
        $paints = array();
        foreach ($commands as $command) {
            if (!is_array($command) || !isset($command['o'])) {
                continue;
            }
            $op = $command['o'];
            $args = isset($command['c']) ? $command['c'] : '';
            if ($op === 'q') {
                $stack[] = $ctm;
            } elseif ($op === 'Q') {
                if (count($stack) > 0) {
                    $ctm = array_pop($stack);
                }
            } elseif ($op === 'cm') {
                $parts = preg_split('/\s+/', trim(is_array($args) ? implode(' ', $args) : (string) $args));
                if (is_array($parts) && count($parts) === 6) {
                    $n = array_map('floatval', $parts);
                    $m = $ctm;
                    // new CTM = n x m (operand matrix applied first)
                    $ctm = array(
                        $n[0] * $m[0] + $n[1] * $m[2],
                        $n[0] * $m[1] + $n[1] * $m[3],
                        $n[2] * $m[0] + $n[3] * $m[2],
                        $n[2] * $m[1] + $n[3] * $m[3],
                        $n[4] * $m[0] + $n[5] * $m[2] + $m[4],
                        $n[4] * $m[1] + $n[5] * $m[3] + $m[5],
                    );
                }
            } elseif ($op === 'Do') {
                $name = ltrim(trim(is_array($args) ? implode(' ', $args) : (string) $args), '/');
                if ($name === '') {
                    continue;
                }
                // the image fills the unit square; its top is the largest y
                // of the transformed corners
                $top = max(
                    $ctm[5],
                    $ctm[3] + $ctm[5],
                    $ctm[1] + $ctm[5],
                    $ctm[1] + $ctm[3] + $ctm[5]
                );
                $paints[] = array('name' => $name, 'top' => $top);
            }
        }
        return $paints;
    }
}

if (!function_exists('haxcmsSystemDownscalePdfJpeg')) {
    /**
     * Downscale a JPEG to at most 1600px on its longest edge (Node parity:
     * MAX_IMAGE_EDGE). PDF exports embed photos at print resolution and the
     * import stores them inline in page HTML. Without GD, or on any failure,
     * the original bytes are returned unchanged.
     *
     * @return array{data: string, width: float, height: float}
     */
    function haxcmsSystemDownscalePdfJpeg($jpeg, $width, $height)
    {
        $maxEdge = 1600;
        $result = array('data' => $jpeg, 'width' => $width, 'height' => $height);
        if ($width <= $maxEdge && $height <= $maxEdge) {
            return $result;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale') || !function_exists('imagejpeg')) {
            return $result;
        }
        $source = @imagecreatefromstring($jpeg);
        if ($source === false) {
            return $result;
        }
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        if ($srcWidth < 1 || $srcHeight < 1) {
            imagedestroy($source);
            return $result;
        }
        $scale = min($maxEdge / $srcWidth, $maxEdge / $srcHeight, 1);
        $newWidth = max(1, (int) round($srcWidth * $scale));
        $newHeight = max(1, (int) round($srcHeight * $scale));
        $scaled = imagescale($source, $newWidth, $newHeight, IMG_BICUBIC);
        imagedestroy($source);
        if ($scaled === false) {
            return $result;
        }
        ob_start();
        $ok = imagejpeg($scaled, null, 90);
        $data = ob_get_clean();
        imagedestroy($scaled);
        if (!$ok || !is_string($data) || $data === '') {
            return $result;
        }
        return array('data' => $data, 'width' => (float) $newWidth, 'height' => (float) $newHeight);
    }
}

if (!function_exists('haxcmsSystemGetPdfColorModel')) {
    /**
     * Describe an image XObject's /ColorSpace (or one nested inside
     * [/Indexed ...]) as a PNG-compatible colour model:
     *   array('type' => 'gray'|'rgb', 'components' => 1|3)
     *   array('type' => 'indexed', 'components' => 1, 'palette' => RGB bytes)
     * DeviceGray / DeviceRGB, [/ICCBased ref] with /N 1 or 3 (Chrome writes
     * every PNG this way) and [/Indexed base hival lookup] over one of those
     * (Dompdf writes palettes this way when ImageMagick is installed). Null
     * for anything else (CMYK, Lab, Separation, DeviceN, pattern).
     */
    function haxcmsSystemGetPdfColorModel($colorSpace)
    {
        $value = function ($el) {
            return (is_object($el) && method_exists($el, 'getContent')) ? $el->getContent() : $el;
        };
        if ($colorSpace instanceof \Smalot\PdfParser\Element\ElementArray) {
            $items = $colorSpace->getContent();
            if (!is_array($items) || count($items) < 2) {
                return null;
            }
            $family = (string) $value($items[0]);
            if ($family === 'ICCBased') {
                $components = 3;
                $profile = $items[1];
                if (is_object($profile) && method_exists($profile, 'has') && $profile->has('N')) {
                    $components = (int) $profile->get('N')->getContent();
                }
                if ($components === 1) {
                    return array('type' => 'gray', 'components' => 1);
                }
                return $components === 3 ? array('type' => 'rgb', 'components' => 3) : null;
            }
            if ($family === 'Indexed' && count($items) >= 4) {
                $base = haxcmsSystemGetPdfColorModel($items[1]);
                $hival = (int) $value($items[2]);
                $lookup = $value($items[3]);
                if ($base === null || $base['type'] === 'indexed' || !is_string($lookup) || $hival < 0 || $hival > 255) {
                    return null;
                }
                $entries = $hival + 1;
                $need = $entries * $base['components'];
                // a hex string lookup may come back undecoded
                if (strlen($lookup) === 2 * $need && ctype_xdigit($lookup)) {
                    $lookup = hex2bin($lookup);
                }
                if (strlen($lookup) < $need) {
                    return null;
                }
                $lookup = substr($lookup, 0, $need);
                if ($base['components'] === 1) {
                    $rgb = '';
                    for ($i = 0; $i < $entries; $i++) {
                        $rgb .= str_repeat($lookup[$i], 3);
                    }
                    $lookup = $rgb;
                }
                return array('type' => 'indexed', 'components' => 1, 'palette' => $lookup);
            }
            return null;
        }
        $name = $value($colorSpace);
        if ($name === 'DeviceRGB') {
            return array('type' => 'rgb', 'components' => 3);
        }
        if ($name === 'DeviceGray') {
            return array('type' => 'gray', 'components' => 1);
        }
        return null;
    }
}

if (!function_exists('haxcmsSystemPdfSamplesToPng')) {
    /**
     * Wrap PDF image samples in a PNG file. $samples is what smalot hands
     * back for a FlateDecode/unfiltered stream: either raw rows (Chrome) or
     * rows already carrying a PNG filter byte (Dompdf, /Predictor 15 —
     * smalot inflates but does not undo predictors). Either way it is valid
     * PNG IDAT payload once each row has its filter byte, so no per-pixel
     * work happens in PHP. PDF and PNG pack sub-byte samples the same way
     * (big-endian, rows padded to a byte), and 16-bit samples are
     * big-endian in both. Returns null for a bit depth PNG cannot express
     * for this colour model, or a length that fits neither row shape.
     */
    function haxcmsSystemPdfSamplesToPng($samples, $width, $height, $model, $bitsPerComponent)
    {
        $allowed = array(
            'gray' => array(1, 2, 4, 8, 16),
            'rgb' => array(8, 16),
            'indexed' => array(1, 2, 4, 8),
        );
        if (!is_array($model) || !isset($allowed[$model['type']]) || !in_array($bitsPerComponent, $allowed[$model['type']], true)) {
            return null;
        }
        $rowBytes = (int) ceil($width * $model['components'] * $bitsPerComponent / 8);
        $length = strlen($samples);
        if ($width < 1 || $height < 1 || $rowBytes < 1) {
            return null;
        }
        if ($length === $height * ($rowBytes + 1)) {
            $filtered = $samples;
        } elseif ($length === $height * $rowBytes) {
            $rows = array();
            for ($y = 0; $y < $height; $y++) {
                $rows[] = "\0" . substr($samples, $y * $rowBytes, $rowBytes);
            }
            $filtered = implode('', $rows);
        } else {
            return null;
        }
        $chunk = function ($type, $data) {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        // PNG colour types: 0 greyscale, 2 truecolour, 3 palette
        $colorTypes = array('gray' => 0, 'rgb' => 2, 'indexed' => 3);
        $ihdr = pack('NNCCCCC', $width, $height, $bitsPerComponent, $colorTypes[$model['type']], 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr);
        if ($model['type'] === 'indexed') {
            $png .= $chunk('PLTE', $model['palette']);
        }
        return $png . $chunk('IDAT', gzcompress($filtered, 6)) . $chunk('IEND', '');
    }
}

if (!function_exists('haxcmsSystemDecodePdfRasterImage')) {
    /**
     * Decode a FlateDecode / unfiltered image XObject (how Chrome and Dompdf
     * embed PNGs) into JPEG, flattening an /SMask (transparency) onto
     * white and downscaling to the 1600px max edge. Node parity:
     * pdfToSemanticHtml.js extractPageImages (pdf.js decode + sharp).
     * Without GD the image is returned as an opaque PNG instead.
     * Returns null for anything it cannot decode (CMYK / Lab / Separation
     * colour, /Decode arrays, image masks, bit depths PNG cannot express,
     * odd sample lengths).
     *
     * @return array{data: string, width: float, height: float, mime: string}|null
     */
    function haxcmsSystemDecodePdfRasterImage($xobject, $samples, $width, $height)
    {
        if ($xobject->has('Decode') || $xobject->has('ImageMask')) {
            return null;
        }
        $bitsPerComponent = (int) $xobject->get('BitsPerComponent')->getContent();
        try {
            $model = haxcmsSystemGetPdfColorModel($xobject->get('ColorSpace'));
        } catch (\Throwable $e) {
            return null;
        }
        if ($model === null) {
            return null;
        }
        $png = haxcmsSystemPdfSamplesToPng($samples, $width, $height, $model, $bitsPerComponent);
        if ($png === null) {
            return null;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return array('data' => $png, 'width' => (float) $width, 'height' => (float) $height, 'mime' => 'image/png');
        }
        $image = @imagecreatefromstring($png);
        if ($image === false) {
            return null;
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        haxcmsSystemFlattenPdfSoftMask($image, $xobject, $width, $height);
        ob_start();
        $ok = imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        if (!$ok || !is_string($jpeg) || $jpeg === '') {
            return null;
        }
        $result = haxcmsSystemDownscalePdfJpeg($jpeg, (float) $width, (float) $height);
        $result['mime'] = 'image/jpeg';
        return $result;
    }
}

if (!function_exists('haxcmsSystemFlattenPdfSoftMask')) {
    /**
     * Composite a GD truecolor image onto white using the XObject's /SMask
     * (DeviceGray alpha at any PNG greyscale depth, same size). Transparent regions otherwise
     * come out in whatever colour the encoder stored under them — black
     * for Chrome — which hides dark line art. Very large images skip the
     * per-pixel pass (kept as-is) to bound import time.
     */
    function haxcmsSystemFlattenPdfSoftMask($image, $xobject, $width, $height)
    {
        if (!$xobject->has('SMask') || $width * $height > 4000000) {
            return;
        }
        try {
            $mask = $xobject->get('SMask');
            if (!is_object($mask) || !method_exists($mask, 'getContent')) {
                return;
            }
            if ((int) $mask->get('Width')->getContent() !== $width || (int) $mask->get('Height')->getContent() !== $height) {
                return;
            }
            $alpha = $mask->getContent();
        } catch (\Throwable $e) {
            return;
        }
        $maskBits = 8;
        try {
            $maskBits = (int) $mask->get('BitsPerComponent')->getContent();
        } catch (\Throwable $e) {
            // default 8
        }
        $maskPng = is_string($alpha)
            ? haxcmsSystemPdfSamplesToPng($alpha, $width, $height, array('type' => 'gray', 'components' => 1), $maskBits)
            : null;
        $maskImage = $maskPng !== null ? @imagecreatefromstring($maskPng) : false;
        if ($maskImage === false) {
            return;
        }
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $a = imagecolorat($maskImage, $x, $y);
                if (!imageistruecolor($maskImage)) {
                    $a = imagecolorsforindex($maskImage, $a)['red'];
                } else {
                    $a = $a & 0xFF;
                }
                if ($a === 255) {
                    continue;
                }
                $rgb = imagecolorat($image, $x, $y);
                $k = $a / 255;
                $r = (int) round((($rgb >> 16) & 0xFF) * $k + 255 * (1 - $k));
                $g = (int) round((($rgb >> 8) & 0xFF) * $k + 255 * (1 - $k));
                $b = (int) round(($rgb & 0xFF) * $k + 255 * (1 - $k));
                imagesetpixel($image, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }
        imagedestroy($maskImage);
    }
}

if (!function_exists('haxcmsSystemPdfImageTag')) {
    /**
     * <img> for an imported image with a display width that keeps it within
     * 800x600 CSS px (Node parity: getImageDisplayWidth / imageToHtml), so a
     * portrait phone photo doesn't fill several screens.
     */
    function haxcmsSystemPdfImageTag($jpeg, $width, $height, $mime = 'image/jpeg')
    {
        $src = 'data:' . $mime . ';base64,' . base64_encode($jpeg);
        if (!($width > 0) || !($height > 0)) {
            return '<img src="' . $src . '">';
        }
        $display = max(1, (int) round(min($width, 800, (600 * $width) / $height)));
        return '<img src="' . $src . '" width="' . $display . '">';
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
