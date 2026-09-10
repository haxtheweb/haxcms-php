<?php
use PHPUnit\Framework\TestCase;

/**
 * Security (HAX-SEC-004 PHP parity) tests for the maxUploadSizeMb app-layer
 * cap in HAXCMSFile::save(), mirroring the NodeJS backend
 * (HAXCMSFile.js:771-787).
 *
 * The cap is enforced BEFORE MIME/image validation and is independent of
 * is_uploaded_file() (which is false under CLI), so the over-cap early
 * return is directly testable. Under-cap / no-cap inputs fall through to
 * the existing 'Invalid upload source' CLI failure, proving the guard does
 * not false-fire.
 */
class HAXCMSFileSizeLimitTest extends TestCase
{
    private $tmpBase;
    private $configDir;
    private $savedHaxcms;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/haxcms_sizelimit_' . uniqid();
        $this->configDir = $this->tmpBase . '/_config';
        mkdir($this->configDir . '/settings', 0777, true);
        $mock = new stdClass();
        $mock->configDirectory = $this->configDir;
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = $mock;
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpBase);
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
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeMediaSettings(?int $maxUploadSizeMb): void
    {
        $payload = array();
        if ($maxUploadSizeMb !== null) {
            $payload['maxUploadSizeMb'] = $maxUploadSizeMb;
        }
        file_put_contents(
            $this->configDir . '/settings/media.json',
            json_encode($payload, JSON_PRETTY_PRINT)
        );
    }

    private function makeUpload(int $size, string $name = 'test.txt'): array
    {
        return array(
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $this->tmpBase . '/nonexistent-tmp-' . uniqid(),
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
        );
    }

    public function testOverCapReturnsSizeLimitBeforeValidation(): void
    {
        $this->writeMediaSettings(1); // 1 MB cap
        $file = new HAXCMSFile();
        $result = $file->save($this->makeUpload(2 * 1024 * 1024), 'system/tmp');
        $this->assertSame(500, $result['status']);
        $this->assertSame(
            'File exceeds the maximum upload size of 1MB',
            $result['data']
        );
    }

    public function testUnderCapFallsThroughWithoutSizeLimitMessage(): void
    {
        $this->writeMediaSettings(1); // 1 MB cap
        $file = new HAXCMSFile();
        // 500 KB is under the 1 MB cap; under CLI is_uploaded_file() is false
        // so save() returns the existing 'Invalid upload source' failure -- NOT
        // the size-limit message, proving the guard does not false-fire.
        $result = $file->save($this->makeUpload(500 * 1024), 'system/tmp');
        $this->assertSame(500, $result['status']);
        $this->assertNotSame(
            'File exceeds the maximum upload size of 1MB',
            $result['data']
        );
    }

    public function testNoCapConfiguredDoesNotEnforce(): void
    {
        // No media.json written -> readMediaSettings returns maxUploadSizeMb=null
        $file = new HAXCMSFile();
        $result = $file->save($this->makeUpload(2 * 1024 * 1024), 'system/tmp');
        $this->assertSame(500, $result['status']);
        $this->assertNotSame(
            'File exceeds the maximum upload size of 1MB',
            $result['data']
        );
    }

    public function testSizeExactlyAtCapFallsThrough(): void
    {
        $this->writeMediaSettings(1); // 1 MB cap = 1048576 bytes
        $file = new HAXCMSFile();
        // equal to the cap is NOT over the cap (strict >), so it falls through.
        $result = $file->save($this->makeUpload(1048576), 'system/tmp');
        $this->assertNotSame(
            'File exceeds the maximum upload size of 1MB',
            $result['data']
        );
    }

    public function testSizeOneByteOverCapRejected(): void
    {
        $this->writeMediaSettings(1); // 1 MB cap = 1048576 bytes
        $file = new HAXCMSFile();
        $result = $file->save($this->makeUpload(1048577), 'system/tmp');
        $this->assertSame(
            'File exceeds the maximum upload size of 1MB',
            $result['data']
        );
    }

    public function testFilesizeFallbackUsedWhenUploadSizeMissing(): void
    {
        $this->writeMediaSettings(1); // 1 MB cap
        // Stage a real file over the cap and omit upload['size'] so the guard
        // must fall back to filesize() to determine the actual size.
        $oversizePath = $this->tmpBase . '/oversize.bin';
        // 2 MB of zeros
        file_put_contents($oversizePath, str_repeat("\0", 2 * 1024 * 1024));
        $upload = array(
            'name' => 'oversize.bin',
            'type' => 'application/octet-stream',
            'tmp_name' => $oversizePath,
            'error' => UPLOAD_ERR_OK,
        );
        $file = new HAXCMSFile();
        $result = $file->save($upload, 'system/tmp');
        $this->assertSame(
            'File exceeds the maximum upload size of 1MB',
            $result['data']
        );
    }
}
