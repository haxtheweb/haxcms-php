<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/BlockOperations.php';

/**
 * Seeded fuzz for block operations (haxtheweb/issues#3270), the PHP side of
 * haxcms-nodejs test/unit/block-operations-fuzz.test.cjs. Generates HAX-like
 * pages with parser edge cases and applies random allowlisted edits with
 * hostile values. For every accepted edit:
 *   - nothing outside the target element changed, byte for byte
 *   - the new value reads back exactly from a parse of the new page
 *   - putting the old value back gives the old page's tree
 * Every refusal carries a documented reason; hostile replaceBlock payloads
 * are never written; a one-byte change to before is never accepted at the
 * same element.
 *
 * BLOCK_FUZZ_SEED and BLOCK_FUZZ_ITERATIONS override the defaults.
 */
class BlockOperationsFuzzTest extends TestCase
{
    private $seed = 3270;

    private function rnd()
    {
        // mulberry32, as in the Node test
        $this->seed = ($this->seed + 0x6d2b79f5) & 0xffffffff;
        $t = $this->seed;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xffffffff;
        return (($t ^ ($t >> 14)) & 0xffffffff) / 4294967296;
    }

    private static function imul($a, $b)
    {
        $lo = (($a & 0xffff) * $b) & 0xffffffff;
        $hi = (((($a >> 16) & 0xffff) * $b) & 0xffff) << 16;
        return ($lo + $hi) & 0xffffffff;
    }

    private function pick($list)
    {
        return $list[(int) floor($this->rnd() * count($list))];
    }

    private function leaf()
    {
        $leaves = array(
            '<img src="files/a.png" alt="x">',
            '<img src="files/b.png">',
            "<media-image source=\"https://media.aanda.psu.edu/m.jpg\" alt='q \"a\" > b'></media-image>",
            '<video-player source="https://youtu.be/x" media-title=unquoted></video-player>',
            '<a href="https://media.aanda.psu.edu/doc.pdf">doc</a>',
            '<!-- <img alt="comment"> -->',
            '<script type="application/json">{"a":"</div>"}</script>',
            '<textarea><img alt="raw"></textarea>',
            '<template><img alt="tpl"></template>',
            'text < 3 &amp; &nbsp;',
            '<iframe src="https://e.x" title="t"></iframe>',
            '<video controls><source src="/v.mp4" type="video/mp4"><track src="/t.vtt" kind="captions"></video>',
        );
        return $this->pick($leaves);
    }

    private function gen($depth)
    {
        if ($depth > 4 || $this->rnd() < 0.35) {
            return $this->leaf();
        }
        $t = $this->pick(array('p', 'div', 'ul', 'table', 'grid-plate', 'figure', 'details'));
        if ($t === 'ul') {
            return '<ul><li>' . $this->gen($depth + 1) . ($this->rnd() < 0.7 ? '</li>' : '') . '<li>' . $this->gen($depth + 1) . '</li></ul>';
        }
        if ($t === 'table') {
            return '<table>' . ($this->rnd() < 0.5 ? '<tbody>' : '') . '<tr><td>' . $this->gen($depth + 1) . '</td></tr></table>';
        }
        if ($t === 'p') {
            return '<p>' . $this->gen($depth + 1) . ($this->rnd() < 0.8 ? '</p>' : '');
        }
        $inner = '';
        $n = 1 + (int) floor($this->rnd() * 3);
        for ($k = 0; $k < $n; $k++) {
            $inner .= $this->gen($depth + 1);
        }
        return '<' . $t . ' class="c">' . $inner . '</' . $t . '>';
    }

