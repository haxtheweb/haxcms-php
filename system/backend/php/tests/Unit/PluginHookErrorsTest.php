<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/PluginOutlineHooksTest.php';
require_once __DIR__ . '/../../lib/plugins/HAXCMSPluginSystem.php';

/**
 * DRAFT plugin platform: a plugin whose hooks throw never changes or breaks
 * the save it hooks (spec/06: "A hook error is logged and does not crash the
 * operation, except an explicit veto"; spec/13: "the chain continues with the
 * previous subject"). saveNode, createNode, deleteNode and a file operation
 * answer as they would with no plugin, and every throw is in the engine log,
 * so the hooks really ran. Mirrors haxcms-nodejs
 * test/unit/plugin-hook-errors.test.cjs. A veto is the one way a hook stops a
 * write; those cases are in PluginOutlineHooksTest and PluginCoreEventsTest.
 */
class PluginHookErrorsTest extends TestCase
{
    private const HOOKS = array(
        'page.before.save', 'page.after.save', 'page.before.delete', 'page.after.delete',
        'file.before.save', 'file.after.save', 'file.before.delete', 'file.after.delete',
        'manifest.before.save', 'manifest.after.save', 'git.before.commit', 'git.after.commit',
    );

    // one copy of the fixture for the whole class: PHP declares a plugin's
    // class once per process, so every test must load it from the same file
    private static $pluginDir;

    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'plugin-hook-errors';

