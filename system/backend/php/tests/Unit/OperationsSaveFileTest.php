<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteSaveFile trait
 * (lib/operations/saveFile.php).
 *
 * Tests the saveFile() entrypoint which handles file uploads to a
 * site's files/ directory. The entrypoint resolves the site name,
 * validates the upload, checks the site token, verifies platform
 * allows uploadMedia, then delegates to HAXCMSFile::save().
 *
 * NOTE: The full happy path (HAXCMSFile::save writing to disk) cannot
 * be exercised under CLI because is_uploaded_file() returns false for
 * non-HTTP-POST temp files. Tests cover all gate logic up to that
 * point: site name resolution, upload resolution, error detection,
 * token validation, and platform capability check.
 */
class OperationsSaveFileTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $savedFiles;
    private $tmpRoot;
    private $siteName = 'savefile-site';
    private $siteRoot;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        if (isset($_FILES)) {
            $this->savedFiles = $_FILES;
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_savefile_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_savefile_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixture();

        $this->haxcms = new SaveFileTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new SaveFileTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
        $_FILES = array();
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
        if (isset($this->savedFiles)) {
            $_FILES = $this->savedFiles;
            $this->savedFiles = null;
        } else {
            $_FILES = array();
        }
        // Clean up any file HAXCMSFile::save() wrote under the shared temp
        // HAXCMS_ROOT during bulk-import success-path tests (save() targets
        // HAXCMS_ROOT/_sites/<siteName>/files/, not this test's tmpRoot).
        if (defined('HAXCMS_ROOT') && is_dir(HAXCMS_ROOT . '/_sites/' . $this->siteName)) {
            $this->rrmdir(HAXCMS_ROOT . '/_sites/' . $this->siteName);
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

    private function buildSiteFixture(): void
    {
        mkdir($this->siteRoot . '/files', 0777, true);
        $manifest = (object)array(
            'id' => 'site-savefile-uuid',
            'title' => 'SaveFile Test Site',
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

    /**
     * Set up a fake $_FILES entry for upload tests.
     */
    private function setFakeUpload(string $key, string $name, string $tmpName, int $error = UPLOAD_ERR_OK): void
    {
        $_FILES[$key] = array(
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => 100,
        );
    }

    // =========================================================================
    // Missing site name
    // =========================================================================

    public function testSaveFileMissingSiteNameReturns400(): void
    {
        $this->ops->params = array();
        $result = $this->ops->saveFile();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Missing site name', $result['__failed']['message']);
    }

    // =========================================================================
    // Missing upload (no $_FILES)
    // =========================================================================

    public function testSaveFileMissingUploadReturns400(): void
    {
        $_FILES = array();
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->saveFile();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Missing file upload', $result['__failed']['message']);
    }

    // =========================================================================
    // Upload error
    // =========================================================================

    public function testSaveFileUploadErrorReturns400(): void
    {
        $this->setFakeUpload('file', 'test.txt', '/tmp/fake', UPLOAD_ERR_PARTIAL);
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->saveFile();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Upload failed before processing', $result['__failed']['message']);
    }

    // =========================================================================
    // Invalid site token
    // =========================================================================

    public function testSaveFileInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->setFakeUpload('file', 'test.txt', '/tmp/fake');
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->saveFile();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // uploadMedia disabled
    // =========================================================================

    public function testSaveFileUploadMediaDisabledReturns403(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->disablePlatformFeature('uploadMedia');
        $this->setFakeUpload('file', 'test.txt', '/tmp/fake');
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->saveFile();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame(
            'Uploading media is disabled for this site',
            $result['__failed']['message']
        );
    }

    // =========================================================================
    // siteName from siteName param (not site.name)
    // =========================================================================

    public function testSaveFileResolvesSiteNameFromSiteNameParam(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->setFakeUpload('file', 'test.txt', '/tmp/fake');
        $this->ops->params = array(
            'site_token' => 'good',
            'siteName' => $this->siteName,
        );
        $result = $this->ops->saveFile();
        // Should NOT get "Missing site name" — should get past site name
        // resolution to the token gate
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // site_token with embedded siteName
    // =========================================================================

    public function testSaveFileParsesSiteNameFromSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->setFakeUpload('file', 'test.txt', '/tmp/fake');
        $this->ops->params = array(
            'site_token' => 'token?siteName=' . $this->siteName,
        );
        $result = $this->ops->saveFile();
        // site_token should be split: 'token' is the token, siteName extracted
        // Token validation fails since mock returns false
        $this->assertSame(403, $result['__failed']['status']);
    }

    private function stageBulkImportFile(string $content, string $fileName): string
    {
        $stagingRoot = $this->haxcms->configDirectory . '/tmp/imports';
        if (!is_dir($stagingRoot)) {
            mkdir($stagingRoot, 0777, true);
        }
        $stagedPath = $stagingRoot . '/' . $fileName;
        file_put_contents($stagedPath, $content);
        return $stagedPath;
    }

    // =========================================================================
    // F2 (HAX-SEC-006 parity): commit message uses the server-sanitized name,
    // not the raw attacker-controlled $_FILES name. Exercised via the
    // bulk-import path, which works under CLI (isValidBulkImportTmpPath
    // replaces is_uploaded_file), so the save() success path + gitCommit are
    // reachable end-to-end.
    // =========================================================================
    public function testSaveFileCommitMessageUsesSanitizedFilenameNotRawUploadName(): void
    {
        $this->haxcms->validRequestToken = true;
        // Stage a real .txt file in the bulk-import staging root.
        $stagedPath = $this->stageBulkImportFile('hello world', 'staged.txt');
        // Crafted raw name with a char ($) that HAXCMSFile::save() strips during
        // filename sanitization. Before F2 the commit message held the raw
        // $_FILES name; after F2 it holds the server-sanitized name.
        $_FILES['file'] = array(
            'name' => 'bad$evil.txt',
            'type' => 'text/plain',
            'tmp_name' => $stagedPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($stagedPath),
            'bulk-import' => TRUE,
        );
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->saveFile();
        // save() must have succeeded (status 200, no __failed).
        $this->assertArrayNotHasKey('__failed', $result);
        $this->assertSame(200, $result['status']);
        // The git commit message must use the sanitized basename (no '$').
        $commits = $this->haxcms->loadedSite->gitCommits;
        $this->assertCount(1, $commits);
        $this->assertSame('File added: ' . 'badevil.txt', $commits[0]);
        $this->assertStringNotContainsString('$', $commits[0]);
    }
}

/**
 * HAXCMS mock for saveFile tests.
 */
class SaveFileTestHaxcms extends OperationsTestHaxcms
{
    // Inherits all behavior from OperationsTestHaxcms.
}

/**
 * HAXCMSSite test subclass for saveFile tests.
 */
class SaveFileTestSite extends HAXCMSSite
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
