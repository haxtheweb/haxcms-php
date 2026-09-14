<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Phase 1 tests for the two /v1/entities endpoints (issue #3043).
 *
 * Both /x/api/v1/entities (site) and /system/api/v1/entities (system) now
 * source from the single merged entities.yaml registry via
 * EntityRegistry::getDefinitions(), so they return one shape. This verifies
 * the merged set matches entities.yaml, ?scope=site|system filters correctly,
 * and the descriptor shape matches the extended EntityDescriptor.
 */
class EntitiesEndpointTest extends TestCase
{
    private $savedHaxcms;
    private $savedGet = array();
    private $savedServer = array();

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->savedGet = isset($_GET) && is_array($_GET) ? $_GET : array();
        unset($_GET);
        $_GET = array();
        foreach (array('REQUEST_METHOD', 'SERVER_SOFTWARE') as $key) {
            if (isset($_SERVER[$key])) {
                $this->savedServer[$key] = $_SERVER[$key];
            }
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_GET = $this->savedGet;
        $this->savedGet = array();
        foreach ($this->savedServer as $key => $value) {
            $_SERVER[$key] = $value;
        }
        foreach (array('REQUEST_METHOD', 'SERVER_SOFTWARE') as $key) {
            if (!array_key_exists($key, $this->savedServer)) {
                unset($_SERVER[$key]);
            }
        }
        $this->savedServer = array();
    }

    // ------------------------------------------------------------------
    // /x/api/v1/entities (site)
    // ------------------------------------------------------------------

    public function testSiteEntitiesReturnsMergedSetMatchingYaml(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $data = $result['data']['data'];
        $types = array_column($data['entities'], 'type');
        $this->assertSame(6, $data['count']);
        $this->assertSame(6, count($data['entities']));
        foreach (array('file', 'item', 'theme', 'skeleton', 'site', 'system') as $expected) {
            $this->assertContains($expected, $types);
        }
    }

    public function testSiteEntitiesScopeSiteFiltersToFileAndItem(): void
    {
        $_GET['scope'] = 'site';
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $data = $result['data']['data'];
        $types = array_column($data['entities'], 'type');
        sort($types);
        $this->assertSame(array('file', 'item'), $types);
        $this->assertSame(2, $data['count']);
    }

    public function testSiteEntitiesScopeSystemFiltersToThemeSkeletonSiteSystem(): void
    {
        $_GET['scope'] = 'system';
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $data = $result['data']['data'];
        $types = array_column($data['entities'], 'type');
        sort($types);
        $this->assertSame(array('site', 'skeleton', 'system', 'theme'), $types);
        $this->assertSame(4, $data['count']);
    }

    public function testSiteEntitiesDescriptorShapeMatchesExtendedEntityDescriptor(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $entities = $result['data']['data']['entities'];
        // Find the file descriptor and verify the extended shape.
        $fileEntity = null;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'file') {
                $fileEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($fileEntity);
        $this->assertSame('file', $fileEntity['name']);
        $this->assertSame('site', $fileEntity['scope']);
        $this->assertSame('uuid', $fileEntity['primaryKey']);
        $this->assertSame(array('uuid'), $fileEntity['uniqueKeys']);
        $this->assertSame(array('uuid', 'path', 'name', 'mimetype'), $fileEntity['requiredFields']);
        $this->assertSame('datastore', $fileEntity['storage']['type']);
        $this->assertTrue($fileEntity['storage']['enabled']);
        $this->assertSame('uuid', $fileEntity['storage']['indexKey']);
        $this->assertSame('HAXCMS-FILE-SCHEMA-V1', $fileEntity['storage']['storeSchema']);
        $this->assertContains('save', $fileEntity['supportedOperations']);
        $this->assertContains('/x/api/v1/files', $fileEntity['endpoints']);
        $this->assertTrue($fileEntity['enabled']);
    }

    public function testSiteEntitiesLinksIncludeSelfAndSchemas(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $links = $result['data']['data']['links'];
        $this->assertSame('/x/api/v1/entities', $links['self']);
        $this->assertSame('/x/api/v1/schemas', $links['schemas']);
    }

    // ------------------------------------------------------------------
    // /system/api/v1/entities (system)
    // ------------------------------------------------------------------

    public function testSystemEntitiesReturnsMergedSetMatchingYaml(): void
    {
        $GLOBALS['HAXCMS'] = new EntitiesEndpointTestHaxcms();
        $context = $this->makeSystemEntitiesContext();
        $result = $this->invokeSystemSettingsHandler($context);
        $data = $result['data']['data'];
        $types = array_column($data['entities'], 'type');
        $this->assertSame(6, $data['count']);
        $this->assertSame(6, count($data['entities']));
        foreach (array('file', 'item', 'theme', 'skeleton', 'site', 'system') as $expected) {
            $this->assertContains($expected, $types);
        }
    }

    public function testSystemEntitiesScopeSiteFiltersToFileAndItem(): void
    {
        $_GET['scope'] = 'site';
        $GLOBALS['HAXCMS'] = new EntitiesEndpointTestHaxcms();
        $context = $this->makeSystemEntitiesContext();
        $result = $this->invokeSystemSettingsHandler($context);
        $data = $result['data']['data'];
        $types = array_column($data['entities'], 'type');
        sort($types);
        $this->assertSame(array('file', 'item'), $types);
        $this->assertSame(2, $data['count']);
    }

    public function testSystemEntitiesScopeSystemFiltersToThemeSkeletonSiteSystem(): void
    {
        $_GET['scope'] = 'system';
        $GLOBALS['HAXCMS'] = new EntitiesEndpointTestHaxcms();
        $context = $this->makeSystemEntitiesContext();
        $result = $this->invokeSystemSettingsHandler($context);
        $data = $result['data']['data'];
        $types = array_column($data['entities'], 'type');
        sort($types);
        $this->assertSame(array('site', 'skeleton', 'system', 'theme'), $types);
        $this->assertSame(4, $data['count']);
    }

    public function testSystemEntitiesDescriptorShapeMatchesExtendedEntityDescriptor(): void
    {
        $GLOBALS['HAXCMS'] = new EntitiesEndpointTestHaxcms();
        $context = $this->makeSystemEntitiesContext();
        $result = $this->invokeSystemSettingsHandler($context);
        $entities = $result['data']['data']['entities'];
        $themeEntity = null;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'theme') {
                $themeEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($themeEntity);
        $this->assertSame('theme', $themeEntity['name']);
        $this->assertSame('system', $themeEntity['scope']);
        $this->assertSame('element', $themeEntity['primaryKey']);
        $this->assertSame(array('element'), $themeEntity['uniqueKeys']);
        $this->assertSame(array('element'), $themeEntity['requiredFields']);
        $this->assertSame('webcomponent', $themeEntity['storage']['type']);
        $this->assertFalse($themeEntity['storage']['enabled']);
        $this->assertSame('themeSettings', $themeEntity['storage']['source']);
        $this->assertSame(array('/system/api/v1/themes'), $themeEntity['endpoints']);
        $this->assertTrue($themeEntity['enabled']);
    }

    public function testSystemEntitiesLinksIncludeSelfAndSites(): void
    {
        $GLOBALS['HAXCMS'] = new EntitiesEndpointTestHaxcms();
        $context = $this->makeSystemEntitiesContext();
        $result = $this->invokeSystemSettingsHandler($context);
        $links = $result['data']['data']['links'];
        $this->assertSame('/system/api/v1/entities', $links['self']);
        $this->assertSame('/system/api/v1/sites', $links['sites']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a SystemApiRequestContext pointed at the v1/entities route,
     * overriding the public properties the settings.php handler reads.
     */
    private function makeSystemEntitiesContext()
    {
        $context = new SystemApiRequestContext();
        $context->method = 'GET';
        $context->apiBasePath = '/system/api/v1';
        $context->routeSuffix = 'v1/entities';
        $context->params = array();
        $context->body = array();
        return $context;
    }

    /**
     * Include the settings.php handler closure, invoke it, and json_decode the
     * captured stdout. Mirrors invokeSiteRouteHandler for the system route.
     */
    private function invokeSystemSettingsHandler($context)
    {
        $path = dirname(__DIR__, 2) . '/lib/systemRoutes/v1/settings.php';
        $handler = include $path;
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        return array('raw' => $raw, 'data' => json_decode($raw, true));
    }
}

/**
 * Minimal HAXCMS mock for the system entities endpoint. The settings.php
 * handler calls getRequestTokenUserName()/getActiveUserName()/getRequestToken()
 * before the route switch; this adds the two methods OperationsTestHaxcms
 * lacks. safeGet is intentionally NOT declared so the handler's isset() check
 * falls through to $_GET (used for the ?scope= filter tests).
 */
class EntitiesEndpointTestHaxcms extends OperationsTestHaxcms
{
    public function getRequestTokenUserName()
    {
        return $this->activeUserName;
    }

    public function getRequestToken($user)
    {
        return 'test-server-token';
    }
}
