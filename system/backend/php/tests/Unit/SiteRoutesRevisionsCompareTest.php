<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once __DIR__ . '/SiteRoutesRevisionsTest.php';

/**
 * Route-level tests for lib/siteRoutes/v1/revisionsCompare.php
 * (GET v1/items/:idOrSlug/revisions/:revisionId/compare/:compareRevisionId,
 * haxtheweb/issues#3191): the route's own dispatch (site guard, slug
 * resolution, delegation to Operations::compareNodeRevisions, the
 * envelope-less failure shape). The git work behind a compare is covered by
 * OperationsNodeRevisionsTest. Same answers as haxcms-nodejs
 * compareItemRevisions (src/siteRoutes/v1/revisions.js).
 */
class SiteRoutesRevisionsCompareTest extends TestCase
{
    private const A = 'abcdef1234567';
    private const B = '1234567abcdef';

    protected function setUp(): void
    {
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
        unset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS']);
        unset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    private function site()
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('item-uuid-1', 'my-page', 'My Page', '', 1),
        );
        return $site;
    }

    private function compare($site, string $idOrSlug, string $from, string $to): array
    {
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => $idOrSlug, 'revisionId' => $from, 'compareRevisionId' => $to),
            'v1/items/' . $idOrSlug . '/revisions/' . $from . '/compare/' . $to
        );
        return invokeSiteRouteHandler('revisionsCompare.php', $context);
    }

    public function testNoSiteIs404(): void
    {
        $result = $this->compare(null, 'item-uuid-1', self::A, self::B);
        $this->assertSame(404, $result['data']['status']);
        $this->assertStringContainsString('Unable to resolve site context', $result['data']['data']['message']);
    }

    public function testAnUnknownPageIs404(): void
    {
        $result = $this->compare($this->site(), 'no-such-page', self::A, self::B);
        $this->assertSame(404, $result['data']['status']);
        $this->assertStringContainsString('Item not found for idOrSlug "no-such-page"', $result['data']['data']['message']);
    }

    // the field is there but blank, so it is checked as a hash
    public function testABlankCompareRevisionIs400(): void
    {
        $result = $this->compare($this->site(), 'item-uuid-1', self::A, '');
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Invalid revision hash', $result['data']['message']);
    }

    public function testAnInvalidRevisionHashIs400(): void
    {
        $result = $this->compare($this->site(), 'item-uuid-1', 'not-a-hash', self::B);
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Invalid revision hash', $result['data']['message']);
    }

    // the page is found by slug, then the token is checked: a rejected token
    // is 403 with no envelope, the same shape as the other revision routes
    public function testASlugResolvesAndARejectedTokenIs403(): void
    {
        $GLOBALS['HAXCMS'] = new SiteRoutesRevisionsFakeHaxcms();
        $GLOBALS['HAXCMS']->acceptTokens = false;
        $result = $this->compare($this->site(), 'my-page', self::A, self::B);
        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('Invalid site token', $result['data']['message']);
        $this->assertArrayNotHasKey('data', $result['data']);
    }
}
