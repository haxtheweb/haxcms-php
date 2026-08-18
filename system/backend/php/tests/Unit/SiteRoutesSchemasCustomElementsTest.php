<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Minimal stand-in for the $GLOBALS['HAXCMS'] global, exposing only the
 * getWCRegistryJson($site) method that customElements.php/schemas.php call
 * via method_exists() guards.
 */
class SiteRoutesFakeHaxcmsWcRegistry
{
    private $wcMap;

    public function __construct($wcMap)
    {
        $this->wcMap = $wcMap;
    }

    public function getWCRegistryJson($site)
    {
        return $this->wcMap;
    }
}

/**
 * Route-level characterization tests for lib/siteRoutes/v1/schemas.php and
 * lib/siteRoutes/v1/customElements.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesSchemasCustomElementsTest extends TestCase
{
    private $tmpDirs = array();

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
        $this->tmpDirs = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        unset($GLOBALS['HAXCMS']);
    }

    private function removeDirRecursive($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            }
            else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function buildSiteWithWcRegistryFile($wcMapArray)
    {
        $site = new SiteRoutesFakeSite();
        $dir = sys_get_temp_dir() . '/site-routes-wc-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        file_put_contents($dir . '/wc-registry.json', json_encode($wcMapArray));
        $site->siteDirectory = $dir;
        return $site;
    }

    /**
     * customElements.php's getWcMap() initializes $wcMap = new stdClass()
     * (already an object) BEFORE checking $GLOBALS['HAXCMS'], so its
     * `if (!is_object($wcMap))` filesystem-fallback branch that reads
     * wc-registry.json directly from the site directory is unreachable
     * whenever no HAXCMS global is present. In production the HAXCMS global
     * is always set, so this fake mirrors the realistic/reachable code path:
     * $GLOBALS['HAXCMS']->getWCRegistryJson($site) returning a decoded map.
     */
    private function buildSiteWithWcRegistryViaHaxcmsGlobal($wcMapArray)
    {
        $site = new SiteRoutesFakeSite();
        $wcMap = json_decode(json_encode($wcMapArray));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry($wcMap);
        return $site;
    }

    // --- schemas.php ---

    public function testSchemasMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testSchemasListReturnsAllDescriptorsWithLinks(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $data = $result['data']['data'];
        $ids = array_column($data['schemas'], 'id');
        $this->assertContains('json-outline-schema', $ids);
        $this->assertContains('hax-properties', $ids);
        $this->assertSame('/x/api/v1/schemas', $data['links']['self']);
        $this->assertSame(count($ids), $data['count']);
    }

    public function testSchemasFilterKindNarrowsToMatchingDescriptor(): void
    {
        $site = new SiteRoutesFakeSite();
        $_GET['filter.kind'] = 'xapi';
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $data = $result['data']['data'];
        $this->assertCount(1, $data['schemas']);
        $this->assertSame('xapi-statement-schema', $data['schemas'][0]['id']);
    }

    public function testSchemasFilterWebcomponentNameWithoutRegistryUsesFallbackShape(): void
    {
        $site = new SiteRoutesFakeSite();
        $_GET['filter.webcomponentName'] = 'my-element';
        $_GET['filter.kind'] = 'haxSchema';
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $data = $result['data']['data'];
        $this->assertCount(1, $data['schemas']);
        $this->assertSame('my-element', $data['schemas'][0]['schema']['tag']);
    }

    // --- customElements.php ---

    public function testCustomElementsMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testCustomElementsEmptyRegistryReturnsEmptyList(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array());
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(0, $data['count']);
        $this->assertSame(array(), $data['customElements']);
    }

    public function testCustomElementsListFromWcRegistryFile(): void
    {
        $site = $this->buildSiteWithWcRegistryFile(array(
            'my-element' => 'my-element/my-element.js',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        // getWcMap() initializes $wcMap = new stdClass() before checking the
        // HAXCMS global, so the is_object() guard on the filesystem fallback
        // never triggers when no HAXCMS global is set -- the on-disk
        // wc-registry.json is NOT read in that case. Characterize that.
        $this->assertSame(0, $data['count']);
        $this->assertSame(array(), $data['customElements']);
    }

    public function testCustomElementsListFromHaxcmsGlobalRegistry(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
            'other-element' => '@scope/other-element/other-element.js',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(2, $data['count']);
        $tags = array_column($data['customElements'], 'tag');
        sort($tags);
        $this->assertSame(array('my-element', 'other-element'), $tags);
    }

    public function testCustomElementsDetailByTagIncludesPackageAndLinks(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
        ));
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-element'), 'v1/custom-elements/my-element');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('my-element', $data['tag']);
        $this->assertSame('my-element/my-element.js', $data['import']);
        $this->assertSame('my-element', $data['package']);
        $this->assertSame('/x/api/v1/custom-elements/my-element', $data['links']['self']);
    }

    public function testCustomElementsDetailUnknownTagReturns404(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array());
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'nope'), 'v1/custom-elements/nope');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testCustomElementsIncludeAddsSchemaFragments(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
        ));
        $_GET['include'] = 'haxProperties,haxSchema,haxElementSchema';
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-element'), 'v1/custom-elements/my-element');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertArrayHasKey('haxProperties', $data);
        $this->assertArrayHasKey('haxSchema', $data);
        $this->assertArrayHasKey('haxElementSchema', $data);
        $this->assertSame('my-element', $data['haxElementSchema']['tag']);
    }

    public function testCustomElementsFilterTagNarrowsList(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
            'other-thing' => 'other-thing/other-thing.js',
        ));
        $_GET['filter.tag'] = 'my-';
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('my-element', $data['customElements'][0]['tag']);
    }
}
