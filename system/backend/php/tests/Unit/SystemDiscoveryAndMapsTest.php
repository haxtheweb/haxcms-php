<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the system discovery handlers (discovery/api.php,
 * discovery/openapi.php) and the static route/method maps
 * (SystemRoutesMap, OperationsMethodMap). The discovery files return a
 * closure and are not auto-loaded by the bootstrap, so they are required
 * per test.
 */
require_once __DIR__ . '/../../lib/systemRoutes/SystemRoutesMap.php';
require_once __DIR__ . '/../../lib/operations/OperationsMethodMap.php';

class SystemDiscoveryAndMapsTest extends TestCase
{
    private $savedHaxcms;
    private $savedGet;

    protected function setUp(): void
    {
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedGet = $_GET;
        unset($_GET['format']);
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_GET = $this->savedGet;
    }

    private function handler(string $file): callable
    {
        return require __DIR__ . '/../../lib/systemRoutes/discovery/' . $file;
    }

    private function invoke(string $file, array $props = array()): string
    {
        $context = new stdClass();
        foreach ($props as $k => $v) {
            $context->$k = $v;
        }
        $fn = $this->handler($file);
        ob_start();
        try {
            $fn($context);
        } finally {
            $out = ob_get_clean();
        }
        return $out;
    }

    private function haxcms(array $props): void
    {
        $h = new stdClass();
        foreach ($props as $k => $v) {
            $h->$k = $v;
        }
        $GLOBALS['HAXCMS'] = $h;
    }

    // ------------------------------------------------------------------
    // discovery/api.php
    // ------------------------------------------------------------------

    public function testApiDiscoveryListsRelativeAndAbsoluteLinks(): void
    {
        $out = json_decode($this->invoke('api.php', array(
            'apiBasePath' => '/system/api/v1',
            'absoluteApiBasePath' => 'https://example.org/system/api/v1',
            'routeSuffix' => 'v1',
        )), true);
        $data = isset($out['data']) ? $out['data'] : $out;
        $this->assertSame('HAXcms System API', $data['name']);
        $this->assertSame('/system/api/v1/openapi.yaml', $data['links']['openapiYaml']);
        $this->assertSame('https://example.org/system/api/v1/sites', $data['absoluteLinks']['sites']);
        $this->assertTrue($data['openapi']['routeDriven']);
    }

    public function testApiDiscoveryDefaultsBasePaths(): void
    {
        $out = json_decode($this->invoke('api.php'), true);
        $data = isset($out['data']) ? $out['data'] : $out;
        $this->assertSame('/system/api', $data['links']['self']);
        $this->assertSame('/system/api', $data['absoluteLinks']['self']);
    }

    public function testApiDiscoveryPublishesTheMcpPolicy(): void
    {
        $cases = array(
            // config->mcp, expected
            array(null, array('enabled' => false, 'readOnly' => true)),
            array(array(), array('enabled' => false, 'readOnly' => true)),
            array(array('enabled' => true), array('enabled' => true, 'readOnly' => true)),
            array(array('enabled' => true, 'readOnly' => false), array('enabled' => true, 'readOnly' => false)),
            // read-only is forced while MCP is disabled
            array(array('enabled' => false, 'readOnly' => false), array('enabled' => false, 'readOnly' => true)),
            // only a real boolean TRUE enables it
            array(array('enabled' => 'true', 'readOnly' => false), array('enabled' => false, 'readOnly' => true)),
        );
        foreach ($cases as $case) {
            $haxcms = new HAXCMS();
            $haxcms->config = new stdClass();
            if ($case[0] !== null) {
                $haxcms->config->mcp = (object) $case[0];
            }
            $GLOBALS['HAXCMS'] = $haxcms;
            $out = json_decode($this->invoke('api.php'), true);
            $data = isset($out['data']) ? $out['data'] : $out;
            $this->assertSame($case[1], $data['mcp'], json_encode($case[0]));
        }
    }

    public function testApiDiscoveryMcpPolicyWithoutHaxcmsIsOffAndReadOnly(): void
    {
        unset($GLOBALS['HAXCMS']);
        $out = json_decode($this->invoke('api.php'), true);
        $data = isset($out['data']) ? $out['data'] : $out;
        $this->assertSame(array('enabled' => false, 'readOnly' => true), $data['mcp']);
    }

    // ------------------------------------------------------------------
    // discovery/openapi.php
    // ------------------------------------------------------------------

