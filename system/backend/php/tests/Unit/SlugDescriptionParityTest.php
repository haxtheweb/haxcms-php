<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * nodejs / PHP parity for the site data written by slug generation and the
 * auto page description (haxtheweb/issues#3292):
 *   - cleanTitle / generateSlugName follow one rule in both backends; the
 *     vectors in tests/fixtures/slug-vectors.json are shared verbatim with
 *     haxcms-nodejs test/fixtures/slug-vectors.json
 *   - SanitizeContent::pageDescription is plain text from the page body
 *     only, every newline removed, cut at 200 characters (not bytes); vectors
 *     in tests/fixtures/page-description-vectors.json are shared with
 *     haxcms-nodejs
 * The real HAXCMS methods run on an instance created without its stateful
 * constructor (same seam as HAXCMSHelpersTest).
 */
class SlugDescriptionParityTest extends TestCase
{
    private $haxcms;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
    }

    private static function fixture(string $name): array
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/' . $name);
        return json_decode($raw, true);
    }

    public static function slugVectorProvider(): array
    {
        $cases = array();
        foreach (self::fixture('slug-vectors.json')['cases'] as $i => $case) {
            $cases[$i . ': ' . json_encode($case['input'], JSON_UNESCAPED_UNICODE)] = array(
                $case['input'],
                $case['cleanTitle'],
                $case['cleanTitleKeepPage'],
                $case['generateSlugName'],
            );
        }
        return $cases;
    }

    #[DataProvider('slugVectorProvider')]
    public function testSharedSlugVectors(string $input, string $cleanTitle, string $keepPage, string $slug): void
    {
        $this->assertSame($cleanTitle, $this->haxcms->cleanTitle($input));
        $this->assertSame($keepPage, $this->haxcms->cleanTitle($input, false));
        $this->assertSame($slug, $this->haxcms->generateSlugName($input));
    }

    public function testReportedParityCases(): void
    {
        $this->assertSame('c-c', $this->haxcms->cleanTitle('C++ & C#'));
        $this->assertSame('c-c', $this->haxcms->generateSlugName('C++ & C#'));
        $this->assertSame('日本語のページ', $this->haxcms->cleanTitle('日本語のページ'));
        $this->assertSame('日本語のページ', $this->haxcms->generateSlugName('日本語のページ'));
        $this->assertSame('über-cool', $this->haxcms->cleanTitle('Über cool'));
        // generateSlugName used ASCII strtolower and kept "Ü" upper case
        $this->assertSame('über-cool', $this->haxcms->generateSlugName('Über cool'));
    }

    public function testEmptyResultsBecomeBlank(): void
    {
        foreach (array('', '   ', '...', '---', '-./-', '%', '😀') as $input) {
            $this->assertSame('blank', $this->haxcms->cleanTitle($input), $input);
            $this->assertSame('blank', $this->haxcms->generateSlugName($input), $input);
        }
        $this->assertSame('blank', $this->haxcms->generateSlugName(null));
    }

    public function testNoTraversalBackslashOrNullByteSurvives(): void
    {
        $inputs = array(
            '../etc/passwd',
            '../../etc',
            '..%2fetc',
            '%2e%2e%2f%2e%2e%2fetc',
            'a/../b',
            'a\\..\\b',
            '..\\..\\windows',
            './foo/./bar',
            'foo%00bar',
            "a\x00b",
            '%C0%AE%C0%AE/etc',
            '....//....//etc',
            '/../',
        );
        foreach ($inputs as $input) {
            $slugs = array(
                $this->haxcms->cleanTitle($input),
                $this->haxcms->cleanTitle($input, false),
                $this->haxcms->generateSlugName($input),
            );
            foreach ($slugs as $slug) {
                $this->assertStringNotContainsString('..', $slug, $input);
                $this->assertStringNotContainsString('\\', $slug, $input);
                $this->assertStringNotContainsString("\x00", $slug, $input);
                $this->assertMatchesRegularExpression('/^[\p{L}\p{N}_\/-]+$/u', $slug, $input);
                $this->assertDoesNotMatchRegularExpression('/^[-.\/]|[-.\/]$/', $slug, $input);
            }
        }
    }

    public function testCleanTitleNeverMapsASiteNameToTheSitesDirectory(): void
    {
        foreach (array('/', '%2F', '//', './', '../', '.', '..') as $name) {
            $this->assertSame('blank', $this->haxcms->cleanTitle(urldecode($name), false), $name);
        }
    }

    public function testReservedRouteSegmentRule(): void
    {
        // same cases as haxcms-nodejs test/unit/slug-description-parity
        $cases = array(
            'x' => 'x-x',
            'x/a' => 'x-x/a',
            'a/x' => 'a/x-x',
            'a/x/b' => 'a/x-x/b',
            'x/inbox/a' => 'x-x/inbox/a',
            'x-x' => 'x-x',
            'xx' => 'xx',
            'box/x' => 'box/x-x',
            'inbox/a' => 'inbox/a',
        );
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, HAXCMSSite::reserveSlugRoutes($input), $input);
            // idempotent
            $this->assertSame($expected, HAXCMSSite::reserveSlugRoutes($expected), $input);
        }
        $this->assertSame('x-x/search', HAXCMSSite::reserveSlugRoutes($this->haxcms->generateSlugName('X/Search')));
    }

    public function testInvalidUtf8AfterDecodingIsASeparatorNotAFailure(): void
    {
        // %E6 alone is a truncated UTF-8 sequence; it must not null the slug
        $this->assertSame('日', $this->haxcms->generateSlugName('%E6%97%A5%E6'));
        $this->assertSame('a-b', $this->haxcms->generateSlugName('a%FFb'));
    }

    public function testCharacterClassDoesNotDependOnPcreVersion(): void
    {
        // PCRE2 10.43+ \w under /u also matches combining marks (Mn) and
        // connector punctuation (Pc); the explicit class keeps letters and
        // digits only, as JS \p{L}\p{N} does
        $this->assertSame('a-b', $this->haxcms->cleanTitle("a\u{203F}b"));
        $this->assertSame('cafe-ok', $this->haxcms->cleanTitle("cafe\u{0301} ok"));
        $this->assertSame('①²-digits', $this->haxcms->cleanTitle('①² digits'));
    }

    public function testLongSeparatorRunsStayFast(): void
    {
        $input = 'a' . str_repeat('-./', 20000) . '!' . str_repeat('-', 20000);
        $started = microtime(true);
        $this->assertSame('a', $this->haxcms->cleanTitle($input));
        $this->assertSame('a', $this->haxcms->generateSlugName($input));
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public static function descriptionVectorProvider(): array
    {
        $cases = array();
        foreach (self::fixture('page-description-vectors.json')['cases'] as $case) {
            $cases[$case['name']] = array($case['body'], $case['expected']);
        }
        return $cases;
    }

    #[DataProvider('descriptionVectorProvider')]
    public function testSharedDescriptionVectors(string $body, string $expected): void
    {
        $this->assertSame($expected, SanitizeContent::pageDescription($body));
    }

    public function testDescriptionCutsAt200CharactersNotBytes(): void
    {
        $this->assertSame(
            str_repeat('é', 200),
            SanitizeContent::pageDescription('<p>' . str_repeat('é', 300) . '</p>')
        );
        $astral = SanitizeContent::pageDescription('<p>' . str_repeat('😀', 300) . '</p>');
        $this->assertSame(200, mb_strlen($astral, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($astral, 'UTF-8'));
    }

    public function testDescriptionRemovesEveryNewline(): void
    {
        $this->assertSame(
            'abcd',
            SanitizeContent::pageDescription("<p>a</p>\n<p>b</p>\r\n<p>c\nd</p>")
        );
    }

    public function testDescriptionOfNonStringIsEmpty(): void
    {
        $this->assertSame('', SanitizeContent::pageDescription(null));
        $this->assertSame('', SanitizeContent::pageDescription(array('x')));
    }
}
