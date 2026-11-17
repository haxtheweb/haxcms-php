<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/../../lib/plugins/HAXCMSPluginSystem.php';

/**
 * DRAFT plugin platform: an outline save and a new page are page events
 * (spec/06). Mirrors the "outline saves and new pages fire the page hooks"
 * tests in haxcms-nodejs test/unit/plugin-platform.test.cjs.
 *
 * The page hooks fire for each page inside one bulk transaction whose origin
 * names the operation, a veto refuses the whole request before anything is
 * written, what the hooks return is not applied, and with no plugin active
 * nothing fires and the save is the same.
 */
class PluginOutlineHooksTest extends TestCase
{
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'plugin-outline';
    private $spec;

    protected function setUp(): void
    {
        $this->spec = HAXCMSPluginEngine::defaultSpecDir();
        if (!is_file($this->spec . '/schema/plugin.schema.json')) {
            $this->markTestSkipped('plugin spec not found (set HAXCMS_PLUGIN_SPEC_DIR)');
        }
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
        $this->fixtureDirName = 'fixtures_plugin_outline_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        putenv('HAXCMS_PLUGINS');
        putenv('HAXCMS_PLUGIN_DIRS');
        HAXCMSPluginSystem::reset();
    }

    protected function tearDown(): void
    {
        putenv('HAXCMS_PLUGINS');
        putenv('HAXCMS_PLUGIN_DIRS');
        HAXCMSPluginSystem::reset();
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
        if ($this->tmpRoot) {
            $this->rrmdir($this->tmpRoot);
        }
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
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function plugins(array $names): void
    {
        $dirs = array();
        foreach ($names as $n) {
            $dirs[] = $this->spec . '/conformance/fixtures/' . $n;
        }
        putenv('HAXCMS_PLUGIN_DIRS=' . implode(PATH_SEPARATOR, $dirs));
        HAXCMSPluginSystem::reset();
    }

    /** pages A, B at the top level and A1 under A, on disk */
    private function buildSite(): PluginOutlineTestSite
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $items = array();
        foreach (array(array('a', 'A', null, 0, 0), array('a1', 'A1', 'a', 0, 1), array('b', 'B', null, 1, 0)) as $p) {
            mkdir($siteDir . '/pages/' . $p[0], 0777, true);
            file_put_contents($siteDir . '/pages/' . $p[0] . '/index.html', '<p>' . $p[1] . '</p>');
            $items[] = (object) array(
                'id' => $p[0],
                'indent' => $p[4],
                'location' => 'pages/' . $p[0] . '/index.html',
                'slug' => $p[0],
                'order' => $p[3],
                'parent' => $p[2],
                'title' => $p[1],
                'description' => '',
                'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
            );
        }
        file_put_contents($siteDir . '/site.json', json_encode((object) array(
            'id' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4c',
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
            'items' => $items,
        ), JSON_PRETTY_PRINT));
        $haxcms = new PluginOutlineTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        @mkdir($haxcms->configDirectory, 0777, true);
        $haxcms->validRequestToken = true;
        $haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $haxcms;
        $site = new PluginOutlineTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $haxcms->loadedSite = $site;
        $this->ops = new Operations();
        return $site;
    }

    private function sent(): array
    {
        return array(
            array('id' => 'a', 'title' => 'A renamed', 'parent' => null, 'indent' => 0, 'order' => 0, 'metadata' => array()),
            array('id' => 'a1', 'title' => 'A1', 'parent' => 'a', 'indent' => 1, 'order' => 0, 'metadata' => array()),
            array('id' => 'b', 'title' => 'B', 'parent' => null, 'indent' => 0, 'order' => 1, 'delete' => true, 'metadata' => array()),
            array('id' => 'client-new', 'title' => 'Added in the outline', 'parent' => null, 'indent' => 0, 'order' => 2,
                'metadata' => array(), 'new' => true),
        );
    }

