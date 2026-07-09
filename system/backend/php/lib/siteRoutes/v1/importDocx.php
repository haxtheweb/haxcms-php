<?php
include_once dirname(__FILE__) . '/../../Operations.php';

/**
 * Convert DOCX word/document.xml into a simplified HTML string using DOMDocument.
 */
function haxcmsConvertDocxXmlToHtml($xmlString)
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

/**
 * Parse a simple HTML string into an array of element descriptors.
 */
function haxcmsSimpleHtmlToElements($html)
{
    $elements = array();
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    @$dom->loadHTML('<?xml encoding="UTF-8"?><div id="docx-import-wrapper">' . $html . '</div>');
    // For full HTML documents, extract from <body> so we don't get <html>/<head>/<script>
    // For flat HTML fragments (e.g. from DOCX conversion), fall back to the wrapper div.
    $source = $dom->getElementsByTagName('body')->item(0);
    if (!$source) {
        $source = $dom->getElementById('docx-import-wrapper');
    }
    if (!$source) {
        return $elements;
    }
    foreach ($source->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $elements[] = array(
                'tagName' => strtoupper($child->tagName),
                'html' => $dom->saveHTML($child),
                'text' => trim($child->textContent),
            );
        }
    }
    return $elements;
}

/**
 * Collect sibling elements until a heading of the specified tag names is encountered.
 */
function haxcmsCollectSiblingsUntil($elements, $startIndex, $stopTags)
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

/**
 * Find the highest (lowest numeric) heading level present in parsed elements.
 */
function haxcmsGetHighestHeadingLevel($elements)
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

/**
 * Return fallback content based on type.
 */
function haxcmsGetFallbackContent($type)
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

