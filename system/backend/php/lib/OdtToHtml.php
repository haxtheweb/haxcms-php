<?php
/**
 * OpenDocument Text (.odt) to semantic HTML.
 *
 * Reads content.xml (and styles.xml for named styles) out of the ODT zip and
 * walks the ODF tree into plain HTML the shared import hierarchy builder
 * understands: text:h -> h1..h6, text:p -> p, text:list -> ul/ol,
 * table:table -> table, text:a -> a, bold/italic/underline/strike/super/sub
 * text styles -> strong/em/del/sup/sub (underline -> em, strike -> del, the
 * same map the .docx importer uses), embedded Pictures/* -> data URI <img>.
 *
 * The output is built only from tags and attributes this class writes, with
 * every text node and attribute value escaped, so it is trusted markup (like
 * the PDF importer's). Content is still sanitized when it is stored
 * (createNode / addPage run MaterializeInlineImages then the storage
 * sanitizer).
 *
 * Parity: a port of haxcms-nodejs src/lib/odtToHtml.js. The walk, skip
 * lists, limits and output strings are kept identical (including JavaScript's
 * trim() and parseInt() behavior) so both backends produce the same HTML for
 * the same document.
 */
class OdtToHtml
{
    const ODT_MIMETYPE = 'application/vnd.oasis.opendocument.text';
    // Security (SEC-UPLOAD): the zip directory states each entry's
    // uncompressed size; refuse to inflate past these, so a small upload
    // can't expand into a huge string (zip bomb).
    const MAX_XML_BYTES = 20971520;
    const MAX_IMAGE_BYTES = 10485760;
    const MAX_TOTAL_IMAGE_BYTES = 52428800;
    // table:number-columns-repeated / rows-repeated can claim thousands of
    // empty cells; render at most this many copies of one cell or row.
    const MAX_REPEAT = 100;
    // text:s c="n" (n spaces)
    const MAX_SPACES = 100;
    // parent-style-name chains
    const MAX_STYLE_DEPTH = 10;

    private static $IMAGE_MIME = array(
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    );
    // generated or out-of-flow structures: tables of contents and indexes
    // repeat the headings, notes and annotations are not body text
    private static $SKIP_BLOCKS = array(
        'text:table-of-content' => true,
        'text:alphabetical-index' => true,
        'text:illustration-index' => true,
        'text:table-index' => true,
        'text:object-index' => true,
        'text:user-index' => true,
        'text:bibliography' => true,
        'text:tracked-changes' => true,
        'text:sequence-decls' => true,
        'text:variable-decls' => true,
        'text:user-field-decls' => true,
        'text:dde-connection-decls' => true,
        'office:forms' => true,
        'office:annotation' => true,
        'office:annotation-end' => true,
        'text:soft-page-break' => true,
        'table:table-columns' => true,
        'table:table-column' => true,
        'table:table-column-group' => true,
        'table:table-header-columns' => true,
    );
    private static $SKIP_INLINE = array(
        'text:note' => true,
        'office:annotation' => true,
        'office:annotation-end' => true,
        'text:bookmark' => true,
        'text:bookmark-start' => true,
        'text:bookmark-end' => true,
        'text:reference-mark' => true,
        'text:reference-mark-start' => true,
        'text:reference-mark-end' => true,
        'text:soft-page-break' => true,
        'text:change' => true,
        'text:change-start' => true,
        'text:change-end' => true,
        'text:toc-mark' => true,
        'text:toc-mark-start' => true,
        'text:toc-mark-end' => true,
        'text:alphabetical-index-mark' => true,
        'text:alphabetical-index-mark-start' => true,
        'text:alphabetical-index-mark-end' => true,
        'draw:custom-shape' => true,
        'draw:rect' => true,
        'draw:line' => true,
        'draw:ellipse' => true,
        'draw:connector' => true,
    );

    private $zip;
    private $styles;
    private $imageBytes = 0;
    private $images = array();

    private function __construct($zip, $styles)
    {
        $this->zip = $zip;
        $this->styles = $styles;
    }

    // ---- JavaScript-compatible helpers (parity with odtToHtml.js) ----