    private function runOutline(array $items)
    {
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    private function createNode(array $extra)
    {
        $this->ops->params = array_merge(array('site_token' => 'good', 'site' => array('name' => $this->siteName)), $extra);
        $this->ops->rawParams = $extra;
        return $this->ops->createNode();
    }

    private function messages($site): array
    {
        $h = HAXCMSPluginSystem::host($site);
        return array_values(array_filter(array_map(function ($l) {
            return (string) ($l['message'] ?? '');
        }, $h->log), function ($m) {
            return strpos($m, 'fx-probe-a:') === 0;
        }));
    }

    private function seen(array $messages, string $text): int
    {
        return count(array_keys($messages, $text, true));
    }

    private function siteJson(): string
    {
        return file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json');
    }

    public function testOutlineChangesNamesWhatASaveWillDoToEachPage(): void
    {
        $stored = array((object) array('id' => 'a', 'title' => 'A', 'slug' => 'a'), (object) array('id' => 'b', 'title' => 'B'));
        $changes = HAXCMSPluginSystem::outlineChanges($stored, array(
            array('id' => 'a', 'title' => 'A2', 'contents' => '<p>x</p>'),
            array('id' => 'b', 'delete' => true),
            array('id' => 'gone', 'delete' => true),
            array('id' => 'n', 'title' => 'New', 'content' => '<p>y</p>'),
        ));
        $this->assertSame(array(
            array('op' => 'update', 'record' => array('id' => 'a', 'title' => 'A2', 'slug' => 'a'), 'previous' => array('id' => 'a', 'title' => 'A', 'slug' => 'a')),
            array('op' => 'delete', 'record' => array('id' => 'b', 'title' => 'B'), 'previous' => null),
            array('op' => 'create', 'record' => array('id' => 'n', 'title' => 'New'), 'previous' => null),
        ), $changes);
    }

    public function testSaveOutlineFiresThePageHooksInOneTransactionEachSide(): void
    {
        $site = $this->buildSite();
        $this->plugins(array('fx-probe-a'));
        $result = $this->runOutline($this->sent());
        $this->assertArrayNotHasKey('__failed', $result, json_encode($result));
        $saved = json_decode($this->siteJson());
        $titles = array_map(function ($i) {
            return $i->title;
        }, $saved->items);
        sort($titles);
        $this->assertSame(array('A renamed', 'A1', 'Added in the outline'), $titles);
        $log = $this->messages($site);
        // a, a1 and the new page are saves; b is a delete, which the probe does not listen for
        $this->assertSame(3, $this->seen($log, 'fx-probe-a:page.before.save'));
        $this->assertSame(3, $this->seen($log, 'fx-probe-a:page.after.save'));
        // two transactions: the before hooks, then the after hooks, each started once
        $this->assertSame(2, $this->seen($log, 'fx-probe-a:transaction.before.start'));
        $this->assertSame('fx-probe-a:transaction.before.start', $log[0]);
        $lastBefore = max(array_keys($log, 'fx-probe-a:page.before.save', true));
        $firstAfter = min(array_keys($log, 'fx-probe-a:page.after.save', true));
        $this->assertLessThan($firstAfter, $lastBefore);
    }

    public function testSaveOutlineIsTheSameWithAndWithoutAListeningPlugin(): void
    {
        $this->buildSite();
        $plain = $this->runOutline($this->sent());
        $this->rrmdir($this->tmpRoot);
        $this->buildSite();
        $this->plugins(array('fx-probe-a'));
        $hooked = $this->runOutline($this->sent());
        $shape = function ($r) {
            $out = array();
            foreach ($r['items'] as $i) {
                $out[] = array($i->title, $i->slug, $i->order, $i->indent, $i->location === '' ? '' : 'set');
            }
            return array($out, count((array) $r['idMap']));
        };
        $this->assertSame($shape($plain), $shape($hooked));
    }

    public function testSaveOutlineVetoRefusesTheWholeSaveAndWritesNothing(): void
    {
        $this->buildSite();
        $this->plugins(array('fx-probe-a', 'fx-vetoer'));
        $stored = $this->siteJson();
        $items = $this->sent();
        $items[3]['title'] = 'forbidden page';
        $result = $this->runOutline($items);
        $this->assertSame(409, $result['__failed']['status']);
        $this->assertSame('fx-vetoer: forbidden', $result['__failed']['message']);
        $this->assertSame($stored, $this->siteJson());
        $this->assertDirectoryExists($this->tmpRoot . '/' . $this->siteName . '/pages/b');
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/' . $this->siteName . '/pages/forbidden-page');
    }

    public function testPluginsOffMeansAnOutlineSaveFiresNothing(): void
    {
        $this->buildSite();
        $this->plugins(array('fx-vetoer'));
        putenv('HAXCMS_PLUGINS=off');
        HAXCMSPluginSystem::reset();
        $items = $this->sent();
        $items[3]['title'] = 'forbidden page';
        $result = $this->runOutline($items);
        $this->assertArrayNotHasKey('__failed', $result, json_encode($result));
    }

    public function testCreateNodeFiresThePageHooksAndAVetoRefusesThePage(): void
    {
        $site = $this->buildSite();
        $this->plugins(array('fx-probe-a', 'fx-vetoer'));
        $ok = $this->createNode(array('node' => array('title' => 'Made by createNode')));
        $this->assertSame(200, $ok['status'] ?? null, json_encode($ok));
        $log = $this->messages($site);
        $this->assertSame(1, $this->seen($log, 'fx-probe-a:page.before.save'));
        $this->assertSame(1, $this->seen($log, 'fx-probe-a:page.after.save'));
        $stored = $this->siteJson();
        $no = $this->createNode(array('node' => array('title' => 'forbidden')));
        $this->assertSame(409, $no['__failed']['status']);
        $this->assertSame($stored, $this->siteJson());
    }

    public function testCreateNodeWithItemsFiresOneBulkTransactionAndAVetoAddsNoPage(): void
    {
        $site = $this->buildSite();
        $this->plugins(array('fx-probe-a', 'fx-vetoer'));
        $ok = $this->createNode(array('items' => array(
            array('id' => 'n1', 'title' => 'One', 'parent' => null, 'indent' => 0, 'order' => 5),
            array('id' => 'n2', 'title' => 'Two', 'parent' => null, 'indent' => 0, 'order' => 6),
        )));
        $this->assertArrayNotHasKey('__failed', $ok, json_encode($ok));
        $log = $this->messages($site);
        $this->assertSame(2, $this->seen($log, 'fx-probe-a:page.before.save'));
        $this->assertSame(2, $this->seen($log, 'fx-probe-a:page.after.save'));
        $this->assertSame(2, $this->seen($log, 'fx-probe-a:transaction.before.start'));
        $stored = $this->siteJson();
        $no = $this->createNode(array('items' => array(
            array('id' => 'n3', 'title' => 'Fine', 'parent' => null, 'indent' => 0, 'order' => 7),
            array('id' => 'n4', 'title' => 'forbidden', 'parent' => null, 'indent' => 0, 'order' => 8),
        )));
        $this->assertSame(409, $no['__failed']['status']);
        $this->assertSame($stored, $this->siteJson());
    }
}

class PluginOutlineTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;
    /** who logout() revoked, for PluginCoreEventsTest */
    public $revoked = array();

    public function decodeRefreshToken($token)
    {
        return (object) array('user' => 'ada', 'family' => 'f1');
    }

    public function revokeRefreshSession($user, $family = null)
    {
        $this->revoked[] = $user;
    }

    public function setRefreshTokenCookie($value = '', $ttl = null)
    {
        return true;
    }

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

/** HAXCMSSite double: git and the generated-file rebuilds are stubbed */
class PluginOutlineTestSite extends HAXCMSSite
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
