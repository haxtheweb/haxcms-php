<?php
/**
 * PowerPoint (.pptx) to semantic HTML, one page per slide.
 *
 * Each slide becomes `<h1>title</h1>` plus its content, the same shape the
 * .odp importer gives: the title placeholder (else the slide's first line of
 * text, taken out of the body, else "Slide N"); body text as paragraphs, or
 * as nested <ul>/<ol> lists where the paragraphs are bulleted or numbered;
 * tables; pictures as data URI <img> (createNode / addPage turn them into
 * files); runs with bold / italic / underline / strike / super / sub as
 * strong / em / em / del / sup / sub (the .docx importer's map); external
 * links as <a>. Slide numbers, dates, headers, footers and speaker notes are
 * left out. Slides come in presentation order.
 *
 * The output is built only from tags and attributes this class writes, with
 * every text node and attribute value escaped, so it is trusted markup (like
 * the PDF importer's); content is still sanitized when it is stored.
 *
 * Parity: a port of haxcms-nodejs src/lib/pptxToHtml.js. The walk, limits and
 * output strings are kept identical (including JavaScript's trim() and
 * parseInt() behavior) so both backends produce the same HTML for the same
 * deck.
 */
require_once __DIR__ . '/OdtToHtml.php';

class PptxToHtml
{
    // Security (SEC-UPLOAD): the zip directory states each entry's
    // uncompressed size; refuse to inflate past these, so a small upload
    // can't expand into a huge string (zip bomb).
    const MAX_XML_BYTES = 20971520;
    const MAX_IMAGE_BYTES = 10485760;
    const MAX_TOTAL_IMAGE_BYTES = 52428800;
    const MAX_SLIDES = 2000;

    private static $IMAGE_MIME = array(
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    );
    // placeholders that are not slide content
    private static $SKIP_PLACEHOLDERS = array('sldNum' => true, 'dt' => true, 'ftr' => true, 'hdr' => true, 'sldImg' => true);
    private static $TITLE_PLACEHOLDERS = array('title' => true, 'ctrTitle' => true);
    // placeholders whose paragraphs are bulleted unless a paragraph says not
    private static $BULLET_PLACEHOLDERS = array('obj' => true, 'body' => true);

    private $zip;
    private $imageBytes = 0;
    private $images = array();
    // nodes already used elsewhere (a slide's fallback title)
    private $skipNodes;

    private function __construct($zip)
    {
        $this->zip = $zip;
        $this->skipNodes = new \SplObjectStorage();
    }

    // ---- helpers (parity with pptxToHtml.js) ----

    private static function jsTrim($value)
    {
        return OdtToHtml::jsTrim($value);
    }

