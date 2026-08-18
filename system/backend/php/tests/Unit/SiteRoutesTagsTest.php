<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/tags.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesTagsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildTaggedSite(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1, 0, '', '', array('tags' => ['red', 'blue'])),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2, 0, '', '', array('tags' => ['blue'])),
            makeSiteRouteItem('c', 'page-c', 'Page C', '', 3, 0, '', '', array('tags' => [])),
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/tags');
        $result = invokeSiteRouteHandler('tags.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testTagsCountedAcrossItems(): void
    {
        $site = $this->buildTaggedSite();
        $context = makeSiteRouteContext($site, array(), 'v1/tags');
        $result = invokeSiteRouteHandler('tags.php', $context);
        $tags = $result['data']['data']['tags'];
        $byTag = array();
        foreach ($tags as $tag) {
            $byTag[$tag['tag']] = $tag['count'];
        }
        $this->assertSame(2, $byTag['blue']);
        $this->assertSame(1, $byTag['red']);
    }

    public function testIncludeItemsAddsItemIdsToTagRecord(): void
    {
        $site = $this->buildTaggedSite();
        $_GET['include'] = 'items';
        $context = makeSiteRouteContext($site, array(), 'v1/tags');
        $result = invokeSiteRouteHandler('tags.php', $context);
        $tags = $result['data']['data']['tags'];
        $blueTag = null;
        foreach ($tags as $tag) {
            if ($tag['tag'] === 'blue') {
                $blueTag = $tag;
            }
        }
        $this->assertNotNull($blueTag);
        $this->assertSame(['a', 'b'], $blueTag['items']);
    }

    public function testItemsNotIncludedByDefault(): void
    {
        $site = $this->buildTaggedSite();
        $context = makeSiteRouteContext($site, array(), 'v1/tags');
        $result = invokeSiteRouteHandler('tags.php', $context);
        $tags = $result['data']['data']['tags'];
        $this->assertSame([], $tags[0]['items']);
    }

    public function testFilterTagsNarrowsResults(): void
    {
        $site = $this->buildTaggedSite();
        $_GET['filter.tags'] = 'red';
        $context = makeSiteRouteContext($site, array(), 'v1/tags');
        $result = invokeSiteRouteHandler('tags.php', $context);
        $tags = $result['data']['data']['tags'];
        $this->assertCount(1, $tags);
        $this->assertSame('red', $tags[0]['tag']);
    }

    public function testDefaultSortIsCountDescending(): void
    {
        $site = $this->buildTaggedSite();
        $context = makeSiteRouteContext($site, array(), 'v1/tags');
        $result = invokeSiteRouteHandler('tags.php', $context);
        $tagNames = array_column($result['data']['data']['tags'], 'tag');
        $this->assertSame('blue', $tagNames[0]);
    }
}
