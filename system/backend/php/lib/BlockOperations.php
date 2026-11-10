<?php
include_once dirname(__FILE__) . '/SanitizeContent.php';

/**
 * Block operations: change one element in a page's stored HTML without
 * re-saving the page (haxtheweb/issues#3270).
 *
 * Port of haxcms-nodejs src/lib/blockOperations.js. Same rules, codes and
 * bytes; tests/fixtures/block-operations-vectors.json runs in both.
 *
 * The stored page is a string. scanElements() walks it once and records each
 * element's exact byte span; nothing is re-serialized, so every byte outside a
 * changed element stays as it was stored. (Offsets here are bytes; in Node
 * they are UTF-16 units. Delimiters are ASCII, so the spans cover the same
 * characters and the spliced strings are identical.)
 *
 * A target is { tag, index, before }; see the Node file for the full
 * description of setAttributes / replaceBlock, the before guard, the per-tag
 * allowlist (haxcms-block-attributes.json), the change-only sanitizer check
 * and the structural check.
 */
class BlockOperations
{
    const MAX_OPS = 200;
    const MAX_TEXT_LENGTH = 4000;
    const MAX_URL_LENGTH = 2048;
    const MAX_BLOCK_LENGTH = 200000;

    // String.prototype.trim's set, plus the control/space set refused in URLs
    const TRIM_CLASS = '\t\n\x{000b}\f\r \x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    private static $VOID = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');
    private static $RAW_TEXT = array('script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript');
    private static $CLOSES_P = array('address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul');
    private static $PHRASING = array('span', 'a', 'em', 'strong', 'b', 'i', 'u', 'small', 'code', 'sub', 'sup', 'mark');
    private static $IMPLIED_BY = array(
        'li' => array('li'),
        'dt' => array('dt', 'dd'),
        'dd' => array('dt', 'dd'),
        'tr' => array('tr', 'tbody', 'thead', 'tfoot'),
        'td' => array('td', 'th', 'tr', 'tbody', 'thead', 'tfoot'),
        'th' => array('td', 'th', 'tr', 'tbody', 'thead', 'tfoot'),
        'option' => array('option', 'optgroup'),
        'thead' => array('tbody', 'tfoot'),
        'tbody' => array('tbody', 'tfoot'),
    );
    private static $NEVER_TARGET = array('page-break', 'script', 'style', 'template');
    private static $CONTEXT = array(
        'td' => array('<table><tbody><tr>', '</tr></tbody></table>'),
        'th' => array('<table><tbody><tr>', '</tr></tbody></table>'),
        'tr' => array('<table><tbody>', '</tbody></table>'),
        'tbody' => array('<table>', '</table>'),
        'thead' => array('<table>', '</table>'),
        'tfoot' => array('<table>', '</table>'),
        'caption' => array('<table>', '</table>'),
        'colgroup' => array('<table>', '</table>'),
        'col' => array('<table><colgroup>', '</colgroup></table>'),
        'li' => array('<ul>', '</ul>'),
        'dt' => array('<dl>', '</dl>'),
        'dd' => array('<dl>', '</dl>'),
        'option' => array('<select>', '</select>'),
        'source' => array('<video>', '</video>'),
        'track' => array('<video>', '</video>'),
        'area' => array('<map name="m">', '</map>'),
    );
    private static $allowlist = null;

    public static function allowlist()
    {
        if (self::$allowlist === null) {
            $raw = @file_get_contents(dirname(__FILE__) . '/haxcms-block-attributes.json');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            self::$allowlist = is_array($decoded) ? $decoded : array('tags' => array());
        }
        return self::$allowlist;
    }

    private static function isWhitespace($c)
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f";
    }

    private static function isNameStart($c)
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
    }

    private static function charAt($s, $i)
    {
        return $i < strlen($s) ? $s[$i] : '';
    }

    private static function asciiLower($value)
    {
        return strtolower((string) $value);
    }

    private static function fail($code, $message, $extra = array())
    {
        return array_merge(array('ok' => false, 'code' => $code, 'message' => $message), $extra);
    }

    /**
     * Attributes of a start tag from $i (just past the tag name).
     */
    public static function scanAttributes($html, $i)
    {
        $attrs = array();
        $n = strlen($html);
        while ($i < $n) {
            while ($i < $n && (self::isWhitespace($html[$i]) || $html[$i] === '/')) {
                if ($html[$i] === '/' && self::charAt($html, $i + 1) === '>') {
                    return array('attrs' => $attrs, 'end' => $i + 2, 'selfClosing' => true);
                }
                $i++;
            }
            if ($i >= $n) {
                break;
            }
            if ($html[$i] === '>') {
                return array('attrs' => $attrs, 'end' => $i + 1, 'selfClosing' => false);
            }
            $start = $i;
            $i++;
            while ($i < $n && !self::isWhitespace($html[$i]) && $html[$i] !== '/' && $html[$i] !== '>' && $html[$i] !== '=') {
                $i++;
            }
            $name = self::asciiLower(substr($html, $start, $i - $start));
            $j = $i;
            while ($j < $n && self::isWhitespace($html[$j])) {
                $j++;
            }
            if (self::charAt($html, $j) === '=') {
                $j++;
                while ($j < $n && self::isWhitespace($html[$j])) {
                    $j++;
                }
                $q = self::charAt($html, $j);
                if ($q === '"' || $q === "'") {
                    $close = strpos($html, $q, $j + 1);
                    if ($close === false) {
                        return array('attrs' => $attrs, 'end' => -1, 'selfClosing' => false);
                    }
                    $attrs[] = array('name' => $name, 'start' => $start, 'end' => $close + 1, 'valueStart' => $j + 1, 'valueEnd' => $close, 'quote' => $q);
                    $i = $close + 1;
                }
                else {
                    $k = $j;
                    while ($k < $n && !self::isWhitespace($html[$k]) && $html[$k] !== '>') {
                        $k++;
                    }
                    $attrs[] = array('name' => $name, 'start' => $start, 'end' => $k, 'valueStart' => $j, 'valueEnd' => $k, 'quote' => '');
                    $i = $k;
                }
            }
            else {
                $attrs[] = array('name' => $name, 'start' => $start, 'end' => $i, 'valueStart' => -1, 'valueEnd' => -1, 'quote' => '');
            }
        }
        return array('attrs' => $attrs, 'end' => -1, 'selfClosing' => false);
    }

    /**
     * Every element with its byte spans, in document order.
     */
    public static function scanElements($html)
    {
        $out = array();
        $stack = array();
        $perTag = array();
        $n = strlen($html);
        $i = 0;
        while ($i < $n) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                break;
            }
            $i = $lt;
            if (substr($html, $i, 4) === '<!--') {
                $close = strpos($html, '-->', $i + 4);
                $i = $close === false ? $n : $close + 3;
                continue;
            }
            $next = self::charAt($html, $i + 1);
            if ($next === '!' || $next === '?') {
                $close = strpos($html, '>', $i + 2);
                $i = $close === false ? $n : $close + 1;
                continue;
            }
            if ($next === '/') {
                if (!self::isNameStart(self::charAt($html, $i + 2))) {
                    $close = strpos($html, '>', $i + 2);
                    $i = $close === false ? $n : $close + 1;
                    continue;
                }
                $j = $i + 2;
                while ($j < $n && !self::isWhitespace($html[$j]) && $html[$j] !== '/' && $html[$j] !== '>') {
                    $j++;
                }
                $name = self::asciiLower(substr($html, $i + 2, $j - $i - 2));
                $close = strpos($html, '>', $j);
                $after = $close === false ? $n : $close + 1;
                $k = count($stack) - 1;
                while ($k >= 0 && $out[$stack[$k]]['tag'] !== $name) {
                    $k--;
                }
                if ($k >= 0) {
                    while (count($stack) - 1 > $k) {
                        $idx = array_pop($stack);
                        $out[$idx]['end'] = $i;
                        $out[$idx]['explicit'] = false;
                    }
                    $idx = array_pop($stack);
                    $out[$idx]['end'] = $after;
                    $out[$idx]['explicit'] = $close !== false;
                }
                $i = $after;
                continue;
            }
            if (!self::isNameStart($next)) {
                $i++;
                continue;
            }
            $j = $i + 1;
            while ($j < $n && !self::isWhitespace($html[$j]) && $html[$j] !== '/' && $html[$j] !== '>') {
                $j++;
            }
            $tag = self::asciiLower(substr($html, $i + 1, $j - $i - 1));
            $parsed = self::scanAttributes($html, $j);
            if ($parsed['end'] === -1) {
                break;
            }
            if (count($stack)) {
                $top = $out[$stack[count($stack) - 1]];
                if (isset(self::$IMPLIED_BY[$top['tag']]) && in_array($tag, self::$IMPLIED_BY[$top['tag']], true)) {
                    $idx = array_pop($stack);
                    $out[$idx]['end'] = $i;
                    $out[$idx]['explicit'] = false;
                }
            }
            if (in_array($tag, self::$CLOSES_P, true)) {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($out[$stack[$k]]['tag'] === 'p') {
                        while (count($stack) - 1 >= $k) {
                            $idx = array_pop($stack);
                            $out[$idx]['end'] = $i;
                            $out[$idx]['explicit'] = false;
                        }
                        break;
                    }
                    if (!in_array($out[$stack[$k]]['tag'], self::$PHRASING, true)) {
                        break;
                    }
                }
            }
            if (!isset($perTag[$tag])) {
                $perTag[$tag] = 0;
            }
            $el = array(
                'tag' => $tag,
                'start' => $i,
                'openEnd' => $parsed['end'],
                'end' => -1,
                'explicit' => false,
                'index' => $perTag[$tag]++,
                'depth' => count($stack),
                'attrs' => $parsed['attrs'],
            );
            $out[] = $el;
            $elIndex = count($out) - 1;
            $i = $parsed['end'];
            // a custom element written <x-y /> is an open tag, as browsers parse it
            if (in_array($tag, self::$VOID, true)) {
                $out[$elIndex]['end'] = $parsed['end'];
                $out[$elIndex]['explicit'] = true;
                continue;
            }
            if (in_array($tag, self::$RAW_TEXT, true)) {
                $close = -1;
                if (preg_match('/<\/' . preg_quote($tag, '/') . '(?=[\t\n\f\r \/>])/i', $html, $m, PREG_OFFSET_CAPTURE, $i)) {
                    $gt = strpos($html, '>', $m[0][1]);
                    $close = $gt === false ? -1 : $gt;
                }
                if ($close === -1) {
                    $out[$elIndex]['end'] = -1;
                    $i = $n;
                    continue;
                }
                $out[$elIndex]['end'] = $close + 1;
                $out[$elIndex]['explicit'] = true;
                $i = $close + 1;
                continue;
            }
            $stack[] = $elIndex;
        }
        while (count($stack)) {
            $idx = array_pop($stack);
            $out[$idx]['end'] = -1;
            $out[$idx]['explicit'] = false;
        }
        return $out;
    }

    public static function escapeAttr($value)
    {
        return str_replace(array('&', '"', '<', '>'), array('&amp;', '&quot;', '&lt;', '&gt;'), (string) $value);
    }

    private static function allowedAttributes($tag)
    {
        $list = self::allowlist();
        return isset($list['tags'][$tag]) && is_array($list['tags'][$tag]) ? $list['tags'][$tag] : null;
    }

    private static function isAllowedUrl($value)
    {
        if ($value === '' || strpos($value, '//') === 0 || strpos($value, '\\') !== false) {
            return $value === '';
        }
        if (preg_match('/^https?:/i', $value)) {
            // ASCII host name or bracketed IPv6, optional port, no user info
            return preg_match('/^https?:\/\/(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::[0-9]{1,5})?(?:[\/?#].*)?$/is', $value) === 1;
        }
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) !== 1;
    }

    /**
     * Normalize a value by its allowlist kind.
     */
    public static function normalizeValue($kind, $raw, $name)
    {
        if ($raw === null) {
            return array('ok' => true, 'value' => null);
        }
        if (!is_string($raw)) {
            return self::fail('invalid', $name . ' must be a string or null');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return self::fail('invalid', $name . ' cannot contain control characters');
        }
        $value = preg_replace("/\r\n?/", "\n", $raw);
        $value = preg_replace('/^[' . self::TRIM_CLASS . ']+|[' . self::TRIM_CLASS . ']+$/u', '', $value);
        if (!is_string($value)) {
            return self::fail('invalid', $name . ' cannot contain control characters');
        }
        // DOMPurify (haxcms-nodejs) drops an attribute holding one of these;
        // refuse them in both backends. ASCII case only (no u flag).
        if (preg_match('/(--!?|\])>|<\/(style|script|title|xmp|textarea|noscript|iframe|noembed|noframes)/i', $value)) {
            return self::fail('invalid', $name . ' cannot contain "-->", "]>" or a closing tag such as "</title"');
        }
        if ($kind === 'url') {
            if (preg_match('/[' . self::TRIM_CLASS . '\x{0000}-\x{001f}\x{007f}]/u', $value)) {
                return self::fail('invalid', $name . ' cannot contain spaces or control characters');
            }
            if (!self::isAllowedUrl($value)) {
                return self::fail('invalid', $name . ' must be an http(s) URL or a site path');
            }
            // stored as sent (haxtheweb/issues#3273)
            if (mb_strlen($value, 'UTF-8') > self::MAX_URL_LENGTH) {
                return self::fail('invalid', $name . ' is longer than ' . self::MAX_URL_LENGTH . ' characters');
            }
            return array('ok' => true, 'value' => $value);
        }
        if (mb_strlen($value, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            return self::fail('invalid', $name . ' is longer than ' . self::MAX_TEXT_LENGTH . ' characters');
        }
        if (preg_match('/[\x{0000}-\x{0008}\x{000b}\x{000c}\x{000e}-\x{001f}\x{007f}]/u', $value)) {
            return self::fail('invalid', $name . ' cannot contain control characters');
        }
        return array('ok' => true, 'value' => $value);
    }

    private static function isIndex($value)
    {
        if (is_int($value)) {
            return $value >= 0;
        }
        return is_float($value) && floor($value) === $value && $value >= 0;
    }

    private static function resolveTarget($html, $scan, $op)
    {
        $tag = isset($op['tag']) && is_string($op['tag']) ? self::asciiLower($op['tag']) : '';
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $tag)) {
            return self::fail('invalid', 'tag must be an element name');
        }
        if (in_array($tag, self::$NEVER_TARGET, true)) {
            return self::fail('not-allowed', '<' . $tag . '> cannot be changed with block operations');
        }
        if (!isset($op['before']) || !is_string($op['before']) || $op['before'] === '') {
            return self::fail('invalid', 'before is required');
        }
        $candidates = array();
        foreach ($scan as $e) {
            if ($e['tag'] === $tag) {
                $candidates[] = $e;
            }
        }
        $at = null;
        if (isset($op['index']) && self::isIndex($op['index']) && isset($candidates[(int) $op['index']])) {
            $at = $candidates[(int) $op['index']];
        }
        if ($at !== null && $at['explicit'] && $at['end'] !== -1 && substr($html, $at['start'], $at['end'] - $at['start']) === $op['before']) {
            return array('ok' => true, 'el' => $at, 'relocated' => false);
        }
        $same = array();
        foreach ($candidates as $e) {
            if ($e['explicit'] && $e['end'] !== -1 && substr($html, $e['start'], $e['end'] - $e['start']) === $op['before']) {
                $same[] = $e;
            }
        }
        if (count($same) === 1) {
            return array('ok' => true, 'el' => $same[0], 'relocated' => true);
        }
        if (count($same) > 1) {
            return self::fail('ambiguous', 'more than one matching element and index does not point at one');
        }
        if ($at !== null && (!$at['explicit'] || $at['end'] === -1)
            && substr($html, $at['start'], $at['openEnd'] - $at['start']) === substr($op['before'], 0, $at['openEnd'] - $at['start'])) {
            return self::fail('not-addressable', 'this element has no exact end in the stored page');
        }
        return self::fail('stale', 'the stored element no longer matches before');
    }

    private static function buildReplacement($html, $el, $op)
    {
        $allowed = self::allowedAttributes($el['tag']);
        if ($allowed === null) {
            return self::fail('not-allowed', '<' . $el['tag'] . '> is not in the block allowlist');
        }
        $source = substr($html, $el['start'], $el['end'] - $el['start']);
        $kind = isset($op['op']) ? $op['op'] : '';
        if ($kind === 'setAttributes') {
            if (!isset($op['set']) || !is_array($op['set']) || (count($op['set']) > 0 && array_keys($op['set']) === range(0, count($op['set']) - 1))) {
                return self::fail('invalid', 'set must be an object of attribute names');
            }
            if (!count($op['set'])) {
                return self::fail('invalid', 'set must name at least one attribute');
            }
            $set = array();
            foreach ($op['set'] as $rawName => $rawValue) {
                $name = self::asciiLower($rawName);
                if (!preg_match('/^[a-z_:][a-z0-9_.:-]*$/', $name) || preg_match('/^(on|srcdoc$|style$|formaction$)/i', $name)) {
                    return self::fail('not-allowed', 'attribute "' . $name . '" cannot be set');
                }
                if (!array_key_exists($name, $allowed)) {
                    return self::fail('not-allowed', 'attribute "' . $name . '" is not in the allowlist for <' . $el['tag'] . '>');
                }
                if (array_key_exists($name, $set)) {
                    return self::fail('invalid', 'attribute "' . $name . '" is named twice');
                }
                $norm = self::normalizeValue($allowed[$name], $rawValue, $name);
                if (!$norm['ok']) {
                    return $norm;
                }
                $set[$name] = $norm['value'];
            }
            $edits = array();
            $seen = array();
            foreach ($el['attrs'] as $a) {
                if (!array_key_exists($a['name'], $set)) {
                    continue;
                }
                if (isset($seen[$a['name']])) {
                    return self::fail('not-addressable', 'attribute "' . $a['name'] . '" appears twice on this element');
                }
                $seen[$a['name']] = true;
                $v = $set[$a['name']];
                if ($v === null) {
                    $from = $a['start'];
                    while ($from > $el['start'] && self::isWhitespace($html[$from - 1])) {
                        $from--;
                    }
                    $edits[] = array($from - $el['start'], $a['end'] - $el['start'], '');
                }
                else {
                    $edits[] = array($a['start'] - $el['start'], $a['end'] - $el['start'], $a['name'] . '="' . self::escapeAttr($v) . '"');
                }
            }
            $insertAt = 1 + strlen($el['tag']);
            $added = '';
            foreach ($set as $name => $v) {
                if (!isset($seen[$name]) && $v !== null) {
                    $added .= ' ' . $name . '="' . self::escapeAttr($v) . '"';
                }
            }
            if ($added !== '') {
                $edits[] = array($insertAt, $insertAt, $added);
            }
            usort($edits, function ($x, $y) {
                return $y[0] - $x[0];
            });
            $next = $source;
            foreach ($edits as $edit) {
                $next = substr($next, 0, $edit[0]) . $edit[2] . substr($next, $edit[1]);
            }
            return array('ok' => true, 'after' => $next, 'set' => $set);
        }
        if ($kind === 'replaceBlock') {
            if (!isset($op['after']) || !is_string($op['after']) || $op['after'] === '') {
                return self::fail('invalid', 'after is required');
            }
            if (mb_strlen($op['after'], 'UTF-8') > self::MAX_BLOCK_LENGTH) {
                return self::fail('invalid', 'after is longer than ' . self::MAX_BLOCK_LENGTH . ' characters');
            }
            $inner = self::scanElements($op['after']);
            $top = array();
            foreach ($inner as $e) {
                if ($e['depth'] === 0) {
                    $top[] = $e;
                }
            }
            if (count($top) !== 1 || $top[0]['start'] !== 0 || $top[0]['end'] !== strlen($op['after']) || !$top[0]['explicit'] || $top[0]['tag'] !== $el['tag']) {
                return self::fail('invalid', 'after must be exactly one complete <' . $el['tag'] . '> element');
            }
            foreach ($inner as $e) {
                if ($e['tag'] === 'page-break') {
                    return self::fail('invalid', 'after cannot contain a page-break');
                }
            }
            return array('ok' => true, 'after' => $op['after'], 'set' => null);
        }
        return self::fail('invalid', 'op must be setAttributes or replaceBlock');
    }

    /**
     * Splice a batch of ops into one page. All or nothing.
     */
    public static function applyBlockOps($html, $ops)
    {
        if (!is_string($html)) {
            return self::fail('invalid', 'the page has no stored content');
        }
        if (!is_array($ops) || !count($ops)) {
            return self::fail('invalid', 'ops must be a non-empty array');
        }
        if (count($ops) > self::MAX_OPS) {
            return self::fail('invalid', 'at most ' . self::MAX_OPS . ' ops per page');
        }
        $ops = array_values($ops);
        $scan = self::scanElements($html);
        $resolved = array();
        foreach ($ops as $i => $op) {
            if (!is_array($op) || (count($op) > 0 && array_keys($op) === range(0, count($op) - 1))) {
                return self::fail('invalid', 'each op must be an object', array('op' => $i));
            }
            $target = self::resolveTarget($html, $scan, $op);
            if (!$target['ok']) {
                $target['op'] = $i;
                return $target;
            }
            $rep = self::buildReplacement($html, $target['el'], $op);
            if (!$rep['ok']) {
                $rep['op'] = $i;
                return $rep;
            }
            $resolved[] = array('i' => $i, 'el' => $target['el'], 'after' => $rep['after'], 'set' => $rep['set'], 'relocated' => $target['relocated']);
        }
        $byStart = $resolved;
        usort($byStart, function ($x, $y) {
            return $x['el']['start'] - $y['el']['start'];
        });
        for ($k = 1; $k < count($byStart); $k++) {
            if ($byStart[$k]['el']['start'] < $byStart[$k - 1]['el']['end']) {
                return self::fail('overlap', 'two ops target the same element or one inside another', array('op' => $byStart[$k]['i']));
            }
        }
        $content = $html;
        for ($k = count($byStart) - 1; $k >= 0; $k--) {
            $r = $byStart[$k];
            $content = substr($content, 0, $r['el']['start']) . $r['after'] . substr($content, $r['el']['end']);
        }
        $results = array_fill(0, count($ops), null);
        $shift = 0;
        foreach ($byStart as $r) {
            $start = $r['el']['start'] + $shift;
            $results[$r['i']] = array(
                'tag' => $r['el']['tag'],
                'index' => $r['el']['index'],
                'start' => $start,
                'end' => $start + strlen($r['after']),
                'relocated' => $r['relocated'],
                'set' => $r['set'],
            );
            $shift += strlen($r['after']) - ($r['el']['end'] - $r['el']['start']);
        }
        return array('ok' => true, 'content' => $content, 'results' => $results);
    }

    // ---- checks with a real parser --------------------------------------

    private static function nodeName($node)
    {
        return strtolower((string) $node->nodeName);
    }

    private static function attributeMap($node)
    {
        $map = array();
        if ($node->attributes) {
            foreach ($node->attributes as $attr) {
                $map[strtolower($attr->nodeName)] = $attr->nodeValue;
            }
        }
        return $map;
    }

    private static function getAttr($node, $name)
    {
        $map = self::attributeMap($node);
        return array_key_exists($name, $map) ? $map[$name] : null;
    }

    private static function sameChildren($a, $b)
    {
        $x = $a->childNodes;
        $y = $b->childNodes;
        if ($x->length !== $y->length) {
            return false;
        }
        for ($i = 0; $i < $x->length; $i++) {
            if (!self::sameNode($x->item($i), $y->item($i))) {
                return false;
            }
        }
        return true;
    }

    private static function sameNode($a, $b)
    {
        if ($a->nodeType !== $b->nodeType) {
            return false;
        }
        if ($a->nodeType === XML_TEXT_NODE || $a->nodeType === XML_COMMENT_NODE || $a->nodeType === XML_CDATA_SECTION_NODE) {
            return $a->nodeValue === $b->nodeValue;
        }
        if ($a->nodeType !== XML_ELEMENT_NODE) {
            return true;
        }
        if (self::nodeName($a) !== self::nodeName($b) || self::attributeMap($a) != self::attributeMap($b)) {
            return false;
        }
        return self::sameChildren($a, $b);
    }

    private static function diffs($a, $b, &$out, $limit)
    {
        if (count($out) > $limit) {
            return;
        }
        if ($a->nodeType !== $b->nodeType || ($a->nodeType === XML_ELEMENT_NODE && self::nodeName($a) !== self::nodeName($b))) {
            $out[] = array('a' => $a, 'b' => $b, 'kind' => 'shape');
            return;
        }
        if ($a->nodeType === XML_TEXT_NODE || $a->nodeType === XML_COMMENT_NODE || $a->nodeType === XML_CDATA_SECTION_NODE) {
            if ($a->nodeValue !== $b->nodeValue) {
                $out[] = array('a' => $a->parentNode, 'b' => $b->parentNode, 'kind' => 'text');
            }
            return;
        }
        if ($a->nodeType !== XML_ELEMENT_NODE && $a->nodeType !== XML_DOCUMENT_FRAG_NODE) {
            return;
        }
        $attrsDiffer = $a->nodeType === XML_ELEMENT_NODE && self::attributeMap($a) != self::attributeMap($b);
        if ($a->childNodes->length !== $b->childNodes->length) {
            $out[] = array('a' => $a, 'b' => $b, 'kind' => 'children');
            return;
        }
        if ($attrsDiffer) {
            $out[] = array('a' => $a, 'b' => $b, 'kind' => 'attrs');
            // keep walking: each difference below must be explained on its
            // own (libxml nests a following HTML5 void sibling such as
            // <track> inside <source>)
            if (self::sameChildren($a, $b)) {
                return;
            }
        }
        for ($i = 0; $i < $a->childNodes->length; $i++) {
            self::diffs($a->childNodes->item($i), $b->childNodes->item($i), $out, $limit);
        }
    }

    private static function insideAny($node, $roots)
    {
        for ($n = $node; $n !== null; $n = $n->parentNode) {
            foreach ($roots as $root) {
                if ($n->isSameNode($root)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function storedForm($value)
    {
        return preg_replace("/\r\n?/", "\n", (string) $value);
    }

    /**
     * The only differences between the page before and after must be the
     * targets (see the Node file).
     */
    public static function verifyStructure($beforeHtml, $afterHtml, $ops, $normalized)
    {
        $A = SanitizeContent::parseBodyForComparison($beforeHtml);
        $B = SanitizeContent::parseBodyForComparison($afterHtml);
        if ($A === null || $B === null) {
            return self::fail('structure-changed', 'the page could not be parsed to check the change');
        }
        // never decide on a partial walk
        $limit = count($ops) * 4 + 64;
        $found = array();
        self::diffs($A, $B, $found, $limit);
        if (count($found) > $limit) {
            return self::fail('structure-changed', 'the change would alter more than the target elements');
        }
        $used = array();
        // elements a replaceBlock op accounts for; everything inside them is its
        $replaced = array();
        foreach ($found as $d) {
            if (!$d['a'] || !$d['b'] || $d['a']->nodeType !== XML_ELEMENT_NODE || $d['b']->nodeType !== XML_ELEMENT_NODE) {
                return self::fail('structure-changed', 'the change would alter more than the target elements');
            }
            // (a text change directly inside reports the element itself)
            if (self::insideAny($d['b'], $replaced)) {
                continue;
            }
            $matched = -1;
            foreach ($ops as $i => $op) {
                if (isset($used[$i]) || self::asciiLower(isset($op['tag']) ? $op['tag'] : '') !== self::nodeName($d['b'])) {
                    continue;
                }
                if (isset($op['op']) && $op['op'] === 'setAttributes') {
                    if ($d['kind'] !== 'attrs') {
                        continue;
                    }
                    $set = isset($normalized[$i]) && is_array($normalized[$i]) ? $normalized[$i] : array();
                    $mapA = self::attributeMap($d['a']);
                    $mapB = self::attributeMap($d['b']);
                    $ok = true;
                    foreach (array_unique(array_merge(array_keys($mapA), array_keys($mapB))) as $name) {
                        $va = array_key_exists($name, $mapA) ? $mapA[$name] : null;
                        $vb = array_key_exists($name, $mapB) ? $mapB[$name] : null;
                        if ($va !== $vb && !array_key_exists($name, $set)) {
                            $ok = false;
                        }
                    }
                    foreach ($set as $name => $value) {
                        $want = $value === null ? null : self::storedForm($value);
                        $have = array_key_exists($name, $mapB) ? $mapB[$name] : null;
                        if ($have !== $want) {
                            $ok = false;
                        }
                    }
                    if ($ok) {
                        $matched = $i;
                        break;
                    }
                }
                elseif (self::nodeName($d['a']) === self::nodeName($d['b'])) {
                    $matched = $i;
                    break;
                }
            }
            if ($matched === -1) {
                return self::fail('structure-changed', 'the change would alter more than the target elements');
            }
            $used[$matched] = true;
            if (isset($ops[$matched]['op']) && $ops[$matched]['op'] === 'replaceBlock') {
                $replaced[] = $d['b'];
            }
        }
        return array('ok' => true);
    }

    private static function firstElement($root, $tag)
    {
        $list = $root->getElementsByTagName($tag);
        return $list->length ? $list->item(0) : null;
    }

    /**
     * Sanitize the change, not the page.
     */
    public static function checkChangeSanitizes($op, $tag, $set, $newBlock)
    {
        $ctx = isset(self::$CONTEXT[$tag]) ? self::$CONTEXT[$tag] : array('', '');
        if (isset($op['op']) && $op['op'] === 'setAttributes') {
            $attrs = '';
            $names = array();
            foreach ($set as $name => $value) {
                if ($value !== null) {
                    $attrs .= ' ' . $name . '="' . self::escapeAttr($value) . '"';
                    $names[] = $name;
                }
            }
            if (!count($names)) {
                return array('ok' => true);
            }
            $probe = in_array($tag, self::$VOID, true) ? '<' . $tag . $attrs . '>' : '<' . $tag . $attrs . '></' . $tag . '>';
            $clean = SanitizeContent::parseBodyForComparison(SanitizeContent::sanitizeHTMLForStorage($ctx[0] . $probe . $ctx[1]));
            $el = $clean ? self::firstElement($clean, $tag) : null;
            if ($el === null) {
                return self::fail('sanitizer-rejected', 'the storage sanitizer removes <' . $tag . '>');
            }
            foreach ($names as $name) {
                if (self::getAttr($el, $name) !== self::storedForm($set[$name])) {
                    return self::fail('sanitizer-rejected', 'the storage sanitizer changes or removes "' . $name . '"');
                }
            }
            return array('ok' => true);
        }
        $raw = SanitizeContent::parseBodyForComparison($ctx[0] . $newBlock . $ctx[1]);
        $clean = SanitizeContent::parseBodyForComparison(SanitizeContent::sanitizeHTMLForStorage($ctx[0] . $newBlock . $ctx[1]));
        if ($raw === null || $clean === null || !self::sameChildren($raw, $clean)) {
            return self::fail('sanitizer-rejected', 'the storage sanitizer would change this block; send clean markup');
        }
        return array('ok' => true);
    }

    /**
     * The whole server pipeline for one page.
     */
    public static function runBlockOperations($html, $ops)
    {
        $applied = self::applyBlockOps($html, $ops);
        if (!$applied['ok']) {
            return $applied;
        }
        $ops = array_values($ops);
        foreach ($ops as $i => $op) {
            $r = $applied['results'][$i];
            $block = substr($applied['content'], $r['start'], $r['end'] - $r['start']);
            $check = self::checkChangeSanitizes($op, $r['tag'], $r['set'] ? $r['set'] : array(), $block);
            if (!$check['ok']) {
                $check['op'] = $i;
                return $check;
            }
        }
        $normalized = array();
        foreach ($applied['results'] as $r) {
            $normalized[] = $r['set'] ? $r['set'] : array();
        }
        $verified = self::verifyStructure($html, $applied['content'], $ops, $normalized);
        if (!$verified['ok']) {
            return $verified;
        }
        return $applied;
    }

    private static function decodeAttributes($tag, $startTag)
    {
        $body = SanitizeContent::parseBodyForComparison(in_array($tag, self::$VOID, true) ? $startTag : $startTag . '</' . $tag . '>');
        $el = $body ? self::firstElement($body, $tag) : null;
        return $el ? self::attributeMap($el) : array();
    }

    /**
     * Addressable allowlisted elements on a page (see the Node file).
     */
    public static function listBlocks($html, $tagFilter = '')
    {
        $filter = is_string($tagFilter) ? self::asciiLower($tagFilter) : '';
        $out = array();
        if (!is_string($html) || $html === '') {
            return $out;
        }
        foreach (self::scanElements($html) as $el) {
            if ($filter !== '' && $el['tag'] !== $filter) {
                continue;
            }
            if (!$el['explicit'] || $el['end'] === -1 || in_array($el['tag'], self::$NEVER_TARGET, true)) {
                continue;
            }
            $allowed = self::allowedAttributes($el['tag']);
            if ($allowed === null) {
                continue;
            }
            $decoded = self::decodeAttributes($el['tag'], substr($html, $el['start'], $el['openEnd'] - $el['start']));
            $attributes = array();
            foreach ($allowed as $name => $kind) {
                if (array_key_exists($name, $decoded)) {
                    $attributes[$name] = $decoded[$name];
                }
            }
            $out[] = array(
                'tag' => $el['tag'],
                'index' => $el['index'],
                'before' => substr($html, $el['start'], $el['end'] - $el['start']),
                'attributes' => (object) $attributes,
            );
        }
        return $out;
    }
}
