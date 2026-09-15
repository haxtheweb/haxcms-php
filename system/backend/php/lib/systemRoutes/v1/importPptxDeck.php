<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../HAXCMSFile.php';
include_once dirname(__FILE__) . '/../../FilesDataStore.php';

/**
 * Read the relationships for a single slide (ppt/slides/_rels/slideN.xml.rels)
 * into an rId => {Target, Type} map. Mirrors the Node PPTXInHTMLOut.getSlideRels.
 */
function haxcmsSystemReadSlideRelsForDeck($zip, $slideNum)
{
    $rels = array();
    $relsContent = $zip->getFromName('ppt/slides/_rels/slide' . $slideNum . '.xml.rels');
    if ($relsContent === false) {
        return $rels;
    }
    $relsDoc = new DOMDocument();
    @$relsDoc->loadXML($relsContent);
    $relNodes = $relsDoc->getElementsByTagName('Relationship');
    foreach ($relNodes as $rel) {
        $rId = $rel->getAttribute('Id');
        $target = $rel->getAttribute('Target');
        $type = $rel->getAttribute('Type');
        if ($rId !== '' && $target !== '') {
            $rels[$rId] = array('Target' => $target, 'Type' => $type);
        }
    }
    return $rels;
}

/**
 * Resolve a relationship target path relative to ppt/slides/, normalizing
 * ../ segments so the final path is absolute within the ZIP. Mirrors the
 * Node resolveRelationshipTarget + the existing PHP convertPptxToHtml logic.
 */
function haxcmsSystemResolveRelsTargetForDeck($target)
{
    $normalizedTarget = str_replace('\\', '/', (string) $target);
    $normalizedTarget = ltrim($normalizedTarget, '/');
    $combined = 'ppt/slides/' . $normalizedTarget;
    $parts = explode('/', $combined);
    $normalized = array();
    foreach ($parts as $part) {
        if ($part === '..') {
            array_pop($normalized);
        } elseif ($part !== '.' && $part !== '') {
            $normalized[] = $part;
        }
    }
    return implode('/', $normalized);
}

/**
 * Extract speaker-notes text for a single slide by following its
 * notesSlide relationship. Mirrors the Node getSlideNotesText.
 */
function haxcmsSystemExtractSlideNotesForDeck($zip, $slideNum)
{
    $rels = haxcmsSystemReadSlideRelsForDeck($zip, $slideNum);
    $notesPath = null;
    foreach ($rels as $rel) {
        if (isset($rel['Type']) && is_string($rel['Type']) && substr($rel['Type'], -11) === '/notesSlide') {
            $notesPath = haxcmsSystemResolveRelsTargetForDeck($rel['Target']);
            break;
        }
    }
    if ($notesPath === null) {
        return '';
    }
    $notesContent = $zip->getFromName($notesPath);
    if ($notesContent === false) {
        return '';
    }
    $nsP = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    $nsA = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $doc = new DOMDocument();
    @$doc->loadXML($notesContent);
    $spTree = $doc->getElementsByTagNameNS($nsP, 'spTree')->item(0);
    if (!$spTree) {
        return '';
    }
    $shapes = $doc->getElementsByTagNameNS($nsP, 'sp');
    $lines = array();
    foreach ($shapes as $sp) {
        $tEls = $sp->getElementsByTagNameNS($nsA, 't');
        $text = '';
        foreach ($tEls as $t) {
            $text .= $t->nodeValue;
        }
        $text = trim($text);
        if ($text !== '') {
            $lines[] = $text;
        }
    }
    return implode("\n", $lines);
}

/**
 * Build a per-slide deck manifest (title/html/notes) from a .pptx and extract
 * embedded media, mirroring the Node PPTXInHTMLOut.toDeckManifest() +
 * getExtractedFiles(). Returns array('slides' => [...], 'extractedFiles' => [...]).
 * Each slide is array('number', 'title', 'html', 'notes'). The pruned contract
 * (no thumbnail/renderTier/image) matches the Node canonical deck.json shape.
 */