    private static function treeString($node)
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return 'T' . strlen($node->nodeValue) . ':' . $node->nodeValue;
        }
        if ($node->nodeType === XML_COMMENT_NODE) {
            return 'C' . strlen($node->nodeValue) . ':' . $node->nodeValue;
        }
        $out = '';
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $attrs = array();
            foreach ($node->attributes as $a) {
                $attrs[strtolower($a->nodeName)] = $a->nodeValue;
            }
            ksort($attrs);
            $out .= '<' . strtolower($node->nodeName) . json_encode($attrs);
        }
        foreach ($node->childNodes as $child) {
            $out .= self::treeString($child);
        }
        return $out . '>';
    }

    public function testFuzz(): void
    {
        $env = getenv('BLOCK_FUZZ_SEED');
        $this->seed = $env !== false && $env !== '' ? ((int) $env) & 0xffffffff : 3270;
        $iterations = getenv('BLOCK_FUZZ_ITERATIONS');
        $iterations = $iterations !== false && $iterations !== '' ? (int) $iterations : 40;
        $reasons = array('invalid', 'not-allowed', 'not-addressable', 'stale', 'ambiguous', 'overlap', 'sanitizer-rejected', 'structure-changed');
        $text = array('A diagram of the water cycle', 'He said "hi" & left', "it's <b>bold</b> > less", '&amp; already &lt;', "line\nbreak\ttab", '  padded  ', '', "emoji \u{1F9EA} مرحبا 光合作用 İ", '"><script>alert(1)</script>', "' onerror='x", 'x]>y', str_repeat('x', 3000));
        $urls = array('https://media.psu.edu/a.mp4', 'files/b.png', '/abs/c.jpg', 'https://e.x/?a=1&b="2"', '/files/café.png', 'javascript:alert(1)', '//evil.example', 'data:x', ' https://trim.me/x ');
        $hostile = array(
            '<%1$s onerror="alert(1)"></%1$s>',
            '<%1$s><script>alert(1)</script></%1$s>',
            '<%1$s style="position:fixed"></%1$s>',
            '<%1$s><a href="javascript:alert(1)">x</a></%1$s>',
            '<%1$s><iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe></%1$s>',
        );
        $allow = BlockOperations::allowlist()['tags'];
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/block-operations-vectors.json'), true);
        $pages = array_values($vectors['pages']);
        for ($i = 0; $i < $iterations; $i++) {
            $pages[] = $this->gen(0) . $this->gen(0) . $this->gen(0) . $this->gen(0);
        }
        $accepted = 0;
        foreach ($pages as $html) {
            $blocks = BlockOperations::listBlocks($html);
            for ($k = 0; $k < min(4, count($blocks)); $k++) {
                $block = $this->pick($blocks);
                $name = $this->pick(array_keys($allow[$block['tag']]));
                $kind = $allow[$block['tag']][$name];
                $value = $this->rnd() < 0.1 ? null : $this->pick($kind === 'url' ? $urls : $text);
                $op = array('op' => 'setAttributes', 'tag' => $block['tag'], 'index' => $block['index'], 'before' => $block['before'], 'set' => array($name => $value));
                $r = BlockOperations::runBlockOperations($html, array($op));
                if (!$r['ok']) {
                    $this->assertContains($r['code'], $reasons);
                    $this->assertNotSame('structure-changed', $r['code'], $block['tag'] . ' ' . $name . ': ' . $r['message']);
                    continue;
                }
                $accepted++;
                // 1. bytes outside the element are untouched
                $same = array_values(array_filter(BlockOperations::scanElements($html), function ($e) use ($block) {
                    return $e['tag'] === $block['tag'];
                }));
                $el = $same[$block['index']];
                $span = $r['results'][0];
                $this->assertSame(substr($html, 0, $el['start']), substr($r['content'], 0, $span['start']));
                $this->assertSame(substr($html, $el['end']), substr($r['content'], $span['end']));
                // 2. the new value reads back from a parse of the new start tag
                $want = $value === null ? null : BlockOperations::normalizeValue($kind, $value, $name)['value'];
                $after = BlockOperations::listBlocks(substr($r['content'], $span['start'], $span['end'] - $span['start']), $block['tag']);
                $attrs = (array) $after[0]['attributes'];
                $this->assertSame($want, array_key_exists($name, $attrs) ? $attrs[$name] : null, $block['tag'] . ' ' . $name);
                // 3. putting the old value back gives the old tree
                $old = (array) $block['attributes'];
                $revert = BlockOperations::runBlockOperations($r['content'], array(array(
                    'op' => 'setAttributes', 'tag' => $block['tag'], 'index' => $block['index'],
                    'before' => substr($r['content'], $span['start'], $span['end'] - $span['start']),
                    'set' => array($name => array_key_exists($name, $old) ? $old[$name] : null),
                )));
                // (only when the old value is already in stored form; a legacy
                // URL with raw non-ASCII would come back percent-encoded)
                $oldValue = array_key_exists($name, $old) ? $old[$name] : null;
                $oldNorm = BlockOperations::normalizeValue($kind, $oldValue, $name);
                if ($revert['ok'] && $oldNorm['ok'] && $oldNorm['value'] === $oldValue) {
                    $this->assertSame(
                        self::treeString(SanitizeContent::parseBodyForComparison($html)),
                        self::treeString(SanitizeContent::parseBodyForComparison($revert['content'])),
                        $block['tag'] . ' ' . $name . ': page differs beyond the edit'
                    );
                }
                // identity replace leaves the page byte-identical
                $id = BlockOperations::runBlockOperations($html, array(array('op' => 'replaceBlock', 'tag' => $block['tag'], 'index' => $block['index'], 'before' => $block['before'], 'after' => $block['before'])));
                if ($id['ok']) {
                    $this->assertSame($html, $id['content']);
                }
                // hostile replacements are never written
                $payload = sprintf($this->pick($hostile), $block['tag']);
                $h = BlockOperations::runBlockOperations($html, array(array('op' => 'replaceBlock', 'tag' => $block['tag'], 'index' => $block['index'], 'before' => $block['before'], 'after' => $payload)));
                $this->assertFalse($h['ok'], 'hostile written into ' . $block['tag'] . ': ' . $payload);
                // a one-byte change to before is never accepted at that element
                $flip = (int) floor($this->rnd() * strlen($block['before']));
                $stale = $block['before'];
                $stale[$flip] = $stale[$flip] === 'x' ? 'y' : 'x';
                $op['before'] = $stale;
                $s = BlockOperations::runBlockOperations($html, array($op));
                if ($s['ok']) {
                    $this->assertNotSame($r['results'][0]['start'], $s['results'][0]['start']);
                }
            }
        }
        $this->assertGreaterThan(0, $accepted);
    }
}
