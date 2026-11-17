<?php
use PHPUnit\Framework\TestCase;

/**
 * DRAFT plugin platform (haxtheweb/issues#3356): haxcms-php's native plugin engine.
 *
 * The full contract is checked by the plugin spec's own runners through test/plugins/haxcms-php-adapter.mjs (every
 * conformance case and every plugin's executable cases, the same ones haxcms-nodejs runs). These tests cover what
 * only PHP can check: the JSON Schema subset, the real-storage host (site.json, page files, store files, rollback on
 * disk), the core hook points, route matching and the SSRF-guarded single hop.
 *
 * They need the spec (haxtheweb/haxcms-plugins) on disk: HAXCMS_PLUGIN_SPEC_DIR, or
 * node_modules/@haxtheweb/haxcms-plugin-spec. Without it they are skipped.
 */
class PluginPlatformTest extends TestCase
{
    private $spec;
    private $tmp = [];

    protected function setUp(): void
    {
        $this->spec = HAXCMSPluginEngine::defaultSpecDir();
        if (!is_file($this->spec . '/schema/plugin.schema.json')) {
            $this->markTestSkipped('plugin spec not found (set HAXCMS_PLUGIN_SPEC_DIR)');
        }
        putenv('HAXCMS_PLUGIN_DIRS');
        HAXCMSPluginSystem::reset();
    }

