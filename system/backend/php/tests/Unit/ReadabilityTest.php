<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

include_once __DIR__ . '/../../lib/Readability.php';

/**
 * Readability for the overview report (lib/Readability.php). The vectors in
 * tests/fixtures/readability-vectors.json are shared verbatim with
 * haxcms-nodejs test/fixtures/readability-vectors.json
 * (test/unit/readability.test.cjs), so both backends answer the same numbers
 * for the same pages.
 */
class ReadabilityTest extends TestCase
{
    private static function vectors(): array
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/readability-vectors.json');
        return json_decode($raw, true);
    }

    public static function vectorProvider(): array
    {
        $cases = array();
        foreach (self::vectors()['cases'] as $case) {
            $cases[$case['name']] = array($case['pages'], $case['readability']);
        }
        return $cases;
    }

    #[DataProvider('vectorProvider')]
    public function testSharedVector(array $pages, array $expected): void
    {
        $this->assertSame(
            $expected,
            Readability::readabilityOf(Readability::readableText($pages))
        );
    }

    public function testGradeLevelBands(): void
    {
        foreach (self::vectors()['gradeLevels'] as $band) {
            $this->assertSame(
                $band['gradeLevel'],
                Readability::gradeLevelFor($band['score']),
                (string) $band['score']
            );
        }
    }

    public function testATagBecomesASpaceSoBlocksStaySeparateWords(): void
    {
        $this->assertSame('one two', Readability::readableText(array('<p>one</p><p>two</p>')));
        $this->assertSame('one', Readability::pageText('<p>one</p>'));
    }

    public function testTheDaleChallAdjustmentAppliesOverFivePercentDifficult(): void
    {
        $familiar = 'the cat sat on the mat and the dog ran to the park with a big red ball ';
        // 1 difficult word in 20 is exactly 5%: no adjustment (score 1.7815)
        $fivePercent = Readability::readabilityOf($familiar . 'photosynthesis home.');
        $this->assertSame(20, $fivePercent['lexiconCount']);
        $this->assertSame(1, $fivePercent['difficultWords']);
        $this->assertSame('4th grade or lower', $fivePercent['gradeLevel']);
        // 2 in 20 is 10%: 0.1579 * 10 + 0.0496 * 20 + 3.6365 = 6.2075
        $tenPercent = Readability::readabilityOf($familiar . 'photosynthesis chlorophyll.');
        $this->assertSame(20, $tenPercent['lexiconCount']);
        $this->assertSame(2, $tenPercent['difficultWords']);
        $this->assertSame('7th / 8th grade', $tenPercent['gradeLevel']);
    }

    public function testTheWordListMatchesHaxcmsNodejs(): void
    {
        // haxcms-nodejs src/lib/daleChallWordList.js holds the same 2941 words
        $this->assertTrue(DaleChallWordList::isFamiliarWord('reading'));
        $this->assertTrue(DaleChallWordList::isFamiliarWord('READING'));
        $this->assertFalse(DaleChallWordList::isFamiliarWord('photosynthesis'));
        $this->assertFalse(DaleChallWordList::isFamiliarWord(''));
    }
}
