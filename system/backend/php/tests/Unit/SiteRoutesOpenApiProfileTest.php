<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * GET x/api/openapi: anonymous callers get the public profile (only
 * operations that need no login), the server URL is the site base the
 * request came through, and the spec carries examples for agents.
 * haxtheweb/issues#3104
 */
class SiteRoutesOpenApiProfileTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
    }

    private function fetchSpec($authenticated, $get = array(), $absoluteApiBasePath = null)
    {
        $_GET = $get;
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'openapi.json', '/x/api', $authenticated);
        if ($absoluteApiBasePath !== null) {
            $context->absoluteApiBasePath = $absoluteApiBasePath;
        }
        $handler = include dirname(__DIR__, 2) . '/lib/siteRoutes/discovery/openapi.php';
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        $spec = json_decode($raw, true);
        $this->assertIsArray($spec, 'spec should be JSON');
        return $spec;
    }

    private function operationIds($spec)
    {
        $ids = array();
        foreach ($spec['paths'] as $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $ids[] = $operation['operationId'];
                }
            }
        }
        return $ids;
    }

    public function testAnonymousGetsOnlyPublicOperations(): void
    {
        $spec = $this->fetchSpec(false);
        $ids = $this->operationIds($spec);
        $this->assertContains('searchContent', $ids);
        $this->assertContains('getItemByIdOrSlug', $ids);
        $this->assertNotContains('createItem', $ids);
        $this->assertNotContains('updateItem', $ids);
        $this->assertNotContains('listFiles', $ids);
        $this->assertSame('public', $spec['x-haxcms-profile']);
        $this->assertStringContainsString('?profile=full', $spec['info']['description']);
        foreach ($spec['paths'] as $pathItem) {
            foreach ($pathItem as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $this->assertTrue(!isset($operation['security']) || $operation['security'] === array(), $operation['operationId'] . ' should be public');
                }
            }
        }
    }

    public function testFullProfileAndLoggedInCallersGetEverything(): void
    {
        $full = $this->operationIds($this->fetchSpec(false, array('profile' => 'full')));
        $authed = $this->operationIds($this->fetchSpec(true));
        $this->assertContains('createItem', $full);
        $this->assertContains('createItem', $authed);
        $this->assertSame(count($full), count($authed));
        $this->assertGreaterThan(count($this->operationIds($this->fetchSpec(false))), count($full));
    }

    public function testServerUrlIsTheSiteBase(): void
    {
        $spec = $this->fetchSpec(false, array(), 'https://example.org/sites/biology/x/api');
        $this->assertSame('https://example.org/sites/biology/', $spec['servers'][0]['url']);
    }

    public function testSpecCarriesExamplesForAgents(): void
    {
        $spec = $this->fetchSpec(false);
        $search = $spec['paths']['/x/api/v1/search']['get']['responses']['200']['content']['application/json'];
        $this->assertSame(200, $search['example']['status']);
        $this->assertSame('photosynthesis', $spec['components']['parameters']['RequiredSearchQuery']['example']);
        $this->assertSame('getting-started', $spec['components']['parameters']['IdOrSlug']['example']);
    }
}
