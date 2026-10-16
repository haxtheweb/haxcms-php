<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Gap-filling characterization tests for the OperationsRouteNodeRevisions
 * trait (lib/operations/nodeRevisions.php), complementary to
 * OperationsNodeRevisionsTest (param validation, gates, happy paths):
 *
 *   - item metadata JSON variants committed alongside the page file:
 *     valid payload -> hasItemMetadata, empty file -> no metadata, invalid
 *     JSON -> no metadata
 *   - restore applies item metadata to the page (title/description strip,
 *     published/locked/image/images/videos) and persists it
 *   - restoring a later bare-metadata revision UNSETS previously applied
 *     fields
 *   - offset paging + limit clamped to 200
 *   - invalid node file locations: directory path, '.' path component,
 *     empty location, symlink escaping the site root, site without
 *     metadata.site.name, site directory missing -> 400
 *   - node file with no commits -> empty revision list, total 0
 *   - git failure (unreadable .git) -> 500 for list/get
 *   - trimmed-empty body fields -> 400 for get + restore
 *   - apiBasePath resolution: explicit param, REQUEST_URI with/without
 *     query string fallback, default /x/api
 */
class OperationsNodeRevisionsGapsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'rev-site';
    private $siteRoot;
    private $commitHashes = array();
    private $emptyBlobSha = '';

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
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_revgaps_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_revgaps_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixtureWithGitHistory();

        $this->haxcms = new NodeRevisionsGapsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new NodeRevisionsGapsTestSite();
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
        // never leave an unreadable .git behind
        @chmod($this->siteRoot . '/.git', 0777);
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
     * Fixture: item-a with 2 commits of its page file, then 4 more commits
     * of the page's JSON variant (valid metadata, empty, invalid JSON, bare
     * metadata). Plus nodes with invalid locations + an uncommitted node.
     */
    private function buildSiteFixtureWithGitHistory(): void
    {
        mkdir($this->siteRoot . '/pages/item-a', 0777, true);
        mkdir($this->siteRoot . '/pages/item-b', 0777, true);
        mkdir($this->siteRoot . '/pages/nometa-item', 0777, true);
        file_put_contents($this->siteRoot . '/pages/item-a/index.html', '<p>Original content</p>');
        file_put_contents($this->siteRoot . '/pages/nometa-item/index.html', '<p>Nometa original</p>');
        // empty blob: git show --quiet <blob> prints nothing with exit 0
        file_put_contents($this->siteRoot . '/pages/item-a/empty.bin', '');
        // on disk but NEVER committed -> empty revision list for item-b
        file_put_contents($this->siteRoot . '/pages/item-b/index.html', '<p>Uncommitted</p>');
        // symlink escaping the site root (resolveNodeRevisionFileContext must reject)
        symlink('/etc/hostname', $this->siteRoot . '/pages/link-out.html');

        $item = function (string $id, string $title, string $location) {
            return (object)array(
                'id' => $id,
                'indent' => 0,
                'location' => $location,
                'slug' => $id,
                'order' => 0,
                'parent' => null,
                'title' => $title,
                'description' => '',
                'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
            );
        };
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
                ),
                'platform' => (object)array('features' => (object)array()),
            ),
            'items' => array(
                $item('item-a', 'Item A', 'pages/item-a/index.html'),
                // location is an existing DIRECTORY, not a file
                $item('dir-item', 'Dir Item', 'pages/item-a'),
                // '.' path component inside the location
                $item('dot-item', 'Dot Item', 'pages/./item-a/index.html'),
                // empty location
                $item('empty-loc-item', 'Empty Loc', ''),
                // symlink resolving outside the site root
                $item('link-item', 'Link Item', 'pages/link-out.html'),
                // on disk but never committed
                $item('item-b', 'Item B', 'pages/item-b/index.html'),
                // location is an ARRAY, not a string
                (object)array(
                    'id' => 'arr-item',
                    'indent' => 0,
                    'location' => array('pages/item-a/index.html'),
                    'slug' => 'arr-item',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Arr Item',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
                // metadata property is null entirely
                (object)array(
                    'id' => 'nometa-item',
                    'indent' => 0,
                    'location' => 'pages/nometa-item/index.html',
                    'slug' => 'nometa-item',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Nometa Item',
                    'description' => '',
                    'metadata' => null,
                ),
            ),
        );
        file_put_contents($this->siteRoot . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $gitDir = $this->siteRoot;
        $this->runGitCommand($gitDir, array('init'));
        $this->runGitCommand($gitDir, array('config', 'user.email', 'test@example.com'));
        $this->runGitCommand($gitDir, array('config', 'user.name', 'Test User'));
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.html'));
        $this->runGitCommand($gitDir, array('add', 'pages/nometa-item/index.html'));
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/empty.bin'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Initial commit'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));
        $this->emptyBlobSha = trim($this->runGitCommand(
            $gitDir,
            array('rev-parse', 'HEAD:pages/item-a/empty.bin')
        ));

        file_put_contents($this->siteRoot . '/pages/item-a/index.html', '<p>Updated content</p>');
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.html'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Second commit'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));

        // commit 3: valid item metadata JSON variant
        file_put_contents($this->siteRoot . '/pages/item-a/index.json', json_encode(array(
            'id' => 'item-a',
            'title' => 'Meta <b>Title</b>',
            'description' => 'Meta desc',
            'slug' => 'item-a',
            'order' => 0,
            'metadata' => array(
                'published' => true,
                'locked' => true,
                'image' => 'files/img.jpg',
                'images' => array('files/a.jpg', 'not a url'),
                'videos' => array('files/v.mp4'),
            ),
        )));
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.json'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Metadata added'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));

        // commit 4: empty JSON variant
        file_put_contents($this->siteRoot . '/pages/item-a/index.json', '');
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.json'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Metadata emptied'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));

        // commit 5: invalid JSON variant
        file_put_contents($this->siteRoot . '/pages/item-a/index.json', 'not json at all');
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.json'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Metadata corrupted'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));

        // commit 6: valid JSON but bare metadata (no fields)
        file_put_contents($this->siteRoot . '/pages/item-a/index.json', json_encode(array(
            'id' => 'item-a',
            'title' => 'Item A',
            'metadata' => array(),
        )));
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.json'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Metadata bare'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));

        // commit 7: valid JSON variant for nometa-item (whose manifest metadata is null)
        file_put_contents($this->siteRoot . '/pages/nometa-item/index.json', json_encode(array(
            'id' => 'nometa-item',
            'title' => 'Nometa Title',
            'description' => 'Nometa desc',
            'metadata' => array('published' => true),
        )));
        $this->runGitCommand($gitDir, array('add', 'pages/nometa-item/index.json'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Nometa metadata'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));

        // commit 8: item-a metadata with a scheme'd URL the sanitizer empties out
        file_put_contents($this->siteRoot . '/pages/item-a/index.json', json_encode(array(
            'id' => 'item-a',
            'title' => 'Item A',
            'metadata' => array('image' => 'javascript:alert(1)'),
        )));
        $this->runGitCommand($gitDir, array('add', 'pages/item-a/index.json'));
        $this->runGitCommand($gitDir, array('commit', '-m', 'Metadata unsafe image'));
        $this->commitHashes[] = trim($this->runGitCommand($gitDir, array('rev-parse', 'HEAD')));
    }

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
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);
        return (string) $stdout;
    }

    /** Run one of the three node-revision entrypoints with valid gate params. */
    private function runRevisions(string $method, string $nodeId, array $extra = array()): array
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array_merge(array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => $nodeId),
        ), $extra);
        return $this->ops->$method();
    }

    // =========================================================================
    // item metadata JSON variants
    // =========================================================================

    public function testGetNodeRevisionWithValidItemMetadata(): void
    {
        $result = $this->runRevisions('getNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[2],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['hasItemMetadata']);
        $itemMetadata = $result['data']['itemMetadata'];
        $this->assertSame('item-a', $itemMetadata['id']);
        $this->assertSame('Meta <b>Title</b>', $itemMetadata['title']);
        $this->assertSame('Meta desc', $itemMetadata['description']);
        $this->assertSame('item-a', $itemMetadata['slug']);
        $this->assertTrue($itemMetadata['metadata']['published']);
        $this->assertTrue($itemMetadata['metadata']['locked']);
        $this->assertSame('files/img.jpg', $itemMetadata['metadata']['image']);
        $this->assertSame(array('files/a.jpg', 'not a url'), $itemMetadata['metadata']['images']);
    }

    public function testGetNodeRevisionWithEmptyJsonVariantHasNoMetadata(): void
    {
        $result = $this->runRevisions('getNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[3],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['data']['hasItemMetadata']);
        $this->assertNull($result['data']['itemMetadata']);
    }

    public function testGetNodeRevisionWithInvalidJsonVariantHasNoMetadata(): void
    {
        $result = $this->runRevisions('getNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[4],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['data']['hasItemMetadata']);
    }

    // =========================================================================
    // restore + item metadata application
    // =========================================================================

    public function testRestoreAppliesItemMetadataToPage(): void
    {
        $result = $this->runRevisions('restoreNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[2],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['itemMetadataRestored']);

        // page content restored from that revision
        $this->assertStringContainsString(
            'Updated content',
            file_get_contents($this->siteRoot . '/pages/item-a/index.html')
        );

        // metadata applied: title/description stripped, fields sanitized
        $site = $this->haxcms->loadedSite;
        $page = $site->loadNode('item-a');
        $this->assertSame('Meta Title', $page->title);
        $this->assertSame('Meta desc', $page->description);
        $this->assertTrue($page->metadata->published);
        $this->assertTrue($page->metadata->locked);
        $this->assertSame('files/img.jpg', $page->metadata->image);
        // relative URLs pass through the sanitizer unchanged (no scheme to strip)
        $this->assertSame(array('files/a.jpg', 'not a url'), (array) $page->metadata->images);
        $this->assertSame(array('files/v.mp4'), (array) $page->metadata->videos);

        // persisted site.json carries the applied title
        $persisted = json_decode(file_get_contents($this->siteRoot . '/site.json'));
        $byId = array();
        foreach ($persisted->items as $item) {
            $byId[$item->id] = $item;
        }
        $this->assertSame('Meta Title', $byId['item-a']->title);
    }

    public function testRestoringBareMetadataRevisionUnsetsAppliedFields(): void
    {
        // first restore applies the full metadata set
        $this->runRevisions('restoreNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[2],
        ));
        // then a later revision with bare metadata unsets the fields
        $result = $this->runRevisions('restoreNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[5],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['itemMetadataRestored']);

        $site = $this->haxcms->loadedSite;
        $page = $site->loadNode('item-a');
        $this->assertSame('Item A', $page->title);
        $this->assertFalse(isset($page->metadata->published));
        $this->assertFalse(isset($page->metadata->locked));
        $this->assertFalse(isset($page->metadata->image));
        $this->assertFalse(isset($page->metadata->images));
        $this->assertFalse(isset($page->metadata->videos));
    }

    // =========================================================================
    // paging
    // =========================================================================

    public function testGetNodeRevisionsOffsetSkipsNewerRevisions(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'item-a', array(
            'offset' => 1,
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['count']);
        // skipping the newest revision leaves the oldest one, numbered past
        // the offset
        $this->assertSame('Initial commit', $result['data']['revisions'][0]['message']);
        $this->assertSame(2, $result['data']['revisions'][0]['revisionNumber']);
    }

    public function testGetNodeRevisionsLimitClampedTo200(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'item-a', array(
            'limit' => 9999,
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame(200, $result['data']['page']['limit']);
    }

    // =========================================================================
    // invalid node file locations
    // =========================================================================

    public function testDirectoryLocationRefusedWith400(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'dir-item');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    public function testDotPathComponentLocationRefusedWith400(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'dot-item');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    public function testEmptyLocationRefusedWith400(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'empty-loc-item');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    public function testSymlinkEscapingSiteRootRefusedWith400(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'link-item');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    public function testSiteWithoutSiteNameRefusedWith400(): void
    {
        $site = $this->haxcms->loadedSite;
        unset($site->manifest->metadata->site->name);
        $result = $this->runRevisions('getNodeRevisions', 'item-a');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    public function testSiteDirectoryMissingRefusedWith400(): void
    {
        $site = $this->haxcms->loadedSite;
        $site->directory = $this->tmpRoot . '/nonexistent-root';
        $result = $this->runRevisions('getNodeRevisions', 'item-a');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    // =========================================================================
    // node with no commits + git failures
    // =========================================================================

    public function testUncommittedNodeReturnsEmptyList(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'item-b');
        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame(array(), $result['data']['revisions']);
    }

    public function testUnreadableGitDirectoryReturns500ForList(): void
    {
        chmod($this->siteRoot . '/.git', 0000);
        try {
            $result = $this->runRevisions('getNodeRevisions', 'item-a');
            $this->assertSame(500, $result['__failed']['status']);
        } finally {
            chmod($this->siteRoot . '/.git', 0777);
        }
    }

    public function testUnreadableGitDirectoryReturns500ForGet(): void
    {
        chmod($this->siteRoot . '/.git', 0000);
        try {
            $result = $this->runRevisions('getNodeRevision', 'item-a', array(
                'hash' => $this->commitHashes[0],
            ));
            $this->assertSame(500, $result['__failed']['status']);
        } finally {
            chmod($this->siteRoot . '/.git', 0777);
        }
    }

    // =========================================================================
    // trimmed-empty body fields
    // =========================================================================

    public function testGetNodeRevisionWithBlankSiteNameReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => '   '),
            'node' => array('id' => 'item-a'),
            'hash' => $this->commitHashes[0],
        );
        $result = $this->ops->getNodeRevision();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Missing required body fields: site.name, node.id and hash',
            $result['__failed']['message']
        );
    }

    public function testRestoreNodeRevisionWithBlankNodeIdReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => '   '),
            'hash' => $this->commitHashes[0],
        );
        $result = $this->ops->restoreNodeRevision();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Missing required body fields: site.name, node.id and hash',
            $result['__failed']['message']
        );
    }

    // =========================================================================
    // apiBasePath resolution
    // =========================================================================

    public function testExplicitApiBasePathParamWins(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'item-a', array(
            'apiBasePath' => '/custom/api',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertStringStartsWith(
            '/custom/api/v1/items/item-a/revisions',
            $result['data']['links']['self']
        );
    }

    public function testRequestUriWithQueryStringResolvesBasePath(): void
    {
        $savedUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        $_SERVER['REQUEST_URI'] = '/x/api/v1/items/item-a/revisions?limit=25';
        try {
            $result = $this->runRevisions('getNodeRevisions', 'item-a');
            $this->assertSame(200, $result['status']);
            $this->assertStringStartsWith(
                '/x/api/v1/items/item-a/revisions',
                $result['data']['links']['self']
            );
        } finally {
            if ($savedUri !== null) {
                $_SERVER['REQUEST_URI'] = $savedUri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
        }
    }

    public function testUnrelatedRequestUriFallsBackToDefaultBasePath(): void
    {
        $savedUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        $_SERVER['REQUEST_URI'] = '/totally/unrelated/path';
        try {
            $result = $this->runRevisions('getNodeRevisions', 'item-a');
            $this->assertSame(200, $result['status']);
            $this->assertStringStartsWith(
                '/x/api/v1/items/item-a/revisions',
                $result['data']['links']['self']
            );
        } finally {
            if ($savedUri !== null) {
                $_SERVER['REQUEST_URI'] = $savedUri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
        }
    }

    // =========================================================================
    // location shape + hash edge cases
    // =========================================================================

    public function testNonStringLocationRefusedWith400(): void
    {
        $result = $this->runRevisions('getNodeRevisions', 'arr-item');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid node file location', $result['__failed']['message']);
    }

    public function testGetNodeRevisionWithBlobHashReturns404RevisionNotFound(): void
    {
        // git show --quiet <blob> exits 0 with empty output -> 'Revision not found'
        $result = $this->runRevisions('getNodeRevision', 'item-a', array(
            'hash' => $this->emptyBlobSha,
        ));
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Revision not found', $result['__failed']['message']);
    }

    public function testRestoreWithOverlongHashReturns500WithGitMessage(): void
    {
        // 64-char hash is valid hex but not a resolvable object in a sha1
        // repo; git emits "fatal: invalid object name" which does not match
        // any of the 404 patterns, so the 500 fallback carries the message.
        $hash64 = 'abcdef1234567890abcdef1234567890abcdef12abcdef1234567890abcdef12';
        $result = $this->runRevisions('restoreNodeRevision', 'item-a', array(
            'hash' => $hash64,
        ));
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertStringContainsString('invalid object name', $result['__failed']['message']);
    }

    // =========================================================================
    // alternate-format location resolution variants
    // =========================================================================

    public function testGetNodeRevisionWhenSiteLacksAlternateLocationMethod(): void
    {
        // plain site double: no getPageAlternateLocation() and a manifest with
        // no getItemById() -> slug resolved by iterating manifest->items
        $site = new NodeRevisionsFakeManifestSite($this->tmpRoot, $this->siteName);
        $site->manifest->items = array(
            (object)array('id' => 'ghost', 'slug' => 'ghost-slug'),
        );
        $this->haxcms->loadedSite = $site;
        $result = $this->runRevisions('getNodeRevision', 'ghost', array(
            'hash' => $this->commitHashes[1],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('ghost-slug', $result['data']['nodeSlug']);
        $this->assertSame('', $result['data']['jsonVariantLocation']);
        $this->assertFalse($result['data']['hasItemMetadata']);
    }

    public function testSlugFallbackIteratesManifestItemsAsObject(): void
    {
        $site = new NodeRevisionsFakeManifestSite($this->tmpRoot, $this->siteName);
        $site->manifest->items = (object) array(
            'ghost' => (object)array('id' => 'ghost', 'slug' => 'obj-slug'),
        );
        $this->haxcms->loadedSite = $site;
        $result = $this->runRevisions('getNodeRevisions', 'ghost');
        $this->assertSame(200, $result['status']);
        $this->assertSame('obj-slug', $result['data']['nodeSlug']);
    }

    public function testSlugFallbackReturnsEmptyWhenItemMissing(): void
    {
        $site = new NodeRevisionsFakeManifestSite($this->tmpRoot, $this->siteName);
        $site->manifest->items = array(
            (object)array('id' => 'someone-else', 'slug' => 'other-slug'),
        );
        $this->haxcms->loadedSite = $site;
        $result = $this->runRevisions('getNodeRevisions', 'ghost');
        $this->assertSame(200, $result['status']);
        $this->assertSame('', $result['data']['nodeSlug']);
        // lookup value falls back to the page id
        $this->assertStringContainsString('/v1/items/ghost/revisions', $result['data']['links']['self']);
    }

    public function testAlternateLocationReturningNonStringYieldsNoMetadata(): void
    {
        $site = new NodeRevisionsNullAltLocationSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $result = $this->runRevisions('getNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[1],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('', $result['data']['jsonVariantLocation']);
        $this->assertFalse($result['data']['hasItemMetadata']);
    }

    // =========================================================================
    // item metadata application to pages without metadata
    // =========================================================================

    public function testRestoreAppliesMetadataToPageWithoutMetadataObject(): void
    {
        $result = $this->runRevisions('restoreNodeRevision', 'nometa-item', array(
            'hash' => $this->commitHashes[6],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['itemMetadataRestored']);
        $site = $this->haxcms->loadedSite;
        $page = $site->loadNode('nometa-item');
        $this->assertSame('Nometa Title', $page->title);
        $this->assertInstanceOf('stdClass', $page->metadata);
        $this->assertTrue($page->metadata->published);
    }

    public function testRestoreWithoutMetadataVariantInitializesPageMetadata(): void
    {
        // hash 0 predates any JSON variant -> no item metadata; the restore
        // path still materializes an empty metadata object on the page
        $result = $this->runRevisions('restoreNodeRevision', 'nometa-item', array(
            'hash' => $this->commitHashes[0],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['data']['itemMetadataRestored']);
        $site = $this->haxcms->loadedSite;
        $page = $site->loadNode('nometa-item');
        $this->assertInstanceOf('stdClass', $page->metadata);
    }

    public function testRestoreUnsetsImageWhenSanitizerEmptiesValue(): void
    {
        // first restore sets image from the valid metadata revision
        $this->runRevisions('restoreNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[2],
        ));
        // then a revision whose image fails URL sanitization unsets it
        $result = $this->runRevisions('restoreNodeRevision', 'item-a', array(
            'hash' => $this->commitHashes[7],
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['itemMetadataRestored']);
        $site = $this->haxcms->loadedSite;
        $page = $site->loadNode('item-a');
        $this->assertFalse(isset($page->metadata->image));
    }

    // =========================================================================
    // write failures
    // =========================================================================

    public function testRestoreToReadOnlySiteReturns500FailedWriting(): void
    {
        // writeLocation() returns file_put_contents() verbatim: an unwritable
        // page file makes it false -> 'Failed writing restored revision'
        $pageFile = $this->siteRoot . '/pages/item-a/index.html';
        chmod($pageFile, 0444);
        try {
            $result = $this->runRevisions('restoreNodeRevision', 'item-a', array(
                'hash' => $this->commitHashes[0],
            ));
            $this->assertSame(500, $result['__failed']['status']);
            $this->assertSame('Failed writing restored revision', $result['__failed']['message']);
        } finally {
            chmod($pageFile, 0664);
        }
    }
}

/**
 * HAXCMS mock for the revision gap tests (all behavior inherited).
 */
class NodeRevisionsGapsTestHaxcms extends OperationsTestHaxcms
{
}

/**
 * Site double WITHOUT HAXCMSSite inheritance: the manifest is a plain
 * stdClass (no getItemById) and getPageAlternateLocation() does not exist,
 * pinning the defensive fallbacks in the revision trait.
 */
class NodeRevisionsFakeManifestSite
{
    public $directory;
    public $manifest;
    private $pages = array();

    public function __construct($root, $siteName)
    {
        $this->directory = $root;
        $this->manifest = new stdClass();
        $this->manifest->metadata = new stdClass();
        $this->manifest->metadata->site = new stdClass();
        $this->manifest->metadata->site->name = $siteName;
        $this->manifest->items = array();
        $this->pages['ghost'] = (object)array(
            'id' => 'ghost',
            'title' => 'Ghost',
            'location' => 'pages/item-a/index.html',
            'metadata' => new stdClass(),
        );
    }

    public function loadNode($id)
    {
        return isset($this->pages[$id]) ? $this->pages[$id] : false;
    }
}

/**
 * Real site double whose getPageAlternateLocation() returns a non-string,
 * pinning the is_string guard in getNodeRevisionJSONVariantLocation().
 */
class NodeRevisionsNullAltLocationSite extends NodeRevisionsGapsTestSite
{
    public function getPageAlternateLocation($location = '', $extension = 'json')
    {
        return null;
    }
}

/**
 * HAXCMSSite double: records git commits; real manifest/loadNode/writeLocation.
 */
class NodeRevisionsGapsTestSite extends HAXCMSSite
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
