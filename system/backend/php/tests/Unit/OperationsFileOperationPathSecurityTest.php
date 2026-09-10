<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Security/scoping characterization tests for the OperationsRouteFileOperation
 * trait (lib/operations/fileOperation.php), specifically the
 * resolveSiteFileOperationPath() gate every operation funnels through.
 *
 * Mirrors the intent of haxcms-nodejs/test/unit/files-path-security.test.cjs:
 * prove that any file operation (delete/rename/duplicate/scale/compress/
 * rotate/transform) can ONLY ever touch the single file it was asked to
 * target, and can NEVER touch anything outside that site's files/
 * directory -- not via '..' traversal, not via a path that never entered
 * files/ at all, and not via a symlink planted inside files/ that points
 * somewhere else on disk. This directly targets the "don't accidentally
 * delete the wrong file" requirement.
 *
 * Fixture layout (all under a temp site root):
 *   <siteRoot>/files/keep.txt        -- sibling file that must survive
 *                                        every test untouched
 *   <siteRoot>/files/target.txt      -- the file operations are run against
 *   <siteRoot>/secret.txt            -- inside the site directory, but
 *                                        OUTSIDE files/ -- must never be
 *                                        reachable via the files API
 *   <tmpRoot>/outside/escape-target.txt -- fully outside the site tree --
 *                                        a symlink inside files/ will point
 *                                        here
 */
class OperationsFileOperationPathSecurityTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'fileops-pathsec-site';
    private $siteRoot;
    private $outsideDir;

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
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_fileops_pathsec_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_fileops_pathsec_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->outsideDir = $this->tmpRoot . '/outside';
        $this->buildSiteFixture();

        $this->haxcms = new FileOpsPathSecTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new FileOpsPathSecTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->haxcms->validRequestToken = true;

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

    private function buildSiteFixture(): void
    {
        mkdir($this->siteRoot . '/files', 0777, true);
        mkdir($this->outsideDir, 0777, true);
        file_put_contents($this->siteRoot . '/files/keep.txt', 'keep me');
        file_put_contents($this->siteRoot . '/files/target.txt', 'delete or rename me');
        file_put_contents($this->siteRoot . '/secret.txt', 'site-root secret, not in files/');
        file_put_contents($this->outsideDir . '/escape-target.txt', 'fully outside the site tree');

        $manifest = (object)array(
            'id' => 'site-fileops-pathsec-uuid',
            'title' => 'File Ops Path Security Test Site',
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

    private function baseParams($extra = array())
    {
        return array_merge(
            array(
                'site_token' => 'good',
                'site' => array('name' => $this->siteName),
            ),
            $extra
        );
    }

    // =========================================================================
    // Traversal / out-of-scope path rejection
    // =========================================================================

    public function testDeleteViaDotDotTraversalToSiteRootSecretIsRejected(): void
    {
        $secretPath = $this->siteRoot . '/secret.txt';
        $this->assertTrue(file_exists($secretPath));

        $this->ops->params = $this->baseParams(array(
            'operation' => 'delete',
            'path' => 'files/../secret.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid file path', $result['__failed']['message']);
        $this->assertTrue(file_exists($secretPath), 'secret.txt outside files/ survives');
    }

    public function testDeleteViaDotDotTraversalOutsideSiteTreeIsRejected(): void
    {
        $outsidePath = $this->outsideDir . '/escape-target.txt';
        $this->assertTrue(file_exists($outsidePath));

        $this->ops->params = $this->baseParams(array(
            'operation' => 'delete',
            'path' => 'files/../../outside/escape-target.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertTrue(file_exists($outsidePath), 'file outside the site tree survives');
    }

    public function testDeletePathThatNeverEntersFilesDirectoryIsRejected(): void
    {
        $this->ops->params = $this->baseParams(array(
            'operation' => 'delete',
            'path' => 'secret.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('File path must start with files/', $result['__failed']['message']);
        $this->assertTrue(file_exists($this->siteRoot . '/secret.txt'));
    }

    public function testDeleteWithNullByteInPathIsRejected(): void
    {
        $this->ops->params = $this->baseParams(array(
            'operation' => 'delete',
            'path' => "files/target.txt\0.png",
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/target.txt'));
    }

    // =========================================================================
    // Confinement of mutations to the single requested file
    // =========================================================================

    public function testDeleteRemovesOnlyTheRequestedFileSiblingSurvives(): void
    {
        $targetPath = $this->siteRoot . '/files/target.txt';
        $keepPath = $this->siteRoot . '/files/keep.txt';
        $this->assertTrue(file_exists($targetPath));
        $this->assertTrue(file_exists($keepPath));

        $this->ops->params = $this->baseParams(array(
            'operation' => 'delete',
            'path' => 'files/target.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(200, $result['status']);
        $this->assertFalse(file_exists($targetPath), 'target removed');
        $this->assertTrue(file_exists($keepPath), 'sibling file survives untouched');
        $this->assertSame('keep me', file_get_contents($keepPath), 'sibling content unchanged');
    }

    public function testUnsupportedOperationPerformsNoFilesystemMutation(): void
    {
        $keepPath = $this->siteRoot . '/files/keep.txt';
        $before = file_get_contents($keepPath);

        $this->ops->params = $this->baseParams(array(
            'operation' => 'frobnicate',
            'path' => 'files/keep.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Unsupported file operation', $result['__failed']['message']);
        $this->assertSame($before, file_get_contents($keepPath), 'file left untouched');
    }

    public function testOperationOnNonexistentFileReturns404AndTouchesNothingElse(): void
    {
        $keepPath = $this->siteRoot . '/files/keep.txt';
        $before = file_get_contents($keepPath);

        $this->ops->params = $this->baseParams(array(
            'operation' => 'delete',
            'path' => 'files/does-not-exist.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame($before, file_get_contents($keepPath), 'unrelated file left untouched');
    }

    public function testSymlinkInsideFilesPointingOutsideSiteIsNotDeletable(): void
    {
        $symlinkPath = $this->siteRoot . '/files/escape-link.txt';
        $outsideTarget = $this->outsideDir . '/escape-target.txt';
        symlink($outsideTarget, $symlinkPath);
        $this->assertTrue(file_exists($outsideTarget));

        try {
            $this->ops->params = $this->baseParams(array(
                'operation' => 'delete',
                'path' => 'files/escape-link.txt',
            ));
            $result = $this->ops->fileOperation();

            // resolveSiteFileOperationPath resolves the symlink's realpath and
            // rejects it as outside the files/ directory (403), or is_link()
            // rejects it outright (404) -- either way, the operation never
            // reaches unlink() on the real target.
            $this->assertArrayHasKey('__failed', $result);
            $this->assertContains($result['__failed']['status'], array(403, 404));
            $this->assertTrue(
                file_exists($outsideTarget),
                'symlink target outside the site tree was never touched'
            );
        } finally {
            @unlink($symlinkPath);
        }
    }

    public function testRenameSanitizesTraversalOutOfNewNameAndStaysInsideFiles(): void
    {
        file_put_contents($this->siteRoot . '/files/rename-me.txt', 'rename target');

        // No '.' in the requested name -- a literal '..' contains dots and
        // would instead hit the separate "only one extension" guard (see
        // testRenameWithMultipleDotsIsRejectedOutright below). This exercises
        // sanitizeFileRenameBaseName, which strips every character outside
        // [a-z0-9-] (including '/'), so a slash-laden, absolute-looking name
        // can never survive into the output path.
        $this->ops->params = $this->baseParams(array(
            'operation' => 'rename',
            'path' => 'files/rename-me.txt',
            'newName' => '/etc/escaped-name',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(200, $result['status']);
        $this->assertStringStartsWith('files/', $result['data']['path']);
        $this->assertStringNotContainsString('..', $result['data']['path']);
        $this->assertSame('files/etc-escaped-name.txt', $result['data']['path']);
        $this->assertTrue(file_exists($this->siteRoot . '/' . $result['data']['path']));
        $this->assertFalse(
            file_exists($this->siteRoot . '/escaped-name.txt'),
            'no file was created at the site root via traversal'
        );
    }

    public function testRenameWithMultipleDotsIsRejectedOutright(): void
    {
        file_put_contents($this->siteRoot . '/files/rename-me-2.txt', 'rename target 2');

        $this->ops->params = $this->baseParams(array(
            'operation' => 'rename',
            'path' => 'files/rename-me-2.txt',
            'newName' => '../../../escaped-name.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertTrue(
            file_exists($this->siteRoot . '/files/rename-me-2.txt'),
            'original file left untouched after rejected rename'
        );
    }

    public function testDuplicateOutputAlwaysLandsInsideFilesNeverAtSiteRoot(): void
    {
        file_put_contents($this->siteRoot . '/files/dup-me.txt', 'dup target');

        $this->ops->params = $this->baseParams(array(
            'operation' => 'duplicate',
            'path' => 'files/dup-me.txt',
        ));
        $result = $this->ops->fileOperation();

        $this->assertSame(200, $result['status']);
        $this->assertSame('files/dup-me-copy.txt', $result['data']['path']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/dup-me-copy.txt'));
        $this->assertFalse(
            file_exists($this->siteRoot . '/dup-me-copy.txt'),
            'duplicate was not created at the site root'
        );
    }
}

/**
 * HAXCMS mock for the path-security file operation tests.
 */
class FileOpsPathSecTestHaxcms extends OperationsTestHaxcms
{
    // Inherits all behavior from OperationsTestHaxcms.
}

/**
 * HAXCMSSite test subclass that no-ops gitCommit while keeping real
 * manifest load and file path resolution.
 */
class FileOpsPathSecTestSite extends HAXCMSSite
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
