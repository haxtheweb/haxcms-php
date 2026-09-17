<?php
include_once dirname(__FILE__) . '/../../../JSONOutlineSchemaItem.php';
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
// D36: sanitize untrusted HTML in the central import helper so every caller is covered.
include_once dirname(__FILE__) . '/../../../SanitizeContent.php';

/**
 * Generate a slug from a title.
 * Uses HAXCMS::cleanTitle if available, otherwise applies a simple fallback.
 */
if (!function_exists('haxcmsImportCleanTitle')) {
    function haxcmsImportCleanTitle($title)
    {
        $haxcms = isset($GLOBALS['HAXCMS']) && is_object($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        if ($haxcms !== null && method_exists($haxcms, 'cleanTitle')) {
            return $haxcms->cleanTitle($title);
        }
        $clean = trim($title);
        $clean = str_replace(array('./', '../'), '', $clean);
        $clean = strtolower(str_replace(' ', '-', $clean));
        $clean = preg_replace('/[^\w\-\/]+/u', '-', $clean);
        $clean = mb_strtolower(preg_replace('/--+/u', '-', $clean), 'UTF-8');
        return $clean !== '' ? $clean : 'blank';
    }
}

/**
 * Build a JSONOutlineSchemaItem-compatible array for an import item.
 */
if (!function_exists('haxcmsImportBuildItem')) {
    function haxcmsImportBuildItem($title, $slug, $order, $parent, $indent, $contents)
    {
        $item = new JSONOutlineSchemaItem();
        return array(
            'id'          => $item->id,
            'title'       => $title,
            'slug'        => $slug,
            'order'       => $order,
            'parent'      => $parent,
            'indent'      => $indent,
            'location'    => $item->location,
            'description' => $item->description,
            'metadata'    => $item->metadata,
            'contents'    => $contents,
        );
    }
}

/**
 * Parse a simple HTML string into an array of element descriptors.
 * Uses body-first extraction for full HTML documents.
 */
if (!function_exists('haxcmsImportSimpleHtmlToElements')) {
    function haxcmsImportSimpleHtmlToElements($html)
    {
        $elements = array();
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div id="import-wrapper">' . $html . '</div>');
        // We always wrap the fragment in <div id="import-wrapper">, so prefer
        // that wrapper and iterate its direct children (the headings/paragraphs).
        // loadHTML() synthesizes a <body> even for fragments, and that body's
        // only child is the wrapper div itself — so preferring body would yield
        // a single DIV element and hide every heading from the hierarchy
        // builder (the bug that flattened PDF/PPTX imports). Fall back to body
        // only when the wrapper was not injected (full-document input).
        $source = $dom->getElementById('import-wrapper');
        if (!$source) {
            $source = $dom->getElementsByTagName('body')->item(0);
        }
        if (!$source) {
            return $elements;
        }
        foreach ($source->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $elements[] = array(
                    'tagName' => strtoupper($child->tagName),
                    'html'    => $dom->saveHTML($child),
                    'text'    => trim($child->textContent),
                );
            }
        }
        return $elements;
    }
}

/**
 * Collect sibling elements until a heading of the specified tag names is encountered.
 */
if (!function_exists('haxcmsImportCollectSiblingsUntil')) {
    function haxcmsImportCollectSiblingsUntil($elements, $startIndex, $stopTags)
    {
        $siblings = array();
        for ($i = $startIndex + 1; $i < count($elements); $i++) {
            if (in_array($elements[$i]['tagName'], $stopTags, true)) {
                break;
            }
            $siblings[] = $elements[$i];
        }
        return $siblings;
    }
}

/**
 * Find the highest (lowest numeric) heading level present in parsed elements.
 */
if (!function_exists('haxcmsImportGetHighestHeadingLevel')) {
    function haxcmsImportGetHighestHeadingLevel($elements)
    {
        for ($level = 1; $level <= 4; $level++) {
            $tag = 'H' . $level;
            foreach ($elements as $el) {
                if ($el['tagName'] === $tag) {
                    return $level;
                }
            }
        }
        return null;
    }
}

