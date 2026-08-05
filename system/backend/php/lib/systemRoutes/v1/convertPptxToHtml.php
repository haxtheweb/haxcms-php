<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importPptx.php'; // reuse haxcmsSystemExtractPptxToHtml

/**
 * Extract media images from a PPTX file and build a files map keyed by
 * files/pptx-media/slide-{N}-image-{M}{ext}.  Mirrors the Node
 * PPTXInHTMLOut.getExtractedFiles() structure: each entry has
 * buffer (base64), mimeType, and originalPath.
 */
function haxcmsSystemExtractPptxFiles($tmpPath)
{
    if (!class_exists('ZipArchive')) {
        return array();
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        return array();
    }

    $nsP = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    $nsA = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $nsR = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $imageMimeByExt = array(
        '.jpg'  => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.png'  => 'image/png',
        '.gif'  => 'image/gif',
        '.webp' => 'image/webp',
        '.svg'  => 'image/svg+xml',
    );

    $files        = array();
    $imageRefMap  = array(); // originalPath => fileReference (dedup across slides)

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
        // Read the slide rels file to map rIds to targets
        $relsFileName = 'ppt/slides/_rels/slide' . $slideNum . '.xml.rels';
        $rels         = array();
        $relsContent  = $zip->getFromName($relsFileName);
        if ($relsContent !== false) {
            $relsDoc = new DOMDocument();
            @$relsDoc->loadXML($relsContent);
            $relNodes = $relsDoc->getElementsByTagName('Relationship');
            foreach ($relNodes as $rel) {
                $rId    = $rel->getAttribute('Id');
                $target = $rel->getAttribute('Target');
                if ($rId !== '' && $target !== '') {
                    $rels[$rId] = $target;
                }
            }
        }

        // Parse slide XML and find p:pic elements
        $slideXml = $zip->getFromName($slideName);
        if ($slideXml === false) {
            continue;
        }
        $doc = new DOMDocument();
        @$doc->loadXML($slideXml);
        $pics = $doc->getElementsByTagNameNS($nsP, 'pic');
        $picIndex = 0;
        foreach ($pics as $pic) {
            $picIndex++;

            // Get r:embed from p:blipFill/a:blip
            $blipFills = $pic->getElementsByTagNameNS($nsP, 'blipFill');
            if ($blipFills->length === 0) {
                continue;
            }
            $blips = $blipFills->item(0)->getElementsByTagNameNS($nsA, 'blip');
            if ($blips->length === 0) {
                continue;
            }
            $embed = $blips->item(0)->getAttributeNS($nsR, 'embed');
            if ($embed === '' || !isset($rels[$embed])) {
                continue;
            }

            // Resolve target path relative to ppt/slides/
            $target    = str_replace('\\', '/', $rels[$embed]);
            $target    = ltrim($target, '/');
            $combined  = 'ppt/slides/' . $target;
            $parts     = explode('/', $combined);
            $normalized = array();
            foreach ($parts as $part) {
                if ($part === '..') {
                    array_pop($normalized);
                } elseif ($part !== '.' && $part !== '') {
                    $normalized[] = $part;
                }
            }
            $imagePath = implode('/', $normalized);

            // Only extract files under ppt/media/
            if (strpos($imagePath, 'ppt/media/') !== 0) {
                continue;
            }

            // Dedup: skip if this image was already extracted
            if (isset($imageRefMap[$imagePath])) {
                continue;
            }

            $ext = '.' . strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            if (!isset($imageMimeByExt[$ext])) {
                continue;
            }

            $imageData = $zip->getFromName($imagePath);
            if ($imageData === false) {
                continue;
            }

            $fileReference = 'files/pptx-media/slide-' . $slideNum . '-image-' . $picIndex . $ext;
            $files[$fileReference] = array(
                'buffer'       => base64_encode($imageData),
                'mimeType'     => $imageMimeByExt[$ext],
                'originalPath' => $imagePath,
            );
            $imageRefMap[$imagePath] = $fileReference;
        }
    }

    $zip->close();
    return $files;
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
            array('status' => 400, 'data' => array('error' => 'No file uploaded', 'contents' => '', 'filename' => null, 'files' => array())),
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
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .pptx, got: ' . $filename, 'contents' => '', 'filename' => $filename, 'files' => array())),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath    = $file['tmp_name'];
    $firstBytes = @file_get_contents($tmpPath, false, null, 0, 4);
    if ($firstBytes === false || strlen($firstBytes) < 4 || substr($firstBytes, 0, 2) !== 'PK') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid .pptx file (missing ZIP signature)', 'contents' => '', 'filename' => $filename, 'files' => array())),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    try {
        $html  = haxcmsSystemExtractPptxToHtml($tmpPath);
        $files = haxcmsSystemExtractPptxFiles($tmpPath);
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('contents' => $html, 'filename' => $filename, 'files' => $files)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error converting PPTX to HTML: ' . $e->getMessage(), 'contents' => '', 'filename' => $filename, 'files' => array())),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
