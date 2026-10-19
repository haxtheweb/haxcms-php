<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the private image-processing helpers on
 * Operations.php (lib/Operations.php): convertImageToJpgFile,
 * scaleImageInPlaceFile, compressImageInPlaceFile, rotateImageInPlaceFile,
 * transformImageInPlaceFile. These helpers are invoked indirectly by
 * fileOperation() (see OperationsFileOperationTest.php for the route-level
 * success paths), but are tested here directly via reflection against small
 * real GD-generated PNG/JPG fixtures so error branches unreachable from the
 * route (e.g. non-image source, unsupported format, unsupported transform
 * mode) are exercised without needing to fabricate route-level plumbing.
 *
 * Note: the former scaleImageToPresetFile / buildImageOpsOutputPath /
 * ensureImageOpsOutputDirectory / getSafeImageOpsBaseName helpers had no
 * production call sites and were removed from Operations.php as dead code.
 */
class OperationsImageOpsTest extends TestCase
{
    private $ops;
    private $tmpDir;

    protected function setUp(): void
    {
        $this->ops = new Operations();
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_ops_imageops_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod('Operations', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->ops, $args);
    }

    private function makePng(string $path, int $width = 20, int $height = 15): void
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 10, 200, 90);
        imagefill($img, 0, 0, $color);
        imagepng($img, $path);
        imagedestroy($img);
    }

    private function makeJpg(string $path, int $width = 20, int $height = 15): void
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 200, 50, 10);
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $path, 90);
        imagedestroy($img);
    }

    private function makeTextFile(string $path): void
    {
        file_put_contents($path, 'not an image');
    }

    // =========================================================================
    // convertImageToJpgFile
    // =========================================================================

    public function testConvertImageToJpgFileSucceedsWithNoneTransform(): void
    {
        $source = $this->tmpDir . '/source.png';
        $output = $this->tmpDir . '/output.jpg';
        $this->makePng($source);

        $result = $this->invokePrivate('convertImageToJpgFile', [$source, $output, 'none', null]);

        $this->assertTrue($result['success']);
        $this->assertSame(20, $result['width']);
        $this->assertSame(15, $result['height']);
        $this->assertTrue(file_exists($output));
        $info = @getimagesize($output);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function testConvertImageToJpgFileSucceedsWithSepiaTransform(): void
    {
        $source = $this->tmpDir . '/source.png';
        $output = $this->tmpDir . '/output-sepia.jpg';
        $this->makePng($source);

        $result = $this->invokePrivate('convertImageToJpgFile', [$source, $output, 'sepia', 80]);

        $this->assertTrue($result['success']);
        $this->assertTrue(file_exists($output));
    }

    public function testConvertImageToJpgFileSucceedsWithBlackAndWhiteTransform(): void
    {
        $source = $this->tmpDir . '/source.png';
        $output = $this->tmpDir . '/output-bw.jpg';
        $this->makePng($source);

        $result = $this->invokePrivate('convertImageToJpgFile', [$source, $output, 'black-and-white', null]);

        $this->assertTrue($result['success']);
        $this->assertTrue(file_exists($output));
    }

    public function testConvertImageToJpgFileRejectsUnsupportedTransformMode(): void
    {
        $source = $this->tmpDir . '/source.png';
        $output = $this->tmpDir . '/output.jpg';
        $this->makePng($source);

        $result = $this->invokePrivate('convertImageToJpgFile', [$source, $output, 'negative', null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Unsupported transform operation', $result['message']);
    }

    public function testConvertImageToJpgFileRejectsNonImageSource(): void
    {
        $source = $this->tmpDir . '/source.txt';
        $output = $this->tmpDir . '/output.jpg';
        $this->makeTextFile($source);

        $result = $this->invokePrivate('convertImageToJpgFile', [$source, $output, 'none', null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Only raster images can be converted to JPG', $result['message']);
    }

    // =========================================================================
    // scaleImageInPlaceFile
    // =========================================================================

    public function testScaleImageInPlaceFileSucceedsAndShrinksDimensions(): void
    {
        $source = $this->tmpDir . '/scale.png';
        $this->makePng($source, 1000, 1000);

        $result = $this->invokePrivate('scaleImageInPlaceFile', [$source, 100, 100, null]);

        $this->assertTrue($result['success']);
        $this->assertSame(100, $result['width']);
        $this->assertSame(100, $result['height']);
        $info = @getimagesize($source);
        $this->assertNotFalse($info);
        $this->assertSame(100, $info[0]);
        $this->assertSame(100, $info[1]);
    }

    public function testScaleImageInPlaceFileDoesNotUpscaleBeyondSource(): void
    {
        $source = $this->tmpDir . '/scale-small.png';
        $this->makePng($source, 20, 15);

        $result = $this->invokePrivate('scaleImageInPlaceFile', [$source, 800, 800, null]);

        $this->assertTrue($result['success']);
        // ratio capped at 1: never upscale beyond the original size
        $this->assertSame(20, $result['width']);
        $this->assertSame(15, $result['height']);
    }

    public function testScaleImageInPlaceFileRejectsNonImageSource(): void
    {
        $source = $this->tmpDir . '/scale.txt';
        $this->makeTextFile($source);

        $result = $this->invokePrivate('scaleImageInPlaceFile', [$source, 100, 100, null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Only raster images can be scaled', $result['message']);
    }

    public function testScaleImageInPlaceFileRejectsUnsupportedFormat(): void
    {
        // A .bmp-extension file whose content is actually a valid PNG so
        // imagecreatefromstring() succeeds but getImageExtensionForRotation
        // (keyed off the filename extension) reports an unsupported format.
        $source = $this->tmpDir . '/scale.bmp';
        $this->makePng($source);

        $result = $this->invokePrivate('scaleImageInPlaceFile', [$source, 100, 100, null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Image format does not support in-place scaling', $result['message']);
    }

    // =========================================================================
    // compressImageInPlaceFile
    // =========================================================================

    public function testCompressImageInPlaceFileSucceeds(): void
    {
        $source = $this->tmpDir . '/compress.jpg';
        $this->makeJpg($source, 200, 200);
        $before = filesize($source);

        $result = $this->invokePrivate('compressImageInPlaceFile', [$source, 30]);

        $this->assertTrue($result['success']);
        $this->assertTrue(file_exists($source));
        $info = @getimagesize($source);
        $this->assertNotFalse($info);
        // Not asserting a strict size decrease (tiny fixture images can vary),
        // only that the file remains a valid image after in-place compression.
        $this->assertGreaterThan(0, filesize($source));
    }

    public function testCompressImageInPlaceFileRejectsNonImageSource(): void
    {
        $source = $this->tmpDir . '/compress.txt';
        $this->makeTextFile($source);

        $result = $this->invokePrivate('compressImageInPlaceFile', [$source, null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Only raster images can be compressed', $result['message']);
    }

    public function testCompressImageInPlaceFileRejectsUnsupportedFormat(): void
    {
        $source = $this->tmpDir . '/compress.bmp';
        $this->makePng($source);

        $result = $this->invokePrivate('compressImageInPlaceFile', [$source, null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Image format does not support in-place compression', $result['message']);
    }

    // =========================================================================
    // rotateImageInPlaceFile
    // =========================================================================

    public function testRotateImageInPlaceFileSucceedsAndSwapsDimensions(): void
    {
        $source = $this->tmpDir . '/rotate.png';
        $this->makePng($source, 20, 15);

        $result = $this->invokePrivate('rotateImageInPlaceFile', [$source, 90]);

        $this->assertTrue($result['success']);
        $info = @getimagesize($source);
        $this->assertNotFalse($info);
        // A 90deg rotation of a 20x15 image yields 15x20 dimensions.
        $this->assertSame(15, $info[0]);
        $this->assertSame(20, $info[1]);
    }

    public function testRotateImageInPlaceFileDefaultsToNinetyWhenDegreesIsZeroOrNegative(): void
    {
        $source = $this->tmpDir . '/rotate-default.png';
        $this->makePng($source, 20, 15);

        $result = $this->invokePrivate('rotateImageInPlaceFile', [$source, 0]);

        $this->assertTrue($result['success']);
        $info = @getimagesize($source);
        // degrees <= 0 falls back to 90, so dimensions still swap.
        $this->assertSame(15, $info[0]);
        $this->assertSame(20, $info[1]);
    }

    public function testRotateImageInPlaceFileRejectsNonImageSource(): void
    {
        $source = $this->tmpDir . '/rotate.txt';
        $this->makeTextFile($source);

        $result = $this->invokePrivate('rotateImageInPlaceFile', [$source, 90]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Only raster images can be rotated', $result['message']);
    }

    public function testRotateImageInPlaceFileRejectsUnsupportedFormat(): void
    {
        $source = $this->tmpDir . '/rotate.bmp';
        $this->makePng($source);

        $result = $this->invokePrivate('rotateImageInPlaceFile', [$source, 90]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Image format does not support in-place rotation', $result['message']);
    }

    // =========================================================================
    // transformImageInPlaceFile
    // =========================================================================

    public function testTransformImageInPlaceFileSucceedsWithSepia(): void
    {
        $source = $this->tmpDir . '/transform-sepia.png';
        $this->makePng($source);

        $result = $this->invokePrivate('transformImageInPlaceFile', [$source, 'sepia', null]);

        $this->assertTrue($result['success']);
        $info = @getimagesize($source);
        $this->assertNotFalse($info);
    }

    public function testTransformImageInPlaceFileSucceedsWithBlackAndWhite(): void
    {
        $source = $this->tmpDir . '/transform-bw.png';
        $this->makePng($source);

        $result = $this->invokePrivate('transformImageInPlaceFile', [$source, 'black-and-white', null]);

        $this->assertTrue($result['success']);
    }

    public function testTransformImageInPlaceFileRejectsUnsupportedMode(): void
    {
        $source = $this->tmpDir . '/transform.png';
        $this->makePng($source);

        $result = $this->invokePrivate('transformImageInPlaceFile', [$source, 'invert', null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Unsupported in-place transform operation', $result['message']);
    }

    public function testTransformImageInPlaceFileRejectsNonImageSource(): void
    {
        $source = $this->tmpDir . '/transform.txt';
        $this->makeTextFile($source);

        $result = $this->invokePrivate('transformImageInPlaceFile', [$source, 'sepia', null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Only raster images can be transformed', $result['message']);
    }

    public function testTransformImageInPlaceFileRejectsUnsupportedFormat(): void
    {
        $source = $this->tmpDir . '/transform.bmp';
        $this->makePng($source);

        $result = $this->invokePrivate('transformImageInPlaceFile', [$source, 'sepia', null]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Image format does not support in-place transform', $result['message']);
    }
}