/**
 * Return fallback content string based on site type.
 */
if (!function_exists('haxcmsImportGetFallbackContent')) {
    function haxcmsImportGetFallbackContent($type)
    {
        switch ($type) {
            case 'portfolio':
                return "<p>Enjoy my portfolio and let me know if you have questions.</p>\n<lesson-overview>\n  <lesson-highlight smart=\"pages\"></lesson-highlight>\n</lesson-overview>";
            case 'course':
                return "<p>Welcome to the lesson.</p>\n<lesson-overview>\n  <lesson-highlight smart=\"pages\"></lesson-highlight>\n  <lesson-highlight smart=\"readTime\"></lesson-highlight>\n  <lesson-highlight smart=\"selfChecks\"></lesson-highlight>\n  <lesson-highlight smart=\"audio\"></lesson-highlight>\n  <lesson-highlight smart=\"video\"></lesson-highlight>\n</lesson-overview>\n<p>Let's begin!</p>";
            default:
                return '<p></p>';
        }
    }
}

/**
 * Convert DOCX word/document.xml into a simplified HTML string using DOMDocument.
 */
/**
 * Load image relationship targets from an open DOCX ZipArchive into a map of
 * rId => data URI. Used so convertDocxXmlToHtml can emit <img src="data:...">
 * the same way mammoth does on Node (required for MaterializeInlineImages).
 *
 * @param ZipArchive $zip Open DOCX archive
 * @return array
 */
if (!function_exists('haxcmsImportLoadDocxMediaMapFromZip')) {
    function haxcmsImportLoadDocxMediaMapFromZip($zip)
    {
        $map = array();
        if (!($zip instanceof ZipArchive)) {
            return $map;
        }
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsXml === false || $relsXml === '') {
            return $map;
        }
        $relsDoc = new DOMDocument();
        if (!@$relsDoc->loadXML($relsXml)) {
            return $map;
        }
        $relNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $imageType = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
        $mimeByExt = array(
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
        );
        $relationships = $relsDoc->getElementsByTagNameNS($relNs, 'Relationship');
        if ($relationships->length === 0) {
            // Some writers omit the default NS prefix
            $relationships = $relsDoc->getElementsByTagName('Relationship');
        }
        foreach ($relationships as $rel) {
            $type = $rel->getAttribute('Type');
            if ($type !== $imageType) {
                continue;
            }
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            if ($id === '' || $target === '') {
                continue;
            }
            // Targets are relative to word/
            $target = str_replace('\\', '/', $target);
            if (strpos($target, '/') === 0) {
                $target = ltrim($target, '/');
            }
            if (strpos($target, '../') === 0) {
                // unusual; skip traversal outside package
                continue;
            }
            $zipPath = 'word/' . $target;
            // normalize ./media/foo -> word/media/foo
            $zipPath = preg_replace('#/\./#', '/', $zipPath);
            $bytes = $zip->getFromName($zipPath);
            if ($bytes === false) {
                // try Target as-is (already word/...)
                $bytes = $zip->getFromName($target);
            }
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $mime = isset($mimeByExt[$ext]) ? $mimeByExt[$ext] : 'application/octet-stream';
            // Only emit raster types MaterializeInlineImages can save
            if (strpos($mime, 'image/') !== 0 || $mime === 'image/svg+xml') {
                continue;
            }
            $map[$id] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
        return $map;
    }
}

/**
 * Convert DOCX word/document.xml into simplified HTML.
 * Optional $mediaMap (rId => data URI) emits inline <img> for drawings so
 * downstream MaterializeInlineImages can persist them as site files (#2945).
 *
 * @param string $xmlString
 * @param array $mediaMap
 * @return string
 */
