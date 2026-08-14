<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the Operations settings, list, and app-store
 * route cluster.
 *
 * Each route is tested through its PUBLIC seam (the route method on
 * Operations) using the shared OperationsTestHaxcms collaborator mock
 * (extended here with the extra methods this cluster needs). For every route:
 *   - one GATE test: invalid token (user_token or site_token) -> 403 with the
 *     route's documented failure message.
 *   - one HAPPY-PATH-SHAPE test: valid token -> 200 / persisted JSON file /
 *     expected return object.
 *
 * Expected values come from the contract (status codes, message strings, file
 * paths, normalized/sorted arrays) NOT by re-reading the implementation.
 */
class OperationsSettingsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $tmpRoot;
    private $siteName = 'my-site';
    private $savedServer = array();
    private $pathsToClean = array();

    protected function setUp(): void
    {
        // HAXCMS_ROOT is process-global; define once at a stable temp base.
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_settings_haxroot');
        }
        if (!is_dir(HAXCMS_ROOT)) {
            mkdir(HAXCMS_ROOT, 0777, true);
        }

        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }

        // Save/restore $_SERVER entries we mutate.
        foreach (array('SERVER_SOFTWARE', 'REQUEST_METHOD', 'HTTP_X_HAXCMS_SITE_TOKEN') as $key) {
            if (isset($_SERVER[$key])) {
                $this->savedServer[$key] = $_SERVER[$key];
            }
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        // Per-test temp root + minimal site fixture.
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_settings_' . uniqid();
        $this->buildMinimalSiteFixture();

        // Mock with extra methods this cluster needs.
        $this->haxcms = new OperationsSettingsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        // coreConfigPath must NOT overlap with configDirectory (else the
        // 'config' and 'core' skeleton scopes resolve to the same dir and
        // the last-mapped scope 'core' wins).
        $this->haxcms->coreConfigPath = $this->tmpRoot . '/_core/';

        // Install mock BEFORE loading the site (HAXCMSSite::load calls
        // $GLOBALS['HAXCMS']->cleanTitle).
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new OperationsSettingsTestSite();
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

        foreach ($this->savedServer as $key => $value) {
            $_SERVER[$key] = $value;
        }
        foreach (array('SERVER_SOFTWARE', 'REQUEST_METHOD', 'HTTP_X_HAXCMS_SITE_TOKEN') as $key) {
            if (!array_key_exists($key, $this->savedServer)) {
                unset($_SERVER[$key]);
            }
        }
        $this->savedServer = array();

        $this->rrmdir($this->tmpRoot);
        foreach ($this->pathsToClean as $path) {
            $this->rrmdir($path);
        }
        $this->pathsToClean = array();
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildMinimalSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages', 0777, true);
        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array('site' => (object)array('name' => $this->siteName)),
            'items' => array(),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJsonPath(): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/site.json';
    }

    private function settingsPath(string $file): string
    {
        return $this->haxcms->configDirectory . '/settings/' . $file;
    }

    // ===== saveAppearanceSettings =====

    public function testSaveAppearanceSettingsFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveAppearanceSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSaveAppearanceSettingsReturnsSuccessShape(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => array('theme' => array()),
        );
        $result = $this->ops->saveAppearanceSettings();
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['saved']);
        $this->assertTrue($result['data']['appearance']['theme']);
    }

    // ===== savePlatformSettings =====

    public function testSavePlatformSettingsFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->savePlatformSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSavePlatformSettingsWritesFeaturesToManifest(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('platform' => array('features' => array('addPage' => true)));
        $result = $this->ops->savePlatformSettings();
        $this->assertTrue(property_exists($result, 'metadata'));
        $this->assertTrue(property_exists($result->metadata->platform->features, 'addPage'));
        $this->assertTrue($result->metadata->platform->features->addPage);
    }

    // ===== saveEditorSettings =====

    public function testSaveEditorSettingsFailsWithMissingSiteName(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('site_token' => 'good');
        $result = $this->ops->saveEditorSettings();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('missing site name', $result['__failed']['message']);
    }

    public function testSaveEditorSettingsFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveEditorSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSaveEditorSettingsWritesAudienceToManifest(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('platform' => array('audience' => 'novice'));
        $result = $this->ops->saveEditorSettings();
        $this->assertTrue(property_exists($result, 'metadata'));
        $this->assertSame('novice', $result->metadata->platform->audience);
    }

    // ===== saveAllowedBlocks =====

    public function testSaveAllowedBlocksFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveAllowedBlocks();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSaveAllowedBlocksWritesSortedUniqueTagsToManifest(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('platform' => array('allowedBlocks' => array('p', 'a', 'p')));
        $result = $this->ops->saveAllowedBlocks();
        $this->assertTrue(property_exists($result, 'metadata'));
        // HTML primitives 'p' and 'a' pass the tag validation; unique + sort.
        $this->assertSame(array('a', 'p'), $result->metadata->platform->allowedBlocks);
    }

    // ===== saveEnabledBlocks =====

    public function testSaveEnabledBlocksFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->saveEnabledBlocks();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSaveEnabledBlocksPersistsSortedJsonFile(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'user_token' => 'good',
            'enabledBlocks' => array('my-tag', 'grid-plate', 'My-Tag'),
        );
        $result = $this->ops->saveEnabledBlocks();
        $this->assertSame(200, $result['status']);
        // Normalized (lowercase), unique, sorted.
        $expected = array('grid-plate', 'my-tag');
        $this->assertSame($expected, $result['data']['enabledBlocks']);
        $this->assertFileExists($this->settingsPath('enabledBlocks.json'));
        $persisted = json_decode(file_get_contents($this->settingsPath('enabledBlocks.json')), true);
        $this->assertSame($expected, $persisted);
    }

    // ===== saveEnabledThemes =====

    public function testSaveEnabledThemesFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->saveEnabledThemes();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSaveEnabledThemesPersistsJsonFile(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'user_token' => 'good',
            'enabledThemes' => array('my-theme'),
        );
        $result = $this->ops->saveEnabledThemes();
        $this->assertSame(200, $result['status']);
        $this->assertContains('my-theme', $result['data']['enabledThemes']);
        $this->assertFileExists($this->settingsPath('enabledThemes.json'));
        $persisted = json_decode(file_get_contents($this->settingsPath('enabledThemes.json')), true);
        $this->assertArrayHasKey('enabledThemes', $persisted);
        $this->assertArrayHasKey('my-theme', $persisted['enabledThemes']);
        $this->assertTrue($persisted['enabledThemes']['my-theme']);
    }

    // ===== saveEnabledSkeletons =====

    public function testSaveEnabledSkeletonsFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->saveEnabledSkeletons();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSaveEnabledSkeletonsPersistsJsonFile(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'user_token' => 'good',
            'enabledSkeletons' => array('my-skeleton'),
        );
        $result = $this->ops->saveEnabledSkeletons();
        $this->assertSame(200, $result['status']);
        $this->assertContains('my-skeleton', $result['data']['enabledSkeletons']);
        $this->assertFileExists($this->settingsPath('enabledSkeletons.json'));
        $persisted = json_decode(file_get_contents($this->settingsPath('enabledSkeletons.json')), true);
        $this->assertArrayHasKey('enabledSkeletons', $persisted);
        $this->assertArrayHasKey('my-skeleton', $persisted['enabledSkeletons']);
        $this->assertTrue($persisted['enabledSkeletons']['my-skeleton']);
    }

    // ===== saveSeoSettings =====

    public function testSaveSeoSettingsFailsWithMissingSiteName(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('site_token' => 'good');
        $result = $this->ops->saveSeoSettings();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('missing site name', $result['__failed']['message']);
    }

    public function testSaveSeoSettingsFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveSeoSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSaveSeoSettingsWritesDescriptionToManifest(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'seo' => array('description' => 'Test description'),
        );
        $result = $this->ops->saveSeoSettings();
        $this->assertTrue(property_exists($result, 'description'));
        $this->assertSame('Test description', $result->description);
    }

    // ===== saveApiKeys =====

    public function testSaveApiKeysFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->saveApiKeys();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSaveApiKeysFailsWithoutAdminAccess(): void
    {
        // valid token but active user is NOT the super-user.
        $this->haxcms->validRequestToken = true;
        $this->haxcms->activeUserName = 'testuser';
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->saveApiKeys();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Admin access required', $result['__failed']['message']);
    }

    public function testSaveApiKeysPersistsJsonFile(): void
    {
        $this->haxcms->validRequestToken = true;
        // Elevate to the super-user name so the admin check passes.
        $this->haxcms->activeUserName = $this->haxcms->superUser->name;
        $this->ops->params = array(
            'user_token' => 'good',
            'apiKeys' => array('youtube' => 'my-youtube-key'),
        );
        $result = $this->ops->saveApiKeys();
        $this->assertSame(200, $result['status']);
        $this->assertSame('my-youtube-key', $result['data']['youtube']);
        $this->assertFileExists($this->settingsPath('apiKeys.json'));
        $persisted = json_decode(file_get_contents($this->settingsPath('apiKeys.json')), true);
        $this->assertSame('my-youtube-key', $persisted['youtube']);
    }

    // ===== saveMediaSettings =====

    public function testSaveMediaSettingsFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->saveMediaSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSaveMediaSettingsPersistsJsonFile(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'user_token' => 'good',
            'mediaSettings' => array('jpegQuality' => 90, 'maxUploadSizeMb' => 512),
        );
        $result = $this->ops->saveMediaSettings();
        $this->assertSame(200, $result['status']);
        $this->assertSame(90, $result['data']['jpegQuality']);
        $this->assertSame(512, $result['data']['maxUploadSizeMb']);
        // MediaSettingsService writes to settings/media.json (not mediaSettings.json).
        $this->assertFileExists($this->settingsPath('media.json'));
        $persisted = json_decode(file_get_contents($this->settingsPath('media.json')), true);
        $this->assertSame(90, $persisted['jpegQuality']);
        $this->assertSame(512, $persisted['maxUploadSizeMb']);
    }

    // ===== getMediaSettings =====

    public function testGetMediaSettingsFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->getMediaSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testGetMediaSettingsReturnsEffectiveDefaultsWhenNoFile(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->getMediaSettings();
        $this->assertSame(200, $result['status']);
        // Documented effective defaults (parity with Node getEffectiveMediaSettings).
        $this->assertSame(80, $result['data']['jpegQuality']);
        $this->assertSame(1024, $result['data']['maxUploadSizeMb']);
        $this->assertSame('jpg,jpeg,png,gif,webp,svg', $result['data']['acceptedFormats']);
    }

    // ===== getSkeleton =====

    public function testGetSkeletonFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'name' => 'test-skeleton');
        $result = $this->ops->getSkeleton();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testGetSkeletonReturns404WhenNotFound(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good', 'name' => 'nonexistent');
        $result = $this->ops->getSkeleton();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('skeleton not found', $result['__failed']['message']);
    }

    public function testGetSkeletonReturnsDataWhenFound(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->writeSkeletonFile('test-skel', '{"meta":{"machineName":"test-skel","name":"Test Skel"},"site":{},"build":{}}');
        $this->ops->params = array('user_token' => 'good', 'name' => 'test-skel');
        $result = $this->ops->getSkeleton();
        $this->assertSame(200, $result['status']);
        $this->assertTrue(is_object($result['data']));
        $this->assertSame('test-skel', $result['data']->meta->machineName);
    }

    // ===== skeletonsList =====

    public function testSkeletonsListFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->skeletonsList();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSkeletonsListReturnsArrayDataShape(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->skeletonsList();
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['data']);
    }

    public function testSkeletonsListDiscoversSkeletonFromFileSystem(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->writeSkeletonFile('discover-me', '{"meta":{"machineName":"discover-me","name":"Discover Me"},"site":{},"build":{}}');
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->skeletonsList();
        $this->assertSame(200, $result['status']);
        $found = false;
        foreach ($result['data'] as $item) {
            if (isset($item['machineName']) && $item['machineName'] === 'discover-me') {
                $found = true;
                $this->assertTrue($item['enabled']);
                $this->assertSame('config', $item['scope']);
                break;
            }
        }
        $this->assertTrue($found, 'skeletonsList should include the discovered skeleton');
    }

    // ===== themesList =====

    public function testThemesListFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->themesList();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testThemesListReturnsArrayDataShape(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->themesList();
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['data']);
    }

    // ===== systemBlocksList =====

    public function testSystemBlocksListFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->systemBlocksList();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSystemBlocksListReturnsDataShapeWithDefaultAutoloader(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->systemBlocksList();
        $this->assertSame(200, $result['status']);
        // No config->appStore->autoloader -> falls back to ['grid-plate'].
        $this->assertSame(array('grid-plate'), $result['autoloader']);
        $this->assertIsArray($result['enabledBlocks']);
        $this->assertIsArray($result['apps']);
        $this->assertIsArray($result['stax']);
    }

    // ===== systemStatus (systemSystem) =====

    public function testSystemStatusFailsWithNonPostMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->systemStatus();
        $this->assertSame(405, $result['__failed']['status']);
        $this->assertSame('method not allowed', $result['__failed']['message']);
    }

    public function testSystemStatusFailsWithInvalidUserToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->systemStatus();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSystemStatusReturnsReportShape(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->systemStatus();
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['data']);
        $this->assertArrayHasKey('summary', $result['data']);
        $this->assertArrayHasKey('rows', $result['data']);
        $this->assertIsArray($result['data']['rows']);
    }

    // ===== connectionSettings =====

    public function testConnectionSettingsFailsWhenIamDenies(): void
    {
        $this->haxcms->iamAllowed = false;
        $result = $this->ops->connectionSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied', $result['__failed']['message']);
    }

    public function testConnectionSettingsReturnsNoEncodeJavaScript(): void
    {
        $this->haxcms->iamAllowed = true;
        $result = $this->ops->connectionSettings();
        $this->assertSame(200, $result['__noencode']['status']);
        $this->assertSame('application/javascript', $result['__noencode']['contentType']);
        $this->assertStringStartsWith(
            'window.MicroFrontendRegistryConfig = window.MicroFrontendRegistryConfig || {};',
            $result['__noencode']['message']
        );
        $this->assertStringContainsString('window.appSettings =', $result['__noencode']['message']);
    }

    // ===== listSites =====

    public function testListSitesFailsWithInvalidUserToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->listSites();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testListSitesReturnsDiscoveredSites(): void
    {
        $this->haxcms->validRequestToken = true;
        // Create a site under HAXCMS_ROOT/sitesDirectory.
        $sitesDir = HAXCMS_ROOT . '/' . $this->haxcms->sitesDirectory;
        $listedName = 'listed-site-' . uniqid();
        $listedDir = $sitesDir . '/' . $listedName;
        mkdir($listedDir, 0777, true);
        $this->pathsToClean[] = $listedDir;
        file_put_contents($listedDir . '/site.json', json_encode((object)array(
            'id' => 'listed-uuid',
            'title' => 'Listed Site',
            'metadata' => (object)array('site' => (object)array('name' => $listedName)),
            'items' => array(),
        )));
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->listSites();
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['data']['items']);
        $found = false;
        foreach ($result['data']['items'] as $item) {
            if (isset($item->title) && $item->title === 'Listed Site') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'listSites should include the discovered site');
    }

    // ===== siteSearch =====

    public function testSiteSearchFailsWithMissingSiteName(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('site_token' => 'good', 'search' => 'test');
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('siteName is required', $result['__failed']['message']);
    }

    public function testSiteSearchFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName), 'search' => 'test');
        $result = $this->ops->siteSearch();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Authentication required', $result['__failed']['message']);
    }

    public function testSiteSearchReturnsEmptyMatchShapeForEmptySite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'search' => 'test',
        );
        $result = $this->ops->siteSearch();
        $this->assertSame(200, $result['status']);
        $this->assertSame('search', $result['data']['operation']);
        $this->assertSame('test', $result['data']['query']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertIsArray($result['data']['matches']);
    }

    // ===== generateAppStore =====

    public function testGenerateAppStoreFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->generateAppStore();
        $this->assertSame(403, $result['__failed']['status']);
        // generateAppStore uses a nested message structure.
        $this->assertSame(403, $result['__failed']['message']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']['data']['message']);
    }

    public function testGenerateAppStoreReturnsAppsStaxAutoloaderShape(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->generateAppStore();
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['apps']);
        // The local-files connection is always appended.
        $this->assertNotEmpty($result['apps']);
        $this->assertTrue(isset($result['stax']));
        $this->assertTrue(isset($result['autoloader']));
        $this->assertIsArray($result['autoloader']);
    }

    // ===== appStoreSearch =====

    public function testAppStoreSearchFailsWithUnsupportedProvider(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('provider' => 'not-a-real-provider');
        $result = $this->ops->appStoreSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Unsupported app store provider', $result['__failed']['message']['message']);
    }

    public function testAppStoreSearchFailsWithMissingSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'provider' => 'wikipedia',
            'siteName' => $this->siteName,
        );
        $result = $this->ops->appStoreSearch();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']['message']);
    }

    public function testAppStoreSearchHappyPathNeedsNetwork(): void
    {
        $this->markTestSkipped('appStoreSearch happy path requires a live HTTP request to an upstream provider');
    }

    // ===== helpers =====

    private function writeSkeletonFile(string $machineName, string $json): void
    {
        $dir = $this->haxcms->configDirectory . '/skeletons';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $machineName . '.json', $json);
    }
}

