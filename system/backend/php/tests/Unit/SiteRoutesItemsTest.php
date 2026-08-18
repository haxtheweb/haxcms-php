<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/items.php.
 *
 * The bootstrap intentionally skips siteRoutes/v1/*.php (see
 * tests/phpunit-bootstrap.php), so this file requires the handler itself.
 * Invocation follows the pattern in tests/v1-integration-tests.php:
 * `$handler = include $path; ob_start(); $handler($context); $raw =
 * ob_get_clean(); json_decode($raw, true);`.
 *
 * Expected values are derived from the documented v1 API contract (item
 * summary shape, pagination envelope, navigation links) rather than a
 * re-derivation of the SiteRouteUtils implementation.
 */
class SiteRoutesItemsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSiteWithThreeItems(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2),
            makeSiteRouteItem('c', 'page-c', 'Page C', '', 3, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'a' => '<p>Content of A</p>',
            'b' => '<p>Content of B</p>',
            'c' => '<p>Content of C</p>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListReturnsAllItemsWithSummaryShape(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(3, $data['count']);
        $this->assertSame(3, $data['total']);
        $this->assertCount(3, $data['items']);
        $first = $data['items'][0];
        $this->assertSame('a', $first['id']);
        $this->assertSame('page-a', $first['slug']);
        $this->assertSame('Page A', $first['title']);
        $this->assertArrayHasKey('links', $first);
        $this->assertSame('/x/api/v1/items/page-a', $first['links']['self']);
    }

    public function testListIncludesExportLinks(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $first = $result['data']['data']['items'][0];
        $this->assertArrayHasKey('exports', $first);
        $this->assertArrayHasKey('docx', $first['exports']);
        $this->assertArrayHasKey('pdf', $first['exports']);
    }

    public function testDetailByIdOrSlugReturnsSingleRecord(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-b'), 'v1/items/page-b');
        $result = invokeSiteRouteHandler('items.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('b', $data['id']);
        $this->assertSame('Page B', $data['title']);
    }

    public function testDetailByIdOrSlugSupportsRawId(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'a'), 'v1/items/a');
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame('a', $result['data']['data']['id']);
    }

    public function testDetailNotFoundReturns404(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'missing'), 'v1/items/missing');
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testDetailHasNavigationLinks(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-b'), 'v1/items/page-b');
        $result = invokeSiteRouteHandler('items.php', $context);
        $links = $result['data']['data']['links'];
        $this->assertSame('/x/api/v1/items/page-a', $links['previous']);
        $this->assertSame('/x/api/v1/items/page-c', $links['next']);
    }

    public function testDetailIncludesContentWhenRequested(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $_GET['include'] = 'content';
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-a'), 'v1/items/page-a');
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame('<p>Content of A</p>', $result['data']['data']['content']);
    }

    public function testDetailIncludesHaxElementSchemaWhenRequested(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $_GET['include'] = 'haxElementSchema';
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-a'), 'v1/items/page-a');
        $result = invokeSiteRouteHandler('items.php', $context);
        $schema = $result['data']['data']['haxElementSchema'];
        $this->assertSame('p', $schema[0]['tag']);
    }

    public function testDetailIncludesJsonLdAlways(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-a'), 'v1/items/page-a');
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame('WebPage', $result['data']['data']['jsonld']['@type']);
    }

    public function testDetailAnonymousRequestHidesUnpublishedItem(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-c'), 'v1/items/page-c', '/x/api', false);
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testDetailAuthenticatedRequestSeesUnpublishedItem(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-c'), 'v1/items/page-c', '/x/api', true);
        $result = invokeSiteRouteHandler('items.php', $context);
        $this->assertSame('c', $result['data']['data']['id']);
    }

    public function testListAnonymousRequestExcludesUnpublishedItems(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $context = makeSiteRouteContext($site, array(), 'v1/items', '/x/api', false);
        $result = invokeSiteRouteHandler('items.php', $context);
        $ids = array_column($result['data']['data']['items'], 'id');
        $this->assertNotContains('c', $ids);
        $this->assertContains('a', $ids);
    }

    public function testFilterByParentNarrowsResults(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('parent', 'parent-page', 'Parent', '', 1),
            makeSiteRouteItem('child', 'child-page', 'Child', 'parent', 2),
        );
        $_GET['filter.parent'] = 'parent';
        $context = makeSiteRouteContext($site, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $ids = array_column($result['data']['data']['items'], 'id');
        $this->assertSame(['child'], $ids);
    }

    public function testFieldsProjectionLimitsOutputFields(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $_GET['fields'] = 'id,title';
        $context = makeSiteRouteContext($site, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $first = $result['data']['data']['items'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayNotHasKey('slug', $first);
    }

    public function testSortDescendingByOrder(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $_GET['sort'] = '-order';
        $context = makeSiteRouteContext($site, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $ids = array_column($result['data']['data']['items'], 'id');
        $this->assertSame(['c', 'b', 'a'], $ids);
    }

    public function testPaginationLimitAppliesToItems(): void
    {
        $site = $this->buildSiteWithThreeItems();
        $_GET['page.limit'] = '1';
        $context = makeSiteRouteContext($site, array(), 'v1/items');
        $result = invokeSiteRouteHandler('items.php', $context);
        $data = $result['data']['data'];
        $this->assertCount(1, $data['items']);
        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['page']['limit']);
    }
}
