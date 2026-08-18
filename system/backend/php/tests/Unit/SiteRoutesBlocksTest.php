<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/blocks.php.
 * Without a HAXCMS global, getAutoloaderList() falls back to
 * array('grid-plate') and readEnabledBlocksSetting()/getWcMap() return
 * null/empty -- these tests characterize that reachable fallback path plus
 * custom-element-tag usage detection scanning item HTML content.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesBlocksTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSiteWithBlockUsage(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'a' => '<my-block foo="bar">Hello</my-block><my-block foo="baz">World</my-block>',
            'b' => '<my-block foo="hidden">Should not count for anon</my-block>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListAlwaysIncludesDefaultAutoloaderFallbackTag(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $tags = array_column($data['blocks'], 'tag');
        $this->assertContains('grid-plate', $tags);
    }

    public function testListIncludesTagsDetectedFromItemContentWithUsageCount(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        // Default authenticated context (see makeSiteRouteContext default):
        // applyItemFilters() only strips unpublished items for ANONYMOUS
        // requests, so this authenticated call still counts item 'b''s
        // usage too: 2 (item a) + 1 (item b) = 3.
        $context = makeSiteRouteContext($site, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $byTag = array();
        foreach ($data['blocks'] as $record) {
            $byTag[$record['tag']] = $record;
        }
        $this->assertArrayHasKey('my-block', $byTag);
        $this->assertSame(3, $byTag['my-block']['usageCount']);
        $this->assertTrue($byTag['my-block']['used']);
    }

    public function testListExcludesUnpublishedItemUsageForAnonymousCallers(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array(), 'v1/blocks', '/x/api', false);
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $byTag = array();
        foreach ($data['blocks'] as $record) {
            $byTag[$record['tag']] = $record;
        }
        // 'my-block' still detected as known (used on published item 'a'
        // twice), but the unpublished item 'b' usage must not count.
        $this->assertSame(2, $byTag['my-block']['usageCount']);
    }

    public function testFilterTagNarrowsList(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $_GET['filter.tag'] = 'my-block';
        $context = makeSiteRouteContext($site, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $tags = array_column($data['blocks'], 'tag');
        $this->assertSame(array('my-block'), $tags);
    }

    public function testBlockDetailByTagReturnsUsageAndInstanceDetails(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        // Default authenticated context: both items a (2 usages) and b (1
        // usage) are counted since anon-visibility filtering only applies
        // to unauthenticated requests.
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-block'), 'v1/blocks/my-block');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('my-block', $data['tag']);
        $this->assertSame(3, $data['usageCount']);
        sort($data['usedIn']);
        $this->assertSame(array('a', 'b'), $data['usedIn']);
        $this->assertCount(2, $data['usedInDetails']);
    }

    public function testBlockDetailUnknownTagWithNoUsageReturns404(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'never-used'), 'v1/blocks/never-used');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testBlockDetailKnownViaAutoloaderFallbackTagSucceeds(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'grid-plate'), 'v1/blocks/grid-plate');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('grid-plate', $data['tag']);
        $this->assertSame(0, $data['usageCount']);
    }

    public function testUsageRouteWithoutWebcomponentNameReturns404(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array(), 'v1/blocks//usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testUsageRouteReturnsItemsWithUsageCount(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        // Default authenticated context: both items a and b appear (see
        // note above about anon-visibility filtering only applying to
        // unauthenticated requests). Default sort is -usageCount, so item
        // 'a' (2 usages) sorts ahead of item 'b' (1 usage).
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-block'), 'v1/blocks/my-block/usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('my-block', $data['block']);
        $this->assertSame(2, $data['count']);
        $this->assertSame('a', $data['items'][0]['id']);
        $this->assertSame(2, $data['items'][0]['usageCount']);
    }

    public function testUsageRouteUnknownTagReturns404(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'never-used'), 'v1/blocks/never-used/usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testInvalidWebcomponentNameFormatIsIgnoredAndTreatedAsList(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext(
            $site,
            array('webcomponentName' => 'bad"name'),
            'v1/blocks/bad%22name'
        );
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        // The malformed tag fails the security regex guard and is reset to
        // '', so this falls through to the list route rather than a detail
        // lookup or a crash.
        $this->assertArrayHasKey('blocks', $data);
    }
}
