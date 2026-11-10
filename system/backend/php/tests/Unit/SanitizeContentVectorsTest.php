<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/SanitizeContent.php';

/**
 * Runs the shared storage sanitizer vectors (tests/fixtures/
 * sanitize-vectors.json, haxtheweb/issues#3273). haxcms-nodejs runs the same
 * file (test/unit/sanitize-vectors.test.cjs); both backends must store
 * exactly this HTML. On PHP 8.3 (libxml) the cases marked libxmlDiffers are
 * malformed markup libxml builds a different tree from; PHP 8.4
 * (Dom\HTMLDocument) must match every probe and real page; its parser
 * follows a newer HTML spec than parse5 on malformed markup (stray table
 * text, elements inside <select>), so the seeded garbage cases and the few
 * marked fosterText are only checked on PHP 8.3.
 * Regenerate the file in haxcms-nodejs (scripts/generate-sanitize-vectors.cjs)
 * and copy it.
 */
class SanitizeContentVectorsTest extends TestCase
{
    private static function vectors()
    {
        static $vectors = null;
        if ($vectors === null) {
            $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/sanitize-vectors.json'), true);
        }
        return $vectors;
    }

    private static function html5Parser()
    {
        return class_exists('\Dom\HTMLDocument') && method_exists('\Dom\HTMLDocument', 'createFromString');
    }

    public function testVectorsFileHasCases(): void
    {
        $this->assertGreaterThan(1000, count(self::vectors()['cases']));
    }

    public function testEveryVectorStoresTheExpectedHTML(): void
    {
        $vectors = self::vectors();
        $failures = array();
        $checked = 0;
        foreach ($vectors['cases'] as $c) {
            $html = isset($c['page']) ? $vectors['pages'][$c['page']] : $c['html'];
            $out = SanitizeContent::sanitizeHTMLForStorage($html, isset($c['options']) ? $c['options'] : array());
            $this->assertIsString($out);
            if (!self::html5Parser() && !empty($c['libxmlDiffers'])) {
                continue;
            }
            if (self::html5Parser() && (!empty($c['fosterText']) || strpos($c['name'], 'generated ') === 0)) {
                // PHP 8.4's parser (lexbor) follows a newer HTML spec than
                // haxcms-nodejs' parse5 on malformed markup: stray text in a
                // table goes before it (fosterText), and <select> keeps
                // elements. Every probe and real page must still match.
                continue;
            }
            $checked++;
            $ok = is_string($c['expect']) ? $out === $c['expect'] : hash('sha256', $out) === $c['expect']['sha256'];
            if (!$ok && count($failures) < 10) {
                $failures[] = $c['name'] . "\n  got:  " . json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
                    (is_string($c['expect']) ? "\n  want: " . json_encode($c['expect'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
            }
        }
        $this->assertSame(array(), $failures, implode("\n", $failures));
        // 1785 cases; PHP 8.4 checks the 1385 probes and pages
        $this->assertGreaterThan(1300, $checked);
    }

    public function testRealPagesAreStableOnASecondSave(): void
    {
        $vectors = self::vectors();
        foreach ($vectors['pages'] as $name => $html) {
            $once = SanitizeContent::sanitizeHTMLForStorage($html);
            $this->assertSame($once, SanitizeContent::sanitizeHTMLForStorage($once), $name);
        }
    }
}
