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

    public function testEntitiesListsKnownEntityTypes(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $data = $result['data']['data'];
        // The endpoint now sources from the single merged entities.yaml registry
        // (EntityRegistry::getDefinitions()), so the set is the 6 registry types.
        $types = array_column($data['entities'], 'type');
        foreach (['file', 'item', 'theme', 'skeleton', 'site', 'system'] as $expected) {
            $this->assertContains($expected, $types);
        }
        $this->assertSame(count($data['entities']), $data['count']);
        // name is kept as a backward-compatible alias of type.
        $names = array_column($data['entities'], 'name');
        $this->assertSame($types, $names);
    }

    public function testEntitiesItemEntityHasEndpointsAndFilterableFields(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $entities = $result['data']['data']['entities'];
        $itemEntity = null;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'item') {
                $itemEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($itemEntity);
        $this->assertContains('/x/api/v1/items', $itemEntity['endpoints']);
        $this->assertContains('tags', $itemEntity['filterableFields']);
    }

    public function testEntitiesFileEntityDeclaresDatastoreStorage(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/entities');
        $result = invokeSiteRouteHandler('entities.php', $context);
        $entities = $result['data']['data']['entities'];
        $fileEntity = null;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'file') {
                $fileEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($fileEntity);
        // file is the sole entity with storage.enabled true (the implemented type).
        $this->assertSame('datastore', $fileEntity['storage']['type']);
        $this->assertTrue($fileEntity['storage']['enabled']);
        $this->assertSame('uuid', $fileEntity['primaryKey']);
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
