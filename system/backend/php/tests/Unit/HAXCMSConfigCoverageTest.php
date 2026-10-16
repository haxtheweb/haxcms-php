<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage for HAXCMS seams not reached by other suites: config schema +
 * app-store/site connections, wc-registry artifacts, shell modulepreload,
 * cache/static-cache/version helpers, input/HTML helpers, form dispatch,
 * MCP/deployment policy and the system OpenAPI operation-path resolver.
 *
 * Instances use newInstanceWithoutConstructor() (same pattern as
 * HAXCMSHelpersTest) so required public properties are set explicitly per
 * test and the heavy constructor never runs. $GLOBALS['HAXCMS'] is swapped
 * in because getWCRegistryJson()/getWCRegistryGraphJson() resolve staticCache
 * through it.
 */
class HAXCMSConfigCoverageTest extends TestCase
{
    private $haxcms;
    private $savedGlobalsHaxcms;
    private $tmpDir;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->config = new stdClass();
        $this->haxcms->privateKey = 'cov-private';
        $this->haxcms->salt = 'cov-salt';
        $this->haxcms->sitesDirectory = 'sites';
        $this->haxcms->basePath = '/';
        $this->haxcms->domain = 'localhost';
        $this->haxcms->protocol = 'http';
        $this->haxcms->cdn = './';
        $this->haxcms->systemRequestBase = 'system/api';
        $this->haxcms->configDirectory = null;
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->haxcms, null);
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = null;
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = null;
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedGlobalsHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_cfg_cov_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->savedGlobalsHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedGlobalsHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir($dir)
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

    private function fakeSite($name, $dir = null)
    {
        $site = new stdClass();
        $site->directory = $dir === null ? $this->tmpDir : $dir;
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = $name;
        return $site;
    }

    private function invokePrivate($method, ...$args)
    {
        $ref = new ReflectionMethod('HAXCMS', $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->haxcms, ...$args);
    }

    // ---- config schema ----

    public function testGetConfigSchemaUsesDefaultsWhenGitUnset()
    {
        $this->haxcms->config->site = new stdClass();
        $schema = $this->haxcms->getConfigSchema();
        $this->assertSame('HAXCMS Config', $schema->title);
        $user = $schema->properties->publishing->properties->user;
        $this->assertSame('', $user->value);
        $this->assertSame('paper-input', $user->component->name);
        // no git user configured -> pass is still offered
        $this->assertObjectHasProperty('pass', $schema->properties->publishing->properties);
    }

    public function testGetConfigSchemaDisablesUserEmailWhenGitUserSet()
    {
        $this->haxcms->config->site = new stdClass();
        $this->haxcms->config->site->git = (object) array(
            'user' => 'me',
            'pass' => 'sekret',
        );
        $schema = $this->haxcms->getConfigSchema();
        $props = $schema->properties->publishing->properties;
        // pass is withheld when a git user already exists...
        $this->assertObjectNotHasProperty('pass', $props);
        // ...and user/email get disabled components
        $this->assertSame('disabled', $props->user->component->attributes->disabled);
        $this->assertSame('disabled', $props->email->component->attributes->disabled);
        $this->assertStringContainsString('manually edit _config/config.json', $props->user->component->slot);
        $this->assertSame('me', $props->user->value);
    }

    public function testGetConfigSchemaIncludesApiKeys()
    {
        $this->haxcms->config->site = new stdClass();
        $this->haxcms->config->appStore = new stdClass();
        $this->haxcms->config->appStore->apiKeys = (object) array('youtube' => 'key-1');
        $schema = $this->haxcms->getConfigSchema();
        $this->assertSame('key-1', $schema->properties->apis->properties->youtube->value);
        // apiDocs supplies title + docs link for supported apps
        $this->assertSame('YouTube', $schema->properties->apis->properties->youtube->title);
    }

    // ---- app store connection ----

    public function testAppStoreConnectionDefaultPointsAtLocalSystemApi()
    {
        $this->haxcms->appStoreFile = 'system/api/generateAppStore';
        $connection = $this->haxcms->appStoreConnection('tok-1', 'my-site');
        $this->assertSame('system/api/v1/integrations/app-store', $connection->url);
        $this->assertSame('my-site', $connection->params->siteName);
        $this->assertSame('tok-1', $connection->headers->{'X-HAXCMS-Site-Token'});
        // basePath prefixes the url
        $this->haxcms->basePath = '/sub/';
        $connection = $this->haxcms->appStoreConnection();
        $this->assertSame('/sub/system/api/v1/integrations/app-store', $connection->url);
    }

    public function testAppStoreConnectionRemoteOverride()
    {
        $this->haxcms->appStoreFile = 'https://remote.example.com/appstore';
        $connection = $this->haxcms->appStoreConnection();
        $this->assertSame('https://remote.example.com/appstore', $connection->url);
        $this->assertObjectNotHasProperty('params', $connection);
    }

    // ---- site connection ----

    public function testSiteConnectionJSONWithAndWithoutSiteName()
    {
        $this->haxcms->acceptedHAXFileTypes = array('image');
        $json = $this->haxcms->siteConnectionJSON('site-tok', 'my site');
        $decoded = json_decode($json);
        $this->assertSame('http', $decoded->connection->protocol);
        $this->assertSame('localhost/', $decoded->connection->url);
        $this->assertSame('site-tok', $decoded->connection->headers->{'X-HAXCMS-Site-Token'});
        $this->assertSame(
            'sites/my%20site/x/api/v1/files',
            $decoded->connection->operations->browse->endPoint
        );
        $this->assertSame(array('image'), $decoded->connection->operations->add->acceptsGizmoTypes);
        // no site name -> generic browse endpoint
        $decoded2 = json_decode($this->haxcms->siteConnectionJSON(''));
        $this->assertSame('x/api/v1/files', $decoded2->connection->operations->browse->endPoint);
    }

    // ---- wc registry ----

    public function testGetWCRegistryJsonFromSiteDirectory()
    {
        $siteDir = $this->tmpDir . '/wcjson-site';
        mkdir($siteDir . '/covwc-a', 0777, true);
        file_put_contents(
            $siteDir . '/covwc-a/wc-registry.json',
            json_encode((object) array('@haxtheweb/x/x.js' => '@haxtheweb/x/x.js'))
        );
        $site = $this->fakeSite('covwc-a', $siteDir);
        $map = $this->haxcms->getWCRegistryJson($site);
        $this->assertObjectHasProperty('@haxtheweb/x/x.js', $map);
        // cached second call is identical
        $this->assertEquals($map, $this->haxcms->getWCRegistryJson($site));
    }

    public function testGetWCRegistryJsonFallsBackToRoot()
    {
        // no site-local file and no HAXCMS_ROOT artifact -> empty map
        $site = $this->fakeSite('covwc-fallback');
        $map = $this->haxcms->getWCRegistryJson($site);
        $this->assertEquals(new stdClass(), $map);
    }

    public function testGetWCRegistryJsonBaseOverride()
    {
        $baseDir = $this->tmpDir . '/wcbase/';
        mkdir($baseDir, 0777, true);
        file_put_contents(
            $baseDir . 'wc-registry.json',
            json_encode((object) array('@haxtheweb/b/b.js' => '@haxtheweb/b/b.js'))
        );
        $site = $this->fakeSite('covwc-base');
        $map = $this->haxcms->getWCRegistryJson($site, $baseDir);
        $this->assertObjectHasProperty('@haxtheweb/b/b.js', $map);
    }

    public function testGetWCRegistryGraphJsonFromSiteDirectory()
    {
        $siteDir = $this->tmpDir . '/wcgraph-site';
        mkdir($siteDir . '/covgraph-a', 0777, true);
        $graph = array(
            'paths' => array('@haxtheweb/a/a.js', '@haxtheweb/b/b.js'),
            'adj' => array('0' => array(1)),
            'tags' => array('x-thing' => 1),
        );
        file_put_contents(
            $siteDir . '/covgraph-a/wc-registry-graph.json',
            json_encode($graph)
        );
        $site = $this->fakeSite('covgraph-a', $siteDir);
        $this->assertSame($graph, $this->haxcms->getWCRegistryGraphJson($site));
    }

    public function testGetWCRegistryGraphJsonMissingYieldsEmptyArray()
    {
        $site = $this->fakeSite('covgraph-missing');
        $this->assertSame(array(), $this->haxcms->getWCRegistryGraphJson($site));
    }

    public function testBuildShellModulepreloadPathsWithGraph()
    {
        $paths = array(
            0 => '@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js',
            1 => '@haxtheweb/d-d-d/d-d-d.js',
            2 => '@haxtheweb/haxcms-elements/lib/core/haxcms-site-store.js',
            3 => '@haxtheweb/mobx/mobx.esm.js',
        );
        $adj = array(
            '0' => array(1, 3),
            '2' => array(1),
        );
        $siteDir = $this->tmpDir . '/shell-site';
        mkdir($siteDir . '/covshell-a', 0777, true);
        file_put_contents(
            $siteDir . '/covshell-a/wc-registry-graph.json',
            json_encode(array('paths' => $paths, 'adj' => $adj))
        );
        $site = $this->fakeSite('covshell-a', $siteDir);
        $result = $this->haxcms->buildShellModulepreloadPaths($site, './', '@haxtheweb/o/o.js');
        // 8 shell entries in fixed order (site-builder is entry 2), theme appended last,
        // then deduped depth-1 imports of the entries
        $this->assertSame('@haxtheweb/wc-autoload/wc-autoload.js', $result[0]);
        $this->assertSame($paths[0], $result[2]);
        $this->assertSame('@haxtheweb/o/o.js', $result[8]);
        $this->assertContains('@haxtheweb/d-d-d/d-d-d.js', $result);
        $this->assertContains('@haxtheweb/mobx/mobx.esm.js', $result);
        $this->assertSame(count($result), count(array_unique($result)));
    }

    public function testBuildShellModulepreloadPathsWithoutGraph()
    {
        $site = $this->fakeSite('covshell-nograph');
        $result = $this->haxcms->buildShellModulepreloadPaths($site);
        $this->assertContains('@haxtheweb/wc-autoload/wc-autoload.js', $result);
        $this->assertCount(8, $result);
        // non-@ theme paths and duplicate shell entries are ignored
        $result = $this->haxcms->buildShellModulepreloadPaths(
            $site,
            './',
            './custom/build/custom.es6.js'
        );
        $this->assertCount(8, $result);
        $result = $this->haxcms->buildShellModulepreloadPaths(
            $site,
            './',
            '@haxtheweb/wc-autoload/wc-autoload.js'
        );
        $this->assertCount(8, $result);
    }

    public function testBuildShellModulepreloadPathsRespectsCap()
    {
        $paths = array();
        $adj = array();
        for ($i = 0; $i < 20; $i++) {
            $paths[$i] = '@haxtheweb/pack' . $i . '/p.js';
            $adj['0'][] = $i;
        }
        $paths[0] = '@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js';
        $siteDir = $this->tmpDir . '/shell-cap';
        mkdir($siteDir . '/covshell-cap', 0777, true);
        file_put_contents(
            $siteDir . '/covshell-cap/wc-registry-graph.json',
            json_encode(array('paths' => $paths, 'adj' => $adj))
        );
        $site = $this->fakeSite('covshell-cap', $siteDir);
        // cap >= shell entry count: depth-1 imports fill the remaining slots
        $result = $this->haxcms->buildShellModulepreloadPaths($site, './', '', 10);
        $this->assertCount(10, $result);
        $this->assertSame('@haxtheweb/wc-autoload/wc-autoload.js', $result[0]);
        $this->assertContains('@haxtheweb/pack1/p.js', $result);
        $this->assertContains('@haxtheweb/pack2/p.js', $result);
    }

    public function testGetContentTagPath()
    {
        $siteDir = $this->tmpDir . '/tag-site';
        mkdir($siteDir . '/covtag-a', 0777, true);
        file_put_contents(
            $siteDir . '/covtag-a/wc-registry.json',
            json_encode((object) array('x-tag' => '@haxtheweb/tag/tag.js'))
        );
        $site = $this->fakeSite('covtag-a', $siteDir);
        $this->assertSame('@haxtheweb/tag/tag.js', $this->haxcms->getContentTagPath($site, './', 'x-tag'));
        $this->assertFalse($this->haxcms->getContentTagPath($site, './', 'x-missing'));
    }

    // ---- cache ----

    public function testGetAndSetCacheRoundTrip()
    {
        // null cache -> null on both operations
        $this->assertNull($this->haxcms->getCache('anything'));
        $this->assertNull($this->haxcms->setCache('anything', 'data'));
        // real cache -> round trip
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->haxcms, new Cache(array(
            'name' => 'covcache',
            'path' => $this->tmpDir . '/',
            'extension' => '.cache',
        )));
        $this->assertInstanceOf(Cache::class, $this->haxcms->setCache('cov-key', array('a' => 1), 60));
        $this->assertSame(array('a' => 1), $this->haxcms->getCache('cov-key'));
    }

    public function testStaticCacheSetGetReset()
    {
        $h = $this->haxcms;
        $val = &$h->staticCache('cov-static', 'init');
        $this->assertSame('init', $val);
        $val = 'changed';
        $again = &$h->staticCache('cov-static');
        $this->assertSame('changed', $again);
        // reset restores the default captured at first set
        $reset = &$h->staticCache('cov-static', 'init', true);
        $this->assertSame('init', $reset);
    }

    public function testCacheBusterHash()
    {
        // no cache + no .git dir -> hash derived from the version token
        $buster = $this->haxcms->cacheBusterHash('?');
        $this->assertIsString($buster);
        $this->assertStringStartsWith('?', $buster);
        $this->assertNotSame('?', $buster);
        // second call returns the cached value
        $this->assertSame($buster, $this->haxcms->cacheBusterHash('?'));
    }

    public function testGetHAXCMSVersionReadsVersionFile()
    {
        // reset the static version cache, then stage a version file
        $this->haxcms->staticCache('getHAXCMSVersion', null, true);
        file_put_contents(HAXCMS_ROOT . '/.version', '9.9.9-cov');
        $this->assertSame('9.9.9-cov', $this->haxcms->getHAXCMSVersion());
        @unlink(HAXCMS_ROOT . '/.version');
    }

    public function testGetCDNForDynamic()
    {
        $this->assertSame('./', $this->haxcms->getCDNForDynamic());
        $site = new stdClass();
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->dynamicCDN = 'https://cdn.example.com/';
        $this->assertSame('https://cdn.example.com/', $this->haxcms->getCDNForDynamic($site));
        $this->assertSame('./', $this->haxcms->getCDNForDynamic(new stdClass()));
    }

    public function testGetUniqueNameCollisions()
    {
        mkdir(HAXCMS_ROOT . '/sites', 0777, true);
        mkdir(HAXCMS_ROOT . '/sites/taken', 0777, true);
        $this->assertSame('fresh', $this->haxcms->getUniqueName('fresh'));
        $this->assertSame('taken-1', $this->haxcms->getUniqueName('taken'));
        $this->rrmdir(HAXCMS_ROOT . '/sites');
    }

    // ---- policy ----

    public function testGetDeploymentProfileVariants()
    {
        $c = $this->haxcms->config;
        $c->deploymentProfile = 'HAXIAM-MANAGED';
        $this->assertSame('haxiam-managed', $this->haxcms->getDeploymentProfile());
        $c->deploymentProfile = 'self-hosted-multi-site';
        $this->assertSame('self-hosted-multi-site', $this->haxcms->getDeploymentProfile());
        $c->deploymentProfile = 'bogus-profile';
        $this->assertSame('single-site', $this->haxcms->getDeploymentProfile());
        $c->deploymentProfile = '';
        $this->assertSame('single-site', $this->haxcms->getDeploymentProfile());
        unset($c->deploymentProfile);
        $this->assertSame('single-site', $this->haxcms->getDeploymentProfile());
    }

    public function testMcpPolicyVariants()
    {
        $c = $this->haxcms->config;
        // nothing configured
        $this->assertFalse($this->haxcms->isMCPEnabled());
        $this->assertTrue($this->haxcms->isMCPReadOnly());
        $this->assertFalse($this->haxcms->isMCPWriteEnabled());
        // enabled + read only default
        $c->mcp = (object) array('enabled' => true);
        $this->assertTrue($this->haxcms->isMCPEnabled());
        $this->assertTrue($this->haxcms->isMCPReadOnly());
        $this->assertFalse($this->haxcms->isMCPWriteEnabled());
        // write mode
        $c->mcp->readOnly = false;
        $this->assertFalse($this->haxcms->isMCPReadOnly());
        $this->assertTrue($this->haxcms->isMCPWriteEnabled());
        // disabled explicitly
        $c->mcp->enabled = false;
        $c->mcp->readOnly = false;
        $this->assertFalse($this->haxcms->isMCPEnabled());
        $this->assertTrue($this->haxcms->isMCPReadOnly());
        $this->assertFalse($this->haxcms->isMCPWriteEnabled());
    }

    // ---- events ----

    public function testDispatchEventPassesValueByReference()
    {
        $calls = array();
        $this->haxcms->addEventListener('cov-event', function (&$value) use (&$calls) {
            $calls[] = $value;
            $value = 'mutated';
        });
        $value = 'original';
        $result = $this->haxcms->dispatchEvent('cov-event', $value);
        $this->assertSame(array('original'), $calls);
        $this->assertSame('mutated', $value);
        $this->assertSame('mutated', $result);
        // no listener registered -> null
        $this->assertNull($this->haxcms->dispatchEvent('cov-other', $value));
        // registering a falsy (but non-null) callback removes the listener;
        // null is a no-op per addEventListener's guard
        $this->haxcms->addEventListener('cov-event', null);
        $value = 'original';
        $this->haxcms->dispatchEvent('cov-event', $value);
        $this->assertSame('mutated', $value);
        $this->haxcms->addEventListener('cov-event', false);
        $value = 'original';
        $this->haxcms->dispatchEvent('cov-event', $value);
        $this->assertSame('original', $value);
    }

    // ---- input helpers ----

    public function testGetInputMethodAllTypes()
    {
        $this->assertSame('textfield', $this->haxcms->getInputMethod('string'));
        $this->assertSame('number', $this->haxcms->getInputMethod('number'));
        $this->assertSame('datepicker', $this->haxcms->getInputMethod('date'));
        $this->assertSame('boolean', $this->haxcms->getInputMethod('boolean'));
        $this->assertSame('textfield', $this->haxcms->getInputMethod(null));
        $this->assertSame('textfield', $this->haxcms->getInputMethod('array'));
    }

    public function testHtmlToObjAndElementToObj()
    {
        $obj = $this->haxcms->html_to_obj('<div class="a" id="b">hello<span>x</span></div>');
        // DOMDocument wraps the fragment: html -> body -> div
        $this->assertSame('html', $obj['tag']);
        $div = $obj['children'][0]['children'][0];
        $this->assertSame('div', $div['tag']);
        $this->assertSame('a', $div['class']);
        $this->assertSame('b', $div['id']);
        $this->assertSame('hello', $div['html']);
        $this->assertSame('span', $div['children'][0]['tag']);
        $this->assertSame('x', $div['children'][0]['html']);
    }

    public function testParseAttributes()
    {
        $attrs = $this->haxcms->parse_attributes('<page-break title="Hi" hide-in-menu id="x">');
        $this->assertSame('Hi', $attrs['title']);
        $this->assertNull($attrs['hide-in-menu']);
        $this->assertSame('x', $attrs['id']);
        // bare words with no leading whitespace match nothing
        $this->assertSame(array(), $this->haxcms->parse_attributes('='));
    }

    public function testObjectToArrayRecurses()
    {
        $input = (object) array(
            'a' => (object) array('b' => array((object) array('c' => 1))),
            'd' => 'scalar',
        );
        $out = $this->invokePrivate('object_to_array', $input);
        $this->assertIsArray($out);
        $this->assertIsArray($out['a']);
        $this->assertIsArray($out['a']['b'][0]);
        $this->assertSame(1, $out['a']['b'][0]['c']);
        $this->assertSame('scalar', $out['d']);
        // scalar passthrough
        $this->assertSame(5, $this->invokePrivate('object_to_array', 5));
    }

    public function testSanitizeArrayValuesWalksTrees()
    {
        $input = array(
            'str' => 'keep',
            'bool' => true,
            'nested' => array('x' => (object) array('y' => 'z')),
        );
        $out = $this->haxcms->sanitizeArrayValues($input);
        $this->assertSame('keep', $out['str']);
        $this->assertTrue($out['bool']);
        // nested objects are walked but remain objects
        $this->assertIsObject($out['nested']['x']);
        $this->assertSame('z', $out['nested']['x']->y);
        // object input variant
        $objInput = (object) array('a' => array('b' => 2), 'c' => 3);
        $outObj = $this->haxcms->sanitizeArrayValues($objInput);
        $this->assertIsObject($outObj);
        $this->assertSame(2, $outObj->a['b']);
        $this->assertSame(3, $outObj->c);
    }

    public function testSaveConfigFileWritesJson()
    {
        $this->haxcms->configDirectory = $this->tmpDir;
        $this->haxcms->config = (object) array('a' => 1);
        $bytes = $this->invokePrivate('saveConfigFile');
        $this->assertGreaterThan(0, $bytes);
        $this->assertSame(1, json_decode(file_get_contents($this->tmpDir . '/config.json'))->a);
    }

    public function testDeepObjectLookUpWalksPaths()
    {
        $obj = (object) array(
            'manifest' => (object) array(
                'metadata' => (object) array('site' => (object) array('name' => 'deep')),
            ),
        );
        $this->assertSame('deep', $this->invokePrivate('deepObjectLookUp', $obj, 'manifest-metadata-site-name'));
        $this->assertNull($this->invokePrivate('deepObjectLookUp', $obj, 'manifest-nope-nothing'));
    }

    public function testPopulateManifestValuesPopulatesAndTransforms()
    {
        $site = new stdClass();
        $site->manifest = new stdClass();
        $site->manifest->title = 'Cov Title';
        $site->manifest->license = 'CC-BY';
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->homePageId = 'home-1';
        $site->manifest->metadata->site->domain = 'https://x.example.com';
        $site->manifest->metadata->site->settings = new stdClass();
        $site->manifest->metadata->site->settings->private = true;
        $site->manifest->metadata->site->settings->lang = 'en';
        $site->manifest->metadata->theme = new stdClass();
        $site->manifest->metadata->theme->element = 'clean-one';
        $site->manifest->metadata->theme->variables = new stdClass();
        $site->manifest->metadata->theme->variables->cssVariable = '--simple-colors-default-theme-grey-7';
        $site->manifest->metadata->theme->regions = new stdClass();
        $site->manifest->metadata->theme->regions->header = array('node-a');
        $site->manifest->metadata->author = new stdClass();
        $site->manifest->metadata->author->name = 'Author';

        $manifestKeys = json_decode('{
            "manifest": {
                "manifest-title": null,
                "manifest-license": null,
                "site": {
                    "manifest-metadata-site-homePageId": null,
                    "manifest-metadata-site-domain": null,
                    "manifest-metadata-site-settings-private": null,
                    "manifest-metadata-site-settings-lang": null
                },
                "theme": {
                    "manifest-metadata-theme-element": null,
                    "manifest-metadata-theme-variables-cssVariable": null,
                    "regions": {
                        "manifest-metadata-theme-regions-header": null
                    }
                },
                "author": {
                    "manifest-metadata-author-name": null
                }
            }
        }');
        $out = $this->invokePrivate('populateManifestValues', $site, $manifestKeys);
        $this->assertSame('Cov Title', $out->manifest->{'manifest-title'});
        $this->assertSame('CC-BY', $out->manifest->{'manifest-license'});
        $this->assertSame('home-1', $out->manifest->site->{'manifest-metadata-site-homePageId'});
        $this->assertSame('https://x.example.com', $out->manifest->site->{'manifest-metadata-site-domain'});
        $this->assertTrue($out->manifest->site->{'manifest-metadata-site-settings-private'});
        $this->assertSame('en', $out->manifest->site->{'manifest-metadata-site-settings-lang'});
        $this->assertSame('clean-one', $out->manifest->theme->{'manifest-metadata-theme-element'});
        // simple-colors prefix + level suffix are stripped
        $this->assertSame('grey', $out->manifest->theme->{'manifest-metadata-theme-variables-cssVariable'});
        // regions become an array of node wrappers
        $this->assertSame(
            array(array('node' => 'node-a')),
            $out->manifest->theme->regions->{'manifest-metadata-theme-regions-header'}
        );
        $this->assertSame('Author', $out->manifest->author->{'manifest-metadata-author-name'});
    }

    // ---- request helpers ----

    public function testRequestUriVariants()
    {
        $backup = $_SERVER;
        $_SERVER['REQUEST_URI'] = '/some/path?x=1';
        $this->assertSame('/some/path?x=1', $this->haxcms->request_uri());
        // leading slash normalized
        $_SERVER['REQUEST_URI'] = 'no-slash';
        $this->assertSame('/no-slash', $this->haxcms->request_uri());
        unset($_SERVER['REQUEST_URI']);
        // argv fallback (present under phpunit CLI)
        $_SERVER['SCRIPT_NAME'] = '/vendor/bin/phpunit';
        $this->assertStringStartsWith('/vendor/bin/phpunit?', $this->haxcms->request_uri());
        unset($_SERVER['argv']);
        $_SERVER['QUERY_STRING'] = 'q=1';
        $this->assertSame('/vendor/bin/phpunit?q=1', $this->haxcms->request_uri());
        unset($_SERVER['QUERY_STRING']);
        $this->assertSame('/vendor/bin/phpunit', $this->haxcms->request_uri());
        $_SERVER = $backup;
    }

    public function testGetUriAndGetDomain()
    {
        $backup = $_SERVER;
        unset($_SERVER['SCRIPT_URI'], $_SERVER['SERVER_NAME']);
        $this->assertNull($this->haxcms->getURI());
        $this->assertNull($this->haxcms->getDomain());
        $_SERVER['SCRIPT_URI'] = 'https://example.com/x';
        $_SERVER['SERVER_NAME'] = 'example.com';
        $this->assertSame('https://example.com/x', $this->haxcms->getURI());
        $this->assertSame('https://example.com', $this->haxcms->getDomain());
        $_SERVER = $backup;
    }

    public function testIsCliVariants()
    {
        $backup = $_SERVER;
        // SERVER_SOFTWARE set means web request -> not CLI
        $_SERVER['SERVER_SOFTWARE'] = 'PHPUnit';
        $this->assertFalse($this->haxcms->isCLI());
        unset($_SERVER['SERVER_SOFTWARE']);
        $this->assertTrue($this->haxcms->isCLI());
        $_SERVER = $backup;
    }

    // ---- system openapi operation paths ----

    public function testGetSystemOpenApiOperationPathsParsesSpec()
    {
        $paths = $this->invokePrivate('getSystemOpenApiOperationPaths');
        $this->assertIsArray($paths);
        $this->assertNotEmpty($paths);
        $this->assertSame('/system/api/v1/session/login', $paths['sessionLogin']);
    }

    public function testResolveSystemOperationPath()
    {
        // known operationId from the spec
        $this->assertSame(
            '/system/api/v1/session/login',
            $this->invokePrivate('resolveSystemOperationPath', 'sessionLogin', '/system/api/v1/', 'session/login')
        );
        // custom base path is preserved with the spec suffix
        $this->assertSame(
            '/sub/system/api/v1/session/refresh',
            $this->invokePrivate('resolveSystemOperationPath', 'sessionRefreshGet', '/sub/system/api/v1/', 'session/refresh')
        );
        // unknown operationId -> fallback route
        $this->assertSame(
            '/system/api/v1/fallback/path',
            $this->invokePrivate('resolveSystemOperationPath', 'nope-op', '/system/api/v1/', 'fallback/path')
        );
        // spec path equal to the spec base -> normalized base
        $this->assertSame(
            '/system/api/v1',
            $this->invokePrivate('resolveSystemOperationPath', 'systemApiDiscovery', '/system/api/v1/', 'x')
        );
    }

    public function testNormalizePathVariants()
    {
        $this->assertSame('/', $this->invokePrivate('normalizePath', ''));
        $this->assertSame('/a/b', $this->invokePrivate('normalizePath', '///a///b/'));
        $this->assertSame('/a/b', $this->invokePrivate('normalizePath', 'a/b'));
        $this->assertSame('/a/b', $this->invokePrivate('normalizePath', '/a/b/'));
        $this->assertSame('/', $this->invokePrivate('normalizePath', '/'));
    }

    // ---- form dispatch ----

    public function testLoadFormUnknownIdFails()
    {
        $result = $this->haxcms->loadForm('notAForm');
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Unknown form', $result['__failed']['message']);
    }

    public function testProcessFormUnknownIdFails()
    {
        $result = $this->haxcms->processForm('notAForm', array());
        $this->assertSame(400, $result['__failed']['status']);
    }

    public function testLoadFormSiteSettingsDispatchesFormAndValue()
    {
        $this->haxcms->configDirectory = $this->tmpDir;
        $this->haxcms->config->site = new stdClass();
        $this->haxcms->config->site->fields = array(new stdClass());
        $result = $this->haxcms->loadForm('siteSettings', array('site' => array('name' => 'no-such-site')));
        $this->assertArrayNotHasKey('__failed', $result);
        $this->assertEquals(array(new stdClass()), $result['fields']);
        // value is the manifest-shaped object with the hidden fields stamped
        $this->assertObjectHasProperty('haxcms_form_id', $result['value']);
        $this->assertSame('siteSettings', $result['value']->haxcms_form_id);
        $this->assertObjectHasProperty('haxcms_form_token', $result['value']);
        $this->assertSame(
            $this->haxcms->getRequestToken('siteSettings'),
            $result['value']->haxcms_form_token
        );
        // processForm runs the value path without output
        $this->haxcms->processForm('siteSettings', array(), array('site' => array('name' => 'no-such-site')));
        $this->addToAssertionCount(1);
    }

    public function testSiteSettingsFormReturnsConfiguredFields()
    {
        $this->haxcms->config->site = new stdClass();
        $this->haxcms->config->site->fields = array('field-a');
        $fields = $this->invokePrivate('siteSettingsForm', array('site' => array('name' => 'no-such-site')));
        $this->assertSame(array('field-a'), $fields);
    }

    public function testGetThemesReturnsConfigThemes()
    {
        $this->haxcms->config->themes = (object) array('clean-one' => (object) array('name' => 'Clean'));
        $themes = $this->haxcms->getThemes();
        $this->assertSame('Clean', $themes->{'clean-one'}->name);
    }
}
