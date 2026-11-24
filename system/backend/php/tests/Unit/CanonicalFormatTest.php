<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/CanonicalFormat.php';

/**
 * The canonical text formats (haxtheweb/issues#3394) are the same byte for
 * byte in haxcms-php and haxcms-nodejs. fixtures/canonical-format-vectors.json
 * is a copy of haxcms-nodejs test/fixtures/canonical-format-vectors.json:
 * both suites check their implementation against it, so the two files must
 * stay identical.
 */
class CanonicalFormatTest extends TestCase
{
    private function vectors(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/canonical-format-vectors.json'));
        return $data->vectors;
    }

    public function testFormatsMatchTheSharedVectors(): void
    {
        $vectors = $this->vectors();
        $this->assertGreaterThan(50, count($vectors));
        foreach ($vectors as $vector) {
            $value = json_decode($vector->input);
            $this->assertSame($vector->json, CanonicalFormat::json($value), $vector->input);
            $this->assertSame($vector->prettyJson, CanonicalFormat::prettyJson($value), $vector->input);
            $this->assertSame($vector->yaml, CanonicalFormat::yaml($value), $vector->input);
        }
    }

    public function testPhpArraysAreListsOrMappingsAsJsonEncodeSeesThem(): void
    {
        $this->assertSame('{"a":[],"b":{},"c":[1,2],"d":{"x":1}}', CanonicalFormat::json(array(
            'a' => array(),
            'b' => new stdClass(),
            'c' => array(1, 2),
            'd' => array('x' => 1),
        )));
        $this->assertSame("a: []\nb: {}\n", CanonicalFormat::yaml(array('a' => array(), 'b' => new stdClass())));
    }

    public function testNumbersAreWrittenAsJavaScriptWritesThem(): void
    {
        $cases = array(
            array(0.00001, '0.00001'),
            array(0.000001, '0.000001'),
            array(1.0e-7, '1e-7'),
            array(1.5e-7, '1.5e-7'),
            array(1.0e21, '1e+21'),
            array(1.2345678901234569e+23, '1.2345678901234569e+23'),
            array(123456789012345680000.0, '123456789012345680000'),
            array(2.0, '2'),
            array(-0.5, '-0.5'),
            array(-0.0, '0'),
            array(0.1, '0.1'),
            array(42, '42'),
            array(-7, '-7'),
        );
        foreach ($cases as $case) {
            $this->assertSame($case[1], CanonicalFormat::numberText($case[0]), var_export($case[0], true));
        }
    }

    public function testDatesAreWrittenAsToIsoString(): void
    {
        $this->assertSame('2025-10-09T08:53:20.000Z', CanonicalFormat::isoDate(1760000000));
        $this->assertSame('1970-01-01T00:00:01.500Z', CanonicalFormat::isoDate(1.5));
        $this->assertSame('1969-12-31T23:59:59.500Z', CanonicalFormat::isoDate(-0.5));
    }

    public function testScalarsAsTextForXml(): void
    {
        $this->assertSame('true', CanonicalFormat::scalarString(true));
        $this->assertSame('false', CanonicalFormat::scalarString(false));
        $this->assertSame('', CanonicalFormat::scalarString(null));
        $this->assertSame('0.00001', CanonicalFormat::scalarString(0.00001));
        $this->assertSame('text', CanonicalFormat::scalarString('text'));
    }

    public function testApiXmlWritesBooleansAsTrueFalse(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteRouteUtils.php';
        $this->assertSame(
            '<r><a>true</a><b>false</b><c>1.5</c></r>',
            SiteRouteUtils::toXmlNode('r', array('a' => true, 'b' => false, 'c' => 1.5))
        );
    }
}
