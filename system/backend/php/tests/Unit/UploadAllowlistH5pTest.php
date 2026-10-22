<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for accepting .h5p packages as uploads.
 *
 * An H5P package is a zip archive (h5p.json, content/content.json and the
 * libraries the content needs). The server never unpacks it: it is stored as
 * is, and the h5p-element web component unpacks and plays it in the browser.
 * So it only has to clear the same gates as every other type: the upload
 * extension allowlist, the bulk-import extension allowlist, and the
 * extension -> MIME table checked against the type libmagic reports for the
 * file's own bytes. These mirror the node backend's uploadAllowlistH5p test.
 *
 * The case worth pinning: an upload is only accepted when its bytes really are
 * a zip, so a page or script renamed to .h5p is refused.
 */
class UploadAllowlistH5pTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_h5p_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function writeSample(string $name, string $body): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $body);
        return $path;
    }

    private function package(): string
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ext-zip is not available');
        }
        $path = $this->tmpDir . '/built-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('h5p.json', json_encode(array(
            'title' => 'Sample',
            'mainLibrary' => 'H5P.Blanks',
            'preloadedDependencies' => array(
                array('machineName' => 'H5P.Blanks', 'majorVersion' => 1, 'minorVersion' => 14),
            ),
        )));
        $zip->addFromString('content/content.json', '{}');
        $zip->close();
        return (string) file_get_contents($path);
    }

    private const MARKUP = '<html><body><script>alert(1)</script></body></html>';

    // ---------------------------------------------------------------- the map

    public static function mimeEntryProvider(): array
    {
        return [
            'h5p' => ['h5p', ['application/zip', 'application/x-zip-compressed']],
            'uppercase H5P' => ['H5P', ['application/zip', 'application/x-zip-compressed']],
            'h5pz is a different extension and was not added' => ['h5pz', null],
            'php still never allowed' => ['php', null],
        ];
    }

    #[DataProvider('mimeEntryProvider')]
    public function testMimeTableEntries(string $ext, ?array $expected): void
    {
        $this->assertSame($expected, HAXCMSFile::getAllowedMimeByExtension($ext));
    }

    public function testPackageDoesNotAcceptHtmlScriptsOrPlainText(): void
    {
        foreach (['text/html', 'text/javascript', 'text/plain'] as $mime) {
            $this->assertNotContains($mime, HAXCMSFile::getAllowedMimeByExtension('h5p'), $mime);
        }
    }

    // ------------------------------------------------------- the two patterns

    private function uploadPattern(): string
    {
        $property = new ReflectionProperty('HAXCMSFile', 'allowedUploadPattern');
        return $property->getValue(new HAXCMSFile());
    }

    private function bulkImportPattern(): string
    {
        $property = new ReflectionProperty('Operations', 'safeBulkImportFilePattern');
        return $property->getValue(new Operations());
    }

    public static function extensionGateProvider(): array
    {
        return [
            'package' => ['quiz.h5p', true],
            'uppercase package' => ['QUIZ.H5P', true],
            'nested package' => ['files/h5p/quiz.h5p', true],
            'a type that was already allowed' => ['photo.png', true],
            'h5pz was not added' => ['quiz.h5pz', false],
            'executable still refused' => ['evil.php', false],
            'double extension still refused' => ['evil.h5p.php', false],
        ];
    }

    #[DataProvider('extensionGateProvider')]
    public function testUploadExtensionGate(string $name, bool $expected): void
    {
        $this->assertSame($expected, (bool) preg_match($this->uploadPattern(), $name), $name);
    }

    #[DataProvider('extensionGateProvider')]
    public function testBulkImportExtensionGate(string $name, bool $expected): void
    {
        $this->assertSame($expected, (bool) preg_match($this->bulkImportPattern(), $name), $name);
    }

    public function testBothPatternsStayIdentical(): void
    {
        $this->assertSame($this->uploadPattern(), $this->bulkImportPattern());
    }

    // -------------------------------------------------- content, end to end

    private function validate(string $name, string $body, ?string &$error = null): bool
    {
        $path = $this->writeSample($name, $body);
        $method = new ReflectionMethod('HAXCMSFile', 'validateUploadMimeAndContent');
        $error = null;
        $detected = null;
        $args = [$name, $path, &$error, &$detected];
        return (bool) $method->invokeArgs(new HAXCMSFile(), $args);
    }

    public function testRealPackageIsAccepted(): void
    {
        $this->assertTrue($this->validate('quiz.h5p', $this->package()));
    }

    public function testPageRenamedToH5pIsRefused(): void
    {
        $this->assertFalse($this->validate('fake.h5p', self::MARKUP . "\n"));
    }

    public function testScriptRenamedToH5pIsRefused(): void
    {
        $this->assertFalse($this->validate('fake.h5p', "<?php echo 'x';\n"));
    }
}
