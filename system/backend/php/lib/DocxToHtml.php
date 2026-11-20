<?php
/**
 * DOCX to HTML, matching haxcms-nodejs.
 *
 * Node converts a .docx with mammoth (styleMap "u => em", "strike => del"),
 * then runs processDocxHtml and stripMSWord (src/lib/convertUtils.js) over
 * the result. elephant-php is a PHP port of mammoth that gives the same HTML,
 * and this class ports the two clean-up passes line by line so both backends
 * hand the same markup to the page splitter.
 *
 * Security: every "opener ... closer" pattern is scanned in linear time
 * (stripDelimitedSpans / lazySequenceReplace) instead of a backtracking lazy
 * regex, so many openers without a closer cannot stall a request.
 */

use EndlessCreativity\ElephantPhp\Converter;

class DocxToHtml
{
    const STYLE_MAP = array('u => em', 'strike => del');
    /** JS `\s` as a PCRE class (needs the /u modifier). */
    const JS_SPACE = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
    /** JS `.` (anything but a line terminator), byte safe without /u. */
    const JS_DOT = '(?:(?!\xE2\x80[\xA8\xA9])[^\n\r])';

    /**
     * Convert a .docx file to cleaned HTML, like Node's import/convert routes.
     */
    public static function convertFile($path)
    {
        self::loadConverter();
        $converter = new Converter(styleMap: self::STYLE_MAP);
        $html = $converter->convertToHtml($path)->value;
        $html = self::processDocxHtml($html);
        return self::stripMSWord($html);
    }

