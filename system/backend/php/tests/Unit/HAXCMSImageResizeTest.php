<?php
use PHPUnit\Framework\TestCase;
use \Gumlet\ImageResizeException;

require_once __DIR__ . '/../../lib/HAXCMSImageResize.php';

/**
 * HAXCMSImageResize keeps PHP's image size limit equal to haxcms-nodejs,
 * where sharp refuses inputs over 16383 x 16383 pixels.
 */
class HAXCMSImageResizeTest extends TestCase
{
    /** 1x1 PNG (same payload the MaterializeInlineImages tests use). */
    private $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /**
     * A PNG made of the signature and an IHDR chunk only. getimagesize()
     * reads the dimensions from IHDR, so the size check runs on it without
     * allocating a canvas of that size.
     */
    private function pngHeaderOnly(int $width, int $height): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    private function constructionError(string $bytes): string
    {
        // the header-only PNG cannot be decoded; GD's warnings about that
        // are expected here and are not what the test checks
        set_error_handler(function () {
            return true;
        }, E_WARNING);
        try {
            HAXCMSImageResize::createFromBytes($bytes);
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            restore_error_handler();
        }
        return '';
    }

    public function testLimitMatchesSharpDefault(): void
    {
        $this->assertSame(16383 * 16383, HAXCMSImageResize::MAX_SOURCE_PIXELS);
        $image = HAXCMSImageResize::createFromBytes(base64_decode($this->pngBase64));
        $this->assertInstanceOf(HAXCMSImageResize::class, $image);
        $this->assertSame(16383 * 16383, $image->max_source_pixels);
    }

    public function testCreateFromBytesLoadsAnImage(): void
    {
        $image = HAXCMSImageResize::createFromBytes(base64_decode($this->pngBase64));
        $this->assertSame(1, $image->getSourceWidth());
        $this->assertSame(1, $image->getSourceHeight());
    }

    public function testCreateFromBytesRejectsEmptyInput(): void
    {
        $this->expectException(ImageResizeException::class);
        HAXCMSImageResize::createFromBytes('');
    }

    public function testImageOverGumletDefaultButUnderSharpLimitPassesSizeCheck(): void
    {
        // 5000 x 5000 = 25 MP: over gumlet's 15 MP default, under sharp's cap.
        // The header-only PNG then fails to decode, but not on the size check.
        $message = $this->constructionError($this->pngHeaderOnly(5000, 5000));
        $this->assertStringNotContainsString('Source image is too large', $message);
    }

    public function testImageOverSharpLimitIsRefusedBeforeDecoding(): void
    {
        $message = $this->constructionError($this->pngHeaderOnly(16384, 16384));
        $this->assertStringContainsString('Source image is too large', $message);
    }
}
