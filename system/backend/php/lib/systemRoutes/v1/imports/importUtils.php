<?php
include_once dirname(__FILE__) . '/../../../JSONOutlineSchemaItem.php';
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';

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
        // Prefer body for full HTML documents; fall back to wrapper div for fragments
        $source = $dom->getElementsByTagName('body')->item(0);
        if (!$source) {
            $source = $dom->getElementById('import-wrapper');
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
if (!function_exists('haxcmsImportConvertDocxXmlToHtml')) {
    function haxcmsImportConvertDocxXmlToHtml($xmlString)
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xmlString);
        $ns   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $html = '';
        $paragraphs = $doc->getElementsByTagNameNS($ns, 'p');
        foreach ($paragraphs as $p) {
            $tag  = 'p';
            $pPr  = $p->getElementsByTagNameNS($ns, 'pPr')->item(0);
            if ($pPr) {
                $pStyle = $pPr->getElementsByTagNameNS($ns, 'pStyle')->item(0);
                if ($pStyle) {
                    $styleVal = $pStyle->getAttribute('val');
                    if ($styleVal === 'Heading1')      { $tag = 'h1'; }
                    elseif ($styleVal === 'Heading2')  { $tag = 'h2'; }
                    elseif ($styleVal === 'Heading3')  { $tag = 'h3'; }
                    elseif ($styleVal === 'Heading4')  { $tag = 'h4'; }
                    elseif ($styleVal === 'Heading5')  { $tag = 'h5'; }
                    elseif ($styleVal === 'Heading6')  { $tag = 'h6'; }
                }
            }
            $textContent = '';
            $runs = $p->getElementsByTagNameNS($ns, 'r');
            foreach ($runs as $r) {
                $rPr      = $r->getElementsByTagNameNS($ns, 'rPr')->item(0);
                $bold     = false;
                $italic   = false;
                $underline = false;
                $strike   = false;
                if ($rPr) {
                    $bold      = $rPr->getElementsByTagNameNS($ns, 'b')->length > 0;
                    $italic    = $rPr->getElementsByTagNameNS($ns, 'i')->length > 0;
                    $underline = $rPr->getElementsByTagNameNS($ns, 'u')->length > 0;
                    $strike    = $rPr->getElementsByTagNameNS($ns, 'strike')->length > 0;
                }
                $tEls    = $r->getElementsByTagNameNS($ns, 't');
                $runText = '';
                foreach ($tEls as $t) {
                    $runText .= $t->nodeValue;
                }
                if ($runText !== '') {
                    if ($bold)      { $runText = '<strong>' . $runText . '</strong>'; }
                    if ($italic)    { $runText = '<em>' . $runText . '</em>'; }
                    if ($underline) { $runText = '<em>' . $runText . '</em>'; }
                    if ($strike)    { $runText = '<del>' . $runText . '</del>'; }
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
