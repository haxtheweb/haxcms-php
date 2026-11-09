<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/BlockOperations.php';

/**
 * Unit tests for lib/BlockOperations.php (haxtheweb/issues#3270). Most
 * behavior is pinned by the shared vectors (BlockOperationsVectorsTest);
 * these cover what the vectors cannot: the allowlist file itself, the
 * allowlist agreeing with this backend's storage sanitizer, and value rules
 * at their edges.
 */
class BlockOperationsTest extends TestCase
{
    public function testAllowlistShape(): void
    {
        $list = BlockOperations::allowlist();
        $this->assertSame(1, $list['version']);
        $this->assertGreaterThan(50, count($list['tags']));
        foreach (array('page-break', 'script', 'style', 'template') as $never) {
            $this->assertArrayNotHasKey($never, $list['tags']);
        }
        foreach ($list['tags'] as $tag => $attrs) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $tag);
            foreach ($attrs as $name => $kind) {
                $this->assertContains($kind, array('text', 'url'), $tag . ' ' . $name);
                $this->assertDoesNotMatchRegularExpression('/^(on|style$|srcdoc$|formaction$)/', $name);
            }
        }
    }

    public function testEveryAllowlistedAttributeSurvivesTheStorageSanitizer(): void
    {
        $void = array('area', 'img', 'source', 'track');
        foreach (BlockOperations::allowlist()['tags'] as $tag => $attrs) {
            foreach ($attrs as $name => $kind) {
                $html = in_array($tag, $void, true) ? '<' . $tag . '>' : '<' . $tag . '></' . $tag . '>';
                $value = $kind === 'url' ? '/files/x.png' : 'Plain words';
                $r = BlockOperations::runBlockOperations($html, array(array(
                    'op' => 'setAttributes', 'tag' => $tag, 'index' => 0, 'before' => $html, 'set' => array($name => $value),
                )));
                $this->assertTrue($r['ok'], $tag . ' ' . $name . ': ' . ($r['ok'] ? '' : $r['message']));
            }
        }
    }

    public function testTextValues(): void
    {
        $this->assertSame('a "b" & c', BlockOperations::normalizeValue('text', "\u{3000} a \"b\" & c\u{feff}\n", 'alt')['value']);
        $this->assertSame("a\nb\nc", BlockOperations::normalizeValue('text', "a\r\nb\rc", 'alt')['value']);
        $this->assertSame("tab\tok", BlockOperations::normalizeValue('text', "tab\tok", 'alt')['value']);
        $this->assertSame('invalid', BlockOperations::normalizeValue('text', "bell\x07", 'alt')['code']);
        $this->assertSame('invalid', BlockOperations::normalizeValue('text', "bad \xff utf8", 'alt')['code']);
        $this->assertSame('invalid', BlockOperations::normalizeValue('text', 5, 'alt')['code']);
        $this->assertNull(BlockOperations::normalizeValue('text', null, 'alt')['value']);
        // DOMPurify drops these in haxcms-nodejs, so neither backend stores them
        foreach (array('a --> b', 'a --!> b', 'x]>y', '</TITLE>', '</script') as $bad) {
            $this->assertSame('invalid', BlockOperations::normalizeValue('text', $bad, 'alt')['code'], $bad);
        }
        $this->assertTrue(BlockOperations::normalizeValue('text', "</\u{017f}tyle>", 'alt')['ok']);
        // length counts code points: 4000 four-byte characters fit
        $this->assertTrue(BlockOperations::normalizeValue('text', str_repeat("\u{1F600}", 4000), 'alt')['ok']);
        $this->assertSame('invalid', BlockOperations::normalizeValue('text', str_repeat('x', 4001), 'alt')['code']);
    }

    public function testUrlValuesAreCheckedThenStoredCanonical(): void
    {
        $ok = function ($v) {
            return BlockOperations::normalizeValue('url', $v, 'src');
        };
        $this->assertSame('https://media.psu.edu/v.mp4', $ok(' https://media.psu.edu/v.mp4 ')['value']);
        $this->assertSame('/files/caf%C3%A9%20x', $ok('/files/café%20x')['value']);
        $this->assertSame('/q?a=%241&b=%5B%5D%22%5E%60%7B%7C%7D', $ok('/q?a=$1&b=[]"^`{|}')['value']);
        $this->assertSame('', $ok('')['value']);
        foreach (array('javascript:alert(1)', 'data:x', '//evil.example', 'https://a@b.example', 'https://[::1]/x', 'https://exa mple.org', '/a\\b', 'mailto:a@b.c') as $bad) {
            $this->assertSame('invalid', $ok($bad)['code'], $bad);
        }
        // the limit applies to the stored (encoded) form
        $this->assertSame('invalid', $ok('/' . str_repeat('é', 400))['code']);
        $this->assertTrue($ok('/' . str_repeat('é', 300))['ok']);
    }

    public function testScannerSpansAreExactAndSkipRawText(): void
    {
        $html = '<p>a<img alt="x"></p><!-- <img> --><script>"<img>"</script><textarea><img></textarea><img alt=\'y\' />';
        $imgs = array_values(array_filter(BlockOperations::scanElements($html), function ($e) {
            return $e['tag'] === 'img';
        }));
        $this->assertCount(2, $imgs);
        $this->assertSame('<img alt="x">', substr($html, $imgs[0]['start'], $imgs[0]['end'] - $imgs[0]['start']));
        $this->assertSame("<img alt='y' />", substr($html, $imgs[1]['start'], $imgs[1]['end'] - $imgs[1]['start']));
    }

    public function testListBlocksDecodesAttributesInAllowlistOrder(): void
    {
        $blocks = BlockOperations::listBlocks('<img src="/a.png" alt="&quot;q&quot; &amp; r"><h2>no</h2>');
        $this->assertCount(1, $blocks);
        $this->assertSame(array('alt' => '"q" & r', 'src' => '/a.png'), (array) $blocks[0]['attributes']);
    }

    public function testStructureCheckIsNeverPartial(): void
    {
        $imgs = function ($alt) {
            $out = '';
            for ($i = 0; $i < 70; $i++) {
                $out .= '<img src="/' . $i . '.png" alt="' . $alt . $i . '">';
            }
            return $out;
        };
        $ops = array();
        $sets = array();
        for ($i = 0; $i < 70; $i++) {
            $ops[] = array('op' => 'setAttributes', 'tag' => 'img', 'index' => $i, 'set' => array('alt' => 'b' . $i));
            $sets[] = array('alt' => 'b' . $i);
        }
        $before = $imgs('a') . '<p>untouched</p>';
        $this->assertTrue(BlockOperations::verifyStructure($before, $imgs('b') . '<p>untouched</p>', $ops, $sets)['ok']);
        $this->assertSame('structure-changed', BlockOperations::verifyStructure($before, $imgs('b') . '<p>changed</p>', $ops, $sets)['code']);
    }
}
