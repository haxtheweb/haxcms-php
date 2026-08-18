<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/regions.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesRegionsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildRegionedSite(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1, 0, '', '', array('region' => 'sidebar')),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2, 0, '', '', array('region' => 'sidebar')),
            makeSiteRouteItem('c', 'page-c', 'Page C', '', 3),
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/regions');
        $result = invokeSiteRouteHandler('regions.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListGroupsItemsByRegionWithDefaultFallback(): void
    {
        $site = $this->buildRegionedSite();
        $context = makeSiteRouteContext($site, array(), 'v1/regions');
        $result = invokeSiteRouteHandler('regions.php', $context);
        $regions = $result['data']['data']['regions'];
        $byName = array();
        foreach ($regions as $region) {
            $byName[$region['name']] = $region['count'];
        }
        $this->assertSame(2, $byName['sidebar']);
        $this->assertSame(1, $byName['default']);
    }

    public function testDetailByRegionNameReturnsMatchingItems(): void
    {
        $site = $this->buildRegionedSite();
        $context = makeSiteRouteContext($site, array('regionName' => 'sidebar'), 'v1/regions/sidebar');
        $result = invokeSiteRouteHandler('regions.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('sidebar', $data['region']['name']);
        $this->assertSame(2, $data['region']['count']);
        $ids = array_column($data['items'], 'id');
        $this->assertSame(['a', 'b'], $ids);
    }

    public function testDetailForUnknownRegionReturns404(): void
    {
        $site = $this->buildRegionedSite();
        $context = makeSiteRouteContext($site, array('regionName' => 'nope'), 'v1/regions/nope');
        $result = invokeSiteRouteHandler('regions.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListSortedByNameAscendingByDefault(): void
    {
        $site = $this->buildRegionedSite();
        $context = makeSiteRouteContext($site, array(), 'v1/regions');
        $result = invokeSiteRouteHandler('regions.php', $context);
        $names = array_column($result['data']['data']['regions'], 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }
}
