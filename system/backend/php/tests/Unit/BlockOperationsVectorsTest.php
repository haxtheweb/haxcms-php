<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/BlockOperations.php';

/**
 * Runs the shared block operation vectors (tests/fixtures/
 * block-operations-vectors.json, haxtheweb/issues#3270). haxcms-nodejs runs
 * the same file (test/unit/block-operations-vectors.test.cjs); both backends
 * must give the same bytes and refusal codes. Regenerate the file in
 * haxcms-nodejs (scripts/generate-block-operation-vectors.cjs) and copy it.
 */
class BlockOperationsVectorsTest extends TestCase
{
    private static function vectors()
    {
        static $vectors = null;
        if ($vectors === null) {
            $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/block-operations-vectors.json'), true);
        }
        return $vectors;
    }

    public static function cases(): array
    {
        $out = array();
        foreach (self::vectors()['cases'] as $c) {
            $out[$c['name']] = array($c);
        }
        return $out;
    }

    public function testVectorsFileHasCases(): void
    {
        $this->assertGreaterThan(50, count(self::vectors()['cases']));
    }

    /**
     * @dataProvider cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testVector(array $c): void
    {
        $vectors = self::vectors();
        $html = isset($c['page']) && $c['page'] ? $vectors['pages'][$c['page']] : $c['html'];
        $r = BlockOperations::runBlockOperations($html, $c['ops']);
        $e = $c['expect'];
        $this->assertSame($e['ok'], $r['ok'], $r['ok'] ? 'accepted' : $r['code'] . ': ' . $r['message']);
        if ($e['ok']) {
            $this->assertSame($e['sha256'], hash('sha256', $r['content']));
            $blocks = array();
            foreach ($r['results'] as $x) {
                $blocks[] = substr($r['content'], $x['start'], $x['end'] - $x['start']);
            }
            $this->assertSame($e['blocks'], $blocks);
            $this->assertSame($e['indexes'], array_column($r['results'], 'index'));
            $this->assertSame($e['relocated'], array_column($r['results'], 'relocated'));
            if (isset($e['content']) && is_string($e['content'])) {
                $this->assertSame($e['content'], $r['content']);
            }
        }
        else {
            $this->assertSame($e['code'], $r['code'], $r['message']);
            if ($e['op'] !== null) {
                $this->assertSame($e['op'], $r['op']);
            }
        }
    }
}
