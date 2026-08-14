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
}
