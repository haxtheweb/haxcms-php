<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the SanitizeContent seam.
 *
 * Expected values are derived from the URL-sanitizing security spec, not by
 * copying the implementation: a URL sanitizer must block non-allow-listed
 * schemes (javascript:, data:, vbscript:, file:, ...) including control-char
 * obfuscation and case tricks, while preserving allow-listed schemes
 * (http/https/mailto/tel), scheme-less relative paths, and fragment refs.
 */
class SanitizeContentTest extends TestCase
{
    public static function sanitizeUrlProvider(): array
    {
        return [
            // [input, fallback, expected]
            'allowed https preserved' => ['https://example.com/path', '', 'https://example.com/path'],
            'allowed http preserved' => ['http://example.com', '', 'http://example.com'],
            'allowed mailto preserved' => ['mailto:foo@bar.com', '', 'mailto:foo@bar.com'],
            'allowed tel preserved' => ['tel:+18005551234', '', 'tel:+18005551234'],
            'relative path preserved (no scheme)' => ['/relative/path', '', '/relative/path'],
            'relative file preserved (no scheme)' => ['page.html', '', 'page.html'],
            'fragment preserved' => ['#section', '', '#section'],
            'javascript scheme blocked -> fallback' => ['javascript:alert(1)', '', ''],
            'mixed-case javascript blocked -> fallback' => ['JaVaScRiPt:alert(1)', '', ''],
            'data scheme blocked -> fallback' => ['data:text/html,<script>alert(1)</script>', '', ''],
            'vbscript scheme blocked -> fallback' => ['vbscript:msgbox(1)', '', ''],
            'file scheme blocked -> fallback' => ['file:///etc/passwd', '', ''],
            'control-char obfuscated javascript blocked -> fallback' => ["java\x00script:alert(1)", '', ''],
            'whitespace-trimmed https preserved' => ['  https://example.com  ', '', 'https://example.com'],
            'uppercase scheme preserved verbatim (scheme check is case-insensitive)' => ['HTTPS://example.com', '', 'HTTPS://example.com'],
            'null returns default fallback' => [null, '', ''],
            'null returns custom fallback' => [null, '/home', '/home'],
            'empty string returns fallback' => ['', '', ''],
            'whitespace-only returns fallback' => ['   ', '', ''],
        ];
    }

    #[DataProvider('sanitizeUrlProvider')]
    public function testSanitizeUrlValue(?string $input, string $fallback, string $expected): void
    {
        $this->assertSame($expected, SanitizeContent::sanitizeURLValue($input, $fallback));
    }

    public static function escapeHtmlAttributeProvider(): array
    {
        // Expected values from the HTML attribute escaping spec: & < > " ' must
        // be entity-encoded; single quotes use the numeric HTML entity &#039;.
        return [
            'ampersand escaped' => ['Tom & Jerry', 'Tom &amp; Jerry'],
            'less-than escaped' => ['a < b', 'a &lt; b'],
            'greater-than escaped' => ['a > b', 'a &gt; b'],
            'double quote escaped' => ['"quoted"', '&quot;quoted&quot;'],
            'single quote escaped to numeric entity' => ["it's", 'it&#039;s'],
            'plain text unchanged' => ['plain text', 'plain text'],
            'already-escaped ampersand is re-escaped' => ['a &amp; b', 'a &amp;amp; b'],
            'null becomes empty string' => [null, ''],
            'empty string unchanged' => ['', ''],
        ];
    }

    #[DataProvider('escapeHtmlAttributeProvider')]
    public function testEscapeHtmlAttribute(mixed $input, string $expected): void
    {
        $this->assertSame($expected, SanitizeContent::escapeHTMLAttribute($input));
    }

    public static function escapeXmlValueProvider(): array
    {
        // Expected values from the XML escaping spec: same as HTML but single
        // quotes use the XML entity &apos; (not the HTML numeric &#039;).
        return [
            'ampersand escaped' => ['Tom & Jerry', 'Tom &amp; Jerry'],
            'less-than escaped' => ['a < b', 'a &lt; b'],
            'greater-than escaped' => ['a > b', 'a &gt; b'],
            'double quote escaped' => ['"quoted"', '&quot;quoted&quot;'],
            'single quote escaped to xml apostrophe entity' => ["it's", 'it&apos;s'],
            'plain text unchanged' => ['plain text', 'plain text'],
            'null becomes empty string' => [null, ''],
            'empty string unchanged' => ['', ''],
        ];
    }

    #[DataProvider('escapeXmlValueProvider')]
    public function testEscapeXmlValue(mixed $input, string $expected): void
    {
        $this->assertSame($expected, SanitizeContent::escapeXMLValue($input));
    }

