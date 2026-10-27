<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * saveOutline failures (haxtheweb/issues#3114). Mirrors haxcms-nodejs
 * test/unit/save-outline-failure-parity.test.cjs.
 *
 * Saving per item used to write site.json as each existing page was
 * updated, so whatever failed part-way through a request, the structure as
 * far as it got was already saved. Batching the writes must not change that:
 *   - a crash or rejection in the content or delete phase leaves the whole
 *     new structure saved (written once, as a checkpoint, after the
 *     structure pass)
 *   - a crash during the structure pass leaves the pages before it saved
 * and siblings that share an order keep the order the request sent them in.
 */
class OperationsSaveOutlineFailureTest extends TestCase
{
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
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
        }
        if (!file_exists($boilerplateDir . '/index.html')) {
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
        $this->fixtureDirName = 'fixtures_outline_fail_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
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

    /** pages A, B, C at the top level and A1, A2 under A, on disk */
    private function buildSite(): FailureTestSite
    {
        $this->rrmdir($this->tmpRoot);
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $pages = array(
            array('a', 'A', null, 0, 0),
            array('a1', 'A1', 'a', 0, 1),
            array('a2', 'A2', 'a', 1, 1),
            array('b', 'B', null, 1, 0),
            array('c', 'C', null, 2, 0),
        );
        $items = array();
        foreach ($pages as $p) {
            mkdir($siteDir . '/pages/' . $p[0], 0777, true);
            file_put_contents($siteDir . '/pages/' . $p[0] . '/index.html', '<p>' . $p[1] . '</p>');
            $items[] = (object)array(
                'id' => $p[0],
                'indent' => $p[4],
                'location' => 'pages/' . $p[0] . '/index.html',
                'slug' => $p[0],
                'order' => $p[3],
                'parent' => $p[2],
                'title' => $p[1],
                'description' => '',
                'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
            );
        }
        file_put_contents($siteDir . '/site.json', json_encode((object)array(
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
                'platform' => (object)array('features' => (object)array()),
            ),
            'items' => $items,
        ), JSON_PRETTY_PRINT));
        $haxcms = new FailureTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        @mkdir($haxcms->configDirectory, 0777, true);
        $haxcms->validRequestToken = true;
        $haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $haxcms;
        $site = new FailureTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $haxcms->loadedSite = $site;
        $this->ops = new Operations();
        return $site;
    }

    /** every page renamed, C moved first, new page D after B with E under it */
    private function editRequest(): array
    {
        $sent = function ($id, $title, $parent, $order, $indent, $extra = array()) {
            return array_merge(array(
                'id' => $id,
                'title' => $title . ' edited',
                'parent' => $parent,
                'indent' => $indent,
                'order' => $order,
                'metadata' => array(),
            ), $extra);
        };
        return array(
            $sent('c', 'C', null, 0, 0),
            $sent('a', 'A', null, 1, 0),
            $sent('a1', 'A1', 'a', 0, 1),
            $sent('a2', 'A2', 'a', 1, 1, array('contents' => '<p>A2 new contents</p>')),
            $sent('b', 'B', null, 2, 0),
            array('id' => 'client-d', 'title' => 'D', 'parent' => null, 'indent' => 0, 'order' => 3,
                'metadata' => array(), 'contents' => '<p>D</p>', 'new' => true),
            array('id' => 'client-e', 'title' => 'E', 'parent' => 'client-d', 'indent' => 1, 'order' => 0,
                'metadata' => array(), 'contents' => '<p>E</p>', 'new' => true),
        );
    }

    private function runOutline(array $items)
    {
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    /** site.json on disk as "title<parent" in tree order */
    private function savedTree(): array
    {
        $items = json_decode(file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json'))->items;
        $titles = array();
        foreach ($items as $i) {
            $titles[$i->id] = $i->title;
        }
        $out = array();
        $walk = function ($parent) use (&$walk, &$out, $items, $titles) {
            $kids = array();
            foreach ($items as $position => $i) {
                $p = empty($i->parent) ? null : $i->parent;
                if ($p === $parent) {
                    $kids[] = array($i, $position);
                }
            }
            usort($kids, function ($x, $y) {
                return ($x[0]->order <=> $y[0]->order) ?: ($x[1] <=> $y[1]);
            });
            foreach ($kids as $k) {
                $out[] = $k[0]->title . '<' . ($parent === null ? 'root' : $titles[$parent]);
                $walk($k[0]->id);
            }
        };
        $walk(null);
        return $out;
    }

    private $edited = array(
        'C edited<root',
        'A edited<root',
        'A1 edited<A edited',
        'A2 edited<A edited',
        'B edited<root',
        'D<root',
        'E<D',
    );

    public function testSavedEditLandsAsSent(): void
    {
        $this->buildSite();
        $result = $this->runOutline($this->editRequest());
        $this->assertArrayNotHasKey('__failed', $result);
        $this->assertSame($this->edited, $this->savedTree());
    }

    public function testContentPhaseCrashLeavesTheNewStructureSaved(): void
    {
        $site = $this->buildSite();
        $site->throwOnAlternate = 2;
        $thrown = null;
        try {
            $this->runOutline($this->editRequest());
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the failure is reported');
        $this->assertSame($this->edited, $this->savedTree());
    }

    public function testContentPhaseRejectionLeavesTheNewStructureSaved(): void
    {
        $this->buildSite();
        $items = $this->editRequest();
        $items[4]['contents'] = 'just text, no markup';
        $result = $this->runOutline($items);
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame($this->edited, $this->savedTree());
    }

    public function testDeleteCrashLeavesTheNewStructureSaved(): void
    {
        $site = $this->buildSite();
        $site->throwOnDelete = true;
        $items = $this->editRequest();
        $items[4]['delete'] = true;
        $thrown = null;
        try {
            $this->runOutline($items);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the failure is reported');
        $this->assertSame($this->edited, $this->savedTree());
    }

    public function testStructureCrashLeavesThePagesBeforeItSaved(): void
    {
        $site = $this->buildSite();
        // E is the second new page: creating its folder fails
        $site->throwOnCopy = 2;
        $thrown = null;
        try {
            $this->runOutline($this->editRequest());
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the failure is reported');
        $tree = $this->savedTree();
        $this->assertContains('B edited<root', $tree);
        $this->assertContains('A2 edited<A edited', $tree);
        $this->assertContains('C edited<root', $tree);
        foreach ($tree as $row) {
            $this->assertStringStartsNotWith('E<', $row);
        }
    }

    public function testTiedSiblingsKeepTheOrderTheyWereSentIn(): void
    {
        $this->buildSite();
        $result = $this->runOutline(array(
            array('id' => 'a', 'title' => 'A', 'parent' => null, 'indent' => 0, 'order' => 0, 'metadata' => array()),
            array('id' => 'a2', 'title' => 'A2', 'parent' => 'a', 'indent' => 1, 'order' => 0, 'metadata' => array()),
            array('id' => 'a1', 'title' => 'A1', 'parent' => 'a', 'indent' => 1, 'order' => 0, 'metadata' => array()),
            array('id' => 'c', 'title' => 'C', 'parent' => null, 'indent' => 0, 'order' => 1, 'metadata' => array()),
            array('id' => 'b', 'title' => 'B', 'parent' => null, 'indent' => 0, 'order' => 1, 'metadata' => array()),
        ));
        $this->assertArrayNotHasKey('__failed', $result);
        $this->assertSame(array('A<root', 'A2<A', 'A1<A', 'C<root', 'B<root'), $this->savedTree());
    }
}

class FailureTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function generateMachineName($name)
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $name);
        $name = strtolower(trim($name, '-_'));
        return $name === '' ? 'default' : $name;
    }
}

/** HAXCMSSite double with failure hooks; git and feed rebuilds stubbed */
class FailureTestSite extends HAXCMSSite
{
    public $throwOnAlternate = 0;
    public $throwOnDelete = false;
    public $throwOnCopy = 0;
    private $alternateWrites = 0;
    private $copies = 0;

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
        $this->alternateWrites++;
        if ($this->throwOnAlternate && $this->alternateWrites === $this->throwOnAlternate) {
            throw new Exception('disk failure');
        }
        return true;
    }

    public function deleteNode($page)
    {
        if ($this->throwOnDelete) {
            throw new Exception('disk failure');
        }
        return parent::deleteNode($page);
    }

    public function recurseCopy($src, $dst, $skip = array())
    {
        $this->copies++;
        if ($this->throwOnCopy && $this->copies === $this->throwOnCopy) {
            throw new Exception('disk failure');
        }
        return parent::recurseCopy($src, $dst, $skip);
    }
}