    protected function setUp(): void
    {
        $spec = HAXCMSPluginEngine::defaultSpecDir();
        if (!is_file($spec . '/schema/plugin.schema.json')) {
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
        $this->fixtureDirName = 'fixtures_plugin_hook_errors_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        mkdir($this->tmpRoot, 0777, true);
        putenv('HAXCMS_PLUGINS');
        if (!self::$pluginDir) {
            self::$pluginDir = self::writeThrower(sys_get_temp_dir() . '/haxcms_fx_thrower_' . getmypid());
        }
        putenv('HAXCMS_PLUGIN_DIRS=' . self::$pluginDir);
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
        if ($this->tmpRoot && is_dir($this->tmpRoot)) {
            $this->rrmdir($this->tmpRoot);
        }
    }

    private function rrmdir(string $dir): void
    {
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

    public static function tearDownAfterClass(): void
    {
        if (self::$pluginDir && is_dir(self::$pluginDir)) {
            @unlink(self::$pluginDir . '/plugin.yaml');
            @unlink(self::$pluginDir . '/src/php/Plugin.php');
            @rmdir(self::$pluginDir . '/src/php');
            @rmdir(self::$pluginDir . '/src');
            @rmdir(self::$pluginDir);
            @rmdir(dirname(self::$pluginDir));
        }
        self::$pluginDir = null;
    }

    /** fx-thrower: throws from every hook in HOOKS */
    private static function writeThrower(string $root): string
    {
        $dir = $root . '/fx-thrower';
        if (!is_dir($dir . '/src/php')) {
            mkdir($dir . '/src/php', 0777, true);
        }
        $yaml = "specVersion: \"0.1.0\"\nid: fx-thrower\nuuid: 0d6b9e2a-5c41-4f8e-b3a7-1e9c2d4f6a85\n"
            . "title: fx-thrower (test fixture)\ndescription: Throws from every hook it subscribes to.\n"
            . "version: 0.1.0\nruntimes: [php]\nentry:\n  php: src/php/Plugin.php\nhooks:\n";
        $php = "<?php\nclass FxThrowerPlugin {\n";
        foreach (self::HOOKS as $hook) {
            $yaml .= '  - name: ' . $hook . "\n";
            $parts = explode('.', $hook);
            $method = $parts[0] . ucfirst($parts[1]) . ucfirst($parts[2]);
            $php .= "  public function $method(\$ctx) { throw new RuntimeException('boom $hook'); }\n";
        }
        $php .= "}\n";
        file_put_contents($dir . '/plugin.yaml', $yaml);
        file_put_contents($dir . '/src/php/Plugin.php', $php);
        return $dir;
    }

    /** pages A and B at the top level, and a file, on disk */
    private function buildSite(): PluginOutlineTestSite
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $items = array();
        foreach (array(array('a', 'A', 0), array('b', 'B', 1)) as $p) {
            mkdir($siteDir . '/pages/' . $p[0], 0777, true);
            file_put_contents($siteDir . '/pages/' . $p[0] . '/index.html', '<p>' . $p[1] . '</p>');
            $items[] = (object) array(
                'id' => $p[0], 'indent' => 0, 'location' => 'pages/' . $p[0] . '/index.html', 'slug' => $p[0],
                'order' => $p[2], 'parent' => null, 'title' => $p[1], 'description' => '',
                'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
            );
        }
        mkdir($siteDir . '/files', 0777, true);
        file_put_contents($siteDir . '/files/kept.txt', 'data');
        file_put_contents($siteDir . '/site.json', json_encode((object) array(
            'id' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4e',
            'title' => 'Hook errors',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array('name' => $this->siteName, 'settings' => (object) array('pathauto' => false)),
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
        $rec = HAXCMSPluginSystem::host($site)->get('fx-thrower');
        $this->assertSame('active', $rec->status ?? null, 'fx-thrower is on: ' . ($rec->reason ?? ''));
        return $site;
    }

    /** the hooks whose throw was logged */
    private function thrown($site): array
    {
        $out = array();
        foreach (HAXCMSPluginSystem::host($site)->log as $l) {
            $message = (string) ($l['message'] ?? '');
            if (($l['level'] ?? '') === 'error' && ($l['plugin'] ?? '') === 'fx-thrower' && strpos($message, ': boom ') !== false) {
                $out[] = substr($message, 0, strpos($message, ':'));
            }
        }
        return $out;
    }

    private function params(array $extra): array
    {
        return array_merge(array('site_token' => 'good', 'site' => array('name' => $this->siteName)), $extra);
    }

    private function persisted(): array
    {
        return json_decode(file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json'))->items;
    }

    public function testSaveNodeWritesThePageAndTheThrowsAreLogged(): void
    {
        $site = $this->buildSite();
        $this->ops->params = $this->params(array('node' => array(
            'id' => 'a',
            'body' => '<page-break title="Still saved" item-id="a" published></page-break><p>written anyway</p>',
        )));
        $result = $this->ops->saveNode();
        $this->assertArrayNotHasKey('__failed', (array) $result, json_encode($result));
        // (the test double's pageBreakParser keeps no attributes, so the title
        // is not checked here; haxcms-nodejs's twin checks it)
        $this->assertStringContainsString('written anyway', file_get_contents($this->tmpRoot . '/' . $this->siteName . '/pages/a/index.html'));
        $thrown = $this->thrown($site);
        $this->assertContains('page.before.save', $thrown, json_encode($thrown));
        $this->assertContains('page.after.save', $thrown, json_encode($thrown));
    }

    public function testCreateNodeAddsThePageAndDeleteNodeRemovesItAndTheThrowsAreLogged(): void
    {
        $site = $this->buildSite();
        $count = count($this->persisted());
        $this->ops->params = $this->params(array('node' => array('title' => 'Made anyway')));
        $this->ops->rawParams = array('node' => array('title' => 'Made anyway'));
        $made = $this->ops->createNode();
        $this->assertArrayNotHasKey('__failed', (array) $made, json_encode($made));
        $created = null;
        foreach ($this->persisted() as $item) {
            if ($item->title === 'Made anyway') {
                $created = $item;
            }
        }
        $this->assertNotNull($created, 'the page is in site.json');
        $this->assertCount($count + 1, $this->persisted());

        $this->ops = new Operations();
        $this->ops->params = $this->params(array('node' => array('id' => $created->id)));
        $gone = $this->ops->deleteNode();
        $this->assertSame(200, $gone['status'] ?? null, json_encode($gone));
        $this->assertCount($count, $this->persisted());
        $thrown = $this->thrown($site);
        foreach (array('page.before.save', 'page.after.save', 'page.before.delete', 'page.after.delete') as $hook) {
            $this->assertContains($hook, $thrown, $hook . ' ' . json_encode($thrown));
        }
    }

    public function testAFileRenameAndDeleteHappenAndTheThrowsAreLogged(): void
    {
        $site = $this->buildSite();
        $dir = $this->tmpRoot . '/' . $this->siteName . '/files';
        $this->ops->params = $this->params(array('operation' => 'rename', 'path' => 'files/kept.txt', 'newName' => 'renamed'));
        $renamed = $this->ops->fileOperation();
        $this->assertArrayNotHasKey('__failed', $renamed, json_encode($renamed));
        $this->assertFileExists($dir . '/renamed.txt');
        $this->assertFileDoesNotExist($dir . '/kept.txt');
        $this->ops->params = $this->params(array('operation' => 'delete', 'path' => 'files/renamed.txt'));
        $deleted = $this->ops->fileOperation();
        $this->assertArrayNotHasKey('__failed', $deleted, json_encode($deleted));
        $this->assertFileDoesNotExist($dir . '/renamed.txt');
        $thrown = $this->thrown($site);
        foreach (array('file.before.save', 'file.after.save', 'file.before.delete', 'file.after.delete') as $hook) {
            $this->assertContains($hook, $thrown, $hook . ' ' . json_encode($thrown));
        }
    }
}