return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';

    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for import endpoint'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }

    $method = isset($body['method']) && is_string($body['method']) ? $body['method'] : 'site';
    $type = isset($body['type']) && is_string($body['type']) ? $body['type'] : '';
    $parentId = isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null'
        ? (string) $body['parentId']
        : null;

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
    if (!preg_match('/\.docx$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'message' => 'Invalid file type. Expected .docx, got: ' . $filename),
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
                    $html = haxcmsConvertDocxXmlToHtml($xmlString);
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

    $titleValue = preg_replace('/\.docx$/i', '', $filename);
    $elements = haxcmsSimpleHtmlToElements($html);
    $items = array();

    $haxcms = isset($GLOBALS['HAXCMS']) && is_object($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;

    $makeSlug = function ($title) use ($haxcms) {
        if ($haxcms !== null && method_exists($haxcms, 'cleanTitle')) {
            return $haxcms->cleanTitle($title);
        }
        $clean = trim($title);
        $clean = str_replace('./', '', $clean);
        $clean = str_replace('../', '', $clean);
        $clean = strtolower(str_replace(' ', '-', $clean));
        $clean = preg_replace('/[^\w\-\/]+/u', '-', $clean);
        $clean = mb_strtolower(preg_replace('/--+/u', '-', $clean), 'UTF-8');
        return $clean !== '' ? $clean : 'blank';
    };

    $buildItem = function ($title, $slug, $order, $parent, $indent, $contents) {
        $item = new JSONOutlineSchemaItem();
        return array(
            'id' => $item->id,
            'title' => $title,
            'slug' => $slug,
            'order' => $order,
            'parent' => $parent,
            'indent' => $indent,
            'location' => $item->location,
            'description' => $item->description,
            'metadata' => $item->metadata,
            'contents' => $contents,
        );
    };

    switch ($method) {
        case 'site': {
            $highestLevel = haxcmsGetHighestHeadingLevel($elements);
            $rootTag = $highestLevel ? 'H' . $highestLevel : null;
            $childTag = ($highestLevel && $highestLevel < 4) ? 'H' . ($highestLevel + 1) : null;
            $rootTagName = $rootTag ? $rootTag : null;
            $childTagName = $childTag ? $childTag : null;

            if (!$rootTag) {
                $contents = '';
                foreach ($elements as $el) {
                    $contents .= $el['html'];
                }
                $items[] = $buildItem($titleValue, $makeSlug($titleValue), 0, $parentId, 0, $contents !== '' ? $contents : '<p></p>');
            } else {
                $rootHeadings = array();
                foreach ($elements as $idx => $el) {
                    if ($el['tagName'] === $rootTagName) {
                        $rootHeadings[] = array('index' => $idx, 'el' => $el);
                    }
                }
                $rootOrder = 0;
                foreach ($rootHeadings as $rootData) {
                    $rootHeading = $rootData['el'];
                    $idx = $rootData['index'];
                    $rootTitle = $rootHeading['text'];
                    $rootSlug = $makeSlug($rootTitle);
                    $rootSiblings = haxcmsCollectSiblingsUntil($elements, $idx, array($rootTagName));
                    $rootContents = '';
                    $childHeading = null;
                    $childStartIdx = null;
                    foreach ($rootSiblings as $sibIdx => $sib) {
                        if ($childTagName && $sib['tagName'] === $childTagName && $childHeading === null) {
                            $childHeading = $sib;
                            $childStartIdx = $idx + $sibIdx + 1;
                            break;
                        } elseif ($childHeading === null) {
                            $rootContents .= $sib['html'];
                        }
                    }
                    $rootItem = $buildItem($rootTitle, $rootSlug, $rootOrder, $parentId, 0, $rootContents !== '' ? $rootContents : haxcmsGetFallbackContent($type));
                    $items[] = $rootItem;
                    $rootOrder += 1;

                    if ($childHeading !== null) {
                        $childOrder = 0;
                        $currentChildIdx = $childStartIdx - 1;
                        while ($currentChildIdx < count($elements)) {
                            if ($elements[$currentChildIdx]['tagName'] !== $childTagName) {
                                $currentChildIdx++;
                                continue;
                            }
                            $childTitle = $elements[$currentChildIdx]['text'];
                            $childSlug = $rootSlug . '/' . $makeSlug($childTitle);
                            $childSiblings = haxcmsCollectSiblingsUntil($elements, $currentChildIdx, array($rootTagName, $childTagName));
                            $childContents = '';
                            foreach ($childSiblings as $sib) {
                                $childContents .= $sib['html'];
                            }
                            $childItem = $buildItem($childTitle, $childSlug, $childOrder, $rootItem['id'], 1, $childContents !== '' ? $childContents : '<p></p>');
                            $items[] = $childItem;
                            $childOrder += 1;
                            $currentChildIdx += count($childSiblings) + 1;
                        }
                    }
                }
            }
            break;
        }
        case 'branch': {
            $highestLevel = haxcmsGetHighestHeadingLevel($elements);
            $rootTag = $highestLevel ? 'H' . $highestLevel : null;
            $rootTagName = $rootTag ? $rootTag : null;

            if (!$rootTag) {
                $contents = '';
                foreach ($elements as $el) {
                    $contents .= $el['html'];
                }
                $items[] = $buildItem($titleValue, $makeSlug($titleValue), 0, $parentId, 0, $contents !== '' ? $contents : '<p></p>');
            } else {
                $rootHeadings = array();
                foreach ($elements as $idx => $el) {
                    if ($el['tagName'] === $rootTagName) {
                        $rootHeadings[] = array('index' => $idx, 'el' => $el);
                    }
                }
                $order = 0;
                foreach ($rootHeadings as $rootData) {
                    $rootHeading = $rootData['el'];
                    $idx = $rootData['index'];
                    $rootTitle = $rootHeading['text'];
                    $rootSlug = $makeSlug($rootTitle);
                    $rootSiblings = haxcmsCollectSiblingsUntil($elements, $idx, array($rootTagName));
                    $rootContents = '';
                    foreach ($rootSiblings as $sib) {
                        $rootContents .= $sib['html'];
                    }
                    $rootItem = $buildItem($rootTitle, $rootSlug, $order, $parentId, 0, $rootContents !== '' ? $rootContents : haxcmsGetFallbackContent($type));
                    $items[] = $rootItem;
                    $order += 1;
                }
            }
            break;
        }
        case 'page':
        default: {
            $contents = '';
            foreach ($elements as $el) {
                $contents .= $el['html'];
            }
            $items[] = $buildItem($titleValue, $makeSlug($titleValue), 0, $parentId, 0, $contents !== '' ? $contents : '<p></p>');
            break;
        }
    }

    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => array('items' => $items, 'filename' => $filename)),
        array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
        $context->routeSuffix,
        $apiBasePath
    );
};
