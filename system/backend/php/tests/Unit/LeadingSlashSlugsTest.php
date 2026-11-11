<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Page slugs and the leading "/" (haxtheweb/issues#3309).
 *
 * getUniqueSlugName builds a slug from its non-empty "/" segments, so a new
 * slug never starts with "/" or holds "//". Sites saved before that can
 * already hold a slug like "/child-1": they are not rewritten, and a lookup
 * by the path without the leading "/" still finds the page (an exact slug
 * match always wins).
 *
 * Parity mirror of haxcms-nodejs test/unit/leading-slash-slugs.test.cjs
 * (same scenario names, TestDox), plus the page-by-path lookup
 * (loadNodeByLocation) that the Node backend does in app.js.
 */
class LeadingSlashSlugsTest extends TestCase
{
    private function siteWith(array $items)
    {
        $site = new stdClass();
        $site->manifest = new JSONOutlineSchema();
        $site->manifest->items = array();
        foreach ($items as $data) {
            $item = new JSONOutlineSchemaItem();
            $item->id = $data['id'];
            $item->slug = $data['slug'];
            $item->location = 'pages/' . $data['id'] . '/index.html';
            $site->manifest->items[] = $item;
        }
        return $site;
    }

    #[TestDox('joinSlugSegments: empty segments are dropped')]
    public function testJoinSlugSegmentsDropsEmptySegments(): void
    {
        $this->assertSame('child-1', HAXCMSSite::joinSlugSegments('/child-1'));
        $this->assertSame('grand/child', HAXCMSSite::joinSlugSegments('grand//child'));
        $this->assertSame('a/b', HAXCMSSite::joinSlugSegments('//a///b/'));
        $this->assertSame('parent/child', HAXCMSSite::joinSlugSegments('parent/child'));
        $this->assertSame('', HAXCMSSite::joinSlugSegments(''));
    }

    #[TestDox("legacySlugMatches: only a stored slug with a leading '/' matches without it")]
    public function testLegacySlugMatches(): void
    {
        $this->assertTrue(HAXCMSSite::legacySlugMatches('/child-1', 'child-1'));
        $this->assertTrue(HAXCMSSite::legacySlugMatches('/child-1', '/child-1'));
        $this->assertFalse(HAXCMSSite::legacySlugMatches('child-1', 'child-1'));
        $this->assertFalse(HAXCMSSite::legacySlugMatches('/child-1', 'child-2'));
        $this->assertFalse(HAXCMSSite::legacySlugMatches('/', ''));
        $this->assertFalse(HAXCMSSite::legacySlugMatches(null, 'child-1'));
    }

    #[TestDox("findItemByIdOrSlug: a page saved with a leading '/' slug is found")]
    public function testFindItemByIdOrSlugFindsALeadingSlashSlug(): void
    {
        $site = $this->siteWith(array(
            array('id' => 'a', 'slug' => 'parent'),
            array('id' => 'b', 'slug' => '/child-1'),
        ));
        $this->assertSame('b', SiteRouteUtils::findItemByIdOrSlug($site, 'child-1')->id);
        $this->assertSame('b', SiteRouteUtils::findItemByIdOrSlug($site, '/child-1')->id);
    }

    #[TestDox("findItemByIdOrSlug: an exact slug match wins over a leading '/' one")]
    public function testFindItemByIdOrSlugPrefersAnExactSlug(): void
    {
        $site = $this->siteWith(array(
            array('id' => 'legacy', 'slug' => '/child-1'),
            array('id' => 'exact', 'slug' => 'child-1'),
        ));
        $this->assertSame('exact', SiteRouteUtils::findItemByIdOrSlug($site, 'child-1')->id);
    }

    #[TestDox("loadNodeByLocation: a page saved with a leading '/' slug answers to its path")]
    public function testLoadNodeByLocationFindsALeadingSlashSlug(): void
    {
        $data = $this->siteWith(array(
            array('id' => 'a', 'slug' => 'parent'),
            array('id' => 'b', 'slug' => '/child-1'),
        ));
        $site = (new ReflectionClass('HAXCMSSite'))->newInstanceWithoutConstructor();
        $site->manifest = $data->manifest;
        $this->assertSame('b', $site->loadNodeByLocation('child-1')->id);
        $this->assertSame('a', $site->loadNodeByLocation('parent')->id);
    }
}
