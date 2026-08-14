<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the bulk-import tmp-path containment seam.
 *
 * Expected values come from the bulk-import security spec: only a real
 * (non-symlink) file that realpath-resolves beneath the staging root is
 * accepted; relative paths, scheme-prefixed URIs, null bytes, symlinks
 * (TOCTOU defense), missing files and non-strings are rejected.
 */
class HAXCMSFileBulkImportTest extends TestCase
{
    private $tmpBase;
    private $stagingRoot;
    private $insideFile;
    private $outsideFile;
    private $symlinkPath;
    private $savedHaxcms;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/haxcms_file_test_' . uniqid();
        $configDir = $this->tmpBase . '/config';
        // recursive mkdir creates config/tmp, config/tmp/imports and .../sub
        mkdir($configDir . '/tmp/imports/sub', 0777, true);
        $this->stagingRoot = $configDir . '/tmp/imports';
        $this->insideFile = $this->stagingRoot . '/sub/inside.txt';
        file_put_contents($this->insideFile, 'payload');
        $this->outsideFile = $configDir . '/tmp/outside.txt';
        file_put_contents($this->outsideFile, 'outside');
        $this->symlinkPath = $this->stagingRoot . '/link.txt';
        symlink($this->outsideFile, $this->symlinkPath);

        $mock = new stdClass();
        $mock->configDirectory = $configDir;
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
        if (is_link($this->symlinkPath)) {
            unlink($this->symlinkPath);
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

    public function testGetBulkImportStagingRootPathReturnsRealpath(): void
    {
        $expected = rtrim(str_replace('\\', '/', realpath($this->stagingRoot)), '/');
        $this->assertSame($expected, HAXCMSFile::getBulkImportStagingRootPath());
    }

    public function testGetBulkImportStagingRootPathFalseWhenMissing(): void
    {
        // point configDirectory at a dir without tmp/imports
        $GLOBALS['HAXCMS']->configDirectory = $this->tmpBase;
        $this->assertFalse(HAXCMSFile::getBulkImportStagingRootPath());
    }

    public function testIsValidBulkImportTmpPathAcceptsFileInsideRoot(): void
    {
        $this->assertTrue(HAXCMSFile::isValidBulkImportTmpPath($this->insideFile));
    }

    public function testIsValidBulkImportTmpPathRejectsFileOutsideRoot(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath($this->outsideFile));
    }

    public function testIsValidBulkImportTmpPathRejectsSymlink(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath($this->symlinkPath));
    }

    public function testIsValidBulkImportTmpPathRejectsNonExistent(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath($this->stagingRoot . '/nope.txt'));
    }

    public function testIsValidBulkImportTmpPathRejectsRelativePath(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath('sub/inside.txt'));
    }

    public function testIsValidBulkImportTmpPathRejectsSchemeUri(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath('file:///etc/passwd'));
    }

    public function testIsValidBulkImportTmpPathRejectsEmbeddedNullByte(): void
    {
        // A null byte embedded mid-path is rejected by the null-byte guard.
        $pos = intval(strlen($this->insideFile) / 2);
        $pathWithNull = substr($this->insideFile, 0, $pos) . "\0" . substr($this->insideFile, $pos);
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath($pathWithNull));
    }

    public function testIsValidBulkImportTmpPathRejectsTrailingNullByte(): void
    {
        // Security (null-byte injection): a trailing null must be rejected.
        // Previously trim() stripped it before the null-byte guard ran, so
        // the path resolved and was accepted.
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath($this->insideFile . "\0"));
    }

    public function testIsValidBulkImportTmpPathRejectsLeadingNullByte(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath("\0" . $this->insideFile));
    }

    public function testIsValidBulkImportTmpPathRejectsEmptyString(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath(''));
    }

    public function testIsValidBulkImportTmpPathRejectsNonString(): void
    {
        $this->assertFalse(HAXCMSFile::isValidBulkImportTmpPath(123));
    }
}
