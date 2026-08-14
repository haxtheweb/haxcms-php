<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the HAXCMSFile security-guard seam.
 *
 * Expected values come from the path-containment / upload-type spec, not from
 * the implementation: a path is "within root" only after separator
 * normalization and only when it equals root or sits beneath root + '/';
 * a sibling directory that merely shares a name prefix must be rejected. The
 * MIME map must allow known media/office types (case-insensitively, with htm
 * folded into html) and must never surface executable types like php.
 */
class HAXCMSFileTest extends TestCase
{
    public static function pathWithinRootProvider(): array
    {
        return [
            'directly inside root' => ['/var/www/files/img.png', '/var/www/files', true],
            'equal to root' => ['/var/www/files', '/var/www/files', true],
            'root trailing slash equals' => ['/var/www/files/', '/var/www/files', true],
            'nested inside root' => ['/var/www/files/sub/dir/x.txt', '/var/www/files', true],
            'nested with trailing slash' => ['/var/www/files/sub/', '/var/www/files', true],
            // critical: a sibling whose name starts with the root name must NOT
            // be treated as inside (classic prefix-match containment bug).
            'sibling prefix attack rejected' => ['/var/www/files-evil/x', '/var/www/files', false],
            'completely outside' => ['/etc/passwd', '/var/www/files', false],
            'parent of root rejected' => ['/var/www', '/var/www/files', false],
            'backslash separators normalized' => ['C:/www/files/img.png', 'C:\\www\\files', true],
            'backslash sibling rejected' => ['C:/www/files-evil/x', 'C:\\www\\files', false],
        ];
    }

    #[DataProvider('pathWithinRootProvider')]
    public function testIsPathWithinRoot(string $path, string $root, bool $expected): void
    {
        $this->assertSame($expected, HAXCMSFile::isPathWithinRoot($path, $root));
    }

    public static function allowedMimeProvider(): array
    {
        return [
            'jpg' => ['jpg', ['image/jpeg']],
            'case-insensitive JPG' => ['JPG', ['image/jpeg']],
            'jpeg' => ['jpeg', ['image/jpeg']],
            'png' => ['png', ['image/png']],
            'htm folded into html' => ['htm', ['text/html', 'application/xhtml+xml']],
            'html' => ['html', ['text/html', 'application/xhtml+xml']],
            'mp3 has two allowed mimes' => ['mp3', ['audio/mpeg', 'audio/mp3']],
            'svg' => ['svg', ['image/svg+xml']],
            'php executable never allowed' => ['php', null],
            'phar never allowed' => ['phar', null],
            'unknown extension null' => ['totallyfake', null],
            'empty string null' => ['', null],
        ];
    }

    #[DataProvider('allowedMimeProvider')]
    public function testGetAllowedMimeByExtension(string $ext, ?array $expected): void
    {
        $this->assertSame($expected, HAXCMSFile::getAllowedMimeByExtension($ext));
    }
}
