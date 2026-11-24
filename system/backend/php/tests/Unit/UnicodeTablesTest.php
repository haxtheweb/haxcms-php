<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/UnicodeTables.php';
require_once dirname(__DIR__, 2) . '/lib/HAXCMS.php';

/**
 * lib/unicode-tables.json fixes the Unicode data the slug and search rules
 * use, so haxcms-php and haxcms-nodejs agree whatever Unicode version PCRE2
 * and mbstring were built with (haxtheweb/issues#3394). It is an identical
 * copy of haxcms-nodejs src/lib/unicode-tables.json, whose test pins the
 * same checksum.
 */
class UnicodeTablesTest extends TestCase
{
    // update together with haxcms-nodejs test/unit/unicode-tables.test.cjs
    private const TABLES_SHA256 = '6a772bd8bcd9658b987585a2934ccc4c92edccfa12280ba1147a78b13a4f5719';

    public function testTablesAreTheCopyNodejsShips(): void
    {
        $this->assertSame(
            self::TABLES_SHA256,
            hash_file('sha256', dirname(__DIR__, 2) . '/lib/unicode-tables.json')
        );
        $this->assertSame('17.0', UnicodeTables::version());
    }

    public function testLetterNewerThanPcreIsStillALetter(): void
    {
        // U+1DF25 (Unicode 14) was a separator for PCRE2 10.42 and a letter
        // in Node.js
        $haxcms = (new ReflectionClass('HAXCMS'))->newInstanceWithoutConstructor();
        $this->assertSame("new-\u{1DF25}-letter", $haxcms->generateSlugName("New \u{1DF25} letter"));
        $this->assertSame(1, preg_match(UnicodeTables::searchKeepShort(), '7'));
        $this->assertSame(0, preg_match(UnicodeTables::searchKeepShort(), 'a'));
        $this->assertSame("\u{10D70}", UnicodeTables::lowerCase("\u{10D50}"));
    }

    public function testLowerCaseKeepsTheFinalSigmaRule(): void
    {
        $this->assertSame('οδος', UnicodeTables::lowerCase('ΟΔΟΣ'));
        $this->assertSame('σα', UnicodeTables::lowerCase('ΣΑ'));
        $this->assertSame('σ', UnicodeTables::lowerCase('Σ'));
        $this->assertSame("ας\u{0301} b", UnicodeTables::lowerCase("ΑΣ\u{0301} b"));
        $this->assertSame('i̇stanbul', UnicodeTables::lowerCase('İstanbul'));
    }
}
