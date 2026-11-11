<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Outline item ids are strings (haxtheweb/json-outline-schema: id is a
 * string, parent an id or null). A hand-made site.json, skeleton or import
 * may hold integer ids; they become strings when the site is loaded (and on
 * every save), so saveOutline can require string ids. Ids are otherwise
 * opaque: never trimmed. haxcms-nodejs mirrors every test here
 * (test/unit/outline-numeric-ids.test.cjs).
 *
 * Same fixture pattern as OperationsSaveOutlineGapsTest: the real
 * saveOutline route, JSONOutlineSchema and HAXCMSSite against a site on
 * disk under a temp HAXCMS_ROOT, with only git / feed rebuilds stubbed.
 */
class OutlineNumericIdsTest extends TestCase
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
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_outline_root');
        }
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
        }
        if (!file_exists($boilerplateDir . '/index.html')) {
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
        $this->fixtureDirName = 'fixtures_numeric_ids_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $this->buildHandMadeSite();

        $this->haxcms = new OutlineNumericIdsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
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
     * A site.json written by hand: pages A (1), A1 (2, under 1), B (3) and
     * C (" page-c ", an id padded with spaces), each with its page folder; the
     * parents of the top level pages are null and "".
     */
    private function buildHandMadeSite(): void
    {
        $siteDir = $this->siteDir();
        $item = function ($id, string $title, string $folder, int $order, $parent, int $indent) use ($siteDir) {
            mkdir($siteDir . '/pages/' . $folder, 0777, true);
            file_put_contents($siteDir . '/pages/' . $folder . '/index.html', '<p>' . $title . '</p>');
            return (object) array(
                'id' => $id,
                'indent' => $indent,
                'location' => 'pages/' . $folder . '/index.html',
                'slug' => strtolower($title),
                'order' => $order,
                'parent' => $parent,
                'title' => $title,
                'description' => '',
                'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
            );
        };
        $manifest = (object) array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
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
                $item(1, 'A', 'page-a', 0, null, 0),
                $item(2, 'A1', 'page-a1', 0, 1, 1),
                $item(3, 'B', 'page-b', 1, '', 0),
                $item(' page-c ', 'C', 'page-c', 2, null, 0),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function readSiteJson(): string
    {
        return file_get_contents($this->siteDir() . '/site.json');
    }

    private function savedByTitle(): array
    {
        $byTitle = array();
        foreach (json_decode($this->readSiteJson())->items as $item) {
            $byTitle[$item->title] = $item;
        }
        return $byTitle;
    }

    private function pageFolders(): array
    {
        $dirs = array_values(array_filter(scandir($this->siteDir() . '/pages'), function ($dir) {
            return $dir[0] !== '.';
        }));
        sort($dirs);
        return $dirs;
    }

    private function loadSite(): OutlineNumericIdsTestSite
    {
        $site = new OutlineNumericIdsTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        return $site;
    }

    private function runOutline(array $items): array
    {
        $this->loadSite();
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    public function testNormalizeOutlineIdTurnsIntegersIntoStrings(): void
    {
        $this->assertSame('7', JSONOutlineSchema::normalizeOutlineId(7));
        $this->assertSame('0', JSONOutlineSchema::normalizeOutlineId(0));
        $this->assertSame('0', JSONOutlineSchema::normalizeOutlineId(-0.0));
        $this->assertSame('-12', JSONOutlineSchema::normalizeOutlineId(-12));
        // json_decode reads 7.0 as a float; JavaScript reads it as 7
        $this->assertSame('7', JSONOutlineSchema::normalizeOutlineId(7.0));
        $this->assertSame('9007199254740991', JSONOutlineSchema::normalizeOutlineId(9007199254740991));
        foreach (array(null, '', '7', ' 7 ', 'item-1', 1.5, 9007199254740992, NAN, INF, true) as $value) {
            $result = JSONOutlineSchema::normalizeOutlineId($value);
            if (is_float($value) && is_nan($value)) {
                $this->assertNan($result);
            } else {
                $this->assertSame($value, $result, var_export($value, true));
            }
        }
    }

    public function testLoadingNumericIdsAndParentsGivesStringIds(): void
    {
        $site = $this->loadSite();
        $items = array();
        foreach ($site->manifest->items as $item) {
            $items[$item->title] = $item;
        }
        $this->assertSame('1', $items['A']->id);
        $this->assertSame('2', $items['A1']->id);
        $this->assertSame('1', $items['A1']->parent);
        $this->assertSame('3', $items['B']->id);
        // null and "" parents are left as they were
        $this->assertNull($items['A']->parent);
        $this->assertSame('', $items['B']->parent);
        // a padded string id is kept exactly
        $this->assertSame(' page-c ', $items['C']->id);
        // lookups by the string id work
        $this->assertSame('A1', $site->loadNode('2')->title);
        $this->assertSame('A', $site->manifest->getItemById('1')->title);
    }

    public function testFullSaveOfASiteLoadedWithNumericIdsSucceedsAndWritesStrings(): void
    {
        $foldersBefore = $this->pageFolders();
        $site = $this->loadSite();
        // what the editor sends back: the items it was given, over JSON
        $sent = json_decode(json_encode($site->manifest->items), true);
        foreach ($sent as $key => $item) {
            if ($item['title'] === 'B') {
                $sent[$key]['title'] = 'B renamed';
            }
        }
        $result = $this->runOutline($sent);
        $this->assertArrayNotHasKey('__failed', $result, json_encode($result));
        // no page was treated as new
        $this->assertSame(array(), (array) $result['idMap']);
        $this->assertSame($foldersBefore, $this->pageFolders());
        $saved = $this->savedByTitle();
        $this->assertCount(4, $saved);
        $this->assertSame('1', $saved['A']->id);
        $this->assertSame('2', $saved['A1']->id);
        $this->assertSame('1', $saved['A1']->parent);
        $this->assertSame('3', $saved['B renamed']->id);
        $this->assertNull($saved['B renamed']->parent);
        $this->assertSame(' page-c ', $saved['C']->id);
        foreach ($saved as $title => $item) {
            $this->assertIsString($item->id, $title);
        }
    }

    public function testAnyOtherSiteJsonWriteAlsoWritesStringIds(): void
    {
        $site = $this->loadSite();
        // an id that bypassed load (an in-memory change) is still saved as a string
        $site->manifest->items[0]->parent = 2;
        $site->manifest->items[0]->id = 10;
        $site->manifest->save(false);
        $saved = json_decode($this->readSiteJson())->items;
        $this->assertSame('10', $saved[0]->id);
        $this->assertSame('2', $saved[0]->parent);
    }

    public function testNumericIdInASaveOutlineRequestIs400AndNothingIsWritten(): void
    {
        $before = $this->readSiteJson();
        $foldersBefore = $this->pageFolders();
        // an existing page's id as a number, and a new page with a number
        foreach (array(1, 99, 0) as $id) {
            $result = $this->runOutline(array(
                array('id' => '3', 'title' => 'B renamed', 'parent' => null, 'indent' => 0, 'order' => 1),
                array('id' => $id, 'title' => 'Numeric', 'parent' => null, 'indent' => 0, 'order' => 0),
            ));
            $this->assertSame(400, $result['__failed']['status']);
            $this->assertSame('Each outline item requires an id', $result['__failed']['message']);
            $this->assertSame($before, $this->readSiteJson());
            $this->assertSame($foldersBefore, $this->pageFolders());
        }
    }

    public function testPaddedExistingIdRoundTripsWithoutCreatingANewPage(): void
    {
        $foldersBefore = $this->pageFolders();
        $result = $this->runOutline(array(
            array('id' => ' page-c ', 'title' => 'C renamed', 'parent' => null, 'indent' => 0, 'order' => 2),
        ));
        $this->assertArrayNotHasKey('__failed', $result, json_encode($result));
        $this->assertSame(array(), (array) $result['idMap']);
        $this->assertSame($foldersBefore, $this->pageFolders());
        $saved = json_decode($this->readSiteJson())->items;
        $this->assertCount(4, $saved);
        $found = null;
        foreach ($saved as $item) {
            if ($item->id === ' page-c ') {
                $found = $item;
            }
        }
        $this->assertNotNull($found, 'the page keeps its id exactly');
        $this->assertSame('C renamed', $found->title);
    }

    public function testIdsAreOpaquePaddedOrZeroPrefixedIsNotTheSamePage(): void
    {
        // PHP's == would match " 3 " and "03" with "3"; haxcms-nodejs does not
        foreach (array(' 3 ', '03') as $id) {
            $result = $this->runOutline(array(
                array('id' => $id, 'title' => 'Not B', 'parent' => null, 'indent' => 0, 'order' => 5),
            ));
            $this->assertArrayNotHasKey('__failed', $result, json_encode($result));
            // a new page, mapped from the id that was sent
            $this->assertSame(array($id), array_keys((array) $result['idMap']));
            $saved = $this->savedByTitle();
            $this->assertSame('3', $saved['B']->id, 'page 3 is unchanged');
        }
        $this->assertCount(6, json_decode($this->readSiteJson())->items);
    }

    public function testBlankIdIs400AndNothingIsWritten(): void
    {
        $before = $this->readSiteJson();
        // blank is the whitespace JavaScript's trim() removes (haxcms-nodejs)
        foreach (array('', '   ', "\t\n", "\u{00A0}", "\u{FEFF}\u{3000}") as $id) {
            $result = $this->runOutline(array(
                array('id' => $id, 'title' => 'Blank', 'parent' => null, 'indent' => 0, 'order' => 0),
            ));
            $this->assertSame(400, $result['__failed']['status'], json_encode($id));
            $this->assertSame('Each outline item requires an id', $result['__failed']['message']);
            $this->assertSame($before, $this->readSiteJson());
        }
    }
}

class OutlineNumericIdsTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }
}

/**
 * HAXCMSSite double: git commits and feed rebuilds are no-ops; manifest,
 * loadNode, updateNode, addItem and page folders are real.
 */
class OutlineNumericIdsTestSite extends HAXCMSSite
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
