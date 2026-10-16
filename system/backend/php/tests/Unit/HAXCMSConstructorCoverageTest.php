<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage for HAXCMS::__construct(): request-data sanitization, protocol
 * detection branches, site directory discovery, _config bootstrapping
 * (cache/salt/config.json boilerplate fallback, themes/publishers/siteFields
 * merge, userData guards, trusted-proxy refinalization) and the init event.
 *
 * Every test constructs real HAXCMS instances against the per-PID
 * HAXCMS_ROOT temp dir; created artifacts (_config, _sites, sites symlinks)
 * are removed again in tearDown because the whole suite shares one process.
 */
class HAXCMSConstructorCoverageTest extends TestCase
{
    private $backupServer;
    private $backupPost;
    private $backupGet;
    private $backupCookie;
    private $backupArgv;

    protected function setUp(): void
    {
        $this->backupServer = $_SERVER;
        $this->backupPost = $_POST;
        $this->backupGet = $_GET;
        $this->backupCookie = $_COOKIE;
        $this->backupArgv = isset($GLOBALS['argv']) ? $GLOBALS['argv'] : null;
        // constructor tests create these; clear any leftovers defensively
        foreach (array('_config', '_sites', '_archived', '_published', 'sites', 'archived', 'published', 'site.json', '.version') as $name) {
            $path = HAXCMS_ROOT . '/' . $name;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->backupServer;
        $_POST = $this->backupPost;
        $_GET = $this->backupGet;
        $_COOKIE = $this->backupCookie;
        if ($this->backupArgv !== null) {
            $GLOBALS['argv'] = $this->backupArgv;
        }
        foreach (array('_config', '_sites', '_archived', '_published', 'sites', 'archived', 'published', 'site.json', '.version') as $name) {
            $path = HAXCMS_ROOT . '/' . $name;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
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

    private function createConfigDirectory($configJson = null, $userDataJson = null, $salt = 'cov-salt-xyz')
    {
        $dir = HAXCMS_ROOT . '/_config';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SALT.txt', $salt);
        if ($configJson !== null) {
            file_put_contents($dir . '/config.json', $configJson);
        }
        if ($userDataJson !== null) {
            file_put_contents($dir . '/userData.json', $userDataJson);
        }
        return $dir;
    }

    private function newHaxcms()
    {
        return new HAXCMS();
    }

    private function clearProtocolSuperglobals()
    {
        foreach (array('HTTPS', 'HTTP_USESSL', 'protossl', 'HTTP_X_FORWARDED_PROTO', 'HTTP_HTTPS_ENABLED', 'HTTP_HOST', 'REMOTE_ADDR') as $key) {
            unset($_SERVER[$key]);
        }
    }

    public function testConstructorDefaultsWithoutConfigDirectory()
    {
        $this->clearProtocolSuperglobals();
        $_POST = array(
            'a' => 'b',
            'jwt' => 'should-be-dropped',
            'token' => 'session-token-value',
            'nested' => array('deep' => (object) array('k' => 'v')),
        );
        $_GET = array('g' => '1');
        $haxcms = $this->newHaxcms();
        $this->assertFalse($haxcms->developerMode);
        $this->assertFalse($haxcms->developerModeAdminOnly);
        $this->assertSame('./', $haxcms->cdn);
        $this->assertSame('http', $haxcms->protocol);
        $this->assertNull($haxcms->domain);
        $this->assertSame('/', $haxcms->basePath);
        $this->assertSame('', $haxcms->siteListing->attr);
        $this->assertSame('', $haxcms->siteListing->slot);
        $this->assertEquals(new stdClass(), $haxcms->config);
        $this->assertNull($haxcms->user->name);
        $this->assertNull($haxcms->superUser->name);
        $this->assertNull($haxcms->sitesDirectory);
        $this->assertNull($haxcms->configDirectory);
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $this->assertNull($cacheProp->getValue($haxcms));
        $this->assertSame('system/api', $haxcms->systemRequestBase);
        $this->assertCount(13, $haxcms->acceptedHAXFileTypes);
        $this->assertInstanceOf('JSONOutlineSchema', $haxcms->outlineSchema);
        // request data sanitization: jwt/token dropped, sessionToken kept
        $this->assertArrayNotHasKey('jwt', $haxcms->safePost);
        $this->assertArrayNotHasKey('token', $haxcms->safePost);
        $this->assertSame('b', $haxcms->safePost['a']);
        $this->assertIsArray($haxcms->safePost['nested']['deep']);
        $this->assertSame('v', $haxcms->safePost['nested']['deep']['k']);
        $this->assertSame('session-token-value', $haxcms->sessionToken);
        $this->assertSame('1', $haxcms->safeGet['g']);
    }

    public function testConstructorProtocolDetectionBranches()
    {
        $cases = array(
            array(array('HTTPS' => '1'), 'https'),
            array(array('HTTPS' => 'off'), 'http'),
            array(array('HTTPS' => 'no'), 'http'),
            array(array('HTTP_USESSL' => '1'), 'https'),
            array(array('protossl' => '1'), 'https'),
            array(array('HTTP_X_FORWARDED_PROTO' => 'https'), 'https'),
            // quirk preserved from the original implementation: presence of
            // HTTP_X_FORWARDED_PROTO combined with HTTP_HTTPS_ENABLED wins
            array(array('HTTP_HTTPS_ENABLED' => '1', 'HTTP_X_FORWARDED_PROTO' => 'http'), 'https'),
        );
        foreach ($cases as $case) {
            $this->clearProtocolSuperglobals();
            foreach ($case[0] as $key => $value) {
                $_SERVER[$key] = $value;
            }
            $haxcms = $this->newHaxcms();
            $this->assertSame($case[1], $haxcms->protocol, 'failed for ' . json_encode($case[0]));
        }
        // domain comes from HTTP_HOST when present
        $this->clearProtocolSuperglobals();
        $_SERVER['HTTP_HOST'] = 'example.test:8080';
        $haxcms = $this->newHaxcms();
        $this->assertSame('example.test:8080', $haxcms->domain);
    }

    public function testConstructorDiscoversUnderscoreDirectories()
    {
        mkdir(HAXCMS_ROOT . '/_sites');
        mkdir(HAXCMS_ROOT . '/_archived');
        mkdir(HAXCMS_ROOT . '/_published');
        $haxcms = $this->newHaxcms();
        $this->assertSame('_sites', $haxcms->sitesDirectory);
        $this->assertSame('_archived', $haxcms->archivedDirectory);
        $this->assertSame('_published', $haxcms->publishedDirectory);
    }

    public function testConstructorDiscoversSymlinkDirectories()
    {
        $target = sys_get_temp_dir() . '/haxcms_ctor_links_' . uniqid();
        mkdir($target . '/real-sites', 0777, true);
        mkdir($target . '/real-archived', 0777, true);
        mkdir($target . '/real-published', 0777, true);
        symlink($target . '/real-sites', HAXCMS_ROOT . '/sites');
        symlink($target . '/real-archived', HAXCMS_ROOT . '/archived');
        symlink($target . '/real-published', HAXCMS_ROOT . '/published');
        $haxcms = $this->newHaxcms();
        $this->assertSame('sites', $haxcms->sitesDirectory);
        $this->assertSame('archived', $haxcms->archivedDirectory);
        $this->assertSame('published', $haxcms->publishedDirectory);
        $this->rrmdir($target);
    }

    public function testConstructorBootstrapsConfigDirectory()
    {
        $this->clearProtocolSuperglobals();
        $this->createConfigDirectory('{}', '{"name": "Cov User", "picture": "x.png"}');
        $haxcms = $this->newHaxcms();
        $this->assertSame(HAXCMS_ROOT . '/_config', $haxcms->configDirectory);
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $this->assertInstanceOf('Cache', $cacheProp->getValue($haxcms));
        $this->assertSame('system/api/generateAppStore', $haxcms->appStoreFile);
        $this->assertSame('cov-salt-xyz', $haxcms->salt);
        // coreConfig themes merged in + theme select options placed
        $this->assertGreaterThan(0, count((array) $haxcms->config->themes));
        $themeSelect = $haxcms->config->site->fields[0]->properties[1]->properties[0]->options;
        $this->assertIsArray($themeSelect);
        $this->assertNotEmpty($themeSelect);
        // defaults seeded for a self-hosted multi-site profile
        $this->assertSame('self-hosted-multi-site', $haxcms->config->deploymentProfile);
        $this->assertTrue($haxcms->config->mcp->enabled);
        $this->assertTrue($haxcms->config->mcp->readOnly);
        $this->assertInstanceOf('stdClass', $haxcms->config->security->loginRateLimit);
        $this->assertSame('en-US', $haxcms->config->localization->defaultLanguage);
        $this->assertInstanceOf('stdClass', $haxcms->config->node->fields);
        $this->assertInstanceOf('stdClass', $haxcms->config->site->settings);
        $this->assertInstanceOf('stdClass', $haxcms->config->site->git);
        $this->assertInstanceOf('stdClass', $haxcms->config->site->static);
        $this->assertGreaterThan(0, count((array) $haxcms->config->site->publishers));
        $this->assertNotEmpty($haxcms->config->site->fields[0]->properties);
        // userData loaded from disk
        $this->assertSame('Cov User', $haxcms->userData->name);
        $this->assertSame('x.png', $haxcms->userData->picture);
    }

    public function testConstructorHaxiamProfileDisablesMcp()
    {
        $this->createConfigDirectory('{"iam": true}');
        $haxcms = $this->newHaxcms();
        $this->assertSame('haxiam-managed', $haxcms->config->deploymentProfile);
        $this->assertFalse($haxcms->config->mcp->enabled);
        $this->assertTrue($haxcms->isMCPReadOnly());
        $this->assertFalse($haxcms->isMCPWriteEnabled());
    }

    public function testConstructorGuardsArrayThemes()
    {
        // themes persisted as a JSON array must not be assigned properties
        $this->createConfigDirectory('{"themes": []}');
        $haxcms = $this->newHaxcms();
        $this->assertIsObject($haxcms->config->themes);
        $this->assertGreaterThan(0, count((array) $haxcms->config->themes));
    }

    public function testConstructorCorruptConfigJsonFallsBackToBoilerplate()
    {
        $corrupt = 'this is { not json';
        $this->createConfigDirectory($corrupt);
        $haxcms = $this->newHaxcms();
        // safeguard: corrupt file untouched on disk, valid config in memory
        $this->assertSame($corrupt, file_get_contents(HAXCMS_ROOT . '/_config/config.json'));
        $this->assertGreaterThan(0, count((array) $haxcms->config->themes));
        $this->assertSame('self-hosted-multi-site', $haxcms->config->deploymentProfile);
    }

    public function testConstructorMissingConfigJsonFallsBack()
    {
        $this->createConfigDirectory(null);
        $haxcms = $this->newHaxcms();
        $this->assertGreaterThan(0, count((array) $haxcms->config->themes));
        $this->assertIsObject($haxcms->config->site->fields[0]);
    }

    public function testConstructorUserDataCorruptFallsBackToEmpty()
    {
        $this->createConfigDirectory('{}', 'not json {{{');
        $haxcms = $this->newHaxcms();
        $this->assertEquals(new stdClass(), $haxcms->userData);
    }

    public function testConstructorUserDataMissingFallsBackToEmpty()
    {
        $this->createConfigDirectory('{}', null);
        $haxcms = $this->newHaxcms();
        $this->assertEquals(new stdClass(), $haxcms->userData);
    }

    public function testConstructorRefinalizesBehindTrustedProxy()
    {
        $this->clearProtocolSuperglobals();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'proxy.test';
        $this->createConfigDirectory('{"security": {"trustedProxies": ["127.0.0.1"]}}');
        $haxcms = $this->newHaxcms();
        // X-Forwarded-Proto is now honored and the host is kept
        $this->assertSame('https', $haxcms->protocol);
        $this->assertSame('proxy.test', $haxcms->domain);
    }
}
