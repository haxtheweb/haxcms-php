<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for the static/metadata-only handlers
 * entities.php and analytics.php. See SiteRoutesItemsTest.php for the shared
 * invocation pattern rationale.
 */
class SiteRoutesEntitiesAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    // ------------------------------------------------------------------
    // entities.php
    // ------------------------------------------------------------------

    public function testEntitiesMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testEntitiesListsKnownEntityNames(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $data = $result['data']['data'];
        $names = array_column($data['entities'], 'name');
        foreach (['site', 'item', 'content', 'file', 'tag', 'customElement', 'block', 'region', 'theme', 'report', 'analytics', 'view', 'user'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertSame(count($data['entities']), $data['count']);
    }

    public function testEntitiesItemEntityHasEndpointsAndFilterableFields(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $entities = $result['data']['data']['entities'];
        $itemEntity = null;
        foreach ($entities as $entity) {
            if ($entity['name'] === 'item') {
                $itemEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($itemEntity);
        $this->assertContains('/x/api/v1/items', $itemEntity['endpoints']);
        $this->assertContains('filter.tags', $itemEntity['filterableFields']);
    }

    public function testEntitiesUserEntityIsDisabledAndReservedForFuturePhases(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $entities = $result['data']['data']['entities'];
        $userEntity = null;
        foreach ($entities as $entity) {
            if ($entity['name'] === 'user') {
                $userEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($userEntity);
        $this->assertFalse($userEntity['enabled']);
        $this->assertSame('authenticated', $userEntity['auth']);
    }

    public function testEntitiesLinksIncludeSelfAndSchemas(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $links = $result['data']['data']['links'];
        $this->assertSame('/x/api/v1/entities', $links['self']);
        $this->assertSame('/x/api/v1/schemas', $links['schemas']);
    }

    // ------------------------------------------------------------------
    // analytics.php
    // ------------------------------------------------------------------

    public function testAnalyticsMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/analytics');
        $result = invokeSiteRouteHandler('analytics.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testAnalyticsReportsReadOnlyModeWithXapiCapability(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/analytics');
        $result = invokeSiteRouteHandler('analytics.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('read-only', $data['mode']);
        $this->assertTrue($data['xapi']['supported']);
        $this->assertSame('/x/api/v1/schemas?filter.kind=xapi', $data['xapi']['schema']);
    }

    public function testAnalyticsLinksIncludeReportsAndXapiSchema(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/analytics');
        $result = invokeSiteRouteHandler('analytics.php', $context);
        $links = $result['data']['data']['links'];
        $this->assertSame('/x/api/v1/analytics', $links['self']);
        $this->assertSame('/x/api/v1/reports', $links['reports']);
    }
}
