<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteNodeRevisions trait
 * (lib/operations/nodeRevisions.php).
 *
 * Tests the three public entrypoints:
 *   - getNodeRevisions()  — list git log entries for a page file
 *   - getNodeRevision()   — get specific revision content by hash
 *   - restoreNodeRevision() — restore a page to a specific revision
 *
 * Uses a REAL temp git repo so git log/show/checkout operations are
 * exercised authentically. The HAXCMSSite subclass stubs gitCommit
 * and writePageAlternateFormats so no git binary commit or twig
 * template tree is needed for the restore path.
 */
class OperationsNodeRevisionsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'rev-site';
    private $siteRoot;
    private $commitHashes = array();

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
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_rev_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_rev_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixtureWithGitHistory();

        $this->haxcms = new NodeRevisionsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new NodeRevisionsTestSite();
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
     * Build a temp site fixture with a real git repo and 2 commits
     * for pages/item-a/index.html so revision history exists.
     */
    private function buildSiteFixtureWithGitHistory(): void
    {
        mkdir($this->siteRoot . '/pages/item-a', 0777, true);
        file_put_contents(
            $this->siteRoot . '/pages/item-a/index.html',
            '<p>Original content</p>'
        );

        $manifest = (object)array(
            'id' => 'site-rev-uuid',
            'title' => 'Revision Test Site',
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
            'items' => array(
                (object)array(
                    'id' => 'item-a',
                    'indent' => 0,
                    'location' => 'pages/item-a/index.html',
                    'slug' => 'item-a',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Item A',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
        );
        file_put_contents(
            $this->siteRoot . '/site.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        // Initialize git repo and create 2 commits
        $gitDir = $this->siteRoot;
        $this->runGitCommand($gitDir, array('init'));
        $this->runGitCommand($gitDir, array('config', 'user.email', 'test@example.com'));
        $this->runGitCommand($gitDir, array('config', 'user.name', 'Test User'));
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.html'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Initial commit'));

        $hash1 = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));
        $this->commitHashes[] = $hash1;

        // Modify and commit again
        file_put_contents(
            $this->siteRoot . '/pages/item-a/index.html',
            '<p>Updated content</p>'
        );
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.html'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Second commit'));
        $hash2 = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));
        $this->commitHashes[] = $hash2;
    }

    /**
     * Run a git command in the given directory and return stdout.
     */
    private function runGitCommand(string $cwd, array $args): string
    {
        $parts = array_merge(array('git', '--no-pager'), $args);
        $escaped = array();
        foreach ($parts as $p) {
            $escaped[] = escapeshellarg((string) $p);
        }
        $command = implode(' ', $escaped);
        $descriptorSpec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $proc = proc_open($command, $descriptorSpec, $pipes, $cwd);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);
        return (string) $stdout;
    }

    // =========================================================================
    // getNodeRevisions — param validation
    // =========================================================================

    public function testGetNodeRevisionsMissingParamsReturns400(): void
    {
        $this->ops->params = array();
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Missing required body fields: site.name and node.id',
            $result['__failed']['message']
        );
    }

    public function testGetNodeRevisionsMissingNodeIdReturns400(): void
    {
        $this->ops->params = array(
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(400, $result['__failed']['status']);
    }

    // =========================================================================
    // getNodeRevisions — token gate
    // =========================================================================

    public function testGetNodeRevisionsInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // getNodeRevisions — node not found
    // =========================================================================

    public function testGetNodeRevisionsNodeNotFoundReturns404(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'nonexistent'),
        );
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Node not found', $result['__failed']['message']);
    }

    // =========================================================================
    // getNodeRevisions — happy path
    // =========================================================================

    public function testGetNodeRevisionsReturnsRevisionList(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(200, $result['status']);
        $this->assertSame('item-a', $result['data']['nodeId']);
        $this->assertSame('item-a', $result['data']['nodeSlug']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertCount(2, $result['data']['revisions']);

        // Verify revision structure
        $rev = $result['data']['revisions'][0];
        $this->assertArrayHasKey('hash', $rev);
        $this->assertArrayHasKey('shortHash', $rev);
        $this->assertArrayHasKey('author', $rev);
        $this->assertArrayHasKey('message', $rev);
        $this->assertSame('Second commit', $rev['message']);

        $rev2 = $result['data']['revisions'][1];
        $this->assertSame('Initial commit', $rev2['message']);
    }

    public function testGetNodeRevisionsRespectsLimit(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'limit' => 1,
        );
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertCount(1, $result['data']['revisions']);
        // Total still reflects all commits
        $this->assertSame(2, $result['data']['total']);
    }

    // =========================================================================
    // getNodeRevision — param validation
    // =========================================================================

    public function testGetNodeRevisionMissingHashReturns400(): void
    {
        $this->ops->params = array(
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->getNodeRevision();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Missing required body fields: site.name, node.id and hash',
            $result['__failed']['message']
        );
    }

    public function testGetNodeRevisionInvalidHashFormatReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => 'not-a-valid-hash!!!',
        );
        $result = $this->ops->getNodeRevision();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid revision hash', $result['__failed']['message']);
    }

    // =========================================================================
    // getNodeRevision — happy path
    // =========================================================================

    public function testGetNodeRevisionReturnsContent(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => $this->commitHashes[0],
        );
        $result = $this->ops->getNodeRevision();
        $this->assertSame(200, $result['status']);
        $this->assertSame('item-a', $result['data']['nodeId']);
        $this->assertStringContainsString('Original content', $result['data']['content']);
        $this->assertSame($this->commitHashes[0], $result['data']['revision']['hash']);
        $this->assertSame('Initial commit', $result['data']['revision']['message']);
    }

    public function testGetNodeRevisionReturnsLatestContent(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => $this->commitHashes[1],
        );
        $result = $this->ops->getNodeRevision();
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Updated content', $result['data']['content']);
        $this->assertSame('Second commit', $result['data']['revision']['message']);
    }

    // =========================================================================
    // getNodeRevision — non-existent hash
    // =========================================================================

    public function testGetNodeRevisionNonExistentHashReturns404(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => 'abcdef1234567890abcdef1234567890abcdef12',
        );
        $result = $this->ops->getNodeRevision();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame(
            'Revision content for this page was not found',
            $result['__failed']['message']
        );
    }

    // =========================================================================
    // restoreNodeRevision — param validation
    // =========================================================================

    public function testRestoreNodeRevisionMissingHashReturns400(): void
    {
        $this->ops->params = array(
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->restoreNodeRevision();
        $this->assertSame(400, $result['__failed']['status']);
    }

    public function testRestoreNodeRevisionInvalidHashFormatReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => 'XYZ_not_hex',
        );
        $result = $this->ops->restoreNodeRevision();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid revision hash', $result['__failed']['message']);
    }

    // =========================================================================
    // restoreNodeRevision — token gate
    // =========================================================================

    public function testRestoreNodeRevisionInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => $this->commitHashes[0],
        );
        $result = $this->ops->restoreNodeRevision();
        $this->assertSame(403, $result['__failed']['status']);
    }

    // =========================================================================
    // restoreNodeRevision — happy path
    // =========================================================================

    public function testRestoreNodeRevisionWritesOldContentToFile(): void
    {
        $this->haxcms->validRequestToken = true;
        // Current file has "Updated content" (from commit 2)
        // Restore to commit 1 which has "Original content"
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => $this->commitHashes[0],
        );
        $result = $this->ops->restoreNodeRevision();
        $this->assertSame(200, $result['status']);
        $this->assertSame($this->commitHashes[0], $result['data']['restoredFromHash']);

        // Verify file on disk was actually written with the old content
        $pageFile = $this->siteRoot . '/pages/item-a/index.html';
        $content = file_get_contents($pageFile);
        $this->assertStringContainsString('Original content', $content);

        // Verify gitCommit was called (stubbed)
        $site = $this->haxcms->loadedSite;
        $this->assertNotEmpty($site->gitCommits);
        $this->assertStringContainsString('Page revision restored', $site->gitCommits[0]);
        $this->assertStringContainsString(substr($this->commitHashes[0], 0, 12), $site->gitCommits[0]);
    }

    // =========================================================================
    // restoreNodeRevision — non-existent hash
    // =========================================================================

    public function testRestoreNodeRevisionNonExistentHashReturns404(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
            'hash' => 'abcdef1234567890abcdef1234567890abcdef12',
        );
        $result = $this->ops->restoreNodeRevision();
        $this->assertSame(404, $result['__failed']['status']);
    }
}

/**
 * HAXCMS mock for node revision tests. Extends OperationsTestHaxcms
 * to inherit token/access behavior.
 */
class NodeRevisionsTestHaxcms extends OperationsTestHaxcms
{
    // Inherits all behavior from OperationsTestHaxcms, including
    // validateRequestToken, loadSite, getActiveUserName, cleanTitle.
}

/**
 * HAXCMSSite test subclass that no-ops git/twig collaborators while
 * keeping real manifest load/save and loadNode. This lets the REAL
 * git command execution in nodeRevisions run against a temp repo
 * while the restore path's gitCommit/writePageAlternateFormats are stubbed.
 */
class NodeRevisionsTestSite extends HAXCMSSite
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
