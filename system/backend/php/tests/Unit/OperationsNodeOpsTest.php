<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the node-mutation Operations routes:
 * createNode, deleteNode, saveNodeDetails, saveOutline.
 *
 * Each route applies the same two-gate seam before any filesystem mutation:
 *   - site_token: validateRequestToken($site_token, user:sitename) -> 403
 *     'invalid site token'.
 *   - platform: $this->platformAllows($site, $capability) -> 403 when a
 *     platform.features flag is explicitly false.
 *
 * Happy-path slices run the REAL mutation logic (JSONOutlineSchema::save,
 * recurseCopy, manifest addItem/unset, order swap) against a temp site.json
 * fixture. git/twig collaborators (gitCommit, rebuildManagedFiles,
 * updateAlternateFormats, writePageAlternateFormats) are stubbed via a
 * HAXCMSSite test subclass so no git binary or twig template tree is needed.
 * Expected mutation values are derived from the route contract (item count
 * change, page-dir creation, order swap, tree-order reorder) and verified
 * independently by reading the persisted site.json back from disk.
 */
class OperationsNodeOpsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'my-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        // HAXCMS_ROOT is process-global (define can't be undone). Point at a
        // stable temp base so the boilerplate page template persists across
        // tests; per-test site fixtures live in their own uniqid subdirs.
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_node_root');
        }
        $this->ensureBoilerplate();

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_node_' . uniqid();
        $this->buildSiteFixture();

        // Install the mock as $GLOBALS['HAXCMS'] BEFORE loading the site,
        // because HAXCMSSite::load() calls $GLOBALS['HAXCMS']->cleanTitle().
        $this->haxcms = new OperationsNodeTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new OperationsNodeTestSite();
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Ensure the boilerplate page/default template exists under HAXCMS_ROOT
     * so recurseCopy has a source to copy from. Idempotent across tests.
     */
    private function ensureBoilerplate(): void
    {
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
        }
        if (!file_exists($boilerplateDir . '/index.html')) {
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
    }

    /**
     * Build a minimal temp site fixture: site.json with 2 top-level items
     * (item-a order 0, item-b order 1) plus on-disk page files so saveOutline's
     * getValidatedOutlineWriteTarget containment check passes.
     */
    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/item-a', 0777, true);
        mkdir($siteDir . '/pages/item-b', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content A</p>');
        file_put_contents($siteDir . '/pages/item-b/index.html', '<p>Content B</p>');

        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => false),
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
                (object)array(
                    'id' => 'item-b',
                    'indent' => 0,
                    'location' => 'pages/item-b/index.html',
                    'slug' => 'item-b',
                    'order' => 1,
                    'parent' => null,
                    'title' => 'Item B',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJsonPath(): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/site.json';
    }

    /**
     * Read the persisted site.json and return the decoded items as a flat
     * list (handles both array and object encodings that can result from
     * save(false) after an unset).
     */
    private function readPersistedItems(): array
    {
        $raw = file_get_contents($this->siteJsonPath());
        $decoded = json_decode($raw);
        $items = array();
        if (isset($decoded->items)) {
            foreach ($decoded->items as $item) {
                $items[] = $item;
            }
        }
        return $items;
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
    // createNode — site_token gate
    // =========================================================================

    public function testCreateNodeFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
            'node' => array('title' => 'New Page'),
        );
        $result = $this->ops->createNode();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // createNode — platformAllows gate (addPage)
    // =========================================================================

    public function testCreateNodeFailsWhenAddPageDisabled(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->disablePlatformFeature('addPage');
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('title' => 'New Page'),
        );
        $result = $this->ops->createNode();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Adding pages is disabled for this site', $result['__failed']['message']);
    }

    // =========================================================================
    // createNode — happy path (single node, no items bulk)
    // =========================================================================

    public function testCreateNodeAddsItemAndCreatesPageDir(): void
    {
        $this->haxcms->validRequestToken = true;
        $initialCount = count($this->haxcms->loadedSite->manifest->items);
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('title' => 'New Page'),
        );
        $result = $this->ops->createNode();

        $this->assertSame(200, $result['status']);
        $this->assertSame('New Page', $result['data']->title);
        $newId = $result['data']->id;
        $this->assertNotSame('', $newId);

        // Observable mutation 1: page directory created on disk via recurseCopy
        $pageFile = $this->tmpRoot . '/' . $this->siteName . '/pages/' . $newId . '/index.html';
        $this->assertTrue(file_exists($pageFile), 'Page directory + index.html created');

        // Observable mutation 2: manifest item count increased by 1
        $this->assertSame($initialCount + 1, count($this->haxcms->loadedSite->manifest->items));

        // Independent source of truth: persisted site.json carries the new item
        $persisted = $this->readPersistedItems();
        $ids = array();
        foreach ($persisted as $item) {
            $ids[] = $item->id;
        }
        $this->assertContains($newId, $ids);
        $this->assertSame($initialCount + 1, count($persisted));
    }

    // =========================================================================
    // deleteNode — site_token gate
    // =========================================================================

    public function testDeleteNodeFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->deleteNode();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // deleteNode — platformAllows gate (deletePage)
    // =========================================================================

    public function testDeleteNodeFailsWhenDeletePageDisabled(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->disablePlatformFeature('deletePage');
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->deleteNode();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Delete is disabled for this site', $result['__failed']['message']);
    }

    // =========================================================================
    // deleteNode — happy path
    // =========================================================================

    public function testDeleteNodeRemovesItemFromManifest(): void
    {
        $this->haxcms->validRequestToken = true;
        $initialCount = count($this->haxcms->loadedSite->manifest->items);
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a'),
        );
        $result = $this->ops->deleteNode();

        $this->assertSame(200, $result['status']);

        // Observable mutation: item-a removed from manifest (in-memory)
        $this->assertSame($initialCount - 1, count($this->haxcms->loadedSite->manifest->items));
        $this->assertFalse($this->haxcms->loadedSite->loadNode('item-a'));

        // Independent source of truth: persisted site.json no longer has item-a
        $persisted = $this->readPersistedItems();
        $ids = array();
        foreach ($persisted as $item) {
            $ids[] = $item->id;
        }
        $this->assertNotContains('item-a', $ids);
        $this->assertSame($initialCount - 1, count($persisted));

        // FINDING: deleteNode.php line 31 — the orphan-check foreach loop
        // reassigns $page via `$page = $site->loadNode($item->id)`, so after
        // the loop $page references the LAST surviving item, not the deleted
        // page. The return data (line 48) and gitCommit message (line 44)
        // therefore reference the wrong page. With 2 items where item-a is
        // deleted, the returned data is item-b (the sole survivor), and the
        // commit message says "Page deleted: Item B (item-b)" instead of
        // "Page deleted: Item A (item-a)". Characterized here as actual behavior.
        $this->assertSame('item-b', $result['data']->id, 'FINDING: returns surviving item, not deleted item');
        $site = $this->haxcms->loadedSite;
        $this->assertSame('Page deleted: Item B (item-b)', $site->gitCommits[0], 'FINDING: commit message names surviving item');
    }

    // =========================================================================
    // saveNodeDetails — site_token gate
    // =========================================================================

    public function testSaveNodeDetailsFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a', 'details' => array('operation' => 'moveDown')),
        );
        $result = $this->ops->saveNodeDetails();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // saveNodeDetails — platformAllows gate (outlineDesigner)
    // =========================================================================

    public function testSaveNodeDetailsFailsWhenOutlineDesignerDisabled(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->disablePlatformFeature('outlineDesigner');
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-a', 'details' => array('operation' => 'moveDown')),
        );
        $result = $this->ops->saveNodeDetails();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Outline operations are disabled for this site', $result['__failed']['message']);
    }

    // =========================================================================
    // saveNodeDetails — happy path (moveDown swaps order with next sibling)
    // =========================================================================

    public function testSaveNodeDetailsMoveDownSwapsOrderWithNextSibling(): void
    {
        $this->haxcms->validRequestToken = true;
        // item-a is order 0, item-b is order 1. moveDown on item-a should
        // swap: item-a -> order 1, item-b -> order 0.
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array(
                'id' => 'item-a',
                'details' => array('operation' => 'moveDown'),
            ),
        );
        $result = $this->ops->saveNodeDetails();

        $this->assertSame(200, $result['status']);
        $this->assertSame('item-a', $result['data']->id);
        $this->assertSame(1, (int) $result['data']->order);

        // Independent source of truth: persisted site.json reflects the swap.
        // save(false) preserves array order [item-a, item-b] but with updated
        // order values.
        $persisted = $this->readPersistedItems();
        $byId = array();
        foreach ($persisted as $item) {
            $byId[$item->id] = $item;
        }
        $this->assertSame(1, (int) $byId['item-a']->order);
        $this->assertSame(0, (int) $byId['item-b']->order);
    }

    // =========================================================================
    // saveNodeDetails — happy path (setTitle updates title via scoped Details)
    // =========================================================================

    public function testSaveNodeDetailsSetTitleUpdatesTitle(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array(
                'id' => 'item-a',
                'details' => array('operation' => 'setTitle', 'title' => 'Renamed Title'),
            ),
        );
        $result = $this->ops->saveNodeDetails();

        $this->assertSame(200, $result['status']);
        $this->assertSame('item-a', $result['data']->id);
        $this->assertSame('Renamed Title', $result['data']->title);

        // Independent source of truth: persisted site.json carries new title
        $persisted = $this->readPersistedItems();
        $byId = array();
        foreach ($persisted as $item) {
            $byId[$item->id] = $item;
        }
        $this->assertSame('Renamed Title', $byId['item-a']->title);
    }

    // =========================================================================
    // saveOutline — site_token gate
    // =========================================================================

    public function testSaveOutlineFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'site_token' => 'bad',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->saveOutline();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // saveOutline — platformAllows gate (outlineDesigner)
    // =========================================================================

    public function testSaveOutlineFailsWhenOutlineDesignerDisabled(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->disablePlatformFeature('outlineDesigner');
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array(
            'items' => array(
                array('id' => 'item-a', 'title' => 'Item A', 'order' => 0, 'slug' => 'item-a'),
                array('id' => 'item-b', 'title' => 'Item B', 'order' => 1, 'slug' => 'item-b'),
            ),
        );
        $result = $this->ops->saveOutline();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Outline operations are disabled for this site', $result['__failed']['message']);
    }

    // =========================================================================
    // saveOutline — happy path (reorders items)
    // =========================================================================

    public function testSaveOutlineReordersItems(): void
    {
        $this->haxcms->validRequestToken = true;
        // Send items in reversed order: item-b first (order 0), item-a second
        // (order 1). After saveOutline's manifest->save() with reorder=true,
        // orderTree sorts by order, so returned items should be [item-b, item-a].
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array(
            'items' => array(
                array(
                    'id' => 'item-b',
                    'title' => 'Item B',
                    'parent' => null,
                    'indent' => 0,
                    'order' => 0,
                    'slug' => 'item-b',
                    'metadata' => array(),
                ),
                array(
                    'id' => 'item-a',
                    'title' => 'Item A',
                    'parent' => null,
                    'indent' => 0,
                    'order' => 1,
                    'slug' => 'item-a',
                    'metadata' => array(),
                ),
            ),
        );
        $result = $this->ops->saveOutline();

        // Success contract: returns ['items' => [...]] in tree order
        $this->assertArrayHasKey('items', $result);
        $this->assertSame(2, count($result['items']));
        $this->assertSame('item-b', $result['items'][0]->id);
        $this->assertSame('item-a', $result['items'][1]->id);

        // Independent source of truth: persisted site.json is in tree order
        $persisted = $this->readPersistedItems();
        $this->assertSame(2, count($persisted));
        $this->assertSame('item-b', $persisted[0]->id);
        $this->assertSame('item-a', $persisted[1]->id);
    }
}

/**
 * HAXCMS mock subclass that adds the outlineSchema property (a real
 * JSONOutlineSchema) needed by itemFromParams / saveOutline's newItem() call.
 * All token/access/site behavior is inherited from OperationsTestHaxcms.
 */
class OperationsNodeTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }
}

/**
 * HAXCMSSite test subclass that no-ops the git/twig collaborators while
 * keeping the real manifest load/save, recurseCopy, addPage, deleteNode,
 * updateNode, loadNode, getUniqueSlugName, and itemFromParams. This lets
 * the REAL mutation logic in the Operations routes run against a temp
 * fixture without a git binary or twig template tree.
 */
class OperationsNodeTestSite extends HAXCMSSite
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