function haxcmsSystemBuildPptxDeckManifest($tmpPath)
{
    if (!class_exists('ZipArchive')) {
        throw new \RuntimeException('ZipArchive extension is not available');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new \RuntimeException('Unable to open PPTX as ZIP archive');
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

    // Collect and sort slide files numerically (ksort on int keys avoids the
    // lexicographic sort bug the Node converter fixed).
    $slideFiles = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $name, $m)) {
            $slideFiles[(int) $m[1]] = $name;
        }
    }
    ksort($slideFiles);

    $manifestSlides = array();
    $extractedFiles = array();
    $imageRefMap = array(); // originalPath => fileReference (dedup across slides)

    foreach ($slideFiles as $slideNum => $slideName) {
        $slideXml = $zip->getFromName($slideName);
        if ($slideXml === false) {
            continue;
        }
        $doc = new DOMDocument();
        @$doc->loadXML($slideXml);
        $spTree = $doc->getElementsByTagNameNS($nsP, 'spTree')->item(0);

        $title = 'Slide ' . $slideNum;
        $bodyHtml = '';
        $titleFound = false;

        if ($spTree) {
            // Text shapes - detect title placeholder and extract body text
            $shapes = $doc->getElementsByTagNameNS($nsP, 'sp');
            foreach ($shapes as $sp) {
                $isTitle = false;
                $nvSpPr = $sp->getElementsByTagNameNS($nsP, 'nvSpPr')->item(0);
                if ($nvSpPr) {
                    $nvPr = $nvSpPr->getElementsByTagNameNS($nsP, 'nvPr')->item(0);
                    if ($nvPr) {
                        $ph = $nvPr->getElementsByTagNameNS($nsP, 'ph')->item(0);
                        if ($ph) {
                            $phType = $ph->getAttributeNS($nsP, 'type');
                            if ($phType === '') {
                                $phType = $ph->getAttribute('type');
                            }
                            if ($phType === 'title' || $phType === 'ctrTitle') {
                                $isTitle = true;
                            }
                        }
                    }
                }
                $tEls = $sp->getElementsByTagNameNS($nsA, 't');
                $text = '';
                foreach ($tEls as $t) {
                    $text .= $t->nodeValue;
                }
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                if ($isTitle && !$titleFound) {
                    $title = preg_replace('/\s+/', ' ', $text);
                    $titleFound = true;
                } else {
                    $lines = preg_split('/\n+/', $text);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $bodyHtml .= '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                        }
                    }
                }
            }

            // Images (p:pic) - resolve via slide rels, extract + embed img src
            $rels = haxcmsSystemReadSlideRelsForDeck($zip, $slideNum);
            $pics = $doc->getElementsByTagNameNS($nsP, 'pic');
            $picIndex = 0;
            foreach ($pics as $pic) {
                $picIndex++;
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
                $imagePath = haxcmsSystemResolveRelsTargetForDeck($rels[$embed]['Target']);
                if (strpos($imagePath, 'ppt/media/') !== 0) {
                    continue;
                }
                if (isset($imageRefMap[$imagePath])) {
                    $bodyHtml .= '<img src="' . $imageRefMap[$imagePath] . '" loading="lazy" decoding="async" alt="" />';
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
                $extractedFiles[$fileReference] = array(
                    'buffer'       => base64_encode($imageData),
                    'mimeType'     => $imageMimeByExt[$ext],
                    'originalPath' => $imagePath,
                );
                $imageRefMap[$imagePath] = $fileReference;
                $bodyHtml .= '<img src="' . $fileReference . '" loading="lazy" decoding="async" alt="" />';
            }
        }

        $html = '<div class="slide" data-slide-number="' . $slideNum . '">';
        $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
        $html .= $bodyHtml;
        $html .= '</div>';

        $notes = haxcmsSystemExtractSlideNotesForDeck($zip, $slideNum);

        $manifestSlides[] = array(
            'number' => $slideNum,
            'title'  => $title,
            'html'   => $html,
            'notes'  => $notes,
        );
    }

    $zip->close();
    return array('slides' => $manifestSlides, 'extractedFiles' => $extractedFiles);
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

    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.pptx';

    if (!preg_match('/\.pptx$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .pptx, got: ' . $filename)),
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
            array('status' => 400, 'data' => array('error' => 'Uploaded file is not a valid .pptx file (missing ZIP signature)')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    // multipart form fields are in $_POST (php://input is empty for multipart,
    // so $context->body is empty - see SystemApiRequestContext::parseBody)
    $siteName = '';
    if (isset($_POST['siteName']) && is_string($_POST['siteName'])) {
        $siteName = trim($_POST['siteName']);
    }
    if ($siteName === '') {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'siteName is required')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site || !isset($site->manifest) || !isset($site->manifest->metadata->site->name)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Site "' . $siteName . '" not found')),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    try {
        $deckData = haxcmsSystemBuildPptxDeckManifest($tmpPath);
        $manifestSlides = $deckData['slides'];
        $extractedFiles = $deckData['extractedFiles'];

        // sanitize the deck folder name - strip extension, then drop anything
        // that isn't alphanumeric/hyphen/underscore so it's a safe path segment
        $baseDeckName = preg_replace('/\.pptx$/i', '', $filename);
        $baseDeckName = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $baseDeckName);
        if ($baseDeckName === '' || $baseDeckName === null) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to derive a deck name from the uploaded filename')),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        // uniquify the deck folder name when a deck of the same name already
        // exists, matching the archiveSite/cloneSite pattern (-1, -2, ...) so a
        // repeated import never silently overwrites a prior deck's files.
        $siteDir = $site->directory . '/' . $site->manifest->metadata->site->name;
        $deckName = $baseDeckName;
        $deckCounter = 1;
        while (is_dir($siteDir . '/files/decks/' . $deckName)) {
            $deckName = $baseDeckName . '-' . $deckCounter;
            $deckCounter++;
        }
        $deckDir = $siteDir . '/files/decks/' . $deckName;

        // route the uploaded .pptx through HAXCMSFile::save() (the same validated
        // code path the v1 files POST uses) so it gets extension/MIME validation,
        // filename sanitization, and collision-safe renaming. The subfolder
        // targets files/decks/<deckName>/ and creates the directory recursively.
        $fileSaver = new HAXCMSFile();
        $upload = array(
            'name'     => 'original.pptx',
            'tmp_name' => $tmpPath,
            'size'     => isset($file['size']) ? (int) $file['size'] : 0,
        );
        $saveResult = $fileSaver->save($upload, $site, null, null, 'decks/' . $deckName);
        if (
            !is_array($saveResult) ||
            (int) (isset($saveResult['status']) ? $saveResult['status'] : 0) !== 200 ||
            !is_array(isset($saveResult['data']) ? $saveResult['data'] : null) ||
            !isset($saveResult['data']['file'])
        ) {
            $errMsg = (is_array($saveResult) && is_string(isset($saveResult['data']) ? $saveResult['data'] : null))
                ? $saveResult['data']
                : 'Unable to save original pptx';
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 500, 'data' => array('error' => $errMsg)),
                array('statusCode' => 500, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        // use the server-sanitized relative path from the save result (handles
        // any per-file collision suffix) instead of assuming original.pptx
        $pptxRelativePath = isset($saveResult['data']['file']['path'])
            ? (string) $saveResult['data']['file']['path']
            : ('files/decks/' . $deckName . '/original.pptx');

        // extracted media are derived from the already-validated PPTX archive;
        // write them directly with basename() to strip any path components.
        foreach ($extractedFiles as $fileReference => $extracted) {
            $destName = basename($fileReference);
            @file_put_contents($deckDir . '/' . $destName, base64_decode($extracted['buffer']));
        }

        // #3043: register the extracted media in the per-site files.json index so
        // they are immediately discoverable via /x/api/v1/files without waiting
        // for a later reconcileMissingFromDisk. original.pptx was already
        // upserted by HAXCMSFile::save() above.
        $dataStore = new FilesDataStore($site);
        foreach ($extractedFiles as $fileReference => $extracted) {
            $destName = basename($fileReference);
            $storeRecord = $dataStore->buildFileRecordFromDisk('files/decks/' . $deckName . '/' . $destName);
            if ($storeRecord !== null) {
                $dataStore->upsertRecord($storeRecord);
            }
        }

        // rewrite slide image src from the converter's deck-agnostic default
        // (files/pptx-media/) to where the files actually landed (files/decks/<name>/)
        $deckSlides = array();
        foreach ($manifestSlides as $slide) {
            $slideHtml = is_string($slide['html'])
                ? str_replace('files/pptx-media/', 'files/decks/' . $deckName . '/', $slide['html'])
                : $slide['html'];
            $deckSlides[] = array(
                'number' => $slide['number'],
                'title'  => $slide['title'],
                'html'   => $slideHtml,
                'notes'  => $slide['notes'],
            );
        }

        $deckManifest = array(
            'title'  => $deckName,
            'source' => $filename,
            'pptx'   => $pptxRelativePath,
            'slides' => $deckSlides,
        );
        @file_put_contents($deckDir . '/deck.json', json_encode($deckManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => 200,
                'data' => array(
                    'deckPath'  => 'files/decks/' . $deckName . '/deck.json',
                    'embedHtml' => '<slide-deck source="files/decks/' . $deckName . '/deck.json"></slide-deck>',
                    'manifest'  => $deckManifest,
                ),
            ),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error processing PPTX: ' . $e->getMessage())),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
