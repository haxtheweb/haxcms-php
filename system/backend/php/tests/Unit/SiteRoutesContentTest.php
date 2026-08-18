<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/content.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesContentTest extends TestCase
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
            'a' => '<p>Body A</p>',
            'b' => '<p>Body B</p>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/content');
        $result = invokeSiteRouteHandler('content.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListReturnsContentRecordsInBundleMode(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array(), 'v1/content');
        $result = invokeSiteRouteHandler('content.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('bundle', $data['mode']);
        $this->assertIsArray($data['content']);
        $first = $data['content'][0];
        $this->assertSame('html', $first['format']);
        $this->assertSame('<p>Body A</p>', $first['body']);
    }

    public function testDetailByIdOrSlugReturnsBodyAndLinks(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-a'), 'v1/content/page-a');
        $result = invokeSiteRouteHandler('content.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('a', $data['id']);
        $this->assertSame('<p>Body A</p>', $data['body']);
        $this->assertSame('/x/api/v1/content/page-a', $data['links']['self']);
        $this->assertSame('/x/api/v1/items/page-a', $data['links']['item']);
    }

    public function testDetailNotFoundReturns404(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'missing'), 'v1/content/missing');
        $result = invokeSiteRouteHandler('content.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testDetailAnonymousRequestHidesUnpublishedItem(): void
    {
        $site = $this->buildSiteWithContent();
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-b'), 'v1/content/page-b', '/x/api', false);
        $result = invokeSiteRouteHandler('content.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testConcatModeJoinsAllRecordBodies(): void
    {
        $site = $this->buildSiteWithContent();
        $_GET['mode'] = 'concat';
        $context = makeSiteRouteContext($site, array(), 'v1/content', '/x/api', true);
        $result = invokeSiteRouteHandler('content.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('concat', $data['mode']);
        $this->assertIsString($data['content']);
        $this->assertStringContainsString('Body A', $data['content']);
        $this->assertStringContainsString('Body B', $data['content']);
    }

    public function testUnknownModeFallsBackToBundle(): void
    {
        $site = $this->buildSiteWithContent();
        $_GET['mode'] = 'bogus';
        $context = makeSiteRouteContext($site, array(), 'v1/content');
        $result = invokeSiteRouteHandler('content.php', $context);
        $this->assertSame('bundle', $result['data']['data']['mode']);
    }

    public function testFieldsProjectionAppliesToDetail(): void
    {
        $site = $this->buildSiteWithContent();
        $_GET['fields'] = 'id,body';
        $context = makeSiteRouteContext($site, array('idOrSlug' => 'page-a'), 'v1/content/page-a');
        $result = invokeSiteRouteHandler('content.php', $context);
        $data = $result['data']['data'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('body', $data);
        $this->assertArrayNotHasKey('title', $data);
    }
}
