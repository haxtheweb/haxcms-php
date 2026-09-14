<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Phase 2 tests for cloneSite files.json preservation (issue #3043).
 *
 * Clone preserves uuids in files.json and rewrites path/fullUrl prefixes
 * inside the clone's files.json. page.metadata.files is now uuids (stable,
 * no rewrite of that array).
 */
class CloneSiteFilesJsonTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedFileSystem;
    private $tmpRoot;
    private $sourceSiteName = 'clone-source';
    private $cloneName = 'clone-source-copy';
    private $sourceSiteRoot;
    private $cloneSiteRoot;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($GLOBALS['fileSystem'])) {
            $this->savedFileSystem = $GLOBALS['fileSystem'];
        }

        // HAXCMS_ROOT is already defined by phpunit-bootstrap.php. Use it as
        // the base for the temp sites directory so cloneSite's realpath(
        // HAXCMS_ROOT . '/' . sitesDirectory . '/' . siteName) resolves.
        $this->tmpRoot = HAXCMS_ROOT;
        $sitesDir = $this->tmpRoot . '/_sites';
        $this->sourceSiteRoot = $sitesDir . '/' . $this->sourceSiteName;
        $this->cloneSiteRoot = $sitesDir . '/' . $this->cloneName;
        $this->buildSourceSiteFixture();

        // Use the real Symfony Filesystem for mirror.
        $GLOBALS['fileSystem'] = new \Symfony\Component\Filesystem\Filesystem();

        $this->haxcms = new CloneSiteFilesJsonTestHaxcms(
            $this->tmpRoot,
            $this->sourceSiteName,
            $this->cloneName
        );
        $GLOBALS['HAXCMS'] = $this->haxcms;

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
        if (isset($this->savedFileSystem)) {
            $GLOBALS['fileSystem'] = $this->savedFileSystem;
            $this->savedFileSystem = null;
        } else {
            unset($GLOBALS['fileSystem']);
        }
        // Only clean up the clone and source site dirs, not all of HAXCMS_ROOT
        // (the bootstrap may have symlinked system/ into it).
        $this->rrmdir($this->sourceSiteRoot);
        $this->rrmdir($this->cloneSiteRoot);
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

    private function buildSourceSiteFixture(): void
    {
        mkdir($this->sourceSiteRoot . '/files', 0777, true);
        file_put_contents($this->sourceSiteRoot . '/files/banner.jpg', 'fake-jpg');
        // Seed a files.json with a known record + uuid.
        $testUuid = 'aaaa1111-bbbb-2222-cccc-3333dddd4444';
        $filesJson = array(
            'schema' => 'HAXCMS-FILE-SCHEMA-V1',
            'site' => $this->sourceSiteName,
            'generated' => time(),
            'data' => array(
                'path' => 'files',
                'files' => array(
                    array(
                        'uuid' => $testUuid,
                        'path' => 'files/banner.jpg',
                        'name' => 'banner.jpg',
                        'mimetype' => 'image/jpeg',
                        'size' => 8,
                        'dateCreated' => 1000000,
                        'fullUrl' => '/_sites/' . $this->sourceSiteName . '/files/banner.jpg?t=1000000',
                        'url' => 'files/banner.jpg',
                        'width' => 0,
                        'height' => 0,
                    ),
                ),
            ),
        );
        file_put_contents(
            $this->sourceSiteRoot . '/files/files.json',
            json_encode($filesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        // site.json so loadSite can load it.
        $manifest = (object) array(
            'id' => 'source-uuid',
            'title' => 'Clone Source',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->sourceSiteName,
                    'settings' => (object) array('pathauto' => false),
                    'created' => time(),
                    'updated' => time(),
                ),
                'platform' => (object) array(
                    'features' => (object) array(),
                ),
            ),
            'items' => array(),
        );
        file_put_contents(
            $this->sourceSiteRoot . '/site.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    public function testClonePreservesUuidsAndRewritesPrefixes(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'site' => array('name' => $this->sourceSiteName),
        );
        $result = $this->ops->cloneSite();
        $this->assertSame(200, $result['status']);
        $this->assertSame($this->cloneName, $result['data']['name']);

        // The clone's files.json should exist.
        $cloneFilesJsonPath = $this->cloneSiteRoot . '/files/files.json';
        $this->assertTrue(file_exists($cloneFilesJsonPath), 'Clone has files.json');

        $cloneJson = json_decode(file_get_contents($cloneFilesJsonPath), true);
        $this->assertIsArray($cloneJson);
        $this->assertSame('HAXCMS-FILE-SCHEMA-V1', $cloneJson['schema']);
        $this->assertSame($this->cloneName, $cloneJson['site']);
        $this->assertCount(1, $cloneJson['data']['files']);

        $record = $cloneJson['data']['files'][0];
        // Uuid preserved.
        $this->assertSame('aaaa1111-bbbb-2222-cccc-3333dddd4444', $record['uuid']);
        // Path stays relative (files/...).
        $this->assertSame('files/banner.jpg', $record['path']);
        // fullUrl prefix rewritten to the clone name.
        $this->assertStringContainsString('/_sites/' . $this->cloneName . '/files/banner.jpg', $record['fullUrl']);
        // The old source prefix is gone from fullUrl.
        $this->assertStringNotContainsString('/_sites/' . $this->sourceSiteName . '/files/', $record['fullUrl']);
    }
}

/**
 * HAXCMS mock for cloneSite files.json test. loadSite returns a real
 * HAXCMSSite loaded from the temp directory so the clone rewriting can
 * access a real manifest + directory.
 */
class CloneSiteFilesJsonTestHaxcms extends OperationsTestHaxcms
{
    private $tmpRoot;
    private $sourceName;
    private $cloneName;

    public function __construct($tmpRoot, $sourceName, $cloneName)
    {
        parent::__construct();
        $this->tmpRoot = $tmpRoot;
        $this->sourceName = $sourceName;
        $this->cloneName = $cloneName;
        $this->basePath = '/';
        $this->sitesDirectory = '_sites';
    }

    public function loadSite($name, $create = false, $domain = null, $build = null)
    {
        $site = new CloneSiteFilesJsonTestSite();
        // HAXCMSSite::load expects the PARENT directory (sitesDirectory) and
        // the site name; it appends name + '/site.json' internally.
        $site->load($this->tmpRoot . '/_sites', '/', $name);
        return $site;
    }

    public function getUniqueName($name)
    {
        return $this->cloneName;
    }
}

/**
 * HAXCMSSite subclass that no-ops gitCommit and save side effects we don't
 * need, while keeping real manifest load + file path resolution.
 */
class CloneSiteFilesJsonTestSite extends HAXCMSSite
{
    public function gitCommit($msg = 'Committed changes')
    {
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
