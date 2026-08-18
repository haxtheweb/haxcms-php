<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/views.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesViewsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSiteWithItems(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1, 0, '', '', array('tags' => 'foo,bar')),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2, 0, '', '', array('tags' => 'bar', 'published' => false)),
            makeSiteRouteItem('c', 'page-c', 'Page C', '', 3, 0, '', '', array()),
        );
        $site->pageContentMap = array(
            'a' => '<p>alpha content</p>',
            'b' => '<p>beta content</p>',
            'c' => '<p>gamma content</p>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/views');
        $result = invokeSiteRouteHandler('views.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListReturnsDefaultBuiltInViews(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array(), 'v1/views');
        $result = invokeSiteRouteHandler('views.php', $context);
        $data = $result['data']['data'];
        $ids = array_column($data['views'], 'id');
        sort($ids);
        $this->assertSame(array('recent', 'search', 'tags'), $ids);
        $this->assertSame('/x/api/v1/views', $data['links']['self']);
    }

    public function testViewDetailByIdReturnsRecordWithLinks(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'tags'), 'v1/views/tags');
        $result = invokeSiteRouteHandler('views.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('tags', $data['id']);
        $this->assertSame('/x/api/v1/views/tags', $data['links']['self']);
        $this->assertSame('/x/api/v1/views/tags/results', $data['links']['results']);
    }

    public function testUnknownViewIdReturns404(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'nope'), 'v1/views/nope');
        $result = invokeSiteRouteHandler('views.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testDefaultSourceResultsReturnItemSummaries(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'recent'), 'v1/views/recent/results');
        $result = invokeSiteRouteHandler('views.php', $context);
        $data = $result['data']['data'];
        $this->assertGreaterThanOrEqual(2, $data['count']);
        $this->assertIsArray($data['results']);
    }

    public function testDefaultSourceResultsHideUnpublishedFromAnonymous(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'recent'), 'v1/views/recent/results', '/x/api', false);
        $result = invokeSiteRouteHandler('views.php', $context);
        $ids = array_column($result['data']['data']['results'], 'id');
        $this->assertNotContains('b', $ids);
    }

    public function testTagsSourceResultsAggregateTagCounts(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'tags'), 'v1/views/tags/results', '/x/api', true);
        $result = invokeSiteRouteHandler('views.php', $context);
        $results = $result['data']['data']['results'];
        $tagsByName = array();
        foreach ($results as $row) {
            $tagsByName[$row['tag']] = $row['count'];
        }
        $this->assertSame(2, $tagsByName['bar']);
        $this->assertSame(1, $tagsByName['foo']);
    }

    public function testTagsSourceResultsExcludeUnpublishedForAnonymous(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'tags'), 'v1/views/tags/results', '/x/api', false);
        $result = invokeSiteRouteHandler('views.php', $context);
        $results = $result['data']['data']['results'];
        $tagsByName = array();
        foreach ($results as $row) {
            $tagsByName[$row['tag']] = $row['count'];
        }
        // item 'b' (unpublished, tag=bar) must be excluded for anonymous callers.
        $this->assertSame(1, $tagsByName['bar']);
    }

    public function testSearchSourceResultsMatchQueryParam(): void
    {
        $site = $this->buildSiteWithItems();
        $_GET['q'] = 'alpha';
        $context = makeSiteRouteContext($site, array('viewId' => 'search'), 'v1/views/search/results');
        $result = invokeSiteRouteHandler('views.php', $context);
        $results = $result['data']['data']['results'];
        $this->assertCount(1, $results);
        $this->assertSame('a', $results[0]['id']);
    }

    public function testSearchSourceWithNoQueryReturnsEmptyResults(): void
    {
        $site = $this->buildSiteWithItems();
        $context = makeSiteRouteContext($site, array('viewId' => 'search'), 'v1/views/search/results');
        $result = invokeSiteRouteHandler('views.php', $context);
        $this->assertSame(array(), $result['data']['data']['results']);
    }

    public function testSearchSourceExcludesUnpublishedFromAnonymous(): void
    {
        $site = $this->buildSiteWithItems();
        $_GET['q'] = 'beta';
        $context = makeSiteRouteContext($site, array('viewId' => 'search'), 'v1/views/search/results', '/x/api', false);
        $result = invokeSiteRouteHandler('views.php', $context);
        $this->assertSame(array(), $result['data']['data']['results']);
    }
}
