<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the HAXCMS pure-helper seam.
 *
 * The pure string/config helpers (generateMachineName, generateSlugName,
 * cleanTitle, getIntConfigValue, safeStringCompare) read no instance state,
 * but the HAXCMS constructor has heavy filesystem/global side effects
 * (mkdir under _config, Cache init, argv shifts, php://input + superglobal
 * reads). To exercise the REAL public method bodies without triggering those
 * side effects, instances are created via ReflectionClass::
 * newInstanceWithoutConstructor(). This invokes the genuine production
 * methods — it does not mock or stub them — so assertions pin real behavior.
 *
 * Expected values come from each helper's spec: machine name = filesystem-safe
 * (no slashes/traversal/null bytes, lowercase, 'default' fallback); slug =
 * URL-safe (slashes preserved, traversal/backslashes removed); cleanTitle =
 * strips page-path identifiers when stripPage, 'blank' fallback; getIntConfigValue
 * = clamp to min/max with fallback; safeStringCompare = timing-safe equality.
 */
class HAXCMSHelpersTest extends TestCase
{
    private $haxcms;

    protected function setUp(): void
    {
        // Skip the stateful constructor; these methods use no $this state.
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
    }

    public static function machineNameProvider(): array
    {
        return [
            'spaces to hyphens lowercased' => ['Hello World', 'hello-world'],
            'slashes removed entirely' => ['foo/bar', 'foobar'],
            'parent traversal removed' => ['../etc', 'etc'],
            'backslash becomes hyphen (filesystem-safe)' => ['a\\b', 'a-b'],
            'underscore preserved' => ['Foo_Bar', 'foo_bar'],
            'multiple separators collapsed' => ['a__b--c', 'a-b-c'],
            'uppercase lowered' => ['UPPER', 'upper'],
            'null byte stripped' => ["a\x00b", 'ab'],
            'urlencoded traversal decoded then stripped' => ['%2e%2e/etc', 'etc'],
            'empty becomes default' => ['', 'default'],
            'only dots becomes default' => ['...', 'default'],
            'special chars become hyphens' => ['a!@#b', 'a-b'],
        ];
    }

