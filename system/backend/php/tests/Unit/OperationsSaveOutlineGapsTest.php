<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Gap-filling characterization tests for saveOutline
 * (lib/operations/saveOutline.php), complementary to OperationsNodeOpsTest
 * (gates, reorder, idMap basics):
 *
 *   - missing items array 400; item without id 400
 *   - non-object items skipped + per-item normalization defaults
 *     (title/indent/order/slug/metadata, non-string contents cleared,
 *     non-boolean delete coerced to false)
 *   - reserved 'x' route slug rewritten to x-x on creation
 *   - new child under a NEW parent maps the client parent id via idMap
 *   - overridePathauto keeps the client slug (and the moved-detection pass)
 *   - existing item with pathauto on regenerates its slug
 *   - manifest item whose page file is missing on disk gets recreated from
 *     the boilerplate
 *   - client metadata keys copied onto the page
 *   - duplicate: source content written to a new page; unknown source 400;
 *     non-string duplicate value 400 (normalization quirk: it becomes ''
 *     which then fails the source lookup); non-HTML source content 400
 *   - contents: written to the new page (alternate formats + scan); non-HTML
 *     contents 400
 *   - delete flag removes the page + commits 'Page deleted:'
 *   - deleting a parent rescues orphaned children (parent -> null, order
 *     bumped)
 *
 * The route's write paths resolve HAXCMS_ROOT/<sitesDirectory>/<siteName>/,
 * so the fixture is nested under a temp HAXCMS_ROOT with sitesDirectory
 * rebound (same pattern as OperationsNodeOpsTest::setUpBodyFixture).
 * Expected values are read back from the persisted site.json + on-disk page
 * files, not re-derived from the implementation.
 */
class OperationsSaveOutlineGapsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
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

        // HAXCMS_ROOT is process-global (define can't be undone). Per-test
        // site fixtures live in their own uniqid subdirs under it.
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_outline_root');
        }
        $this->ensureBoilerplate();

        $this->fixtureDirName = 'fixtures_outline_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $this->buildSiteFixture();

        $this->haxcms = new SaveOutlineGapsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new SaveOutlineGapsTestSite();
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
     * Ensure the boilerplate page/default template exists under HAXCMS_ROOT
     * so recurseCopy has a source. Idempotent across tests.
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
     * Nested site fixture with pathauto ON:
     *   item-a (top, order 0, on-disk content)
     *   item-b (top, order 1, NO on-disk file -> recreate branch)
     *   item-c (child of item-a, order 0, on-disk content)
     */
    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/item-a', 0777, true);
        mkdir($siteDir . '/pages/item-c', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content A</p>');
        file_put_contents($siteDir . '/pages/item-c/index.html', '<p>Content C</p>');

        $item = function (string $id, string $title, string $slug, int $order, $parent, int $indent, string $location) {
            return (object)array(
                'id' => $id,
                'indent' => $indent,
                'location' => $location,
                'slug' => $slug,
                'order' => $order,
                'parent' => $parent,
                'title' => $title,
                'description' => '',
                'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
            );
        };
        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => true),
                ),
                'platform' => (object)array('features' => (object)array()),
            ),
            'items' => array(
                $item('item-a', 'Item A', 'item-a', 0, null, 0, 'pages/item-a/index.html'),
                $item('item-b', 'Item B', 'item-b', 1, null, 0, 'pages/item-b/index.html'),
                $item('item-c', 'Child Page', 'child-page', 0, 'item-a', 1, 'pages/item-c/index.html'),
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
        if (isset($decoded->items)) {
            foreach ($decoded->items as $item) {
                $byId[$item->id] = $item;
            }
        }
        return $byId;
    }

    /** Run saveOutline with the given raw items payload. */
    private function runOutline(array $items): array
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    private function itemPayload(array $overrides = array()): array
    {
        return array_merge(array(
            'id' => 'item-a',
            'title' => 'Item A',
            'slug' => 'item-a',
            'order' => 0,
            'parent' => null,
            'indent' => 0,
            'metadata' => array(),
        ), $overrides);
    }

    // =========================================================================
    // payload validation
    // =========================================================================

    public function testMissingItemsArrayFailsWith400(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array();
        $result = $this->ops->saveOutline();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Outline payload requires an items array', $result['__failed']['message']);
    }

    public function testItemWithoutIdFailsWith400(): void
    {
        $result = $this->runOutline(array(array('title' => 'No id')));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Each outline item requires an id', $result['__failed']['message']);
    }

    public function testNonObjectItemsSkippedAndDefaultsApplied(): void
    {
        $result = $this->runOutline(array(
            // non-object entry: skipped entirely
            'garbage-entry',
            // item-a with junk fields: defaults kick in per field
            array(
                'id' => 'item-a',
                'contents' => 12345,
                'delete' => 'yes',
            ),
        ));
        $this->assertArrayHasKey('items', $result);

        // non-string contents was cleared (not written); delete flag coerced
        // off (only literal true deletes)
        $byId = $this->persistedById();
        $this->assertArrayHasKey('item-a', $byId);
        $this->assertSame('Item A', $byId['item-a']->title);
        // on-disk content untouched by the junk contents value
        $this->assertSame('<p>Content A</p>', file_get_contents($this->siteDir() . '/pages/item-a/index.html'));
    }

    // =========================================================================
    // slug normalization + creation
    // =========================================================================

    public function testReservedXRouteSlugRewrittenOnCreation(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-x',
            'title' => 'X',
            'slug' => '',
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertArrayHasKey('items', $result);

        // pathauto + override + empty slug: slug falls back to the title
        // derivation, and 'x' is the reserved front-end route so it becomes x-x
        // (the server assigns a fresh id; idMap maps the client id to it)
        $serverId = $result['idMap']->{'fresh-x'};
        $this->assertNotSame('', $serverId);
        $byId = $this->persistedById();
        $this->assertArrayHasKey($serverId, $byId);
        $this->assertSame('x-x', $byId[$serverId]->slug);
    }

    public function testNewChildUnderNewParentMapsClientId(): void
    {
        $result = $this->runOutline(array(
            $this->itemPayload(array(
                'id' => 'client-parent',
                'title' => 'New Parent',
                'slug' => '',
                'order' => 0,
            )),
            array(
                'id' => 'client-child',
                'title' => 'Child Page',
                'slug' => '',
                'order' => 1,
                // references the CLIENT id of the new parent above
                'parent' => 'client-parent',
                'indent' => 1,
                'metadata' => array(),
            ),
        ));
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('idMap', $result);

        // idMap maps client-parent to the server-generated id
        $serverParentId = $result['idMap']->{'client-parent'};
        $this->assertNotSame('', $serverParentId);

        // the child's parent was remapped to the server id (not the client id)
        $byId = $this->persistedById();
        $serverChildId = $result['idMap']->{'client-child'};
        $this->assertSame($serverParentId, $byId[$serverChildId]->parent);
    }

    public function testOverridePathautoKeepsClientSlug(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'slug' => 'my-custom-slug',
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertArrayHasKey('items', $result);

        // pathauto is on for the fixture, but the page-level override wins:
        // the client slug survives (and is detected as a move)
        $byId = $this->persistedById();
        $this->assertSame('my-custom-slug', $byId['item-a']->slug);
    }

    public function testExistingItemSlugRegeneratedWithPathauto(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'title' => 'Renamed Title',
            'slug' => '',
            'metadata' => array(),
        ))));
        $this->assertArrayHasKey('items', $result);

        // pathauto on + no override: slug regenerated from the new title
        $byId = $this->persistedById();
        $this->assertSame('Renamed Title', $byId['item-a']->title);
        $this->assertSame('renamed-title', $byId['item-a']->slug);
    }

    public function testMissingPageFileOnDiskIsRecreated(): void
    {
        // pathauto off for this slice so the item is NOT "moved" and the
        // missing on-disk page file is the thing that triggers recreation
        $site = $this->haxcms->loadedSite;
        $site->manifest->metadata->site->settings->pathauto = false;
        // item-b is in the manifest but has no pages/item-b on disk
        $this->assertFileDoesNotExist($this->siteDir() . '/pages/item-b/index.html');

        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'item-b',
            'title' => 'Item B',
            'slug' => 'item-b',
            'order' => 1,
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertArrayHasKey('items', $result);

        // the boilerplate page was copied in for the missing location
        // (HAXCMS_ROOT/system is symlinked at the REAL repo boilerplate by the
        // phpunit bootstrap, so expect the real template content)
        $boilerplate = file_get_contents(
            HAXCMS_ROOT . '/system/boilerplate/page/default/index.html'
        );
        $this->assertFileExists($this->siteDir() . '/pages/item-b/index.html');
        $this->assertSame(
            $boilerplate,
            file_get_contents($this->siteDir() . '/pages/item-b/index.html')
        );
    }

    public function testClientMetadataCopiedOntoPage(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'metadata' => array(
                'tags' => array('one', 'two'),
                'icon' => 'icons:star',
            ),
        ))));
        $this->assertArrayHasKey('items', $result);

        $byId = $this->persistedById();
        $this->assertSame(array('one', 'two'), (array) $byId['item-a']->metadata->tags);
        $this->assertSame('icons:star', $byId['item-a']->metadata->icon);
    }

    // =========================================================================
    // duplicate
    // =========================================================================

    public function testDuplicateWritesSourceContentToNewPage(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-dup',
            'title' => 'Fresh Dup',
            'slug' => 'fresh-dup',
            'duplicate' => 'item-a',
            'order' => 2,
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertArrayHasKey('items', $result);

        // the new page carries the (sanitized) content of item-a; the server
        // assigned a fresh id, mapped from the client id via idMap
        $serverId = $result['idMap']->{'fresh-dup'};
        $this->assertNotSame('', $serverId);
        $byId = $this->persistedById();
        $this->assertArrayHasKey($serverId, $byId);
        $this->assertSame(
            '<p>Content A</p>',
            file_get_contents($this->siteDir() . '/pages/' . $serverId . '/index.html')
        );

        // alternate formats were asked to write for the new page
        $site = $this->haxcms->loadedSite;
        $this->assertContains($serverId, $site->alternateFormatsWritten);
    }

    public function testDuplicateUnknownSourceFailsWith400(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-dup',
            'slug' => 'fresh-dup',
            'duplicate' => 'ghost-source',
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid duplicate source', $result['__failed']['message']);
    }

    public function testDuplicateNonStringValueBecomesEmptyAndFailsLookup(): void
    {
        // normalization clears a non-string duplicate to '', and '' then
        // fails the source lookup -> invalid duplicate source
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-dup',
            'slug' => 'fresh-dup',
            'duplicate' => 42,
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid duplicate source', $result['__failed']['message']);
    }

    public function testDuplicateNonHtmlSourceFailsWith400(): void
    {
        // a source page whose content is not HTML
        file_put_contents($this->siteDir() . '/pages/item-c/index.html', 'plain text, no tags');
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-dup',
            'slug' => 'fresh-dup',
            'duplicate' => 'item-c',
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid duplicate content', $result['__failed']['message']);
    }

    // =========================================================================
    // contents
    // =========================================================================

    public function testContentsWrittenToNewPage(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-contents',
            'title' => 'Fresh Contents',
            'slug' => 'fresh-contents',
            'contents' => '<p>Fresh <b>body</b> content</p>',
            'order' => 2,
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertArrayHasKey('items', $result);

        $serverId = $result['idMap']->{'fresh-contents'};
        $this->assertNotSame('', $serverId);
        $byId = $this->persistedById();
        $this->assertArrayHasKey($serverId, $byId);
        $this->assertSame(
            '<p>Fresh <b>body</b> content</p>',
            file_get_contents($this->siteDir() . '/pages/' . $serverId . '/index.html')
        );

        $site = $this->haxcms->loadedSite;
        $this->assertContains($serverId, $site->alternateFormatsWritten);
    }

    public function testNonHtmlContentsFailsWith400(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'id' => 'fresh-contents',
            'slug' => 'fresh-contents',
            'contents' => 'just text, no markup',
            'metadata' => array('overridePathauto' => true),
        ))));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid page contents', $result['__failed']['message']);
    }

    // =========================================================================
    // delete flag + orphan rescue
    // =========================================================================

    public function testDeleteFlagRemovesPageAndCommits(): void
    {
        $result = $this->runOutline(array($this->itemPayload(array(
            'delete' => true,
        ))));
        $this->assertArrayHasKey('items', $result);

        $byId = $this->persistedById();
        $this->assertArrayNotHasKey('item-a', $byId);

        // the delete pass commits with the canonical message
        $site = $this->haxcms->loadedSite;
        $this->assertContains('Page deleted: Item A (item-a)', $site->gitCommits);
    }

    public function testDeletingParentRescuesOrphanedChild(): void
    {
        // item-c is a child of item-a; deleting item-a must rescue item-c
        $result = $this->runOutline(array($this->itemPayload(array(
            'delete' => true,
        ))));
        $this->assertArrayHasKey('items', $result);

        $byId = $this->persistedById();
        $this->assertArrayHasKey('item-c', $byId);
        // orphaned child reparented to the top level
        $this->assertNull($byId['item-c']->parent);
        // order bumped to the bottom of the surviving outline
        $this->assertGreaterThanOrEqual(1, (int) $byId['item-c']->order);
    }
}

/**
 * OperationsTestHaxcms subclass with the outlineSchema (newItem for new
 * pages) + generateMachineName seams saveOutline needs.
 */
class SaveOutlineGapsTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', (string) $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = str_replace(array('\\\\', '/'), '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        return $name === '' ? 'default' : $name;
    }
}

/**
 * HAXCMSSite double: records git commits + alternate-format writes; real
 * manifest/loadNode/updateNode/addItem/deleteNode/recurseCopy/getPageContent.
 */
class SaveOutlineGapsTestSite extends HAXCMSSite
{
    public $gitCommits = array();
    public $alternateFormatsWritten = array();

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
        $this->alternateFormatsWritten[] = is_object($page) ? $page->id : strval($page);
        return true;
    }
}
