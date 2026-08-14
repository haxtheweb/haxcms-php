<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the SiteRouteUtils pure-utility seam.
 *
 * Expected values are derived from each utility's spec, not the
 * implementation: base-path normalization, tag-list parsing, ISO date
 * conversion, dot-path accessors, primitive comparison / sort order, record
 * projection, slug-path encoding, canonical page paths, metadata boolean
 * coercion, and the cleanSlug fallback (no global HAXCMS present).
 */
class SiteRouteUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        // Deterministic: exercises the no-HAXCMS fallback branches for
        // cleanSlug and ensures global state from other suites is absent.
        unset($GLOBALS['HAXCMS']);
    }

    public static function normalizeBasePathProvider(): array
    {
        return [
            'root unchanged' => ['/', '/'],
            'empty becomes root' => ['', '/'],
            'bare gets leading and trailing slash' => ['foo', '/foo/'],
            'leading slash added trailing' => ['/foo', '/foo/'],
            'trailing slash kept' => ['/foo/', '/foo/'],
            'multi segment' => ['foo/bar', '/foo/bar/'],
            'multi segment leading only' => ['/foo/bar', '/foo/bar/'],
        ];
    }

    #[DataProvider('normalizeBasePathProvider')]
    public function testNormalizeBasePath(string $input, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::normalizeBasePath($input));
    }

    public static function tagListProvider(): array
    {
        return [
            'array trims and drops empty' => [['a', 'b', ' c '], ['a', 'b', 'c']],
            'array drops empty entries' => [['a', '', 'b'], ['a', 'b']],
            'csv string' => ['a,b,c', ['a', 'b', 'c']],
            'csv string with spaces' => ['a, b , c', ['a', 'b', 'c']],
            'csv drops empties' => ['a,,b', ['a', 'b']],
            'empty string' => ['', []],
            'null' => [null, []],
        ];
    }

    #[DataProvider('tagListProvider')]
    public function testNormalizeTagList(mixed $input, array $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::normalizeTagList($input));
    }

    public static function isoDateProvider(): array
    {
        return [
            'zero is null' => [0, null],
            'negative is null' => [-1, null],
            'non-numeric is null' => ['abc', null],
            'unix epoch 2021-01-01' => [1609459200, '2021-01-01T00:00:00+00:00'],
            'numeric string works' => ['1609459200', '2021-01-01T00:00:00+00:00'],
        ];
    }

    #[DataProvider('isoDateProvider')]
    public function testToIsoDateFromUnixTime(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::toIsoDateFromUnixTime($input));
    }

    public static function valueByPathProvider(): array
    {
        return [
            'nested array access' => [['a' => ['b' => 1]], 'a.b', 1],
            'top level array access' => [['a' => ['b' => 1]], 'a', ['b' => 1]],
            'missing leaf null' => [['a' => ['b' => 1]], 'a.c', null],
            'empty path null' => [['a' => 1], '', null],
            'object property access' => [(object)['x' => 5], 'x', 5],
            'object nested access' => [(object)['x' => ['y' => 5]], 'x.y', 5],
            'descend through scalar null' => [['a' => 1], 'a.b', null],
        ];
    }

    #[DataProvider('valueByPathProvider')]
    public function testGetValueByPath(mixed $record, string $path, mixed $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::getValueByPath($record, $path));
    }

    public static function compareProvider(): array
    {
        return [
            'asc less' => [1, 2, false, -1],
            'asc greater' => [2, 1, false, 1],
            'equal' => [1, 1, false, 0],
            'desc puts larger first' => [2, 1, true, -1],
            'null sorts first asc' => [null, 5, false, -1],
            'non-null after null asc' => [5, null, false, 1],
            'both null equal' => [null, null, false, 0],
            'string asc' => ['a', 'b', false, -1],
            'string desc' => ['b', 'a', true, -1],
            'string case-insensitive equal' => ['Apple', 'apple', false, 0],
            'numeric string compared numerically' => ['10', '9', false, 1],
        ];
    }

    #[DataProvider('compareProvider')]
    public function testComparePrimitiveValues(mixed $a, mixed $b, bool $desc, int $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::comparePrimitiveValues($a, $b, $desc));
    }

    public function testSortRecordsByOrderAscending(): void
    {
        $records = [
            ['id' => 'a', 'order' => 3],
            ['id' => 'b', 'order' => 1],
            ['id' => 'c', 'order' => 2],
        ];
        $sorted = SiteRouteUtils::sortRecords($records, 'order');
        $this->assertSame(['b', 'c', 'a'], array_column($sorted, 'id'));
    }

    public function testSortRecordsDescending(): void
    {
        $records = [
            ['id' => 'a', 'order' => 3],
            ['id' => 'b', 'order' => 1],
            ['id' => 'c', 'order' => 2],
        ];
        $sorted = SiteRouteUtils::sortRecords($records, '-order');
        $this->assertSame(['a', 'c', 'b'], array_column($sorted, 'id'));
    }

    public function testSortRecordsFallsBackToMetadataPath(): void
    {
        $records = [
            ['id' => 'a', 'metadata' => ['order' => 3]],
            ['id' => 'b', 'metadata' => ['order' => 1]],
        ];
        $sorted = SiteRouteUtils::sortRecords($records, 'order');
        $this->assertSame(['b', 'a'], array_column($sorted, 'id'));
    }

    public function testSortRecordsNoTokensReturnsUnsorted(): void
    {
        $records = [['id' => 'a'], ['id' => 'b']];
        $this->assertSame($records, SiteRouteUtils::sortRecords($records, ''));
    }

    public static function projectRecordProvider(): array
    {
        return [
            'select subset of fields' => [['a' => 1, 'b' => 2, 'c' => 3], ['a', 'c'], ['a' => 1, 'c' => 3]],
            'no fields returns unchanged' => [['a' => 1], [], ['a' => 1]],
            'nested field projection' => [['a' => ['b' => 1, 'c' => 2]], ['a.b'], ['a' => ['b' => 1]]],
            'missing field skipped' => [['a' => 1], ['a', 'x'], ['a' => 1]],
        ];
    }

    #[DataProvider('projectRecordProvider')]
    public function testProjectRecord(array $record, array $fields, array $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::projectRecord($record, $fields));
    }

    public function testProjectCollectionMapsProjection(): void
    {
        $records = [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]];
        $expected = [['a' => 1], ['a' => 3]];
        $this->assertSame($expected, SiteRouteUtils::projectCollection($records, ['a']));
    }

    public function testProjectCollectionNoFieldsReturnsUnchanged(): void
    {
        $records = [['a' => 1]];
        $this->assertSame($records, SiteRouteUtils::projectCollection($records, []));
    }

    public static function encodeSlugPathProvider(): array
    {
        return [
            'simple path' => ['foo/bar', 'foo/bar'],
            'encodes spaces' => ['foo/bar baz', 'foo/bar%20baz'],
            'trims segments' => ['a/ b ', 'a/b'],
            'empty string' => ['', ''],
            'single segment' => ['foo', 'foo'],
        ];
    }

    #[DataProvider('encodeSlugPathProvider')]
    public function testEncodeSlugPath(string $input, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::encodeSlugPath($input));
    }

    public static function canonicalPathProvider(): array
    {
        return [
            'root base with slug' => ['/', 'about-us', '/about-us'],
            'site base with slug' => ['/site/', 'about-us', '/site/about-us'],
            'root base no slug' => ['/', '', '/'],
            'site base no slug' => ['/site/', '', '/site'],
            'nested slug' => ['/site/', 'foo/bar', '/site/foo/bar'],
        ];
    }

    #[DataProvider('canonicalPathProvider')]
    public function testBuildCanonicalPagePath(string $base, string $slug, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::buildCanonicalPagePath($base, $slug));
    }

    public static function metaBoolTrueProvider(): array
    {
        return [
            'bool true' => [true, true],
            'int 1' => [1, true],
            'string 1' => ['1', true],
            'string true' => ['true', true],
            'string yes' => ['yes', true],
            'string on' => ['on', true],
            'uppercase TRUE' => ['TRUE', true],
            'padded yes' => [' Yes ', true],
            'bool false' => [false, false],
            'int 0' => [0, false],
            'string false' => ['false', false],
            'string maybe' => ['maybe', false],
            'null' => [null, false],
            'empty string' => ['', false],
        ];
    }

    #[DataProvider('metaBoolTrueProvider')]
    public function testIsMetadataBooleanTrue(mixed $input, bool $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::isMetadataBooleanTrue($input));
    }

    public static function metaBoolFalseProvider(): array
    {
        return [
            'bool false' => [false, true],
            'int 0' => [0, true],
            'string 0' => ['0', true],
            'string false' => ['false', true],
            'string no' => ['no', true],
            'string off' => ['off', true],
            'uppercase FALSE' => ['FALSE', true],
            'bool true' => [true, false],
            'int 1' => [1, false],
            'string true' => ['true', false],
            'string maybe' => ['maybe', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('metaBoolFalseProvider')]
    public function testIsMetadataBooleanFalse(mixed $input, bool $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::isMetadataBooleanFalse($input));
    }

    public static function cleanSlugProvider(): array
    {
        // Exercises the no-global-HAXCMS fallback branch.
        return [
            'spaces to hyphens lowercased' => ['Hello World', 'hello-world'],
            'slash preserved' => ['foo/bar', 'foo/bar'],
            'dot slash removed' => ['a ./ b', 'a-b'],
            'parent traversal removed cleanly' => ['../etc', 'etc'],
            'underscore preserved' => ['Foo_Bar', 'foo_bar'],
            'multiple words' => ['Foo Bar Baz', 'foo-bar-baz'],
            'empty becomes blank' => ['', 'blank'],
            'whitespace becomes blank' => ['   ', 'blank'],
        ];
    }

    #[DataProvider('cleanSlugProvider')]
    public function testCleanSlugFallback(string $input, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::cleanSlug($input));
    }

    public function testCleanSlugFallbackNeverEmitsTraversalSegments(): void
    {
        // Security-relevant invariant of the fallback: traversal segments
        // must never survive into the slug, even when the single-pass
        // str_replace leaves a cosmetic hyphen behind (see data set above).
        foreach (['../etc', '../../etc', './etc', 'a/../b'] as $input) {
            $slug = SiteRouteUtils::cleanSlug($input);
            $this->assertStringNotContainsString('..', $slug, "slug for '$input' leaked '..'");
            $this->assertStringNotContainsString('./', $slug, "slug for '$input' leaked './'");
        }
    }

    public static function sortTokensProvider(): array
    {
        return [
            'single asc' => ['order', [['key' => 'order', 'desc' => false]]],
            'single desc' => ['-order', [['key' => 'order', 'desc' => true]]],
            'multi token' => ['a,-b', [['key' => 'a', 'desc' => false], ['key' => 'b', 'desc' => true]]],
            'empty' => ['', []],
            'only commas' => [',,', []],
        ];
    }

    #[DataProvider('sortTokensProvider')]
    public function testNormalizeSortTokens(string $input, array $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::normalizeSortTokens($input));
    }

    public static function pathForResponseProvider(): array
    {
        return [
            'backslash normalized' => ['a\\b', 'a/b'],
            'forward slash unchanged' => ['a/b', 'a/b'],
            'empty string' => ['', ''],
            'integer cast to string' => [123, '123'],
        ];
    }

    #[DataProvider('pathForResponseProvider')]
    public function testNormalizePathForResponse(mixed $input, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::normalizePathForResponse($input));
    }
}
