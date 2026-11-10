<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * createNode (lib/operations/createNode.php), single-node mode: the
 * `node.duplicate` and `node.contents` branches had no coverage. These are
 * how the editor's "duplicate page" and the import flows (docx/html/pdf
 * converted client-side to HTML) put content onto a brand new page, so they
 * are a stored-content write path:
 *
 *   - duplicate copies the source page's body onto the new page, through
 *     sanitizeHTMLForStorage
 *   - an unknown duplicate source still creates the page, from boilerplate
 *   - contents are written through sanitizeHTMLForStorage (script and inline
 *     event handlers never reach disk)
 *   - contents with no inline images leave page.metadata.files empty
 *   - a client-supplied node id with traversal cannot escape pages/
 *   - the commit message names the new page
 *   - items (bulk): deleted entries are skipped; an all-delete or empty
 *     payload answers 400 instead of 200 with no page
 *   - (skipped, bug) the single-node title should be stored as plain text
 *
 * The route builds write paths from HAXCMS_ROOT/<sitesDirectory>/<site>/, so
 * the fixture is nested under the bootstrap's temp HAXCMS_ROOT with
 * sitesDirectory rebound (same pattern as OperationsSaveOutlineGapsTest).
 */
class OperationsCreateNodeContentTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'create-node-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
        }
        if (!file_exists($boilerplateDir . '/index.html')) {
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
        $this->fixtureDirName = 'fixtures_create_node_' . bin2hex(random_bytes(6));
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $this->buildSite();

        $this->haxcms = new CreateNodeContentTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new CreateNodeContentTestSite();
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
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                $this->rrmdir($path);
            }
        }
        @rmdir($dir);
    }

    private function buildSite(): void
    {
        $siteDir = $this->siteDir();
        mkdir($siteDir . '/pages/item-a', 0777, true);
        file_put_contents(
            $siteDir . '/pages/item-a/index.html',
            '<p>Source body</p><img src="x" onerror="alert(1)"><script>alert(2)</script>'
        );
        $manifest = (object) array(
            'id' => 'site-uuid',
            'title' => 'Create Node Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->siteName,
                    'settings' => (object) array('pathauto' => false),
                ),
                'platform' => (object) array('features' => (object) array()),
            ),
            'items' => array(
                (object) array(
                    'id' => 'item-a',
                    'indent' => 0,
                    'location' => 'pages/item-a/index.html',
                    'slug' => 'item-a',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Item A',
                    'description' => '',
                    'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function persistedById(): array
    {
        $decoded = json_decode(file_get_contents($this->siteDir() . '/site.json'));
        $byId = array();
        foreach ($decoded->items as $item) {
            $byId[$item->id] = $item;
        }
        return $byId;
    }

    private function create(array $node): array
    {
        $this->ops->params = array(
            'site_token' => 'accepted-by-double',
            'site' => array('name' => $this->siteName),
            'node' => $node,
        );
        return $this->ops->createNode();
    }

    private function pageBody(string $id): string
    {
        return file_get_contents($this->siteDir() . '/pages/' . $id . '/index.html');
    }

    // =========================================================================
    // duplicate
    // =========================================================================

    public function testDuplicateCopiesSanitizedSourceBody(): void
    {
        $result = $this->create(array('title' => 'Copy of A', 'duplicate' => 'item-a'));
        $this->assertSame(200, $result['status']);
        $newId = $result['data']->id;
        $this->assertNotSame('item-a', $newId);
        $body = $this->pageBody($newId);
        $this->assertStringContainsString('Source body', $body);
        $this->assertStringNotContainsStringIgnoringCase('<script', $body);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $body);
        // the source is untouched
        $this->assertStringContainsString('<script>alert(2)</script>', $this->pageBody('item-a'));
    }

    public function testDuplicateOfUnknownSourceStillCreatesPageFromBoilerplate(): void
    {
        $result = $this->create(array('title' => 'Orphan copy', 'duplicate' => 'no-such-page'));
        $this->assertSame(200, $result['status']);
        $newId = $result['data']->id;
        $this->assertArrayHasKey($newId, $this->persistedById());
        $this->assertSame(
            file_get_contents(HAXCMS_ROOT . '/system/boilerplate/page/default/index.html'),
            $this->pageBody($newId)
        );
    }

    // =========================================================================
    // contents
    // =========================================================================

    public function testContentsAreSanitizedBeforeTheyReachDisk(): void
    {
        $result = $this->create(array(
            'title' => 'Imported',
            'contents' => '<h2>Imported heading</h2><p onclick="steal()">Body</p>'
                . '<script>alert(1)</script><a href="javascript:alert(1)">link</a>',
        ));
        $this->assertSame(200, $result['status']);
        $body = $this->pageBody($result['data']->id);
        $this->assertStringContainsString('Imported heading', $body);
        $this->assertStringContainsString('Body', $body);
        $this->assertStringNotContainsStringIgnoringCase('<script', $body);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $body);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $body);
    }

    public function testContentsWithoutInlineImagesLeaveFilesEmpty(): void
    {
        $result = $this->create(array('title' => 'Plain', 'contents' => '<p>No images here</p>'));
        $this->assertSame(200, $result['status']);
        $page = $this->haxcms->loadedSite->loadNode($result['data']->id);
        $this->assertIsObject($page->metadata);
        $this->assertSame(array(), $page->metadata->files);
    }

    public function testDuplicateWinsOverContentsWhenBothAreSent(): void
    {
        $result = $this->create(array(
            'title' => 'Both',
            'duplicate' => 'item-a',
            'contents' => '<p>Ignored contents</p>',
        ));
        $this->assertSame(200, $result['status']);
        $body = $this->pageBody($result['data']->id);
        $this->assertStringContainsString('Source body', $body);
        $this->assertStringNotContainsString('Ignored contents', $body);
    }

    // =========================================================================
    // id / commit
    // =========================================================================

    public function testTraversalNodeIdStaysInsidePages(): void
    {
        $result = $this->create(array(
            'title' => 'Sneaky',
            'id' => '../../../escaped',
            'contents' => '<p>sneaky</p>',
        ));
        $this->assertSame(200, $result['status']);
        $item = $result['data'];
        $this->assertStringNotContainsString('..', $item->id);
        $this->assertStringNotContainsString('/', $item->id);
        $this->assertSame('pages/' . $item->id . '/index.html', $item->location);
        $this->assertFileExists($this->siteDir() . '/' . $item->location);
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/escaped');
        $this->assertDirectoryDoesNotExist(HAXCMS_ROOT . '/escaped');
    }

    public function testSingleNodeTitleIsStoredAsPlainText(): void
    {
        // Security (SEC-SANITIZE): single-node createNode stores plain text
        // titles, like saveOutline / saveNodeDetails / the bulk items path
        $result = $this->create(array('title' => '<img src=x onerror=alert(1)><b>Hello</b>'));
        $this->assertSame(200, $result['status']);
        $this->assertSame('Hello', $this->persistedById()[$result['data']->id]->title);
    }

    // =========================================================================
    // items (bulk create)
    // =========================================================================

    private function createWith(array $extra): array
    {
        $this->ops->params = array_merge(array(
            'site_token' => 'accepted-by-double',
            'site' => array('name' => $this->siteName),
        ), $extra);
        return $this->ops->createNode();
    }

    public function testItemsAllMarkedDeleteAnswer400AndChangeNothing(): void
    {
        $before = file_get_contents($this->siteDir() . '/site.json');
        $result = $this->createWith(array('items' => array(
            array('title' => 'Gone 1', 'delete' => true),
            array('title' => 'Gone 2', 'delete' => true),
        )));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid items payload (every item is marked delete)', $result['__failed']['message']);
        $this->assertSame($before, file_get_contents($this->siteDir() . '/site.json'));
        $this->assertSame(array(), $this->haxcms->loadedSite->gitCommits);
    }

    public function testEmptyItemsWithoutNodeAnswer400(): void
    {
        $result = $this->createWith(array('items' => array()));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Node payload is required', $result['__failed']['message']);
        $this->assertSame(array(), $this->haxcms->loadedSite->gitCommits);
    }

    public function testEmptyItemsWithNodeCreatesTheNode(): void
    {
        // the items route treats an empty items array as no bulk payload and
        // lets a node through; the node is what gets created
        $result = $this->createWith(array(
            'items' => array(),
            'node' => array('title' => 'Single from node'),
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('Single from node', $result['data']->title);
        $this->assertArrayHasKey($result['data']->id, $this->persistedById());
    }

    public function testItemsSkipDeletedEntriesAndReturnTheLastCreated(): void
    {
        $result = $this->createWith(array('items' => array(
            array('title' => 'Kept 1'),
            array('title' => 'Dropped', 'delete' => true),
            array('title' => 'Kept 2'),
        )));
        $this->assertSame(200, $result['status']);
        $this->assertSame('Kept 2', $result['data']->title);
        $titles = array_map(function ($item) { return $item->title; }, array_values($this->persistedById()));
        $this->assertContains('Kept 1', $titles);
        $this->assertContains('Kept 2', $titles);
        $this->assertNotContains('Dropped', $titles);
        $this->assertCount(1, $this->haxcms->loadedSite->gitCommits);
    }

    public function testCommitMessageNamesTheNewPage(): void
    {
        $result = $this->create(array('title' => 'Committed page', 'contents' => '<p>x</p>'));
        $this->assertSame(200, $result['status']);
        $this->assertContains(
            'Page added:Committed page (' . $result['data']->id . ')',
            $this->haxcms->loadedSite->gitCommits
        );
    }
}

/**
 * OperationsTestHaxcms plus the outlineSchema seam itemFromParams needs and
 * the REAL generateMachineName (id sanitization is what is under test).
 */
class CreateNodeContentTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;
    private $real;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
        $this->real = (new ReflectionClass('HAXCMS'))->newInstanceWithoutConstructor();
    }

    public function generateMachineName($name)
    {
        return $this->real->generateMachineName($name);
    }
}

/** HAXCMSSite double: no git or feed rebuilds; real manifest + file writes. */
class CreateNodeContentTestSite extends HAXCMSSite
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
