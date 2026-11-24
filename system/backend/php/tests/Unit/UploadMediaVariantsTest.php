<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audio / video uploads whose bytes vary by the tool that wrote them.
 *
 * A .mp4 from a phone, screen recorder or editor can carry a QuickTime or
 * M4A brand, can have a free box ahead of ftyp, and an .mp3 can start with
 * zero padding before its first frame. Each plays in a browser, so each is
 * accepted; anything that is not media is still refused. Mirrors the node
 * backend's test/unit/uploadMediaVariants.test.cjs.
 */
class UploadMediaVariantsTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_media_' . uniqid();
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

    private static function box(string $type, string $payload = ''): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    private static function ftyp(string $brand): string
    {
        return self::box('ftyp', $brand . "\0\0\0\0isom" . $brand);
    }

    private static function mp3Frame(): string
    {
        return "\xff\xfb\x90\x64" . str_repeat("\x55", 413);
    }

    public static function acceptedProvider(): array
    {
        return [
            'isom mp4' => ['isom.mp4', self::ftyp('isom') . self::box('mdat', 'x')],
            'quicktime brand mp4' => ['qt.mp4', self::ftyp('qt  ') . self::box('mdat', 'x')],
            'm4a brand mp4' => ['m4a.mp4', self::ftyp('M4A ') . self::box('mdat', 'x')],
            'free box before ftyp' => ['free.mp4', self::box('free') . self::ftyp('mp42') . self::box('mdat')],
            'mov without ftyp' => ['noftyp.mov', self::box('moov', 'x') . self::box('mdat', 'x')],
            'mp4 brand mov' => ['iso.mov', self::ftyp('isom') . self::box('mdat', 'x')],
            'mp3 frame' => ['frame.mp3', self::mp3Frame()],
            'zero padded mp3' => ['padded.mp3', str_repeat("\0", 64) . self::mp3Frame()],
            'id3 mp3' => ['id3.mp3', "ID3\x04\0\0\0\0\0\0" . self::mp3Frame()],
        ];
    }

    public static function refusedProvider(): array
    {
        return [
            'page named mp4' => ['page.mp4', '<html><body><script>alert(1)</script></body>'],
            'text named mp3' => ['text.mp3', "just some words that are not audio at all\n"],
            'zeros named mp3' => ['zeros.mp3', str_repeat("\0", 512)],
            'zip named mp4' => ['zip.mp4', "PK\x03\x04\0\0\0\0"],
        ];
    }

    private function validate(string $name, string $bytes): bool
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $bytes);
        $method = new ReflectionMethod('HAXCMSFile', 'validateUploadMimeAndContent');
        $error = '';
        $detected = null;
        $args = [$name, $path, &$error, &$detected];
        return (bool) $method->invokeArgs(new HAXCMSFile(), $args);
    }

    #[DataProvider('acceptedProvider')]
    public function testMediaVariantsAreAccepted(string $name, string $bytes): void
    {
        $this->assertTrue($this->validate($name, $bytes), $name);
    }

    #[DataProvider('refusedProvider')]
    public function testNonMediaIsRefused(string $name, string $bytes): void
    {
        $this->assertFalse($this->validate($name, $bytes), $name);
    }

    public function testMediaFamilyIsSharedByMp4AndMovOnly(): void
    {
        $mp4 = HAXCMSFile::getAllowedMimeByExtension('mp4');
        $mov = HAXCMSFile::getAllowedMimeByExtension('mov');
        $this->assertContains('video/quicktime', $mp4);
        $this->assertContains('video/mp4', $mov);
        foreach (['text/html', 'text/plain', 'application/octet-stream'] as $mime) {
            $this->assertNotContains($mime, $mp4, $mime);
            $this->assertNotContains($mime, $mov, $mime);
        }
    }
}