if (!function_exists('haxcmsImportConvertDocxXmlToHtml')) {
    function haxcmsImportConvertDocxXmlToHtml($xmlString, $mediaMap = array())
    {
        if (!is_array($mediaMap)) {
            $mediaMap = array();
        }
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xmlString);
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $aNs = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $html = '';
        $paragraphs = $doc->getElementsByTagNameNS($ns, 'p');
        foreach ($paragraphs as $p) {
            $tag = 'p';
            $pPr = $p->getElementsByTagNameNS($ns, 'pPr')->item(0);
            if ($pPr) {
                $pStyle = $pPr->getElementsByTagNameNS($ns, 'pStyle')->item(0);
                if ($pStyle) {
                    // w:val is namespaced; getAttribute('val') always returns ''.
                    // Use getAttributeNS so headings are detected (parity with importDocx.php).
                    $styleVal = $pStyle->getAttributeNS($ns, 'val');
                    if ($styleVal === 'Heading1') {
                        $tag = 'h1';
                    }
                    elseif ($styleVal === 'Heading2') {
                        $tag = 'h2';
                    }
                    elseif ($styleVal === 'Heading3') {
                        $tag = 'h3';
                    }
                    elseif ($styleVal === 'Heading4') {
                        $tag = 'h4';
                    }
                    elseif ($styleVal === 'Heading5') {
                        $tag = 'h5';
                    }
                    elseif ($styleVal === 'Heading6') {
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

                // Inline drawings (mammoth parity): a:blip r:embed -> data URI img
                if (count($mediaMap) > 0) {
                    $blips = $r->getElementsByTagNameNS($aNs, 'blip');
                    if ($blips->length === 0) {
                        $blips = $r->getElementsByTagName('blip');
                    }
                    foreach ($blips as $blip) {
                        $embedId = $blip->getAttributeNS($rNs, 'embed');
                        if ($embedId === '') {
                            $embedId = $blip->getAttribute('r:embed');
                        }
                        if ($embedId === '' || !isset($mediaMap[$embedId])) {
                            continue;
                        }
                        $dataUri = $mediaMap[$embedId];
                        // data URIs are generated by us from package bytes; still escape quotes
                        $safeSrc = htmlspecialchars($dataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $textContent .= '<img src="' . $safeSrc . '" alt="" />';
                    }
                }
            }
            if ($textContent !== '') {
                $html .= '<' . $tag . '>' . $textContent . '</' . $tag . '>' . "\n";
            }
            elseif ($tag === 'p') {
                $html .= '<p></p>' . "\n";
            }
        }
        return trim($html);
    }
}

/**
 * Parse HTML from an import wrapper into a JSON Outline Schema items array.
 *
 * @param string $html       Raw HTML string to parse
 * @param array  $options    Keys: titleValue, method (site|branch|page), type, parentId
 * @return array             Array of item arrays
 */
if (!function_exists('haxcmsImportHtmlToItems')) {
    function haxcmsImportHtmlToItems($html, $options = array())
    {
        $method     = isset($options['method'])     ? $options['method']     : 'site';
        $type       = isset($options['type'])       ? $options['type']       : '';
        $parentId   = isset($options['parentId'])   ? $options['parentId']   : null;
        $titleValue = isset($options['titleValue']) ? $options['titleValue'] : 'import';

        // D36: sanitize untrusted HTML once, centrally, so all import callers are protected.
        $html = SanitizeContent::sanitizeHTMLForStorage($html);

        $elements = haxcmsImportSimpleHtmlToElements($html);
        $items    = array();

        switch ($method) {
            case 'site': {
                $highestLevel = haxcmsImportGetHighestHeadingLevel($elements);
                $rootTag      = $highestLevel ? 'H' . $highestLevel : null;
                $childTag     = ($highestLevel && $highestLevel < 4) ? 'H' . ($highestLevel + 1) : null;

                if (!$rootTag) {
                    $contents = '';
                    foreach ($elements as $el) { $contents .= $el['html']; }
                    $items[] = haxcmsImportBuildItem($titleValue, haxcmsImportCleanTitle($titleValue), 0, $parentId, 0, $contents !== '' ? $contents : '<p></p>');
                } else {
                    $rootHeadings = array();
                    foreach ($elements as $idx => $el) {
                        if ($el['tagName'] === $rootTag) {
                            $rootHeadings[] = array('index' => $idx, 'el' => $el);
                        }
                    }
                    $rootOrder = 0;
                    foreach ($rootHeadings as $rootData) {
                        $rootHeading  = $rootData['el'];
                        $idx          = $rootData['index'];
                        $rootTitle    = $rootHeading['text'];
                        $rootSlug     = haxcmsImportCleanTitle($rootTitle);
                        $rootSiblings = haxcmsImportCollectSiblingsUntil($elements, $idx, array($rootTag));
                        $rootContents = '';
                        $childHeading = null;
                        $childStartIdx = null;
                        foreach ($rootSiblings as $sibIdx => $sib) {
                            if ($childTag && $sib['tagName'] === $childTag && $childHeading === null) {
                                $childHeading  = $sib;
                                $childStartIdx = $idx + $sibIdx + 1;
                                break;
                            } elseif ($childHeading === null) {
                                $rootContents .= $sib['html'];
                            }
                        }
                        $rootItem = haxcmsImportBuildItem($rootTitle, $rootSlug, $rootOrder, $parentId, 0, $rootContents !== '' ? $rootContents : haxcmsImportGetFallbackContent($type));
                        $items[]  = $rootItem;
                        $rootOrder++;

                        if ($childHeading !== null) {
                            $childOrder    = 0;
                            $currentChildIdx = $childStartIdx - 1;
                            while ($currentChildIdx < count($elements)) {
                                if ($elements[$currentChildIdx]['tagName'] !== $childTag) {
                                    $currentChildIdx++;
                                    continue;
                                }
                                $childTitle    = $elements[$currentChildIdx]['text'];
                                $childSlug     = $rootSlug . '/' . haxcmsImportCleanTitle($childTitle);
                                $childSiblings = haxcmsImportCollectSiblingsUntil($elements, $currentChildIdx, array($rootTag, $childTag));
                                $childContents = '';
                                foreach ($childSiblings as $sib) { $childContents .= $sib['html']; }
                                $items[]     = haxcmsImportBuildItem($childTitle, $childSlug, $childOrder, $rootItem['id'], 1, $childContents !== '' ? $childContents : '<p></p>');
                                $childOrder++;
                                $currentChildIdx += count($childSiblings) + 1;
                            }
                        }
                    }
                }
                break;
            }
            case 'branch': {
                $highestLevel = haxcmsImportGetHighestHeadingLevel($elements);
                $rootTag      = $highestLevel ? 'H' . $highestLevel : null;

                if (!$rootTag) {
                    $contents = '';
                    foreach ($elements as $el) { $contents .= $el['html']; }
                    $items[] = haxcmsImportBuildItem($titleValue, haxcmsImportCleanTitle($titleValue), 0, $parentId, 0, $contents !== '' ? $contents : '<p></p>');
                } else {
                    $rootHeadings = array();
                    foreach ($elements as $idx => $el) {
                        if ($el['tagName'] === $rootTag) {
                            $rootHeadings[] = array('index' => $idx, 'el' => $el);
                        }
                    }
                    $order = 0;
                    foreach ($rootHeadings as $rootData) {
                        $rootHeading  = $rootData['el'];
                        $idx          = $rootData['index'];
                        $rootTitle    = $rootHeading['text'];
                        $rootSlug     = haxcmsImportCleanTitle($rootTitle);
                        $rootSiblings = haxcmsImportCollectSiblingsUntil($elements, $idx, array($rootTag));
                        $rootContents = '';
                        foreach ($rootSiblings as $sib) { $rootContents .= $sib['html']; }
                        $items[] = haxcmsImportBuildItem($rootTitle, $rootSlug, $order, $parentId, 0, $rootContents !== '' ? $rootContents : haxcmsImportGetFallbackContent($type));
                        $order++;
                    }
                }
                break;
            }
            case 'page':
            default: {
                $contents = '';
                foreach ($elements as $el) { $contents .= $el['html']; }
                $items[] = haxcmsImportBuildItem($titleValue, haxcmsImportCleanTitle($titleValue), 0, $parentId, 0, $contents !== '' ? $contents : '<p></p>');
                break;
            }
        }

        return $items;
    }
}