    public static function sanitizeMetadataProvider(): array
    {
        // sanitizeMetadataValue delegates to HTML attribute escaping, so a
        // single quote must become &#039; (HTML), not &apos; (XML).
        return [
            'delegates ampersand escaping' => ['Tom & Jerry', 'Tom &amp; Jerry'],
            'delegates single quote to html entity not xml' => ["it's", 'it&#039;s'],
            'delegates null to empty string' => [null, ''],
            'plain text unchanged' => ['plain', 'plain'],
        ];
    }

    #[DataProvider('sanitizeMetadataProvider')]
    public function testSanitizeMetadataValueDelegatesToHtmlAttributeEscaping(mixed $input, string $expected): void
    {
        $this->assertSame($expected, SanitizeContent::sanitizeMetadataValue($input));
    }

    public function testSanitizeHtmlForStorageRemovesForbiddenTags(): void
    {
        $input = '<p>keep me</p><script>alert(1)</script><style>.x{color:red}</style>' .
            '<meta http-equiv="refresh" content="0;url=evil"><link rel="stylesheet" href="evil.css">';
        $out = SanitizeContent::sanitizeHTMLForStorage($input);
        $this->assertStringContainsString('keep me', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringNotContainsString('<meta', $out);
        $this->assertStringNotContainsString('<link', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('color:red', $out);
        $this->assertStringNotContainsString('evil', $out);
    }

    public function testSanitizeHtmlForStorageRemovesOnEventAttributes(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<p onclick="steal()" onload="x()">visible text</p>');
        $this->assertStringContainsString('visible text', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('steal', $out);
    }

    public function testSanitizeHtmlForStorageRemovesStyleAndSrcdocAttributes(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<p style="color:red" srcdoc="payload">visible</p>');
        $this->assertStringContainsString('visible', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('srcdoc', $out);
        $this->assertStringNotContainsString('color:red', $out);
        $this->assertStringNotContainsString('payload', $out);
    }

    public function testSanitizeHtmlForStorageSanitizesDangerousUrlSchemes(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<a href="javascript:alert(1)">click</a><img src="javascript:alert(2)">');
        $this->assertStringContainsString('click', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('alert', $out);
    }

    public function testSanitizeHtmlForStoragePreservesSafeUrls(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<a href="https://example.com/path">link</a>');
        $this->assertStringContainsString('href="https://example.com/path"', $out);
        $this->assertStringContainsString('link', $out);
    }

    public function testSanitizeHtmlForStoragePreservesSafeNestedContent(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<div><h1>Title</h1><p>Body text</p></div>');
        $this->assertStringContainsString('<div>', $out);
        $this->assertStringContainsString('<h1>Title</h1>', $out);
        $this->assertStringContainsString('<p>Body text</p>', $out);
    }

    public function testSanitizeHtmlForStorageNormalizesIframeAttributes(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<iframe src="https://example.com"></iframe>');
        $this->assertStringContainsString('src="https://example.com"', $out);
        $this->assertStringContainsString('loading="lazy"', $out);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $out);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-popups allow-forms"', $out);
    }

    public function testSanitizeHtmlForStorageHardensIframeWithDangerousAttrs(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<iframe src="javascript:alert(1)" onload="alert(2)" allow="autoplay"></iframe>');
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('alert', $out);
        // unsafe src removed and onload removed, but safe defaults still applied
        $this->assertStringContainsString('loading="lazy"', $out);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-popups allow-forms"', $out);
        // allowlisted iframe attribute survives
        $this->assertStringContainsString('allow="autoplay"', $out);
    }

    public function testSanitizeHtmlForStorageFlattensTemplatesInTextTemplateHosts(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<code-sample><template><script>alert(1)</script></template></code-sample>');
        $this->assertStringContainsString('code-sample', $out);
        $this->assertStringContainsString('template', $out);
        // script must be inert escaped text, not a live element
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('alert(1)', $out);
    }

    public function testSanitizeHtmlForStorageRecursesIntoTemplatesOutsideHosts(): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage('<div><template><script>alert(1)</script><p>safe</p></template></div>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('safe', $out);
    }

    public static function nonStringInputProvider(): array
    {
        return [
            'integer rejected' => [123],
            'null rejected' => [null],
            'array rejected' => [['a' => 'b']],
            'boolean rejected' => [true],
        ];
    }

    #[DataProvider('nonStringInputProvider')]
    public function testSanitizeHtmlForStorageRejectsNonStringInput(mixed $input): void
    {
        $this->assertSame('', SanitizeContent::sanitizeHTMLForStorage($input));
    }

    public function testSanitizeHtmlForStorageHandlesEmptyString(): void
    {
        $this->assertSame('', SanitizeContent::sanitizeHTMLForStorage(''));
    }
}
