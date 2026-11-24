<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/ReadabilityScore.php';

/**
 * Readability contract shared with haxcms-nodejs: the overview report must
 * answer exactly the values in tests/fixtures/readability-parity.json, an
 * identical copy of haxcms-nodejs test/fixtures/readability-parity.json
 * (generated there from text-readability 1.1.1).
 */
class ReadabilityScoreTest extends TestCase
{
    public static function parityCases(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/readability-parity.json'), true);
        $cases = array();
        foreach ($fixture['cases'] as $case) {
            $cases[$case['name']] = array($case);
        }
        return $cases;
    }

    /**
     * @dataProvider parityCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parityCases')]
    public function testMatchesHaxcmsNodejs(array $case): void
    {
        $text = ReadabilityScore::textFromHTML($case['pages']);
        $this->assertSame($case['text'], $text);
        $this->assertSame($case['readability'], ReadabilityScore::metrics($text));
        $this->assertEquals($case['daleChallScore'], ReadabilityScore::daleChallReadabilityScore($text));
    }

    public function testDaleChallAddsTheAdjustmentOnlyAboveFivePercentDifficult(): void
    {
        // no difficult words: no 3.6365 adjustment (the old code added it here)
        $this->assertEquals(0.32, ReadabilityScore::daleChallReadabilityScore('The cat sat on the mat. It was a good day for reading.'));
        // over 5% difficult words: the adjustment applies
        $this->assertEquals(18.41, ReadabilityScore::daleChallReadabilityScore(
            'Institutional accreditation necessitates comprehensive documentation. '
            . 'Interdisciplinary collaboration substantially complicates organizational decision making.'
        ));
    }

    public function testInvalidUtf8IsScoredNotDropped(): void
    {
        $metrics = ReadabilityScore::metrics("The cat sat \xC3\x28 on the mat.");
        // the bad byte becomes U+FFFD, as when Node reads the page file
        $this->assertSame(7, $metrics['lexiconCount']);
    }

    public function testPagesFullOfUnclosedMarkupAreReadQuickly(): void
    {
        $pages = array(
            str_repeat('<', 200000),
            str_repeat('<!--', 50000),
            str_repeat('<script', 30000),
            '<script>' . str_repeat('</script', 30000),
        );
        $started = microtime(true);
        foreach ($pages as $page) {
            $this->assertSame('', ReadabilityScore::textFromHTML(array($page)));
        }
        // a backtracking pattern takes minutes here; the scan takes milliseconds
        $this->assertLessThan(2.0, microtime(true) - $started);
    }
}