    /** String.prototype.trim(): JavaScript's whitespace set */
    public static function jsTrim($value)
    {
        $ws = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
        $trimmed = preg_replace('/^' . $ws . '+|' . $ws . '+$/u', '', (string) $value);
        return $trimmed === null ? trim((string) $value) : $trimmed;
    }

    /** parseInt(value, 10): leading integer, or null for NaN */
    private static function jsParseInt($value)
    {
        if (preg_match('/^\s*([+-]?\d+)/', (string) $value, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** Math.min(Math.max(parseInt(value || fallback) || 1, 1), max) */
    private static function countAttr($value, $max)
    {
        $parsed = self::jsParseInt($value !== '' ? $value : '1');
        if (!$parsed) {
            $parsed = 1;
        }
        return min(max($parsed, 1), $max);
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

    private static function findChild($node, $name)
    {
        foreach (self::elementChildren($node) as $child) {
            if ($child->nodeName === $name) {
                return $child;
            }
        }
        return null;
    }

    /** first element named $name below $node, in document order */
    private static function findFirst($node, $name)
    {
        foreach (self::elementChildren($node) as $child) {
            if ($child->nodeName === $name) {
                return $child;
            }
            $found = self::findFirst($child, $name);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    /** every element named $name below $node, in document order */
    private static function findAll($node, $name, &$found)
    {
        foreach (self::elementChildren($node) as $child) {
            if ($child->nodeName === $name) {
                $found[] = $child;
            }
            self::findAll($child, $name, $found);
        }
    }

    private static function attr($node, $name)
    {
        return ($node && $node->nodeType === XML_ELEMENT_NODE && $node->hasAttribute($name))
            ? (string) $node->getAttribute($name)
            : '';
    }

    // ---- styles ----

    private static function collectStyles($container, &$styles)
    {
        foreach (self::elementChildren($container) as $child) {
            if ($child->nodeName === 'style:style') {
                $name = self::attr($child, 'style:name');
                if ($name === '') {
                    continue;
                }
                $props = self::findChild($child, 'style:text-properties');
                $outline = self::jsParseInt(self::attr($child, 'style:default-outline-level'));
                $styles['text'][$name] = array(
                    'parent' => self::attr($child, 'style:parent-style-name'),
                    'format' => $props ? self::formatFromProps($props) : array(),
                    'outline' => $outline ? $outline : 0,
                );
            } elseif ($child->nodeName === 'text:list-style') {
                $name = self::attr($child, 'style:name');
                if ($name === '') {
                    continue;
                }
                $levels = array();
                foreach (self::elementChildren($child) as $level) {
                    $depth = self::jsParseInt(self::attr($level, 'text:level') !== '' ? self::attr($level, 'text:level') : '1');
                    if ($depth === null) {
                        continue;
                    }
                    if ($level->nodeName === 'text:list-level-style-number') {
                        $levels[$depth] = self::attr($level, 'style:num-format') !== '' ? 'ol' : 'ul';
                    } elseif ($level->nodeName === 'text:list-level-style-bullet' || $level->nodeName === 'text:list-level-style-image') {
                        $levels[$depth] = 'ul';
                    }
                }
                $styles['lists'][$name] = $levels;
            }
        }
    }

    private static function formatFromProps($props)
    {
        $format = array();
        $weight = self::attr($props, 'fo:font-weight');
        $weightNumber = self::jsParseInt($weight);
        if ($weight === 'bold' || ($weightNumber !== null && $weightNumber >= 600)) {
            $format['bold'] = true;
        } elseif ($weight !== '') {
            $format['bold'] = false;
        }
        $fontStyle = self::attr($props, 'fo:font-style');
        if ($fontStyle === 'italic' || $fontStyle === 'oblique') {
            $format['italic'] = true;
        } elseif ($fontStyle !== '') {
            $format['italic'] = false;
        }
        $underline = self::attr($props, 'style:text-underline-style');
        if ($underline !== '') {
            $format['underline'] = $underline !== 'none';
        }
        $strike = self::attr($props, 'style:text-line-through-style');
        if ($strike !== '') {
            $format['strike'] = $strike !== 'none';
        }
        $position = self::jsTrim(self::attr($props, 'style:text-position'));
        if ($position !== '') {
            $parts = preg_split('/\s+/', $position);
            $first = $parts[0];
            if ($first === 'super' || (preg_match('/^\d/', $first) && floatval($first) > 0)) {
                $format['position'] = 'sup';
            } elseif ($first === 'sub' || preg_match('/^-\d/', $first)) {
                $format['position'] = 'sub';
            } else {
                $format['position'] = '';
            }
        }
        return $format;
    }

    /** a style's format with its parent chain folded in (child wins) */
    private function resolveFormat($name)
    {
        $chain = array();
        $current = $name;
        $depth = 0;
        while ($current !== '' && isset($this->styles['text'][$current]) && $depth < self::MAX_STYLE_DEPTH) {
            array_unshift($chain, $this->styles['text'][$current]['format']);
            $current = $this->styles['text'][$current]['parent'];
            $depth++;
        }
        $format = array();
        foreach ($chain as $part) {
            $format = array_merge($format, $part);
        }
        return $format;
    }

    /**
     * The heading level a paragraph style gives (its or a parent's
     * default-outline-level, or the standard "Heading N" style name), else 0.
     */
    private function resolveOutline($name)
    {
        $current = $name;
        $depth = 0;
        while ($current !== '' && $depth < self::MAX_STYLE_DEPTH) {
            $style = isset($this->styles['text'][$current]) ? $this->styles['text'][$current] : null;
            if ($style && $style['outline'] > 0) {
                return $style['outline'];
            }
            if (preg_match('/^Heading_20_([1-6])$/', $current, $m)) {
                return (int) $m[1];
            }
            $current = $style ? $style['parent'] : '';
            $depth++;
        }
        return 0;
    }

    private static function wrapFormat($html, $format)
    {
        if ($html === '') {
            return $html;
        }
        $out = $html;
        $position = isset($format['position']) ? $format['position'] : '';
        if ($position === 'sup') {
            $out = '<sup>' . $out . '</sup>';
        } elseif ($position === 'sub') {
            $out = '<sub>' . $out . '</sub>';
        }
        if (!empty($format['strike'])) {
            $out = '<del>' . $out . '</del>';
        }
        if (!empty($format['italic']) || !empty($format['underline'])) {
            $out = '<em>' . $out . '</em>';
        }
        if (!empty($format['bold'])) {
            $out = '<strong>' . $out . '</strong>';
        }
        return $out;
    }

    /** only the format keys that change from $outer to $inner */
    private static function diffFormat($outer, $inner)
    {
        $diff = array();
        foreach (array('bold', 'italic', 'underline', 'strike') as $key) {
            if (!empty($inner[$key]) && empty($outer[$key])) {
                $diff[$key] = true;
            }
        }
        $innerPosition = isset($inner['position']) ? $inner['position'] : '';
        $outerPosition = isset($outer['position']) ? $outer['position'] : null;
        if ($innerPosition !== '' && $innerPosition !== $outerPosition) {
            $diff['position'] = $innerPosition;
        }
        return $diff;
    }

    private static function safeHref($href)
    {
        $value = self::jsTrim($href);
        if (preg_match('/^(https?:|mailto:)/i', $value) || strpos($value, '#') === 0) {
            return $value;
        }
        return '';
    }

    /** text, or an image, once the tags are taken out */
    private static function hasContent($html)
    {
        if (preg_match('/<img\s/i', $html)) {
            return true;
        }
        $text = str_replace(array('&nbsp;', "\xC2\xA0"), ' ', self::textOutsideTags($html));
        return self::jsTrim($text) !== '';
    }

    /**
     * The text between tags, for the emptiness check only (never output):
     * a "<" with no ">" after it is kept as text. Mirrors textOutsideTags
     * in odtToHtml.js.
     */
    private static function textOutsideTags($html)
    {
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
        return $text;
    }

    // ---- images ----

    private function loadImages($root)
    {
        $paths = array();
        $images = array();
        self::findAll($root, 'draw:image', $images);
        foreach ($images as $image) {
            $href = self::attr($image, 'xlink:href');
            if ($href !== '' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
                $paths[] = preg_replace('/^\.\//', '', $href);
            }
        }
        foreach ($paths as $path) {
            if (array_key_exists($path, $this->images)) {
                continue;
            }
            $this->images[$path] = null;
            $parts = explode('.', $path);
            $ext = strtolower(end($parts));
            $stat = $this->zip->statName($path);
            if (!isset(self::$IMAGE_MIME[$ext]) || $stat === false) {
                continue;
            }
            $size = (int) $stat['size'];
            if ($size > self::MAX_IMAGE_BYTES || $this->imageBytes + $size > self::MAX_TOTAL_IMAGE_BYTES) {
                continue;
            }
            $data = $this->zip->getFromName($path);
            if ($data === false) {
                continue;
            }
            $this->imageBytes += strlen($data);
            $this->images[$path] = 'data:' . self::$IMAGE_MIME[$ext] . ';base64,' . base64_encode($data);
        }
    }

    private function imageHtml($frame, $image)
    {
        $href = self::attr($image, 'xlink:href');
        $src = '';
        if (preg_match('/^https?:\/\//i', $href)) {
            $src = $href;
        } else {
            $key = preg_replace('/^\.\//', '', $href);
            $src = isset($this->images[$key]) && $this->images[$key] ? $this->images[$key] : '';
        }
        if ($src === '') {
            return '';
        }
        $alt = '';
        $title = self::findChild($frame, 'svg:title');
        $desc = self::findChild($frame, 'svg:desc');
        if ($title && self::jsTrim($title->textContent) !== '') {
            $alt = self::jsTrim($title->textContent);
        } elseif ($desc && self::jsTrim($desc->textContent) !== '') {
            $alt = self::jsTrim($desc->textContent);
        }
        return '<img src="' . self::escapeAttr($src) . '" alt="' . self::escapeAttr($alt) . '">';
    }

    private function frameHtml($frame)
    {
        foreach (self::elementChildren($frame) as $child) {
            if ($child->nodeName === 'draw:image') {
                $html = $this->imageHtml($frame, $child);
                if ($html !== '') {
                    return $html;
                }
            } elseif ($child->nodeName === 'draw:text-box') {
                $parts = array();
                foreach (self::elementChildren($child) as $box) {
                    $inner = self::jsTrim($this->inline($box, array()));
                    if ($inner !== '') {
                        $parts[] = $inner;
                    }
                }
                if (count($parts)) {
                    return implode('<br>', $parts);
                }
            }
        }
        return '';
    }

    // ---- inline ----

    private function inline($node, $format)
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= self::escapeText(preg_replace('/[ \t\r\n]+/', ' ', $child->nodeValue));
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE || isset(self::$SKIP_INLINE[$child->nodeName])) {
                continue;
            }
            switch ($child->nodeName) {
                case 'text:s':
                    $out .= str_repeat(' ', self::countAttr(self::attr($child, 'text:c'), self::MAX_SPACES));
                    break;
                case 'text:tab':
                    $out .= ' ';
                    break;
                case 'text:line-break':
                    $out .= '<br>';
                    break;
                case 'text:span':
                    $spanFormat = array_merge($format, $this->resolveFormat(self::attr($child, 'text:style-name')));
                    $out .= self::wrapFormat($this->inline($child, $spanFormat), self::diffFormat($format, $spanFormat));
                    break;
                case 'text:a':
                case 'draw:a':
                    $href = self::safeHref(self::attr($child, 'xlink:href'));
                    $inner = $this->inline($child, $format);
                    $out .= $href !== '' ? '<a href="' . self::escapeAttr($href) . '">' . $inner . '</a>' : $inner;
                    break;
                case 'draw:frame':
                    $out .= $this->frameHtml($child);
                    break;
                case 'text:p':
                case 'text:h':
                    $inner = self::jsTrim($this->inline($child, $format));
                    if ($inner !== '') {
                        $out .= ($out !== '' ? '<br>' : '') . $inner;
                    }
                    break;
                default:
                    // fields (dates, page numbers, sequences) and unknown
                    // wrappers: keep their text
                    $out .= $this->inline($child, $format);
                    break;
            }
        }
        return $out;
    }

    private function paragraphInline($node)
    {
        $base = $this->resolveFormat(self::attr($node, 'text:style-name'));
        return self::wrapFormat(self::jsTrim($this->inline($node, $base)), $base);
    }

    // ---- blocks ----

    private function blocks($node)
    {
        // rendered blocks; a list stays as its parts until the next block,
        // so a following list that continues it can join it
        $out = array();
        foreach (self::elementChildren($node) as $child) {
            if ($child->nodeName === 'text:list' && !self::containsListHeading($child)) {
                $parts = $this->listParts($child, '', 1);
                $lastIndex = count($out) - 1;
                if ($lastIndex >= 0 && is_array($out[$lastIndex]) && self::joinsList($child, $parts, $out[$lastIndex])) {
                    self::joinListParts($out[$lastIndex], $parts);
                } elseif (count($parts['items'])) {
                    $out[] = $parts;
                }
                continue;
            }
            $html = $this->block($child);
            if ($html !== '') {
                $out[] = $html;
            }
        }
        $html = '';
        foreach ($out as $entry) {
            $html .= is_array($entry) ? self::renderList($entry) : $entry;
        }
        return $html;
    }

    private function block($node)
    {
        if (isset(self::$SKIP_BLOCKS[$node->nodeName])) {
            return '';
        }
        switch ($node->nodeName) {
            case 'text:h':
                $levelAttr = self::attr($node, 'text:outline-level');
                $level = self::jsParseInt($levelAttr !== '' ? $levelAttr : '1');
                if ($level === null || $level < 1) {
                    $level = 1;
                }
                return $this->heading($node, $level);
            case 'text:p':
                $outline = $this->resolveOutline(self::attr($node, 'text:style-name'));
                if ($outline > 0) {
                    return $this->heading($node, $outline);
                }
                $inner = $this->paragraphInline($node);
                return self::hasContent($inner) ? '<p>' . $inner . "</p>\n" : '';
            case 'text:list':
                return self::containsListHeading($node)
                    ? $this->flatList($node)
                    : self::renderList($this->listParts($node, '', 1));
            case 'table:table':
                return $this->table($node);
            case 'draw:frame':
                $inner = $this->frameHtml($node);
                return self::hasContent($inner) ? '<p>' . $inner . "</p>\n" : '';
            default:
                // text:section, office:text and other containers
                return $this->blocks($node);
        }
    }

    private function heading($node, $level)
    {
        $inner = self::jsTrim($this->inline($node, array()));
        if (!self::hasContent($inner)) {
            return '';
        }
        $tag = 'h' . min($level, 6);
        return '<' . $tag . '>' . $inner . '</' . $tag . ">\n";
    }

    /** numbered headings written as a list: keep them as headings */
    private function flatList($node)
    {
        $flat = '';
        foreach (self::elementChildren($node) as $item) {
            if ($item->nodeName !== 'text:list-item' && $item->nodeName !== 'text:list-header') {
                continue;
            }
            foreach (self::elementChildren($item) as $child) {
                $flat .= $child->nodeName === 'text:list' ? $this->flatList($child) : $this->block($child);
            }
        }
        return $flat;
    }

    /**
     * A list as array(tag, items => array(array(html, wrapper))). A wrapper
     * item holds only nested lists (no text of its own), the shape
     * Word-origin documents use to carry a nested list in a list of its own.
     */
    private function listParts($node, $styleName, $depth)
    {
        $ownStyle = self::attr($node, 'text:style-name');
        if ($ownStyle === '') {
            $ownStyle = $styleName;
        }
        $levels = isset($this->styles['lists'][$ownStyle]) ? $this->styles['lists'][$ownStyle] : array();
        $parts = array(
            'tag' => (isset($levels[$depth]) && $levels[$depth] === 'ol') ? 'ol' : 'ul',
            'items' => array(),
        );
        foreach (self::elementChildren($node) as $item) {
            if ($item->nodeName !== 'text:list-item' && $item->nodeName !== 'text:list-header') {
                continue;
            }
            $children = array();
            foreach (self::elementChildren($item) as $child) {
                if (!isset(self::$SKIP_BLOCKS[$child->nodeName])) {
                    $children[] = $child;
                }
            }
            $paragraphs = 0;
            $others = 0;
            foreach ($children as $child) {
                if ($child->nodeName === 'text:p') {
                    $paragraphs++;
                } elseif ($child->nodeName !== 'text:list') {
                    $others++;
                }
            }
            $inline = $paragraphs === 1 && $others === 0;
            $html = '';
            foreach ($children as $child) {
                if ($child->nodeName === 'text:list') {
                    $html .= self::renderList($this->listParts($child, $ownStyle, $depth + 1));
                } else {
                    $html .= $inline ? $this->paragraphInline($child) : $this->block($child);
                }
            }
            $html = self::jsTrim($html);
            if (self::hasContent($html)) {
                $parts['items'][] = array(
                    'html' => $html,
                    'wrapper' => $paragraphs === 0 && $others === 0,
                );
            }
        }
        return $parts;
    }

    private static function renderList($parts)
    {
        if (!count($parts['items'])) {
            return '';
        }
        $items = '';
        foreach ($parts['items'] as $item) {
            $items .= '<li>' . $item['html'] . '</li>';
        }
        return '<' . $parts['tag'] . '>' . $items . '</' . $parts['tag'] . ">\n";
    }

    /**
     * Whether a list continues the list rendered just before it: it opens
     * with a wrapper item (a nested level split out on its own), or ODF marks
     * it as continuing (text:continue-list / text:continue-numbering) with
     * the same list type.
     */
    private static function joinsList($node, $parts, $previous)
    {
        if (!count($parts['items']) || !count($previous['items'])) {
            return false;
        }
        if ($parts['items'][0]['wrapper']) {
            return true;
        }
        return (self::attr($node, 'text:continue-list') !== ''
            || self::attr($node, 'text:continue-numbering') === 'true')
            && $parts['tag'] === $previous['tag'];
    }

    /** fold $parts into $previous: wrapper items nest under its last item */
    private static function joinListParts(&$previous, $parts)
    {
        foreach ($parts['items'] as $item) {
            if ($item['wrapper'] && count($previous['items'])) {
                $previous['items'][count($previous['items']) - 1]['html'] .= $item['html'];
            } else {
                $previous['items'][] = $item;
            }
        }
    }

    private static function containsListHeading($list)
    {
        foreach (self::elementChildren($list) as $item) {
            foreach (self::elementChildren($item) as $child) {
                if ($child->nodeName === 'text:h') {
                    return true;
                }
                if ($child->nodeName === 'text:list' && self::containsListHeading($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function table($node)
    {
        $head = array();
        $body = array();
        $this->collectRows($node, $body, $head);
        $html = '<table>';
        if (count($head)) {
            $html .= '<thead>';
            foreach ($head as $row) {
                $html .= $this->rowHtml($row, 'th');
            }
            $html .= '</thead>';
        }
        $html .= '<tbody>';
        foreach ($body as $row) {
            $html .= $this->rowHtml($row, 'td');
        }
        $html .= '</tbody>';
        $html .= "</table>\n";
        return (count($head) || count($body)) ? $html : '';
    }

    private function collectRows($container, &$target, &$head)
    {
        foreach (self::elementChildren($container) as $child) {
            if ($child->nodeName === 'table:table-row') {
                $repeat = self::countAttr(self::attr($child, 'table:number-rows-repeated'), self::MAX_REPEAT);
                for ($i = 0; $i < $repeat; $i++) {
                    $target[] = $child;
                }
            } elseif ($child->nodeName === 'table:table-header-rows') {
                $this->collectRows($child, $head, $head);
            } elseif ($child->nodeName === 'table:table-rows' || $child->nodeName === 'table:table-row-group') {
                $this->collectRows($child, $target, $head);
            }
        }
    }

    private function rowHtml($row, $cellTag)
    {
        $cells = '';
        foreach (self::elementChildren($row) as $cell) {
            if ($cell->nodeName !== 'table:table-cell') {
                continue;
            }
            $attrs = '';
            $colspanAttr = self::attr($cell, 'table:number-columns-spanned');
            $rowspanAttr = self::attr($cell, 'table:number-rows-spanned');
            $colspan = self::jsParseInt($colspanAttr !== '' ? $colspanAttr : '1');
            $rowspan = self::jsParseInt($rowspanAttr !== '' ? $rowspanAttr : '1');
            if ($colspan !== null && $colspan > 1) {
                $attrs .= ' colspan="' . min($colspan, 1000) . '"';
            }
            if ($rowspan !== null && $rowspan > 1) {
                $attrs .= ' rowspan="' . min($rowspan, 1000) . '"';
            }
            $children = array();
            foreach (self::elementChildren($cell) as $child) {
                if (!isset(self::$SKIP_BLOCKS[$child->nodeName])) {
                    $children[] = $child;
                }
            }
            if (count($children) === 1 && $children[0]->nodeName === 'text:p') {
                $inner = $this->paragraphInline($children[0]);
            } else {
                $inner = self::jsTrim($this->blocks($cell));
            }
            $repeat = self::countAttr(self::attr($cell, 'table:number-columns-repeated'), self::MAX_REPEAT);
            for ($i = 0; $i < $repeat; $i++) {
                $cells .= '<' . $cellTag . $attrs . '>' . $inner . '</' . $cellTag . '>';
            }
        }
        return '<tr>' . $cells . '</tr>';
    }

    // ---- entry points ----

    /** True when a file starts with the ZIP local file header signature. */
    public static function hasZipSignature($path)
    {
        $bytes = @file_get_contents($path, false, null, 0, 4);
        return $bytes !== false && strlen($bytes) === 4 && $bytes === "PK\x03\x04";
    }

    private static function readXml($zip, $path, $required)
    {
        $stat = $zip->statName($path);
        if ($stat === false) {
            if ($required) {
                throw new \Exception('The .odt file has no ' . $path . '.');
            }
            return null;
        }
        if ((int) $stat['size'] > self::MAX_XML_BYTES) {
            throw new \Exception('The .odt file\'s ' . $path . ' is too large to import.');
        }
        $xml = $zip->getFromName($path);
        if ($xml === false || strlen($xml) > self::MAX_XML_BYTES) {
            throw new \Exception('The .odt file\'s ' . $path . ' is too large to import.');
        }
        // Security: no DTD / entity declarations (billion laughs); ODF never needs them
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \Exception('The .odt file\'s ' . $path . ' is not valid OpenDocument XML.');
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        // lenient like the Node parser (htmlparser2), so a slightly broken
        // file still imports the same way on both backends
        $dom->recover = true;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \Exception('The .odt file\'s ' . $path . ' is not valid OpenDocument XML.');
        }
        return $dom;
    }

    /**
     * Convert an .odt file to HTML.
     * @param string $path path to the uploaded file
     * @return string
     * @throws \Exception with a client-safe message
     */
    public static function convertFile($path)
    {
        if (!self::hasZipSignature($path)) {
            throw new \Exception('Uploaded file is not a valid .odt file (missing ZIP signature).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \Exception('Uploaded file is not a valid .odt file (unreadable ZIP).');
        }
        try {
            $mimeStat = $zip->statName('mimetype');
            if ($mimeStat !== false && (int) $mimeStat['size'] <= 256) {
                $mime = self::jsTrim((string) $zip->getFromName('mimetype'));
                // .odt, plus Writer's web / master / template variants, all
                // keep their body in office:text
                if ($mime !== self::ODT_MIMETYPE && strpos($mime, self::ODT_MIMETYPE . '-') !== 0) {
                    throw new \Exception('Uploaded file is not an OpenDocument text file (' . ($mime !== '' ? $mime : 'unknown type') . ').');
                }
            }
            $content = self::readXml($zip, 'content.xml', true);
            $stylesDoc = self::readXml($zip, 'styles.xml', false);
            $styles = array('text' => array(), 'lists' => array());
            if ($stylesDoc) {
                $named = self::findFirst($stylesDoc, 'office:styles');
                if ($named) {
                    self::collectStyles($named, $styles);
                }
                $auto = self::findFirst($stylesDoc, 'office:automatic-styles');
                if ($auto) {
                    self::collectStyles($auto, $styles);
                }
            }
            $contentAuto = self::findFirst($content, 'office:automatic-styles');
            if ($contentAuto) {
                self::collectStyles($contentAuto, $styles);
            }
            $body = self::findFirst($content, 'office:text');
            if (!$body) {
                throw new \Exception('The .odt file has no text body.');
            }
            $converter = new OdtToHtml($zip, $styles);
            $converter->loadImages($body);
            return self::jsTrim($converter->blocks($body));
        } finally {
            $zip->close();
        }
    }
}
