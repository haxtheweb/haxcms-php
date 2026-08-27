<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteFileOperation trait
 * (lib/operations/fileOperation.php).
 *
 * Tests the fileOperation() entrypoint which dispatches to delete,
 * rename, and image transform operations (rotate-90, convert-jpg,
 * scale, sepia, black-and-white). Each path is exercised against
 * real temp files in a site-like fixture directory tree.
 *
 * Gate tests cover: missing site_token, missing site name, invalid
 * token, platform uploadMedia disabled, unsupported operation, path
 * traversal, missing file. Happy paths verify actual filesystem
 * mutations (delete/rename) and output file creation (image ops).
 */
class OperationsFileOperationTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'fileops-site';
    private $siteRoot;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_fileops_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_fileops_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixture();

        $this->haxcms = new FileOpsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new FileOpsTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        $this->rrmdir($this->tmpRoot);
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
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Build a temp site fixture with files/ directory containing:
     *   - test.txt (a text file for delete/rename tests)
     *   - test.png (a small valid PNG for image operation tests)
     */
    private function buildSiteFixture(): void
    {
        mkdir($this->siteRoot . '/files', 0777, true);
        file_put_contents(
            $this->siteRoot . '/files/test.txt',
            'Test file content'
        );

        // Create a small valid PNG using GD
        $img = imagecreatetruecolor(20, 15);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagepng($img, $this->siteRoot . '/files/test.png');
        imagedestroy($img);

        $manifest = (object)array(
            'id' => 'site-fileops-uuid',
            'title' => 'File Ops Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => false),
                    'created' => time(),
                    'updated' => time(),
                ),
                'platform' => (object)array(
                    'features' => (object)array(),
                ),
            ),
            'items' => array(),
        );
        file_put_contents(
            $this->siteRoot . '/site.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    private function disablePlatformFeature(string $feature): void
    {
        $site = $this->haxcms->loadedSite;
        if (!isset($site->manifest->metadata->platform)) {
            $site->manifest->metadata->platform = new stdClass();
        }
        if (!isset($site->manifest->metadata->platform->features)) {
            $site->manifest->metadata->platform->features = new stdClass();
        }
        $site->manifest->metadata->platform->features->$feature = false;
    }

    // =========================================================================
    // Gate tests
    // =========================================================================

    public function testFileOperationMissingTokenReturns403(): void
    {
        $this->ops->params = array(
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Missing site token', $result['__failed']['message']);
    }

    public function testFileOperationMissingSiteNameReturns400(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Missing site name', $result['__failed']['message']);
    }

    public function testFileOperationInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Invalid site token', $result['__failed']['message']);
    }

    public function testFileOperationUploadMediaDisabledReturns403(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->disablePlatformFeature('uploadMedia');
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame(
            'File operations are disabled for this site',
            $result['__failed']['message']
        );
    }

    public function testFileOperationUnsupportedOperationReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'frobnicate',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Unsupported file operation', $result['__failed']['message']);
    }

    public function testFileOperationPathTraversalReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'files/../test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid file path', $result['__failed']['message']);
    }

    public function testFileOperationPathNotStartingWithFilesReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'pages/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('File path must start with files/', $result['__failed']['message']);
    }

    public function testFileOperationFileNotFoundReturns404(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'files/nonexistent.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Requested file was not found', $result['__failed']['message']);
    }

    // =========================================================================
    // delete — happy path
    // =========================================================================

    public function testFileOperationDeleteRemovesFileAndCommits(): void
    {
        $this->haxcms->validRequestToken = true;
        $filePath = $this->siteRoot . '/files/test.txt';
        $this->assertTrue(file_exists($filePath));

        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse(file_exists($filePath), 'File deleted from disk');

        // Verify gitCommit was called
        $site = $this->haxcms->loadedSite;
        $this->assertNotEmpty($site->gitCommits);
        $this->assertStringContainsString('File deleted', $site->gitCommits[0]);
    }

    // =========================================================================
    // rename — happy path
    // =========================================================================

    public function testFileOperationRenameMovesFileAndCommits(): void
    {
        $this->haxcms->validRequestToken = true;
        $oldPath = $this->siteRoot . '/files/test.txt';
        $newPath = $this->siteRoot . '/files/renamed.txt';
        $this->assertTrue(file_exists($oldPath));

        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'rename',
            'path' => 'files/test.txt',
            'newName' => 'renamed.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('files/renamed.txt', $result['data']['path']);
        $this->assertFalse(file_exists($oldPath), 'Old file removed');
        $this->assertTrue(file_exists($newPath), 'New file created');

        $site = $this->haxcms->loadedSite;
        $this->assertStringContainsString('File renamed', $site->gitCommits[0]);
    }

    public function testFileOperationRenameMissingNewNameReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'rename',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('New file name is required', $result['__failed']['message']);
    }

    public function testFileOperationRenameExtensionChangeReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'rename',
            'path' => 'files/test.txt',
            'newName' => 'renamed.md',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString(
            'Extension cannot be changed',
            $result['__failed']['message']
        );
    }

    public function testFileOperationRenameSameNameReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'rename',
            'path' => 'files/test.txt',
            'newName' => 'test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'New file name must be different from current name',
            $result['__failed']['message']
        );
    }

    // =========================================================================
    // rotate-90 — happy path
    // =========================================================================

    public function testFileOperationRotate90SucceedsAndCommits(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'rotate-90',
            'path' => 'files/test.png',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('rotate-90', $result['data']['operation']);

        $site = $this->haxcms->loadedSite;
        $this->assertStringContainsString('File rotated', $site->gitCommits[0]);
    }

    // =========================================================================
    // convert-jpg — happy path
    // =========================================================================

    public function testFileOperationConvertJpgCreatesJpgOutputAndCommits(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'convert-jpg',
            'path' => 'files/test.png',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('convert-jpg', $result['data']['operation']);
        $this->assertStringContainsString('files/imgops/', $result['data']['file']['path']);
        $this->assertStringEndsWith('.jpg', $result['data']['file']['path']);

        // Verify output file exists on disk
        $outputPath = $this->siteRoot . '/' . $result['data']['file']['path'];
        $this->assertTrue(file_exists($outputPath), 'Converted JPG file created');

        $site = $this->haxcms->loadedSite;
        $this->assertStringContainsString('File converted to JPG', $site->gitCommits[0]);
    }

    // =========================================================================
    // scale — happy path
    // =========================================================================

    public function testFileOperationScaleCreatesScaledOutputAndCommits(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'scale',
            'path' => 'files/test.png',
            'size' => 'sm',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('scale', $result['data']['operation']);
        // In-place resize: path stays the same, no files/imgops/ derivative
        $this->assertSame('files/test.png', $result['data']['path']);
        $this->assertSame('files/test.png', $result['data']['file']['path']);

        // Original file still exists and is a valid image after in-place resize
        $originalPath = $this->siteRoot . '/files/test.png';
        $this->assertTrue(file_exists($originalPath), 'Original image still exists');
        $info = @getimagesize($originalPath);
        $this->assertNotFalse($info, 'Resized image is still a valid image');

        // No files/imgops directory or derivative was created
        $imgopsDir = $this->siteRoot . '/files/imgops';
        $this->assertFalse(
            is_dir($imgopsDir),
            'No files/imgops derivative directory should be created'
        );

        $site = $this->haxcms->loadedSite;
        $this->assertStringContainsString('File scaled', $site->gitCommits[0]);
    }

    // =========================================================================
    // sepia — happy path
    // =========================================================================

    public function testFileOperationSepiaCreatesTransformedOutputAndCommits(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'sepia',
            'path' => 'files/test.png',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('sepia', $result['data']['operation']);

        $outputPath = $this->siteRoot . '/' . $result['data']['file']['path'];
        $this->assertTrue(file_exists($outputPath), 'Sepia image file created');

        $site = $this->haxcms->loadedSite;
        $this->assertStringContainsString('File transformed', $site->gitCommits[0]);
        $this->assertStringContainsString('sepia', $site->gitCommits[0]);
    }

    // =========================================================================
    // black-and-white — happy path
    // =========================================================================

    public function testFileOperationBlackAndWhiteCreatesTransformedOutput(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'black-and-white',
            'path' => 'files/test.png',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('black-and-white', $result['data']['operation']);

        $outputPath = $this->siteRoot . '/' . $result['data']['file']['path'];
        $this->assertTrue(file_exists($outputPath), 'B&W image file created');
    }

    // =========================================================================
    // scale — default preset when size key invalid
    // =========================================================================

    public function testFileOperationScaleDefaultsToMdPresetWhenSizeInvalid(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => 'scale',
            'path' => 'files/test.png',
            'size' => 'gigantic',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('scale', $result['data']['operation']);
        $this->assertSame('files/test.png', $result['data']['path']);

        // md default verified via commit message (data.size no longer returned)
        $site = $this->haxcms->loadedSite;
        $this->assertStringContainsString('File scaled (md)', $site->gitCommits[0]);
    }
}

/**
 * HAXCMS mock for file operation tests.
 */
class FileOpsTestHaxcms extends OperationsTestHaxcms
{
    // Inherits all behavior from OperationsTestHaxcms.
}

/**
 * HAXCMSSite test subclass that no-ops gitCommit while keeping real
 * manifest load and file path resolution.
 */
class FileOpsTestSite extends HAXCMSSite
{
    public $gitCommits = array();

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }
}