    private static function loadConverter()
    {
        if (class_exists('EndlessCreativity\\ElephantPhp\\Converter')) {
            return;
        }
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /** node-html-parser's void elements. */
    const VOID_TAGS = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');

    /**
     * Split HTML into its top-level elements the way haxcms-nodejs sees them
     * through node-html-parser: each entry has tagName (upper case), html
     * (the element's outerHTML: raw text, tags rebuilt as `<name attrs>`, the
     * closing slash of a void or self-closed tag dropped) and text (decoded
     * text with <br> as a newline). DOMDocument is not used because it
     * re-serializes attributes and closes nested anchors, which Node keeps.
     *
     * @return array<int, array{tagName: string, html: string, text: string}>
     */
    public static function topLevelElements($html)
    {
        $html = (string) $html;
        $pattern = '/<!--[\s\S]*?-->|<(\/?)([a-zA-Z][-.:0-9_a-zA-Z]*)((?:\s+[^>]*?(?:\'[^\']*\'|"[^"]*")?)*)\s*(\/?)>/';
        if (!preg_match_all($pattern, $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return array();
        }
        $elements = array();
        $stack = array();
        /** @var array{tagName: string, html: string, text: string}|null $current */
        $current = null;
        $pos = 0;
        $append = function ($outer, $text) use (&$current) {
            if ($current !== null) {
                $current['html'] .= $outer;
                $current['text'] .= $text;
            }
        };
        foreach ($tags as $tag) {
            $offset = $tag[0][1];
            $raw = $tag[0][0];
            $between = substr($html, $pos, $offset - $pos);
            $append($between, html_entity_decode($between, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $pos = $offset + strlen($raw);
            if (strncmp($raw, '<!--', 4) === 0) {
                $append($raw, '');
                continue;
            }
            $closing = $tag[1][0] === '/';
            $name = $tag[2][0];
            $lower = strtolower($name);
            if ($closing) {
                $at = array_search($lower, array_reverse($stack, true), true);
                if ($at === false) {
                    continue;
                }
                $append('</' . $name . '>', '');
                $stack = array_slice($stack, 0, $at);
                if (count($stack) === 0 && $current !== null) {
                    $elements[] = $current;
                    $current = null;
                }
                continue;
            }
            $rawAttrs = (string) substr($tag[3][0], 1);
            $open = '<' . $name . ($rawAttrs !== '' ? ' ' . $rawAttrs : '') . '>';
            $selfClosed = $tag[4][0] === '/' || in_array($lower, self::VOID_TAGS, true);
            if (count($stack) === 0) {
                $current = array('tagName' => strtoupper($name), 'html' => '', 'text' => '');
            }
            $append($open, $lower === 'br' ? "\n" : '');
            if ($selfClosed) {
                if (count($stack) === 0) {
                    $elements[] = $current;
                    $current = null;
                }
                continue;
            }
            $stack[] = $lower;
        }
        if ($current !== null) {
            $tail = substr($html, $pos);
            $append($tail, html_entity_decode($tail, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach (array_reverse($stack) as $openName) {
                $current['html'] .= '</' . $openName . '>';
            }
            $elements[] = $current;
        }
        return $elements;
    }

    /**
     * Node's processDocxHtml: run each top-level element through htmlFromEl
     * (haxcmsSystemHtmlFromEl, defined by the docx route file in use;
     * importDocx.php and convertDocxToHtml.php keep identical copies).
     */
    public static function processDocxHtml($html)
    {
        $content = '';
        foreach (self::topLevelElements($html) as $el) {
            $content .= haxcmsSystemHtmlFromEl(self::jsTrim($el['text']), $el['html']);
        }
        return $content !== '' ? $content : $html;
    }

    private static function jsTrim($value)
    {
        if (!class_exists('OdtToHtml')) {
            require_once __DIR__ . '/OdtToHtml.php';
        }
        return OdtToHtml::jsTrim($value);
    }

    /**
     * Remove every non-overlapping `open ... close` span (shortest match).
     */
    public static function stripDelimitedSpans($input, $open, $close)
    {
        $result = '';
        $pos = 0;
        $len = strlen($input);
        while ($pos < $len) {
            $start = strpos($input, $open, $pos);
            if ($start === false) {
                break;
            }
            $end = strpos($input, $close, $start + strlen($open));
            if ($end === false) {
                break;
            }
            $result .= substr($input, $pos, $start - $pos);
            $pos = $end + strlen($close);
        }
        return $result . substr($input, $pos);
    }

    /**
     * Linear-time equivalent of a global lazy regex OPEN gap TOKEN gap TOKEN...
     * (see lazySequenceReplace in haxcms-nodejs src/lib/convertUtils.js).
     *
     * @param string $input
     * @param string $openRe PCRE pattern for the opener
     * @param array $steps list of array('re' => pattern, 'lineBounded' => bool)
     * @param callable $replacer function ($input, $spans): string
     */
    public static function lazySequenceReplace($input, $openRe, $steps, $replacer)
    {
        $len = strlen($input);
        $inf = PHP_INT_MAX;
        $cache = array_fill(0, count($steps), null);
        $terminatorCache = null;
        $cachedFor = function ($entry, $from) {
            return $entry !== null && $entry['from'] <= $from && $entry['start'] >= $from;
        };
        $nextOccurrence = function ($i, $from) use (&$cache, $steps, $input, $inf, $cachedFor) {
            if ($cachedFor($cache[$i], $from)) {
                return $cache[$i];
            }
            if (preg_match($steps[$i]['re'], $input, $m, PREG_OFFSET_CAPTURE, $from)) {
                $cache[$i] = array('from' => $from, 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0]));
            } else {
                $cache[$i] = array('from' => $from, 'start' => $inf, 'end' => $inf);
            }
            return $cache[$i];
        };
        $nextTerminator = function ($from) use (&$terminatorCache, $input, $inf, $cachedFor) {
            if ($cachedFor($terminatorCache, $from)) {
                return $terminatorCache['start'];
            }
            $start = $inf;
            if (preg_match("/[\n\r]|\xE2\x80[\xA8\xA9]/", $input, $m, PREG_OFFSET_CAPTURE, $from)) {
                $start = $m[0][1];
            }
            $terminatorCache = array('from' => $from, 'start' => $start);
            return $start;
        };
        $result = '';
        $copied = 0;
        $searchFrom = 0;
        while ($searchFrom <= $len) {
            if (!preg_match($openRe, $input, $open, PREG_OFFSET_CAPTURE, $searchFrom)) {
                break;
            }
            $openStart = $open[0][1];
            $spans = array(array('start' => $openStart, 'end' => $openStart + strlen($open[0][0])));
            $cur = $spans[0]['end'];
            $exhausted = false;
            $failed = false;
            foreach ($steps as $i => $step) {
                $hit = $nextOccurrence($i, $cur);
                if ($hit['start'] === $inf) {
                    $exhausted = true;
                    break;
                }
                if (!empty($step['lineBounded']) && $nextTerminator($cur) < $hit['start']) {
                    $failed = true;
                    break;
                }
                $spans[] = $hit;
                $cur = $hit['end'];
            }
            if ($exhausted) {
                break;
            }
            if ($failed) {
                $searchFrom = $openStart + 1;
                continue;
            }
            $result .= substr($input, $copied, $openStart - $copied) . $replacer($input, $spans);
            $copied = $cur;
            $searchFrom = $cur;
        }
        return $result . substr($input, $copied);
    }

    /**
     * Strip Microsoft Word generated HTML artifacts (Node's stripMSWord).
     */
    public static function stripMSWord($input)
    {
        if (!is_string($input)) {
            return '';
        }
        $dropMatch = function () {
            return '';
        };
        $keepQuote = function () {
            return '"';
        };
        $output = str_replace("\n\r", "\n", $input);
        $output = str_replace("\r", "\n", $output);
        $output = str_replace("\n\n", "\n", $output);
        $output = str_replace("\n\n", "\n", $output);
        $output = str_replace("\n\n", "\n", $output);
        $output = str_replace("\n", ' ', $output);
        $output = preg_replace('/( class=")?Mso[a-zA-Z]+(")?/', '', $output);
        $output = self::stripDelimitedSpans($output, '<!--', '-->');
        $output = self::stripDelimitedSpans($output, '<!?', '>');
        $output = self::lazySequenceReplace(
            $output,
            '/<(\/)*(meta|link|title|html|head|body|font|br|\\\\\\\\?xml:|xml|st1:|o:|w:|m:|v:)/i',
            array(array('re' => '/>/')),
            $dropMatch
        );
        $output = self::lazySequenceReplace(
            $output,
            '/<span/i',
            array(array('re' => '/>/'), array('re' => '/<\/span>/i')),
            function ($str, $spans) {
                return substr($str, $spans[1]['end'], $spans[2]['start'] - $spans[1]['end']);
            }
        );
        $badTags = array('style', 'script', 'applet', 'embed', 'noframes', 'noscript');
        foreach ($badTags as $tag) {
            $output = self::lazySequenceReplace(
                $output,
                '/<' . $tag . '/i',
                array(
                    array('re' => '/' . $tag . '/i', 'lineBounded' => true),
                    array('re' => '/>/', 'lineBounded' => true),
                ),
                $dropMatch
            );
        }
        $output = preg_replace("/ style='[^']*'/", '', $output);
        $output = preg_replace('/ style="[^"]*"/', '', $output);
        $output = preg_replace('/ face="[^"]*"/', '', $output);
        $output = preg_replace('/ align=' . self::JS_DOT . '*? /', '', $output);
        $output = preg_replace("/ start='" . self::JS_DOT . "*?'/", '', $output);
        $output = self::lazySequenceReplace($output, '/line-height:/', array(array('re' => '/"/', 'lineBounded' => true)), $keepQuote);
        $output = self::lazySequenceReplace($output, '/line-height:/', array(array('re' => '/;/', 'lineBounded' => true)), $dropMatch);
        $output = str_replace('font-weight:normal;', '', $output);
        $output = str_replace('text-decoration:none;', '', $output);
        $output = self::lazySequenceReplace(
            $output,
            '/margin-/',
            array(array('re' => '/:/', 'lineBounded' => true), array('re' => '/"/', 'lineBounded' => true)),
            $keepQuote
        );
        $output = self::lazySequenceReplace(
            $output,
            '/margin-/',
            array(array('re' => '/:/', 'lineBounded' => true), array('re' => '/;/', 'lineBounded' => true)),
            $dropMatch
        );
        $output = str_replace(' style=""', '', $output);
        $output = preg_replace('/ id="[^"]*"/i', '', $output);
        $output = preg_replace('/ dir="[^"]*"/i', '', $output);
        $output = preg_replace('/ role="[^"]*"/i', '', $output);
        $output = preg_replace('/ contenteditable="[^"]*"/i', '', $output);
        $output = self::lazySequenceReplace($output, '/ data-/i', array(array('re' => '/"/'), array('re' => '/"/')), $dropMatch);
        $output = preg_replace('/ class="[^"]*"/i', '', $output);
        $plain = array(
            array('<pstyle', '<p style'),
            array('<a name="_GoBack"></a>', ''),
            array('&nbsp;', ' '),
            array('<section>', '<p>'),
            array('</section>', '</p>'),
            array('<p><p>', '<p>'),
            array('<p><p>', '<p>'),
            array('</p></p>', '</p>'),
            array('</p></p>', '</p>'),
            array('<br />', '<br/>'),
            array('<p><br /><b>', '<p><b>'),
            array('</p><br /></b>', '</p></b>'),
            array('<b><p>', '<p>'),
            array('</p></b>', '</p>'),
            array('<b>', '<strong>'),
            array('</b>', '</strong>'),
        );
        foreach ($plain as $pair) {
            $output = str_replace($pair[0], $pair[1], $output);
        }
        foreach (array('p', 'ul', 'ol', 'li', 'td', 'tr') as $tag) {
            $output = self::lazySequenceReplace(
                $output,
                '/<' . $tag . ' style="/',
                array(array('re' => '/">/', 'lineBounded' => true)),
                function () use ($tag) {
                    return '<' . $tag . '>';
                }
            );
        }
        $plain = array(
            array('<li><p>', '<li>'),
            array('</p></li>', '</li>'),
            array('<b><ul>', '<ul>'),
            array('</ul></b>', '</ul>'),
            array('<b><ol>', '<ol>'),
            array('</ol></b>', '</ol>'),
            array('<span><p>', '<p>'),
            array('</p></span>', '</p>'),
        );
        foreach ($plain as $pair) {
            $output = str_replace($pair[0], $pair[1], $output);
        }
        $output = preg_replace('/<p>(' . self::JS_SPACE . '*)<\/p>/u', ' ', $output);
        $output = str_replace('<p></p>', '', $output);
        $output = str_replace('<p>&nbsp;</p>', ' ', $output);
        $output = str_replace('<p><br/></p>', '', $output);
        $output = str_replace('<p><br></p>', '', $output);
        $output = preg_replace('/<\/p>(' . self::JS_SPACE . '*)<p>/u', '</p><p>', $output);
        $output = preg_replace('/ data-hax-ray="[^"]*"/i', '', $output);
        $output = preg_replace('/ class=""/i', '', $output);
        $output = preg_replace('/ class="hax-active"/i', '', $output);
        $output = preg_replace('/ contenteditable="[^"]*"/i', '', $output);
        $output = preg_replace('/ t="[^"]*"/i', '', $output);
        foreach ($badTags as $tag) {
            $output = preg_replace('/<' . $tag . '><\/' . $tag . '>/i', '', $output);
        }
        return self::jsTrim($output);
    }
}