    protected function tearDown(): void
    {
        putenv('HAXCMS_PLUGIN_DIRS');
        putenv('HAXCMS_PLUGINS');
        HAXCMSPluginSystem::reset();
        foreach ($this->tmp as $d) {
            $this->rrmdir($d);
        }
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rrmdir("$dir/$e");
            }
        }
        @rmdir($dir);
    }

    private function fx($name)
    {
        return $this->spec . '/conformance/fixtures/' . $name;
    }

    /** A real site folder with a site.json, loaded through JSONOutlineSchema. */
    private function site()
    {
        $root = HAXCMSPluginEngine::tempDir('haxcms-plugin-test-');
        $this->tmp[] = $root;
        $dir = "$root/_sites/demo";
        mkdir("$dir/pages", 0775, true);
        file_put_contents("$dir/site.json", json_encode([
            'id' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', 'title' => 'Demo', 'author' => '', 'description' => '',
            'license' => 'by-sa', 'metadata' => ['site' => ['name' => 'demo']], 'items' => [],
        ]));
        $m = new JSONOutlineSchema();
        $m->load("$dir/site.json");
        $site = new stdClass();
        $site->directory = "$root/_sites";
        $site->manifest = $m;
        return ['root' => $root, 'dir' => $dir, 'manifest' => $m, 'site' => $site];
    }

    private function host(array $s)
    {
        return new HAXCMSPluginHost(['manifest' => $s['manifest'], 'siteDirectory' => $s['dir'], 'root' => $s['root'], 'now' => 0]);
    }

    private function onDisk($dir)
    {
        return array_map(function ($i) {
            return $i['id'];
        }, json_decode(file_get_contents("$dir/site.json"), true)['items']);
    }

    public function testSchemaSubsetReportsWhatAjvReports(): void
    {
        $v = new HAXCMSPluginSchema(json_decode(file_get_contents($this->spec . '/schema/plugin.schema.json')));
        $read = function ($dir) {
            return \Symfony\Component\Yaml\Yaml::parseFile("$dir/plugin.yaml", \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP);
        };
        $this->assertSame(['/ must NOT have additional properties (surprise)'], $v->validate($read($this->fx('fx-bad-manifest'))));
        $this->assertContains("/ must have required property 'core'", $v->validate($read($this->fx('fx-contrib-required'))));
        $this->assertNotEmpty($v->validate($read($this->fx('fx-pack-code'))));
        // every shipped plugin is valid
        foreach (['core-plugins', 'contrib-plugins', 'examples'] as $tier) {
            foreach (glob($this->spec . "/$tier/*/plugin.yaml") as $f) {
                $this->assertSame([], $v->validate($read(dirname($f))), $f);
            }
        }
    }

    public function testHookMethodNamesCamelCaseAcrossDashes(): void
    {
        $this->assertSame('pageBeforeSave', HAXCMSPluginEngine::camelHook('page.before.save'));
        $this->assertSame('fxDefinerAfterPing', HAXCMSPluginEngine::camelHook('fx-definer.after.ping'));
        $this->assertSame('importFileConvert', HAXCMSPluginEngine::contractMethod('import.file', 'convert'));
        $this->assertSame('DataStorePlugin', HAXCMSPluginEngine::studly('data-store'));
    }

    public function testPageSavesGoToSiteJsonAndPageFiles(): void
    {
        $s = $this->site();
        $h = $this->host($s);
        $h->load([$this->fx('fx-vetoer')]);
        $r = $h->saveEntity('page', ['id' => 'p1', 'title' => 'Hello world', 'content' => '<p>hi</p>']);
        $this->assertArrayNotHasKey('veto', $r);
        $this->assertSame(['p1'], $this->onDisk($s['dir']));
        $this->assertSame('<p>hi</p>', file_get_contents($s['dir'] . '/pages/hello-world-p1/index.html'));
        // a veto writes nothing
        $r = $h->saveEntity('page', ['id' => 'p2', 'title' => 'forbidden', 'content' => '']);
        $this->assertSame('fx-vetoer: forbidden', $r['veto']['message']);
        $this->assertSame(['p1'], $this->onDisk($s['dir']));
        // records are what is on disk
        $h->reload();
        $this->assertSame('Hello world', $h->records['page']['p1']['title']);
        $this->assertTrue($h->deleteEntity('page', 'p1')['deleted']);
        $this->assertSame([], $this->onDisk($s['dir']));
        $this->assertFileDoesNotExist($s['dir'] . '/pages/hello-world-p1/index.html');
    }

    public function testFailedAtomicOperationPutsBackWhatItWroteOnDisk(): void
    {
        $s = $this->site();
        $h = $this->host($s);
        $h->load([$this->fx('fx-atomic')]);
        $pages = [['id' => 'p1', 'title' => 'One', 'content' => 'a'], ['id' => 'p2', 'title' => 'Two', 'content' => 'b'], ['id' => 'p3', 'title' => 'Three', 'content' => 'c']];
        $r = $h->call('fxAtomicSaveAll', ['body' => ['failAt' => 2, 'file' => 'files/x.txt', 'pages' => $pages]]);
        $this->assertSame(500, $r['status']);
        $this->assertTrue($r['body']['rolledBack']);
        $this->assertSame([], $this->onDisk($s['dir']));
        $this->assertFileDoesNotExist($s['dir'] . '/pages/one-p1/index.html');
        $this->assertFileDoesNotExist($s['dir'] . '/files/x.txt');
        $h->reload();
        $this->assertSame([], $h->records['page']);
        // the same operation without the failure keeps all three
        $r = $h->call('fxAtomicSaveAll', ['body' => ['pages' => $pages]]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(['p1', 'p2', 'p3'], $this->onDisk($s['dir']));
    }

    public function testStoresLiveWhereSpec04PutsThem(): void
    {
        $s = $this->site();
        $h = $this->host($s);
        $h->load([$this->fx('fx-scheduled')]);
        $this->assertSame(200, $h->runSchedule('fx-scheduled', 'tick')['status']);
        $p = $h->get('fx-scheduled');
        $file = $h->storeFile($p, $p->stores['ticks']);
        $this->assertFileExists($file);
        $this->assertSame('1', trim(file_get_contents($file)));
        // a site-scope store lives in the site folder (spec/04)
        $this->assertSame($s['dir'] . '/_config/data/fx-scheduled/ticks.json', $file);
    }

    public function testSettingsAreSavedUnderPluginConfig(): void
    {
        $s = $this->site();
        $h = $this->host($s);
        $h->load([$this->fx('fx-needs-token')]);
        $this->assertSame('inactive', $h->get('fx-needs-token')->status);
        $r = $h->saveConfig('fx-needs-token', ['token' => 'abc']);
        $this->assertSame('active', $r['body']['status']);
        $this->assertSame(['token' => 'abc'], json_decode(file_get_contents($s['root'] . '/_config/plugins/config/fx-needs-token.json'), true));
    }

    public function testPluginSystemFiresEntityHooksAroundCoreSaves(): void
    {
        $s = $this->site();
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->fx('fx-vetoer'));
        $pre = HAXCMSPluginSystem::before($s['site'], 'page', ['id' => 'p1', 'title' => 'forbidden'], ['id' => 'p1', 'title' => 'old'], ['root' => $s['root']]);
        $this->assertSame('fx-vetoer: forbidden', $pre['veto']['message']);
        $this->assertSame(['__failed' => ['status' => 409, 'message' => 'fx-vetoer: forbidden', 'data' => ['plugin' => 'fx-vetoer']]], HAXCMSPluginSystem::vetoFailure($pre['veto']));
        $ok = HAXCMSPluginSystem::before($s['site'], 'page', ['id' => 'p1', 'title' => 'fine'], ['id' => 'p1', 'title' => 'old'], ['root' => $s['root']]);
        $this->assertNull($ok['veto']);
        $this->assertSame('fine', $ok['record']['title']);
        putenv('HAXCMS_PLUGINS=off');
        HAXCMSPluginSystem::reset();
        $off = HAXCMSPluginSystem::before($s['site'], 'page', ['id' => 'p1', 'title' => 'forbidden'], null, ['root' => $s['root']]);
        $this->assertNull($off['veto']);
    }

    public function testDeploymentConfigBlocksAPlugin(): void
    {
        $s = $this->site();
        mkdir($s['root'] . '/_config/plugins', 0775, true);
        file_put_contents($s['root'] . '/_config/plugins/plugins.yaml', "version: 1\nblocked: [fx-vetoer]\n");
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->fx('fx-vetoer'));
        $h = HAXCMSPluginSystem::host($s['site'], ['root' => $s['root']]);
        $this->assertSame('refused', $h->get('fx-vetoer')->status);
        $this->assertSame('system-contrib', $h->get('fx-vetoer')->tier);
    }

    /** The system engine (spec/13 section 8b): no site, system stores only; anything site-bound refuses. */
    public function testSystemEngineHasNoSite(): void
    {
        $root = HAXCMSPluginEngine::tempDir('haxcms-plugin-test-');
        $this->tmp[] = $root;
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->fx('fx-scheduled'));
        $h = HAXCMSPluginSystem::systemHost(['root' => $root]);
        $this->assertNotNull($h);
        $this->assertSame($h, HAXCMSPluginSystem::systemHost(['root' => $root]), 'cached per HAXcms root');
        $this->assertNull($h->site);
        $this->assertNull($h->siteInfo());
        $this->assertSame('active', $h->get('fx-scheduled')->status);
        // fx-scheduled keeps its ticks in a site store, so the job is refused rather than run against some site
        $r = $h->runSchedule('fx-scheduled', 'tick');
        $this->assertSame(500, $r['status']);
        $this->assertStringContainsString('needs a site; this is the system engine', $r['body']['error']);
        try {
            $h->render(['id' => 'p1', 'content' => 'x']);
            $this->fail('rendering needs a site');
        } catch (RuntimeException $e) {
            $this->assertSame('rendering: needs a site; this is the system engine', $e->getMessage());
        }
        try {
            $h->saveEntity('page', ['id' => 'p1', 'title' => 'x']);
            $this->fail('page records need a site');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('page records: needs a site', $e->getMessage());
        }
    }

    /** /.well-known/<reserved name> is answered by the plugin that declares it, on the system engine (spec/05). */
    public function testWellKnownServesADeclaredReservedName(): void
    {
        $root = HAXCMSPluginEngine::tempDir('haxcms-plugin-test-');
        $this->tmp[] = $root;
        mkdir("$root/_config/plugins/config", 0775, true);
        file_put_contents("$root/_config/plugins/config/fx-well-known.json", json_encode(['issuer' => 'https://cms.example.edu']));
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->fx('fx-well-known'));
        $this->assertSame('active', HAXCMSPluginSystem::systemHost(['root' => $root])->get('fx-well-known')->status);
        // not reserved, another base path, or not a GET: never a plugin's
        $this->assertFalse(HAXCMSPluginRoutes::dispatchWellKnown('/.well-known/security.txt', '/', 'GET', ['root' => $root]));
        $this->assertFalse(HAXCMSPluginRoutes::dispatchWellKnown('/other/.well-known/oauth-authorization-server', '/', 'GET', ['root' => $root]));
        $this->assertFalse(HAXCMSPluginRoutes::dispatchWellKnown('/.well-known/oauth-authorization-server', '/', 'POST', ['root' => $root]));
        $this->assertFalse(HAXCMSPluginRoutes::dispatchWellKnown('/.well-known/openid-configuration', '/', 'GET', ['root' => $root]), 'nothing declares it');
        ob_start();
        $served = HAXCMSPluginRoutes::dispatchWellKnown('/.well-known/oauth-authorization-server', '/', 'GET', ['root' => $root]);
        $body = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($served);
        $this->assertSame('https://cms.example.edu', $body['issuer'] ?? null, json_encode($body));
    }

    public function testRoutesMatchPathTemplatesAndScope(): void
    {
        $p = new HAXCMSPluginRecord();
        $p->ops = [
            'aGet' => ['verb' => 'get', 'path' => '/items/{id}', 'scope' => 'site'],
            'aSys' => ['verb' => 'get', 'path' => '/items/{id}', 'scope' => 'system'],
            'aDot' => ['verb' => 'get', 'path' => '/feed.xml', 'scope' => 'site'],
        ];
        $this->assertSame(['operationId' => 'aGet', 'params' => ['id' => 'a b']], HAXCMSPluginRoutes::matchOperation($p, 'GET', '/items/a%20b', 'site'));
        $this->assertSame('aSys', HAXCMSPluginRoutes::matchOperation($p, 'GET', '/items/1', 'system')['operationId']);
        $this->assertNull(HAXCMSPluginRoutes::matchOperation($p, 'POST', '/items/1', 'site'));
        $this->assertNull(HAXCMSPluginRoutes::matchOperation($p, 'GET', '/feedxxml', 'site'));
        $this->assertNull(HAXCMSPluginRoutes::matchOperation($p, 'GET', '/items/1/2', 'site'));
        $this->assertSame('both', HAXCMSPluginRoutes::credentialsOf(['jwt' => true, 'userToken' => false, 'siteToken' => true]));
        $this->assertSame('jwt', HAXCMSPluginRoutes::credentialsOf(['jwt' => true, 'userToken' => false, 'siteToken' => false]));
        $this->assertSame('none', HAXCMSPluginRoutes::credentialsOf(['jwt' => false, 'userToken' => true, 'siteToken' => true]));
    }

    public function testFetchRefusesPrivateTargetsOnEveryHop(): void
    {
        $e = new HAXCMSPluginEngine(['specDir' => $this->spec]);
        $p = new HAXCMSPluginRecord();
        $p->id = 'x';
        $p->manifest = ['capabilities' => ['network:egress'], 'egress' => ['a.example', 'b.example']];
        $e->fetchFakes = ['https://a.example/go' => ['status' => 302, 'headers' => ['Location' => 'http://127.0.0.1/admin']]];
        try {
            $e->fetchFor($p, 'https://a.example/go');
            $this->fail('redirect to a private address was followed');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('private address', $ex->getMessage());
        }
        $e->fetchFakes = ['https://a.example/go' => ['status' => 302, 'headers' => ['Location' => 'https://c.example/']]];
        try {
            $e->fetchFor($p, 'https://a.example/go');
            $this->fail('redirect outside the egress list was followed');
        } catch (RuntimeException $ex) {
            $this->assertSame('fetch to c.example not allowed (redirected)', $ex->getMessage());
        }
        // the live single hop rejects private targets before connecting
        $this->expectException(SsrfGuardException::class);
        SsrfGuard::safeFetchOnce('http://127.0.0.1/');
    }

    public function testArchiveReaderMatchesTheReference(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('word/document.xml', str_repeat('<w:p>hi</w:p>', 50));
        $z->addFromString('other.txt', 'x');
        $z->close();
        $b64 = base64_encode(file_get_contents($tmp));
        unlink($tmp);
        $this->assertSame(['word/document.xml' => str_repeat('<w:p>hi</w:p>', 50)], HAXCMSPluginEngine::readZip($b64, ['match' => '^word/']));
        $this->expectExceptionMessage('zip archive has 2 entries (limit 1)');
        HAXCMSPluginEngine::readZip($b64, ['maxEntries' => 1]);
    }

    public function testOutlineFromHtmlSplitsLikeCore(): void
    {
        $items = HAXCMSPluginEngine::outlineFromHtml('<p>lead</p><h2>One</h2><p>a</p><h3>Sub</h3><p>b</p><h2>Two</h2>', ['titleValue' => 'Doc']);
        $this->assertSame([['Doc', null, 'doc'], ['One', null, 'one'], ['Sub', 'item-2', 'one/sub'], ['Two', null, 'two']], array_map(function ($i) {
            return [$i['title'], $i['parent'], $i['slug']];
        }, $items));
    }

    /** ctx->exec on a real HAXcms: the same rules haxcms-nodejs's host runs (plugin-platform.test.cjs). */
    private function execHost()
    {
        $s = $this->site();
        $h = new HAXCMSPluginHost(['manifest' => $s['manifest'], 'siteDirectory' => $s['dir'], 'root' => $s['root'], 'now' => 0, 'liveExec' => true]);
        $p = new HAXCMSPluginRecord();
        $p->id = 'fx-exec-live';
        $p->manifest = ['capabilities' => ['exec:run'], 'exec' => [PHP_BINARY]];
        return [$h, $p, $s];
    }

    public function testExecRunsListedCommandsWithFilesInAPrivateTmp(): void
    {
        [$h, $p, $s] = $this->execHost();
        putenv('HAX_SECRET_TEST=leak');
        $script = '$t=$argv[1];echo json_encode(["key"=>file_get_contents("$t/id"),"mode"=>decoct(fileperms("$t/id")&0777),"home"=>getenv("HOME")===$t,"cwd"=>getcwd(),"secret"=>getenv("HAX_SECRET_TEST")?:null,"input"=>stream_get_contents(STDIN),"tmp"=>$t]);fwrite(STDERR,"warn");';
        try {
            $r = $h->execFor($p, PHP_BINARY, ['-r', $script, '{tmp}'], ['files' => ['id' => ['content' => 'KEY']], 'input' => 'hello']);
        } finally {
            putenv('HAX_SECRET_TEST');
        }
        $this->assertSame(0, $r['code']);
        $this->assertSame('warn', $r['stderr']);
        $out = json_decode($r['stdout'], true);
        $this->assertSame(['KEY', '600', true, null, 'hello'], [$out['key'], $out['mode'], $out['home'], $out['secret'], $out['input']]);
        $this->assertSame(realpath($s['dir']), realpath($out['cwd']));
        $this->assertDirectoryDoesNotExist($out['tmp']);
        $this->assertSame(['<tmp>'], array_slice(end($h->execCalls)['args'], -1));
    }

    public function testExecReturnsExitCodesAndRefusesTimeoutsAndEscapes(): void
    {
        [$h, $p] = $this->execHost();
        $this->assertSame(3, $h->execFor($p, PHP_BINARY, ['-r', 'exit(3);'])['code']);
        foreach ([
            [['-r', 'sleep(10);'], ['timeout' => 200], 'timed out after 200 ms'],
            [['-r', '1;'], ['cwd' => '..'], 'cwd must be inside the site folder'],
            [['-r', '1;'], ['files' => ['../x' => ['content' => '']]], 'not a plain file name'],
        ] as [$args, $opts, $message]) {
            try {
                $h->execFor($p, PHP_BINARY, $args, $opts);
                $this->fail("expected: $message");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('may not run "sh"');
        $h->execFor($p, 'sh', ['-c', 'id']);
    }

    /**
     * Install a fixture the way a deployment installs a plugin, as a folder under _plugins. A link, not a copy: PHP
     * has one class table per process, so a second copy of the same plugin class could not load in this test run.
     */
    private function install($from, $to)
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0775, true);
        }
        symlink($from, $to);
    }

    /** With no plugin enabled, core's save points do no engine work and the site's pages are never read. */
    public function testNothingEnabledMeansNothingIsReadOrFired(): void
    {
        $s = $this->site();
        $this->install($this->fx('fx-vetoer'), $s['root'] . '/_plugins/fx-vetoer');
        $record = ['id' => 'p1', 'title' => 'forbidden'];
        $pre = HAXCMSPluginSystem::before($s['site'], 'page', $record, ['id' => 'p1', 'title' => 'old'], ['root' => $s['root']]);
        $this->assertNull($pre['veto'], 'an available plugin vetoed');
        $this->assertSame($record, $pre['record']);
        $this->assertSame([], HAXCMSPluginSystem::after($s['site'], 'page', $record, null, ['root' => $s['root']])['warnings']);
        $this->assertNull(HAXCMSPluginSystem::rebuild($s['site'], 'save', ['root' => $s['root']]));
        $h = HAXCMSPluginSystem::host($s['site'], ['root' => $s['root']]);
        $this->assertSame('available', $h->get('fx-vetoer')->status);
        $this->assertNull($h->get('fx-vetoer')->instance, 'the code of a plugin that is not enabled was loaded');
        $this->assertSame([], $h->records['page'] ?? [], 'pages were read with nothing listening');
    }

    /** The registry (spec/03): installed is not enabled; enable and disable write plugins.yaml per scope. */
    public function testRegistryEnableAndDisablePerScope(): void
    {
        $s = $this->site();
        $o = ['root' => $s['root'], 'user' => HAXCMSPluginSystem::userRecord('admin')];
        foreach (['fx-exec', 'fx-optional'] as $id) {
            $this->install($this->fx($id), $s['root'] . "/_plugins/$id");
        }
        $entry = function ($list, $id) {
            foreach ($list as $e) {
                if ($e['id'] === $id) {
                    return $e;
                }
            }
            return null;
        };
        $e = $entry(HAXCMSPluginSystem::registry(null, $o), 'fx-exec');
        $this->assertSame('available', $e['status']);
        $this->assertFalse($e['enabled']);
        $this->assertSame(['backend', 'process'], $e['needs']);
        $this->assertSame(['server' => 'full', 'desktop' => 'full', 'cli' => 'full', 'browser' => 'no'], $e['targets']);

        // for the system: written to <root>/_config/plugins/plugins.yaml, on in every site, a site cannot turn it off
        $on = HAXCMSPluginSystem::enable(null, 'fx-exec', $o);
        $this->assertSame(200, $on['status'], json_encode($on));
        $this->assertSame('active', $on['body']['status']);
        $this->assertSame('system', $on['body']['scope']);
        $reg = HAXCMSPluginSystem::readRegistryFile($s['root']);
        $this->assertSame(['fx-exec'], $reg['enabled']);
        $this->assertSame('admin', $reg['plugins']['fx-exec']['by']);
        $this->assertSame('active', $entry(HAXCMSPluginSystem::registry($s['site'], $o), 'fx-exec')['status']);
        $this->assertSame(409, HAXCMSPluginSystem::disable($s['site'], 'fx-exec', $o)['status']);

        // for one site: written to the site's own file and counted by the system
        $site = HAXCMSPluginSystem::enable($s['site'], 'fx-optional', $o);
        $this->assertSame(200, $site['status'], json_encode($site));
        $this->assertSame('site', $site['body']['scope']);
        $this->assertSame(['fx-optional'], HAXCMSPluginSystem::readRegistryFile($s['dir'])['enabled']);
        $system = $entry(HAXCMSPluginSystem::registry(null, $o), 'fx-optional');
        $this->assertSame('available', $system['status']);
        $this->assertSame(['demo'], $system['sites']);

        // disable removes it again; ids that are not plugin ids are not found
        $off = HAXCMSPluginSystem::disable(null, 'fx-exec', $o);
        $this->assertSame(200, $off['status'], json_encode($off));
        $this->assertFalse($off['body']['enabled']);
        $this->assertSame([], HAXCMSPluginSystem::readRegistryFile($s['root'])['enabled']);
        $this->assertSame(200, HAXCMSPluginSystem::disable($s['site'], 'fx-optional', $o)['status']);
        $this->assertSame([], HAXCMSPluginSystem::readRegistryFile($s['dir'])['enabled']);
        $this->assertSame(404, HAXCMSPluginSystem::enable(null, 'no-such-plugin', $o)['status']);
        $this->assertSame(404, HAXCMSPluginSystem::enable(null, '../etc', $o)['status']);

        // a blocked plugin cannot be enabled
        file_put_contents($s['root'] . '/_config/plugins/plugins.yaml', "version: 1\nenabled: []\nblocked: [fx-exec]\n");
        HAXCMSPluginSystem::reset();
        $this->assertSame(409, HAXCMSPluginSystem::enable(null, 'fx-exec', $o)['status']);
    }

    /** The engine knows which deployment target it is (spec/14); haxcms-php itself is always a server. */
    public function testTargetDecidesWhatAnEnabledPluginCanDo(): void
    {
        $s = $this->site();
        $browser = new HAXCMSPluginHost(['manifest' => $s['manifest'], 'siteDirectory' => $s['dir'], 'root' => $s['root'], 'now' => 0, 'target' => 'browser']);
        $browser->load([$this->fx('fx-exec'), $this->fx('fx-optional')]);
        $this->assertSame('inactive', $browser->get('fx-exec')->status);
        $this->assertSame('backend.process', $browser->get('fx-exec')->unmet[0]['key']);
        $this->assertSame('active', $browser->get('fx-optional')->status);
        $this->assertSame('backend.visitors', $browser->get('fx-optional')->limited[0]['key']);
        $server = $this->host($s);
        $server->load([$this->fx('fx-exec'), $this->fx('fx-optional')]);
        $this->assertSame('server', $server->haxcms['target']);
        $this->assertSame('active', $server->get('fx-exec')->status);
        $this->assertSame([], $server->get('fx-optional')->limited);
    }

    /** Settings (spec/13 section 7): a secret is saved outside the config file, never handed back, kept when empty. */
    public function testSecretSettingsAreWriteOnlyAndKeptOutsideConfig(): void
    {
        $s = $this->site();
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->spec . '/examples/example');
        $o = ['root' => $s['root'], 'user' => HAXCMSPluginSystem::userRecord('admin')];
        $before = HAXCMSPluginSystem::settings(null, 'example', $o);
        $this->assertSame(200, $before['status']);
        $this->assertSame(['secret' => true, 'set' => false], $before['body']['values']->apiKey);
        $saved = HAXCMSPluginSystem::saveSettings(null, 'example', ['apiKey' => 'k-12345-secret'], $o);
        $this->assertSame(200, $saved['status'], json_encode($saved));
        $this->assertSame(['secret' => true, 'set' => true], $saved['body']['values']->apiKey);
        $this->assertStringNotContainsString('k-12345-secret', json_encode($saved));
        $secrets = $s['root'] . '/_config/plugins/secrets/example.json';
        $this->assertSame('k-12345-secret', json_decode(file_get_contents($secrets), true)['apiKey']);
        $this->assertSame(0, fileperms($secrets) & 0077, 'the secrets file is readable by others');
        $this->assertStringNotContainsString('k-12345-secret', file_get_contents($s['root'] . '/_config/plugins/config/example.json'));
        // a new request reads the secret back from its own file; sending it empty keeps it
        HAXCMSPluginSystem::reset();
        $again = HAXCMSPluginSystem::saveSettings(null, 'example', ['apiKey' => ''], $o);
        $this->assertSame(['secret' => true, 'set' => true], $again['body']['values']->apiKey);
        $this->assertSame('k-12345-secret', json_decode(file_get_contents($secrets), true)['apiKey']);
        $this->assertSame(404, HAXCMSPluginSystem::settings(null, 'no-such-plugin', $o)['status']);
    }

    /** Front-end files, store updates under a lock, and anonymous limits kept where every request sees them. */
    public function testAssetsStoreUpdatesAndAnonymousLimits(): void
    {
        $s = $this->site();
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->fx('fx-tally'));
        $h = HAXCMSPluginSystem::host($s['site'], ['root' => $s['root'], 'user' => null]);
        $ok = $h->asset('fx-tally', 'fx-tally-board.js');
        $this->assertSame(200, $ok['status']);
        $this->assertSame('text/javascript', $ok['type']);
        foreach (['unlisted.js', 'edited.js', '../config/openapi.yaml', 'img/../../src/php/Plugin.php', 'sneaky.php', '.env'] as $bad) {
            $this->assertSame(404, $h->asset('fx-tally', $bad)['status'], $bad);
        }
        $this->assertSame(404, $h->asset('../fx-tally', 'fx-tally-board.js')['status']);

        // three anonymous votes from one address count, the fourth waits; the counts are in a file, so the next
        // request (a new engine) still knows
        $vote = function ($ip) use ($s) {
            HAXCMSPluginSystem::reset();
            $h = HAXCMSPluginSystem::host($s['site'], ['root' => $s['root'], 'user' => null]);
            return $h->call('fxTallyVote', [], 'none', $ip);
        };
        foreach ([1, 2, 3] as $n) {
            $this->assertSame(200, $vote('203.0.113.5')['status'], "vote $n");
        }
        $over = $vote('203.0.113.5');
        $this->assertSame(429, $over['status']);
        $this->assertGreaterThan(0, (int) $over['headers']['retry-after']);
        $this->assertSame(200, $vote('203.0.113.9')['status']);
        $dir = $s['root'] . '/_config/siteData/' . $s['manifest']->id . '/fx-tally';
        $this->assertSame(['n' => 4], json_decode(file_get_contents("$dir/tally.json"), true));
        $left = array_values(array_filter(scandir($dir), function ($f) {
            return substr($f, -4) === '.tmp';
        }));
        $this->assertSame([], $left, 'a temp file was left behind');
        $this->assertFileExists("$dir/tally.json.lock");
    }

    public function testTheContractIsWrittenWithMapsAsMaps(): void
    {
        $s = $this->site();
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->fx('fx-tally'));
        $h = HAXCMSPluginSystem::host($s['site'], ['root' => $s['root'], 'user' => null]);
        $one = $h->unifiedContract(['scope' => 'site', 'plugin' => 'fx-tally']);
        $this->assertSame('HAXcms plugin fx-tally', $one['info']['title']);
        $this->assertSame(
            ['/x/api/v1/plugins/fx-tally/burst', '/x/api/v1/plugins/fx-tally/careless', '/x/api/v1/plugins/fx-tally/vote'],
            array_keys($one['paths'])
        );
        // nothing at system scope, and nothing for a plugin that is not on
        $this->assertSame([], $h->unifiedContract(['scope' => 'system', 'plugin' => 'fx-tally'])['paths']);
        $this->assertSame([], $h->unifiedContract(['scope' => 'site', 'plugin' => 'fx-absent'])['paths']);

        // YAML read into arrays cannot tell {} from []: what is sent keeps maps as maps
        $json = json_encode(HAXCMSPluginRoutes::contractForOutput([
            'openapi' => '3.1.0',
            'paths' => ['/x/api/v1/plugins/p/a' => ['get' => ['operationId' => 'a', 'security' => [[], ['bearerAuth' => []]], 'responses' => []]]],
        ]), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"security":[{},{"bearerAuth":[]}]', $json);
        $this->assertStringContainsString('"responses":{}', $json);
        $this->assertSame('{"openapi":"3.1.0","paths":{}}', json_encode(HAXCMSPluginRoutes::contractForOutput(['openapi' => '3.1.0', 'paths' => []])));
        $sent = json_decode(json_encode(HAXCMSPluginRoutes::contractForOutput($one), JSON_UNESCAPED_SLASHES), true);
        $this->assertSame('fxTallyVote', $sent['paths']['/x/api/v1/plugins/fx-tally/vote']['post']['operationId']);
    }
}