    /** parseInt(value, 10): leading integer, or null for NaN */
    private static function jsParseInt($value)
    {
        if (preg_match('/^\s*([+-]?\d+)/', (string) $value, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** parseInt(value || fallback, 10) || 0 */
    private static function intOr($value, $fallback)
    {
        $parsed = self::jsParseInt($value !== '' ? $value : $fallback);
        return $parsed ? $parsed : 0;
    }

    private static function escapeText($value)
    {
        return str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), (string) $value);
    }

    private static function escapeAttr($value)
    {
        return str_replace('"', '&quot;', self::escapeText($value));
    }

    private static function elementChildren($node)
    {
        $children = array();
        if ($node && $node->childNodes) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $children[] = $child;
                }
            }
        }
        return $children;
    }

    private static function child($node, $name)
    {
        foreach (self::elementChildren($node) as $item) {
            if ($item->nodeName === $name) {
                return $item;
            }
        }
        return null;
    }

    /** first element named $name below $node, in document order */
    private static function findFirst($node, $name)
    {
        foreach (self::elementChildren($node) as $item) {
            if ($item->nodeName === $name) {
                return $item;
            }
            $found = self::findFirst($item, $name);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    private static function attr($node, $name)
    {
        return ($node && $node->nodeType === XML_ELEMENT_NODE && $node->hasAttribute($name))
            ? (string) $node->getAttribute($name)
            : '';
    }

    private static function safeHref($href)
    {
        $value = self::jsTrim($href);
        if (preg_match('/^(https?:|mailto:)/i', $value) || strpos($value, '#') === 0) {
            return $value;
        }
        return '';
    }

    /** text, or an image, once the tags are taken out (never output) */
    private static function hasContent($html)
    {
        if (preg_match('/<img\s/i', $html)) {
            return true;
        }
        $text = '';
        $at = 0;
        $length = strlen($html);
        while ($at < $length) {
            $open = strpos($html, '<', $at);
            if ($open === false) {
                $text .= substr($html, $at);
                break;
            }
            $close = strpos($html, '>', $open);
            if ($close === false) {
                $text .= substr($html, $at);
                break;
            }
            $text .= substr($html, $at, $open - $at);
            $at = $close + 1;
        }
        $text = str_replace(array('&nbsp;', "\xC2\xA0"), ' ', $text);
        return self::jsTrim($text) !== '';
    }

    /** a zip path for $target relative to the folder $baseDir */
    private static function resolvePath($baseDir, $target)
    {
        $parts = array();
        if (strpos($target, '/') !== 0) {
            foreach (explode('/', $baseDir) as $part) {
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }
        foreach (explode('/', $target) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    private static function dirOf($path)
    {
        $index = strrpos($path, '/');
        return $index === false ? '' : substr($path, 0, $index);
    }

    private static function readXml($zip, $path, $required)
    {
        $stat = $zip->statName($path);
        if ($stat === false) {
            if ($required) {
                throw new \Exception('The .pptx file has no ' . $path . '.');
            }
            return null;
        }
        if ((int) $stat['size'] > self::MAX_XML_BYTES) {
            throw new \Exception('The .pptx file\'s ' . $path . ' is too large to import.');
        }
        $xml = $zip->getFromName($path);
        if ($xml === false || strlen($xml) > self::MAX_XML_BYTES) {
            throw new \Exception('The .pptx file\'s ' . $path . ' is too large to import.');
        }
        // Security: no DTD / entity declarations (billion laughs); OOXML never needs them
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \Exception('The .pptx file\'s ' . $path . ' is not valid PowerPoint XML.');
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        // lenient like the Node parser (htmlparser2)
        $dom->recover = true;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \Exception('The .pptx file\'s ' . $path . ' is not valid PowerPoint XML.');
        }
        return $dom;
    }

    /** Relationship Id => array('target' => zip path or URL, 'external' => bool) */
    private static function readRels($zip, $partPath)
    {
        $parts = explode('/', $partPath);
        $relsPath = self::dirOf($partPath) . '/_rels/' . end($parts) . '.rels';
        $doc = self::readXml($zip, $relsPath, false);
        $rels = array();
        if (!$doc) {
            return $rels;
        }
        $root = self::findFirst($doc, 'Relationships');
        foreach (self::elementChildren($root) as $rel) {
            if ($rel->nodeName !== 'Relationship') {
                continue;
            }
            $id = self::attr($rel, 'Id');
            $target = self::attr($rel, 'Target');
            if ($id === '') {
                continue;
            }
            $external = self::attr($rel, 'TargetMode') === 'External';
            $rels[$id] = array(
                'target' => $external ? $target : self::resolvePath(self::dirOf($partPath), $target),
                'external' => $external,
            );
        }
        return $rels;
    }

    // ---- images ----

    private function loadImage($path)
    {
        if (array_key_exists($path, $this->images)) {
            return;
        }
        $this->images[$path] = null;
        $parts = explode('.', $path);
        $ext = strtolower(end($parts));
        $stat = $this->zip->statName($path);
        if (!isset(self::$IMAGE_MIME[$ext]) || $stat === false) {
            return;
        }
        $size = (int) $stat['size'];
        if ($size > self::MAX_IMAGE_BYTES || $this->imageBytes + $size > self::MAX_TOTAL_IMAGE_BYTES) {
            return;
        }
        $data = $this->zip->getFromName($path);
        if ($data === false) {
            return;
        }
        $this->imageBytes += strlen($data);
        $this->images[$path] = 'data:' . self::$IMAGE_MIME[$ext] . ';base64,' . base64_encode($data);
    }

    /** read every picture a slide shows, before the walk */
    private function loadSlideImages($node, $rels)
    {
        foreach (self::elementChildren($node) as $item) {
            if ($item->nodeName === 'a:blip') {
                $id = self::attr($item, 'r:embed');
                if (isset($rels[$id]) && !$rels[$id]['external']) {
                    $this->loadImage($rels[$id]['target']);
                }
            }
            $this->loadSlideImages($item, $rels);
        }
    }

    // ---- text ----

    private function inline($paragraph, $rels)
    {
        $out = '';
        foreach (self::elementChildren($paragraph) as $item) {
            if ($item->nodeName === 'a:br') {
                $out .= '<br>';
                continue;
            }
            if ($item->nodeName !== 'a:r' && $item->nodeName !== 'a:fld') {
                continue;
            }
            $textNode = self::child($item, 'a:t');
            $text = $textNode ? $textNode->textContent : '';
            if ($text === '') {
                continue;
            }
            $props = self::child($item, 'a:rPr');
            $link = $props ? self::child($props, 'a:hlinkClick') : null;
            $href = '';
            if ($link) {
                $id = self::attr($link, 'r:id');
                if (isset($rels[$id]) && $rels[$id]['external']) {
                    $href = self::safeHref($rels[$id]['target']);
                }
            }
            $html = str_replace("\x0B", '<br>', self::escapeText($text));
            $baseline = self::intOr(self::attr($props, 'baseline'), '0');
            if ($baseline > 0) {
                $html = '<sup>' . $html . '</sup>';
            } elseif ($baseline < 0) {
                $html = '<sub>' . $html . '</sub>';
            }
            $strike = self::attr($props, 'strike');
            if ($strike !== '' && $strike !== 'noStrike') {
                $html = '<del>' . $html . '</del>';
            }
            $underline = self::attr($props, 'u');
            $italic = self::attr($props, 'i') === '1' || self::attr($props, 'i') === 'true';
            // underline is how links look, not emphasis
            if ($italic || ($underline !== '' && $underline !== 'none' && $href === '')) {
                $html = '<em>' . $html . '</em>';
            }
            if (self::attr($props, 'b') === '1' || self::attr($props, 'b') === 'true') {
                $html = '<strong>' . $html . '</strong>';
            }
            $out .= $href !== '' ? '<a href="' . self::escapeAttr($href) . '">' . $html . '</a>' : $html;
        }
        return $out;
    }

    /**
     * A text body's paragraphs as HTML: plain paragraphs as <p>, bulleted or
     * numbered ones grouped into nested <ul>/<ol>.
     */
    private function textBody($body, $bulleted, $rels)
    {
        $out = '';
        // open lists: array('tag', 'level', 'items' => array(html))
        /** @var list<array{tag: string, level: int, items: list<string>}> $stack */
        $stack = array();
        $closeTo = function ($level) use (&$stack, &$out) {
            while (count($stack) && $stack[count($stack) - 1]['level'] > $level) {
                $done = array_pop($stack);
                $items = '';
                foreach ($done['items'] as $item) {
                    $items .= '<li>' . $item . '</li>';
                }
                $html = '<' . $done['tag'] . '>' . $items . '</' . $done['tag'] . '>';
                if (count($stack)) {
                    $last = count($stack[count($stack) - 1]['items']) - 1;
                    $stack[count($stack) - 1]['items'][$last] .= $html;
                } else {
                    $out .= $html . "\n";
                }
            }
        };
        foreach (self::elementChildren($body) as $paragraph) {
            if ($paragraph->nodeName !== 'a:p' || $this->skipNodes->contains($paragraph)) {
                continue;
            }
            $inner = self::jsTrim($this->inline($paragraph, $rels));
            if (!self::hasContent($inner)) {
                continue;
            }
            $props = self::child($paragraph, 'a:pPr');
            $level = self::intOr(self::attr($props, 'lvl'), '0');
            $level = min(max($level, 0), 8);
            $tag = $bulleted ? 'ul' : '';
            if ($props && self::child($props, 'a:buNone')) {
                $tag = '';
            } elseif ($props && self::child($props, 'a:buAutoNum')) {
                $tag = 'ol';
            } elseif ($props && (self::child($props, 'a:buChar') || self::child($props, 'a:buBlip'))) {
                $tag = 'ul';
            }
            if ($tag === '') {
                $closeTo(-1);
                $out .= '<p>' . $inner . "</p>\n";
                continue;
            }
            // a deeper level than the list open now nests one level down
            if (count($stack) && $level > $stack[count($stack) - 1]['level']) {
                $level = $stack[count($stack) - 1]['level'] + 1;
            } elseif (!count($stack)) {
                $level = 0;
            }
            $closeTo($level);
            $top = count($stack) ? $stack[count($stack) - 1] : null;
            if ($top && $top['level'] === $level && $top['tag'] === $tag) {
                $stack[count($stack) - 1]['items'][] = $inner;
            } elseif ($top && $top['level'] === $level) {
                // same level, other list type: close it and open the new one
                $closeTo($level - 1);
                $stack[] = array('tag' => $tag, 'level' => $level, 'items' => array($inner));
            } else {
                $stack[] = array('tag' => $tag, 'level' => $level, 'items' => array($inner));
            }
        }
        $closeTo(-1);
        return $out;
    }

    private function table($tbl, $rels)
    {
        $props = self::child($tbl, 'a:tblPr');
        $firstRow = self::attr($props, 'firstRow') === '1' || self::attr($props, 'firstRow') === 'true';
        $rows = array();
        foreach (self::elementChildren($tbl) as $item) {
            if ($item->nodeName === 'a:tr') {
                $rows[] = $item;
            }
        }
        if (!count($rows)) {
            return '';
        }
        $html = '<table>';
        $bodyRows = $rows;
        if ($firstRow) {
            $html .= '<thead>' . $this->rowHtml($rows[0], 'th', $rels) . '</thead>';
            $bodyRows = array_slice($rows, 1);
        }
        $html .= '<tbody>';
        foreach ($bodyRows as $row) {
            $html .= $this->rowHtml($row, 'td', $rels);
        }
        $html .= '</tbody>';
        return $html . "</table>\n";
    }

    private function rowHtml($row, $cellTag, $rels)
    {
        $cells = '';
        foreach (self::elementChildren($row) as $cell) {
            if ($cell->nodeName !== 'a:tc') {
                continue;
            }
            // covered by a merged neighbour
            if (self::attr($cell, 'hMerge') === '1' || self::attr($cell, 'vMerge') === '1') {
                continue;
            }
            $attrs = '';
            $colspan = self::jsParseInt(self::attr($cell, 'gridSpan') !== '' ? self::attr($cell, 'gridSpan') : '1');
            $rowspan = self::jsParseInt(self::attr($cell, 'rowSpan') !== '' ? self::attr($cell, 'rowSpan') : '1');
            if ($colspan !== null && $colspan > 1) {
                $attrs .= ' colspan="' . min($colspan, 1000) . '"';
            }
            if ($rowspan !== null && $rowspan > 1) {
                $attrs .= ' rowspan="' . min($rowspan, 1000) . '"';
            }
            $body = self::child($cell, 'a:txBody');
            $inner = '';
            if ($body) {
                $blocks = self::jsTrim($this->textBody($body, false, $rels));
                // one plain paragraph: its text alone
                if (preg_match('/^<p>(.*)<\/p>\z/s', $blocks, $single) && strpos($blocks, '<p>', 1) === false) {
                    $inner = $single[1];
                } else {
                    $inner = $blocks;
                }
            }
            $cells .= '<' . $cellTag . $attrs . '>' . $inner . '</' . $cellTag . '>';
        }
        return '<tr>' . $cells . '</tr>';
    }

    private function picture($pic, $rels)
    {
        $blip = self::findFirst($pic, 'a:blip');
        $id = $blip ? self::attr($blip, 'r:embed') : '';
        $rel = ($id !== '' && isset($rels[$id])) ? $rels[$id] : null;
        $src = ($rel && !$rel['external'] && isset($this->images[$rel['target']])) ? $this->images[$rel['target']] : null;
        if (!$src) {
            return '';
        }
        $info = self::findFirst($pic, 'p:cNvPr');
        $alt = self::jsTrim(self::attr($info, 'descr'));
        return '<p><img src="' . self::escapeAttr($src) . '" alt="' . self::escapeAttr($alt) . "\"></p>\n";
    }

    // ---- slides ----

    /** a slide's shapes in document order, groups opened */
    private function shapes($node, &$list)
    {
        foreach (self::elementChildren($node) as $item) {
            if ($item->nodeName === 'p:grpSp') {
                $this->shapes($item, $list);
            } elseif ($item->nodeName === 'mc:AlternateContent') {
                $fallback = self::child($item, 'mc:Fallback');
                if ($fallback) {
                    $this->shapes($fallback, $list);
                }
            } elseif ($item->nodeName === 'p:sp' || $item->nodeName === 'p:pic' || $item->nodeName === 'p:graphicFrame') {
                $ph = self::findFirst(self::child($item, 'p:nvSpPr'), 'p:ph');
                $type = '';
                if ($ph) {
                    $type = self::attr($ph, 'type') !== '' ? self::attr($ph, 'type') : 'obj';
                }
                if (!isset(self::$SKIP_PLACEHOLDERS[$type])) {
                    $list[] = array('node' => $item, 'placeholder' => $type);
                }
            }
        }
    }

    private function shapeHtml($shape, $rels)
    {
        $node = $shape['node'];
        if ($node->nodeName === 'p:pic') {
            return $this->picture($node, $rels);
        }
        if ($node->nodeName === 'p:graphicFrame') {
            $tbl = self::findFirst($node, 'a:tbl');
            return $tbl ? $this->table($tbl, $rels) : '';
        }
        $body = self::child($node, 'p:txBody');
        if (!$body) {
            return '';
        }
        return $this->textBody($body, isset(self::$BULLET_PLACEHOLDERS[$shape['placeholder']]), $rels);
    }

    private function slide($doc, $rels, $number)
    {
        $spTree = self::findFirst($doc, 'p:spTree');
        if (!$spTree) {
            return '';
        }
        $shapes = array();
        $this->shapes($spTree, $shapes);
        $title = '';
        foreach ($shapes as $shape) {
            if (!isset(self::$TITLE_PLACEHOLDERS[$shape['placeholder']])) {
                continue;
            }
            $body = self::child($shape['node'], 'p:txBody');
            $lines = array();
            foreach (self::elementChildren($body) as $paragraph) {
                if ($paragraph->nodeName !== 'a:p') {
                    continue;
                }
                $inner = self::jsTrim($this->inline($paragraph, $rels));
                if (self::hasContent($inner)) {
                    $lines[] = $inner;
                }
            }
            if (count($lines)) {
                $title = implode(' ', $lines);
                break;
            }
        }
        if ($title === '') {
            foreach ($shapes as $shape) {
                if (isset(self::$TITLE_PLACEHOLDERS[$shape['placeholder']]) || $shape['node']->nodeName !== 'p:sp') {
                    continue;
                }
                $body = self::child($shape['node'], 'p:txBody');
                foreach (self::elementChildren($body) as $paragraph) {
                    if ($paragraph->nodeName !== 'a:p') {
                        continue;
                    }
                    $inner = self::jsTrim($this->inline($paragraph, $rels));
                    if (self::hasContent($inner)) {
                        $title = $inner;
                        $this->skipNodes->attach($paragraph);
                        break;
                    }
                }
                if ($title !== '') {
                    break;
                }
            }
        }
        $bodyHtml = '';
        foreach ($shapes as $shape) {
            if (isset(self::$TITLE_PLACEHOLDERS[$shape['placeholder']])) {
                continue;
            }
            $bodyHtml .= $this->shapeHtml($shape, $rels);
        }
        if ($title === '' && $bodyHtml === '') {
            return '';
        }
        if ($title === '') {
            $title = 'Slide ' . $number;
        }
        return '<h1>' . $title . "</h1>\n" . $bodyHtml;
    }

    // ---- entry point ----

    /** True when a file starts with the ZIP local file header signature. */
    public static function hasZipSignature($path)
    {
        $bytes = @file_get_contents($path, false, null, 0, 4);
        return $bytes !== false && strlen($bytes) === 4 && $bytes === "PK\x03\x04";
    }

    /**
     * Convert a .pptx file to HTML: one `<h1>` per slide, then its content.
     * @param string $path path to the uploaded file
     * @return string
     * @throws \Exception with a client-safe message
     */
    public static function convertFile($path)
    {
        if (!self::hasZipSignature($path)) {
            throw new \Exception('Uploaded file is not a valid .pptx file (missing ZIP signature).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \Exception('Uploaded file is not a valid .pptx file (unreadable ZIP).');
        }
        try {
            $presentationPath = 'ppt/presentation.xml';
            $presentation = self::readXml($zip, $presentationPath, false);
            $presentationRels = $presentation ? self::readRels($zip, $presentationPath) : array();
            $slidePaths = array();
            $list = $presentation ? self::findFirst($presentation, 'p:sldIdLst') : null;
            foreach (self::elementChildren($list) as $slideId) {
                if ($slideId->nodeName !== 'p:sldId') {
                    continue;
                }
                $id = self::attr($slideId, 'r:id');
                if (isset($presentationRels[$id]) && !$presentationRels[$id]['external']
                    && !in_array($presentationRels[$id]['target'], $slidePaths, true)) {
                    $slidePaths[] = $presentationRels[$id]['target'];
                }
            }
            if (!count($slidePaths)) {
                // no slide list to follow: the slide parts in number order
                $numbered = array();
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (is_string($name) && preg_match('/^ppt\/slides\/slide(\d+)\.xml$/', $name, $match)) {
                        $numbered[] = array('name' => $name, 'number' => (int) $match[1]);
                    }
                }
                usort($numbered, function ($a, $b) {
                    return $a['number'] <=> $b['number'];
                });
                foreach ($numbered as $entry) {
                    $slidePaths[] = $entry['name'];
                }
            }
            if (count($slidePaths) > self::MAX_SLIDES) {
                throw new \Exception('The .pptx file has too many slides to import.');
            }
            $converter = new PptxToHtml($zip);
            $html = '';
            $number = 0;
            foreach ($slidePaths as $slidePath) {
                $number++;
                $doc = self::readXml($zip, $slidePath, false);
                if (!$doc) {
                    continue;
                }
                $rels = self::readRels($zip, $slidePath);
                $spTree = self::findFirst($doc, 'p:spTree');
                if ($spTree) {
                    $converter->loadSlideImages($spTree, $rels);
                }
                $html .= $converter->slide($doc, $rels, $number);
            }
            return self::jsTrim($html);
        } finally {
            $zip->close();
        }
    }
}
