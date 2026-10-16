<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage for HAXCMS site operations: createSite via loadSite($create=true),
 * symlink re-wiring on existing sites, loadSiteFromConfig, userCanAccessSite
 * and the appJWTConnectionSettings payload (referer site extraction,
 * single-site site.json fallback, dashboard requests, base path stripping,
 * HAXIAM endpoint injection, config overrides).
 *
 * Works against a real site created from the boilerplate inside the per-PID
 * HAXCMS_ROOT temp dir (removed again in tearDown since the whole suite
 * shares one process). $GLOBALS['HAXCMS'] is required by HAXCMSSite.
 */
class HAXCMSSiteOpsCoverageTest extends TestCase
{
    private $haxcms;
    private $savedGlobalsHaxcms;
    private $backupServer;
    private $siteName = 'cov-site-a';

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->sitesDirectory = 'sites';
        $this->haxcms->basePath = '/';
        $this->haxcms->domain = 'localhost';
        $this->haxcms->protocol = 'http';
        $this->haxcms->systemRequestBase = 'system/api';
        $this->haxcms->privateKey = 'cov-private';
        $this->haxcms->salt = 'cov-salt';
        $this->haxcms->configDirectory = null;
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->haxcms, null);
        $this->haxcms->sessionJwt = null;
        $this->haxcms->config = new stdClass();
        $this->haxcms->config->site = new stdClass();
        $this->haxcms->config->site->git = new stdClass();
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = 'admin';
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = null;
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedGlobalsHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->backupServer = $_SERVER;
        mkdir(HAXCMS_ROOT . '/sites', 0777, true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->backupServer;
        if (isset($this->savedGlobalsHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedGlobalsHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir(HAXCMS_ROOT . '/sites');
        @unlink(HAXCMS_ROOT . '/site.json');
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

    private function ensureSiteCreated()
    {
        $site = $this->haxcms->loadSite($this->siteName, true, 'https://d.example.com');
        $this->assertInstanceOf('HAXCMSSite', $site);
        return $site;
    }

    public function testLoadSiteWithCreateBuildsRealSite()
    {
        $site = $this->ensureSiteCreated();
        $siteDir = HAXCMS_ROOT . '/sites/' . $this->siteName;
        $this->assertTrue(is_dir($siteDir));
        $this->assertSame($this->siteName, $site->manifest->metadata->site->name);
        $this->assertSame('https://d.example.com', $site->manifest->metadata->site->domain);
        $this->assertFileExists($siteDir . '/site.json');
        $this->assertFileExists($siteDir . '/CNAME');
        $this->assertSame('https://d.example.com', file_get_contents($siteDir . '/CNAME'));
        // initial page was created through addPage
        $this->assertNotEmpty($site->manifest->items);
    }

    public function testLoadSiteExistingRewiresSymlinks()
    {
        $this->ensureSiteCreated();
        $siteDir = HAXCMS_ROOT . '/sites/' . $this->siteName;
        // drop the build symlink so loadSite() has to re-create the bucket
        @unlink($siteDir . '/build');
        $site = $this->haxcms->loadSite($this->siteName);
        $this->assertInstanceOf('HAXCMSSite', $site);
        $this->assertTrue(is_link($siteDir . '/build'));
        $this->assertTrue(is_link($siteDir . '/dist'));
        $this->assertTrue(is_link($siteDir . '/node_modules'));
        $this->assertTrue(is_link($siteDir . '/wc-registry.json'));
        $this->assertSame($this->siteName, $site->manifest->metadata->site->name);
    }

    public function testLoadSiteMissingReturnsFalse()
    {
        $this->assertFalse($this->haxcms->loadSite('no-such-site-xyz'));
    }

    public function testLoadSiteFromConfigLoadsManifest()
    {
        $this->ensureSiteCreated();
        $site = $this->haxcms->loadSiteFromConfig(HAXCMS_ROOT . '/sites/' . $this->siteName);
        $this->assertInstanceOf('HAXCMSSite', $site);
        $this->assertSame($this->siteName, $site->manifest->metadata->site->name);
        // HAXSITE_BASE_URL env var overrides the derived base
        putenv('HAXSITE_BASE_URL=https://vanity.example.com/');
        try {
            $site = $this->haxcms->loadSiteFromConfig(HAXCMS_ROOT . '/sites/' . $this->siteName);
            $this->assertSame('https://vanity.example.com/', $site->basePath);
        } finally {
            putenv('HAXSITE_BASE_URL');
        }
    }

    public function testUserCanAccessSiteVariants()
    {
        $this->ensureSiteCreated();
        // non-string / empty -> false
        $this->assertFalse($this->haxcms->userCanAccessSite(123));
        $this->assertFalse($this->haxcms->userCanAccessSite('   '));
        // unknown site -> false
        $this->assertFalse($this->haxcms->userCanAccessSite('missing-site'));
        // existing site, IAM disabled -> allowed
        $this->assertTrue($this->haxcms->userCanAccessSite($this->siteName));
        // IAM enabled: HAXCMS_ROOT is not user-scoped and no /var/www
        // convention exists -> denied for a non-matching candidate path
        $this->haxcms->config->iam = true;
        $this->assertFalse($this->haxcms->userCanAccessSite($this->siteName));
    }

    public function testAppJWTConnectionSettingsFromReferer()
    {
        $_SERVER['HTTP_REFERER'] = 'http://localhost/sites/' . $this->siteName . '/index.html';
        $this->haxcms->config->deploymentProfile = 'self-hosted-multi-site';
        $settings = $this->haxcms->appJWTConnectionSettings('/');
        $this->assertSame($this->siteName, $settings->appStore->params->siteName);
        $this->assertSame('/sites/' . $this->siteName . '/x/api', $settings->siteApiBasePath);
        $this->assertSame('/system/api/v1', $settings->systemApiBasePath);
        $this->assertSame('/system/api/v1/session/login', $settings->login);
        $this->assertSame('X-HAXCMS-User-Token', $settings->userTokenHeader);
        // tokens are derived from the configured keys
        $this->assertSame(
            $this->haxcms->getRequestToken('admin:' . $this->siteName),
            $settings->siteToken
        );
        $this->assertSame(
            $this->haxcms->getRequestToken('admin'),
            $settings->userToken
        );
        // no IAM -> no haxiam endpoint
        $this->assertObjectNotHasProperty('haxiamAddUserAccess', $settings);
    }

    public function testAppJWTConnectionSettingsSingleSiteManifestFallback()
    {
        // no referer: single-site mode resolves the name from HAXCMS_ROOT/site.json
        file_put_contents(
            HAXCMS_ROOT . '/site.json',
            json_encode((object) array(
                'metadata' => array('site' => array('name' => $this->siteName)),
            ))
        );
        $settings = $this->haxcms->appJWTConnectionSettings('/');
        $this->assertSame($this->siteName, $settings->appStore->params->siteName);
        // variant: flat name only
        file_put_contents(
            HAXCMS_ROOT . '/site.json',
            json_encode((object) array('name' => $this->siteName))
        );
        $settings = $this->haxcms->appJWTConnectionSettings('/');
        $this->assertSame($this->siteName, $settings->appStore->params->siteName);
    }

    public function testAppJWTConnectionSettingsDashboardRequest()
    {
        // a dashboard request has no site segment and no system api segment
        $_SERVER['REQUEST_URI'] = '/dashboard';
        $this->haxcms->config->deploymentProfile = 'self-hosted-multi-site';
        $settings = $this->haxcms->appJWTConnectionSettings('/');
        $this->assertSame('', $settings->appStore->params->siteName);
        $this->assertSame('/x/api', $settings->siteApiBasePath);
    }

    public function testAppJWTConnectionSettingsSubPathWithIamAndOverrides()
    {
        $this->haxcms->basePath = '/sub/sites/foo/';
        $this->haxcms->config->iam = true;
        $this->haxcms->config->deploymentProfile = 'self-hosted-multi-site';
        $this->haxcms->config->appJWTConnectionSettings = (object) array(
            'token' => 'override-token',
        );
        $settings = $this->haxcms->appJWTConnectionSettings();
        // site segments are stripped from the system API base path
        $this->assertSame('/sub/system/api/v1', $settings->systemApiBasePath);
        $this->assertSame('/sub/system/api/v1/session/login', $settings->login);
        // HAXIAM mode injects its endpoint
        $this->assertSame('/sub/system/api/v1/haxiamAddUserAccess', $settings->haxiamAddUserAccess);
        // config overrides win
        $this->assertSame('override-token', $settings->token);
    }

    public function testGetUniqueNameAvoidsCollisions()
    {
        $this->ensureSiteCreated();
        $this->assertSame('fresh-name', $this->haxcms->getUniqueName('fresh-name'));
        $this->assertSame($this->siteName . '-1', $this->haxcms->getUniqueName($this->siteName));
    }
}