/**
 * Extended OperationsTestHaxcms with the extra methods this route cluster
 * calls on $GLOBALS['HAXCMS'] (generateMachineName, dispatchEvent,
 * getWCRegistryJson, appJWTConnectionSettings, siteConnectionJSON,
 * validateIAMRouteAuthorization) plus properties read by the routes
 * (coreConfigPath, systemRequestBase, protocol, domain, publishedDirectory).
 */
class OperationsSettingsTestHaxcms extends OperationsTestHaxcms
{
    public $coreConfigPath = '';
    public $systemRequestBase = 'system/api';
    public $protocol = 'https';
    public $domain = 'test.example.com';
    public $publishedDirectory = '_published';
    public $iamAllowed = true;
    public $iamStatus = 403;
    public $iamMessage = 'Access denied';

    public function generateMachineName($name)
    {
        // Mirror HAXCMS::generateMachineName contract: filesystem-safe slug.
        $name = str_replace(chr(0), '', $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = str_replace(array('\\', '/'), '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        if (empty($name)) {
            $name = 'default';
        }
        return $name;
    }

    public function dispatchEvent($eventName, $context)
    {
        // no-op: tests do not hook events.
        return null;
    }

    public function getWCRegistryJson($site, $base = './')
    {
        return new stdClass();
    }

    public function appJWTConnectionSettings($basePath = '/')
    {
        return array('basePath' => $basePath, 'jwt' => 'test-jwt-token');
    }

    public function siteConnectionJSON($siteToken = '', $siteName = '')
    {
        return json_encode(array(
            'details' => array(
                'title' => 'Local files',
                'icon' => 'perm-media',
                'color' => 'light-blue',
                'author' => 'HAXCMS',
                'description' => 'HAXCMS integration for HAX',
                'tags' => array('media', 'hax'),
            ),
            'connection' => array(
                'protocol' => $this->protocol,
                'url' => $this->domain . $this->basePath,
                'headers' => array('X-HAXCMS-Site-Token' => (string) $siteToken),
                'operations' => array(
                    'browse' => array(
                        'method' => 'GET',
                        'endPoint' => $this->sitesDirectory . '/' . rawurlencode((string) $siteName) . '/x/api/v1/files',
                    ),
                ),
            ),
        ));
    }

    public function validateIAMRouteAuthorization($requireAdmin = false)
    {
        return array(
            'allowed' => $this->iamAllowed,
            'status' => $this->iamStatus,
            'message' => $this->iamMessage,
        );
    }
}

/**
 * HAXCMSSite test subclass that no-ops the git/twig collaborators while
 * keeping the real manifest load + JSONOutlineSchema::save. This lets the
 * site-scoped settings routes (saveAppearanceSettings, savePlatformSettings,
 * saveEditorSettings, saveAllowedBlocks, saveSeoSettings) mutate + persist
 * against a temp fixture without a git binary or twig template tree.
 */
class OperationsSettingsTestSite extends HAXCMSSite
{
    public $gitCommits = array();
    public $rebuiltManagedFiles = false;
    public $updatedAlternateFormats = false;

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        $this->rebuiltManagedFiles = true;
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->updatedAlternateFormats = true;
        return null;
    }
}
