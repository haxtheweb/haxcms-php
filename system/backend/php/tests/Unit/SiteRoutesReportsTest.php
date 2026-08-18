<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/reports.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesReportsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSiteWithContent(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'a' => '<p>The quick brown fox jumps over the lazy dog. This is a simple sentence.</p><h2>A heading</h2><a href="https://example.com">Example link</a>',
            'b' => '<p>Hidden unpublished content.</p>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/reports');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListReturnsAllFourReportDefinitions(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array(), 'v1/reports');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $data = $result['data']['data'];
        $ids = array_column($data['reports'], 'id');
        sort($ids);
        $this->assertSame(array('content', 'links', 'media', 'overview'), $ids);
        $this->assertSame('/x/api/v1/reports', $data['links']['self']);
    }

    public function testUnknownReportNameReturns404(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('report' => 'nope'), 'v1/reports/nope');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testOverviewReportIncludesReadabilityAndCounts(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('report' => 'overview'), 'v1/reports/overview');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('overview', $data['id']);
        $this->assertArrayHasKey('readability', $data['data']);
        $this->assertArrayHasKey('gradeLevel', $data['data']['readability']);
        // published item counted, unpublished item ('b') excluded from
        // getSelectedItems() default (no activeId) filter.
        $this->assertGreaterThanOrEqual(1, $data['data']['pages']);
        $this->assertSame('/x/api/v1/reports/overview', $data['links']['self']);
    }

    public function testContentReportReturnsPerPageRowsWithLinkField(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('report' => 'content'), 'v1/reports/content');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $data = $result['data']['data'];
        $this->assertIsArray($data['data']['contentData']);
        $this->assertNotEmpty($data['data']['contentData']);
        $this->assertArrayHasKey('link', $data['data']['contentData'][0]);
    }

    public function testLinksReportGroupsExternalLinksByHref(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('report' => 'links'), 'v1/reports/links');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $data = $result['data']['data'];
        $this->assertArrayHasKey('https://example.com', $data['data']['linkData']);
    }

    public function testMediaReportReturnsEmptyArrayWhenNoMedia(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('report' => 'media'), 'v1/reports/media');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(array(), $data['data']['mediaData']);
    }

    public function testFieldsProjectionAppliesToTopLevelPayload(): void
    {
        $site = $this->buildSiteWithContent();
        $_GET['fields'] = 'id,title';
        $context = makeSiteRouteContext($site, array('report' => 'overview'), 'v1/reports/overview');
        $result = invokeSiteRouteHandler('reports.php', $context);
        $data = $result['data']['data'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayNotHasKey('data', $data);
    }
}
