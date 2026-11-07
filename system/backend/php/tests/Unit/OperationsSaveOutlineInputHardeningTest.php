<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * saveOutline (lib/operations/saveOutline.php) input hardening and the
 * normalization branches the existing saveOutline suites do not reach.
 *
 * outline-designer posts the whole tree on every save, so anything a client
 * can put in an item has to come out the other side either as sent or as a
 * safe, deterministic value. These tests pin:
 *
 *   - a client-sent `location` is ignored: existing pages keep their stored
 *     location and new pages always land at pages/<server id>/index.html,
 *     even when the client sends a traversal path
 *   - a client-sent slug with traversal is reduced to a safe slug (real
 *     HAXCMS::generateSlugName, not the test double)
 *   - the reserved `x` route prefix: a slug of x/... is rewritten to x-x/...
 *   - a slug that normalizes to nothing becomes `blank`
 *   - pathauto off: an existing page whose slug changed keeps the new slug
 *   - a title that is nothing but markup keeps the stored title
 *   - scalar metadata is normalized to an object (save still succeeds)
 *   - an item with no order takes its position in the payload
 *   - a brand new item sent with delete:true is not left in site.json
 *
 * Expected values are read back from the persisted site.json and the files
 * on disk.
 */
class OperationsSaveOutlineInputHardeningTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'harden-site';

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
        $this->fixtureDirName = 'fixtures_outline_harden_' . bin2hex(random_bytes(6));
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $this->haxcms = new SaveOutlineHardeningTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        $this->haxcms->validRequestToken = true;
        $this->haxcms->sitesDirectory = $this->fixtureDirName;
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

    /**
     * Two top-level pages (item-a, item-b), both with content on disk.
     */
    private function buildSite(bool $pathauto): void
    {
        $siteDir = $this->siteDir();
        mkdir($siteDir . '/pages/item-a', 0777, true);
        mkdir($siteDir . '/pages/item-b', 0777, true);
        mkdir($this->haxcms->configDirectory, 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content A</p>');
        file_put_contents($siteDir . '/pages/item-b/index.html', '<p>Content B</p>');
        $item = function (string $id, string $title, int $order) {
            return (object) array(
                'id' => $id,
                'indent' => 0,
                'location' => 'pages/' . $id . '/index.html',
                'slug' => $id,
                'order' => $order,
                'parent' => null,
                'title' => $title,
                'description' => '',
                'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
            );
        };
        $manifest = (object) array(
            'id' => 'site-uuid',
            'title' => 'Hardening Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->siteName,
                    'settings' => (object) array('pathauto' => $pathauto),
                ),
                'platform' => (object) array('features' => (object) array()),
            ),
            'items' => array(
                $item('item-a', 'Item A', 0),
                $item('item-b', 'Item B', 1),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $site = new SaveOutlineHardeningTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function persisted(): array
    {
        $decoded = json_decode(file_get_contents($this->siteDir() . '/site.json'));
        $byId = array();
        foreach ($decoded->items as $item) {
            $byId[$item->id] = $item;
        }
        return $byId;
    }

    private function runOutline(array $items): array
    {
        $this->ops->params = array(
            'site_token' => 'accepted-by-double',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    private function assertSaved(array $result): void
    {
        $this->assertArrayNotHasKey('__failed', $result, isset($result['__failed']['message']) ? $result['__failed']['message'] : '');
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('idMap', $result);
    }

    private function item(string $id, array $overrides = array()): array
    {
        return array_merge(array(
            'id' => $id,
            'title' => $id === 'item-a' ? 'Item A' : 'Item B',
            'slug' => $id,
            'order' => $id === 'item-a' ? 0 : 1,
            'parent' => null,
            'indent' => 0,
            'metadata' => array(),
        ), $overrides);
    }

    // =========================================================================
    // location is backend-controlled
    // =========================================================================

    public function testClientLocationOnExistingPageIsIgnored(): void
    {
        $this->buildSite(true);
        $result = $this->runOutline(array(
            $this->item('item-a', array('location' => '../../outside/index.html')),
            $this->item('item-b'),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertSame('pages/item-a/index.html', $byId['item-a']->location);
        $this->assertFileDoesNotExist($this->tmpRoot . '/outside/index.html');
        $this->assertSame('<p>Content A</p>', file_get_contents($this->siteDir() . '/pages/item-a/index.html'));
    }

    public function testNewPageLandsUnderServerIdNotClientLocation(): void
    {
        $this->buildSite(true);
        $result = $this->runOutline(array(
            $this->item('item-a'),
            $this->item('item-b'),
            array(
                'id' => 'client-new',
                'title' => 'Brand New',
                'order' => 2,
                'parent' => null,
                'indent' => 0,
                'location' => '../../escape/index.html',
                'contents' => '<p>new body</p>',
            ),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertArrayNotHasKey('client-new', $byId, 'client ids are not trusted for new pages');
        $newItems = array_values(array_diff_key($byId, array('item-a' => 1, 'item-b' => 1)));
        $this->assertCount(1, $newItems);
        $new = $newItems[0];
        $this->assertSame('pages/' . $new->id . '/index.html', $new->location);
        $this->assertStringContainsString('new body', file_get_contents($this->siteDir() . '/' . $new->location));
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/escape');
        $this->assertDirectoryDoesNotExist(dirname($this->tmpRoot) . '/escape');
    }

    // =========================================================================
    // slug normalization (pathauto off so client slugs are honored)
    // =========================================================================

    public function testTraversalSlugIsReducedToASafeSlug(): void
    {
        $this->buildSite(false);
        $result = $this->runOutline(array(
            $this->item('item-a', array('slug' => '../../etc/passwd')),
            $this->item('item-b'),
        ));
        $this->assertSaved($result);
        $slug = $this->persisted()['item-a']->slug;
        $this->assertStringNotContainsString('..', $slug);
        $this->assertStringStartsNotWith('/', $slug);
        $this->assertSame('etc/passwd', $slug);
    }

    public function testReservedXRoutePrefixIsRewritten(): void
    {
        $this->buildSite(false);
        $result = $this->runOutline(array(
            $this->item('item-a', array('slug' => 'x/api')),
            $this->item('item-b'),
        ));
        $this->assertSaved($result);
        $this->assertSame('x-x/api', $this->persisted()['item-a']->slug);
    }

    public function testSlugThatNormalizesToNothingBecomesBlank(): void
    {
        $this->buildSite(false);
        $result = $this->runOutline(array(
            $this->item('item-a', array('slug' => '!!!')),
            $this->item('item-b'),
        ));
        $this->assertSaved($result);
        $this->assertSame('blank', $this->persisted()['item-a']->slug);
    }

    public function testPathautoOffKeepsTheChangedSlugOfAnExistingPage(): void
    {
        $this->buildSite(false);
        $result = $this->runOutline(array(
            $this->item('item-a', array('slug' => 'Renamed Page')),
            $this->item('item-b'),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertSame('renamed-page', $byId['item-a']->slug);
        $this->assertSame('item-b', $byId['item-b']->slug);
        // a slug change does not move the page file
        $this->assertSame('pages/item-a/index.html', $byId['item-a']->location);
    }

    // =========================================================================
    // item normalization
    // =========================================================================

    public function testMarkupOnlyTitleKeepsTheStoredTitle(): void
    {
        $this->buildSite(true);
        $result = $this->runOutline(array(
            $this->item('item-a', array('title' => '<img src=x onerror=alert(1)>')),
            $this->item('item-b', array('title' => '<b>Bold</b> Title')),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertSame('Item A', $byId['item-a']->title);
        $this->assertSame('Bold Title', $byId['item-b']->title);
    }

    public function testScalarMetadataIsNormalizedToAnObject(): void
    {
        $this->buildSite(true);
        $result = $this->runOutline(array(
            $this->item('item-a', array('metadata' => 'not-an-object')),
            $this->item('item-b', array('metadata' => 42)),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertIsObject($byId['item-a']->metadata);
        $this->assertIsObject($byId['item-b']->metadata);
        // stored metadata survives a scalar overwrite attempt
        $this->assertSame(1000000, $byId['item-a']->metadata->created);
    }

    public function testMissingOrderFallsBackToPayloadPosition(): void
    {
        $this->buildSite(true);
        $b = $this->item('item-b');
        $a = $this->item('item-a');
        unset($a['order'], $b['order']);
        // b first, a second: position in the payload becomes the order
        $result = $this->runOutline(array($b, $a));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertSame(0, $byId['item-b']->order);
        $this->assertSame(1, $byId['item-a']->order);
    }

    public function testNewItemSentWithDeleteIsNotLeftInSiteJson(): void
    {
        $this->buildSite(true);
        $result = $this->runOutline(array(
            $this->item('item-a'),
            $this->item('item-b'),
            array(
                'id' => 'client-throwaway',
                'title' => 'Throwaway',
                'order' => 2,
                'parent' => null,
                'indent' => 0,
                'delete' => true,
            ),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertSame(array('item-a', 'item-b'), array_keys($byId));
    }

    public function testNonBooleanDeleteDoesNotDelete(): void
    {
        $this->buildSite(true);
        $result = $this->runOutline(array(
            $this->item('item-a', array('delete' => 'true')),
            $this->item('item-b', array('delete' => 1)),
        ));
        $this->assertSaved($result);
        $byId = $this->persisted();
        $this->assertArrayHasKey('item-a', $byId);
        $this->assertArrayHasKey('item-b', $byId);
    }
}

/**
 * OperationsTestHaxcms with the outlineSchema seam saveOutline needs and the
 * REAL HAXCMS slug / machine-name normalizers (the shared double's
 * generateSlugName is a simplification that would hide traversal handling).
 */
class SaveOutlineHardeningTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;
    private $real;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
        $this->real = (new ReflectionClass('HAXCMS'))->newInstanceWithoutConstructor();
    }

    public function generateSlugName($name)
    {
        return $this->real->generateSlugName($name);
    }

    public function generateMachineName($name)
    {
        return $this->real->generateMachineName($name);
    }
}

/**
 * HAXCMSSite double: no git, no feed rebuilds; real manifest, loadNode,
 * deleteNode, recurseCopy and getPageContent.
 */
class SaveOutlineHardeningTestSite extends HAXCMSSite
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
