<?php
/**
 * PPTX deck manifest builder helpers.
 *
 * Extracted from the former standalone importPptxDeck.php system route so that
 * both the site file-operation handler (operations/fileOperation.php) and any
 * future caller can include this single file. The manifest builder accepts a
 * resolved file path to a .pptx on disk (the uploaded file that already lives
 * in files/, or a tmp upload path) and returns the per-slide manifest +
 * extracted media buffers.
 *
 * Mirrors the Node PPTXInHTMLOut.toDeckManifest() + getExtractedFiles().
 */

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
 *
 * @param string $filePath Resolved path to the .pptx file on disk (the uploaded
 *                          file in files/ or a tmp upload path).
 * @return array{'slides': array, 'extractedFiles': array}
 */
function haxcmsSystemBuildPptxDeckManifest($filePath)
{
    if (!class_exists('ZipArchive')) {
        throw new \RuntimeException('ZipArchive extension is not available');
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
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