    public function testOpenApiJsonUsesAbsoluteServerUrlAndContractVersion(): void
    {
        $this->haxcms(array('protocol' => 'https', 'domain' => 'example.org', 'basePath' => 'cms'));
        $spec = json_decode($this->invoke('openapi.php', array('routeSuffix' => 'v1/openapi.json')), true);
        $this->assertIsArray($spec);
        $this->assertArrayHasKey('paths', $spec);
        // info.version is the spec's contract version, never the running HAXcms version
        $this->assertNotSame(SiteRouteUtils::getVersion(), $spec['info']['version']);
        $this->assertSame('1.0.0', $spec['info']['version']);
        $this->assertSame(
            array(array('url' => 'https://example.org/cms/', 'description' => 'HAXcms system base URL')),
            $spec['servers']
        );
    }

    public function testOpenApiFallsBackToRelativeBasePathWithoutHost(): void
    {
        $this->haxcms(array('basePath' => '/'));
        $spec = json_decode($this->invoke('openapi.php', array('routeSuffix' => 'v1/openapi')), true);
        $this->assertSame('/', $spec['servers'][0]['url']);
    }

    public function testOpenApiWithoutHaxcmsGlobalDefaultsToRoot(): void
    {
        unset($GLOBALS['HAXCMS']);
        $spec = json_decode($this->invoke('openapi.php'), true);
        $this->assertSame('/', $spec['servers'][0]['url']);
    }

    public function testOpenApiDefaultsHttpProtocolAndAppendsTrailingSlash(): void
    {
        $this->haxcms(array('domain' => 'h.test', 'basePath' => '/sub'));
        $spec = json_decode($this->invoke('openapi.php', array('routeSuffix' => 'v1/openapi')), true);
        $this->assertSame('http://h.test/sub/', $spec['servers'][0]['url']);
    }

    public function testOpenApiYamlSuffixEmitsYaml(): void
    {
        $this->haxcms(array('domain' => 'example.org', 'basePath' => '/'));
        $out = $this->invoke('openapi.php', array('routeSuffix' => 'v1/openapi.yaml'));
        $this->assertNull(json_decode($out, true));
        $this->assertStringContainsString('openapi:', $out);
        $this->assertStringContainsString('http://example.org/', $out);
    }

    public function testOpenApiFormatQueryParamSelectsYaml(): void
    {
        $_GET['format'] = 'yaml';
        $out = $this->invoke('openapi.php', array('routeSuffix' => 'v1/openapi'));
        $this->assertStringContainsString('openapi:', $out);
    }

    // ------------------------------------------------------------------
    // SystemRoutesMap
    // ------------------------------------------------------------------

    public function testRoutesForMethodIsCaseInsensitiveAndUnknownIsEmpty(): void
    {
        $get = SystemRoutesMap::getRoutesForMethod('get');
        $this->assertArrayHasKey('v1/openapi.yaml', $get);
        $this->assertSame(SystemRoutesMap::getRoutesMap()['GET'], $get);
        $this->assertSame(array(), SystemRoutesMap::getRoutesForMethod('OPTIONS'));
        $this->assertArrayHasKey('v1', SystemRoutesMap::getRoutesForMethod());
    }

    public function testEveryMappedRouteFileExists(): void
    {
        foreach (SystemRoutesMap::getRoutesMap() as $method => $routes) {
            foreach ($routes as $route => $file) {
                $this->assertFileExists($file, $method . ' ' . $route);
            }
        }
    }

    public function testSuperUserRoutesAreSubsetsOfAdminRoutes(): void
    {
        $admin = SystemRoutesMap::getSystemV1AdminRoutes();
        $super = SystemRoutesMap::getSystemV1SuperUserRoutes();
        $this->assertSame(array(), array_diff($super, $admin));
        $this->assertSame(array(), array_diff(SystemRoutesMap::getSystemV1SuperUserReadRoutes(), $super));
        // site-lifecycle mutations are not superUser-gated
        $this->assertNotContains('v1/sites', $super);
    }

    // ------------------------------------------------------------------
    // OperationsMethodMap
    // ------------------------------------------------------------------

    public function testEveryOperationMethodFileExists(): void
    {
        $map = OperationsMethodMap::getMethodsMap();
        $this->assertArrayHasKey('saveNode', $map);
        foreach ($map as $method => $file) {
            $this->assertFileExists($file, $method);
        }
        // the three revision operations share one implementation file
        $this->assertSame($map['getNodeRevisions'], $map['restoreNodeRevision']);
    }
}