    #[DataProvider('machineNameProvider')]
    public function testGenerateMachineName(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->haxcms->generateMachineName($input));
    }

    public static function slugNameProvider(): array
    {
        return [
            'spaces to hyphens lowercased' => ['Hello World', 'hello-world'],
            'forward slashes preserved' => ['foo/bar', 'foo/bar'],
            'parent traversal removed' => ['../etc', 'etc'],
            'backslash removed' => ['a\\b', 'ab'],
            'double slashes collapsed' => ['a//b', 'a/b'],
            'uppercase lowered' => ['UPPER/LOWER', 'upper/lower'],
            'trailing slash trimmed' => ['foo/', 'foo'],
            'dot dot mid-string removed' => ['a..b', 'ab'],
            'special chars become hyphens' => ['a!b', 'a-b'],
        ];
    }

    #[DataProvider('slugNameProvider')]
    public function testGenerateSlugName(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->haxcms->generateSlugName($input));
    }

    public function testGenerateSlugNameNeverEmitsTraversal(): void
    {
        // Security invariant: no path-traversal segment survives, and no
        // backslash survives, regardless of input encoding.
        foreach (['../etc', '../../etc', '..%2fetc', 'a/../b', 'a\\..\\b'] as $input) {
            $slug = $this->haxcms->generateSlugName($input);
            $this->assertStringNotContainsString('..', $slug, "slug for '$input' leaked '..'");
            $this->assertStringNotContainsString('\\', $slug, "slug for '$input' leaked backslash");
        }
    }

    public static function cleanTitleProvider(): array
    {
        return [
            'spaces to hyphens lowercased' => ['Hello World', true, 'hello-world'],
            'page path stripped by default' => ['pages/foo/index.html', true, 'foo'],
            'page path kept when stripPage false' => ['pages/foo/index.html', false, 'pages/foo/index-html'],
            'parent traversal removed' => ['../etc', true, 'etc'],
            'dot slash removed' => ['a ./ b', true, 'a-b'],
            'uppercase lowered' => ['UPPER Case', true, 'upper-case'],
            'empty becomes blank' => ['', true, 'blank'],
        ];
    }

    #[DataProvider('cleanTitleProvider')]
    public function testCleanTitle(string $input, bool $stripPage, string $expected): void
    {
        $this->assertSame($expected, $this->haxcms->cleanTitle($input, $stripPage));
    }

    public static function intConfigProvider(): array
    {
        return [
            'in range passes through' => [7, 5, 1, 10, 7],
            'non-numeric uses fallback in range' => ['notnum', 5, 1, 10, 5],
            'below min clamped' => [0, 5, 1, 10, 1],
            'above max clamped' => [100, 5, 1, 10, 10],
            'numeric string works' => ['8', 5, 1, 10, 8],
            'null uses fallback' => [null, 5, 1, 10, 5],
            'fallback below min clamps to min' => ['x', 0, 1, 10, 1],
            'fallback above max clamps to max' => ['x', 999, 1, 10, 10],
        ];
    }

    #[DataProvider('intConfigProvider')]
    public function testGetIntConfigValue($value, $fallback, $min, $max, int $expected): void
    {
        $this->assertSame($expected, $this->haxcms->getIntConfigValue($value, $fallback, $min, $max));
    }

    public static function safeStringCompareProvider(): array
    {
        return [
            'equal strings true' => ['secret', 'secret', true],
            'different strings false' => ['secret', 'other', false],
            'different length false' => ['a', 'ab', false],
            'non-string stored false' => [123, '123', false],
            'non-string submitted false' => ['123', 123, false],
            'both empty true' => ['', '', true],
            'null submitted false' => ['x', null, false],
        ];
    }

    #[DataProvider('safeStringCompareProvider')]
    public function testSafeStringCompare($stored, $submitted, bool $expected): void
    {
        $this->assertSame($expected, $this->haxcms->safeStringCompare($stored, $submitted));
    }

    /**
     * pageBreakParser must capture bare boolean attributes (hide-in-menu,
     * published, locked) so saveNode can persist them. The browser serializes
     * Lit Boolean reflected attrs (set to "") as BARE words, and
     * parse_attributes stores bare attrs as null. isset() returns false on a
     * null value, so the OLD saveNode code (isset($data['attributes']['hide-in-menu']))
     * always took the else branch and forced hideInMenu=false. saveNode must use
     * array_key_exists to detect presence (mirroring override-pathauto). This
     * test pins both the parser output and the isset-vs-array_key_exists
     * distinction so a regression to isset() is caught.
     */
    public function testPageBreakParserCapturesBareHideInMenuAttribute(): void
    {
        // hide-in-menu as a bare (last) attribute, exactly how the browser
        // serializes a Lit Boolean reflected property set to true.
        $body = '<page-break item-id="item-a" title="Test" hide-in-menu>content</page-break>';
        $pages = $this->haxcms->pageBreakParser($body);
        $this->assertCount(1, $pages);
        $attrs = $pages[0]['attributes'];
        // title and item-id parse as valued attributes
        $this->assertSame('Test', $attrs['title']);
        $this->assertSame('item-a', $attrs['item-id']);
        // hide-in-menu is a bare boolean attr -> parsed as null
        $this->assertArrayHasKey('hide-in-menu', $attrs);
        $this->assertNull($attrs['hide-in-menu']);
        // The root cause: isset() returns false on a null value, so the OLD
        // saveNode code always took the else branch and forced hideInMenu=false.
        // array_key_exists correctly detects presence regardless of null.
        $this->assertFalse(isset($attrs['hide-in-menu']));
        $this->assertTrue(array_key_exists('hide-in-menu', $attrs));
    }

    /**
     * hide-in-menu as a non-last bare attribute (trailing space before the
     * next attr) is also captured as null. pageBreakParser's str_replace only
     * normalizes 'published ' and 'locked ' (with trailing space); hide-in-menu
     * is never normalized, so it always comes through as a bare null attr.
     */
    public function testPageBreakParserCapturesBareHideInMenuWhenNotLast(): void
    {
        $body = '<page-break hide-in-menu item-id="item-a" title="Test">content</page-break>';
        $pages = $this->haxcms->pageBreakParser($body);
        $this->assertCount(1, $pages);
        $attrs = $pages[0]['attributes'];
        $this->assertArrayHasKey('hide-in-menu', $attrs);
        $this->assertNull($attrs['hide-in-menu']);
        $this->assertTrue(array_key_exists('hide-in-menu', $attrs));
    }

    /**
     * When hide-in-menu is absent from the page-break, it must NOT be in the
     * parsed attributes, so saveNode's else branch sets hideInMenu=false.
     */
    public function testPageBreakParserOmitsHideInMenuWhenAbsent(): void
    {
        $body = '<page-break item-id="item-a" title="Test">content</page-break>';
        $pages = $this->haxcms->pageBreakParser($body);
        $this->assertCount(1, $pages);
        $attrs = $pages[0]['attributes'];
        $this->assertArrayNotHasKey('hide-in-menu', $attrs);
        $this->assertFalse(array_key_exists('hide-in-menu', $attrs));
    }
}
