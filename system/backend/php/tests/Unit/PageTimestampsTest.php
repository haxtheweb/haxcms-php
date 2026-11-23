<?php
use PHPUnit\Framework\TestCase;

/**
 * SanitizeContent::pageTimestamps: a page brought in from another site keeps
 * its created / updated times, anything else gets the time it was added.
 * Same cases as haxcms-nodejs test/unit/page-timestamps.test.cjs.
 */
class PageTimestampsTest extends TestCase
{
    const NOW = 1800000000;

    public function testNewPageGetsNow(): void
    {
        $now = array('created' => self::NOW, 'updated' => self::NOW);
        $this->assertSame($now, SanitizeContent::pageTimestamps(null, self::NOW));
        $this->assertSame($now, SanitizeContent::pageTimestamps(new stdClass(), self::NOW));
    }

    public function testImportedTimesAreKept(): void
    {
        $this->assertSame(
            array('created' => 1700000000, 'updated' => 1750000000),
            SanitizeContent::pageTimestamps((object)array('created' => 1700000000, 'updated' => 1750000000), self::NOW)
        );
        // array metadata (a decoded request) works the same
        $this->assertSame(
            array('created' => 1700000000, 'updated' => 1700000000),
            SanitizeContent::pageTimestamps(array('created' => 1700000000), self::NOW)
        );
    }

    public function testDigitStringsCount(): void
    {
        $times = SanitizeContent::pageTimestamps(array('created' => '1700000000'), self::NOW);
        $this->assertSame(1700000000, $times['created']);
    }

    public function testUpdatedEarlierThanCreatedIsRaised(): void
    {
        $this->assertSame(
            array('created' => 1700000000, 'updated' => 1700000000),
            SanitizeContent::pageTimestamps(array('created' => 1700000000, 'updated' => 1600000000), self::NOW)
        );
    }

    public function testBadValuesAreIgnored(): void
    {
        foreach (array(self::NOW + 1, 0, -5, 1700000000.5, '17e8', 'yesterday', true, array(1), NAN) as $bad) {
            $this->assertSame(
                array('created' => self::NOW, 'updated' => self::NOW),
                SanitizeContent::pageTimestamps(array('created' => $bad), self::NOW),
                var_export($bad, true)
            );
        }
        $this->assertSame(
            array('created' => 1700000000, 'updated' => 1700000000),
            SanitizeContent::pageTimestamps(array('created' => 1700000000, 'updated' => self::NOW + 60), self::NOW)
        );
    }
}
