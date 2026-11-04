<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * GET x/api/openapi: ?profile=public returns only operations that need no
 * login (the default stays complete because the editor reads it), the
 * server URL is the site base the request came through, and the spec
 * carries examples for agents.
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

    public function testPublicProfileHasOnlyPublicOperations(): void
    {
        $spec = $this->fetchSpec(false, array('profile' => 'public'));
        $ids = $this->operationIds($spec);
        $this->assertContains('searchContent', $ids);
        $this->assertContains('getItemByIdOrSlug', $ids);
        $this->assertNotContains('createItem', $ids);
        $this->assertNotContains('updateItem', $ids);
        $this->assertNotContains('listFiles', $ids);
        $this->assertSame('public', $spec['x-haxcms-profile']);
        $this->assertStringContainsString('?profile=public', $spec['info']['description']);
        foreach ($spec['paths'] as $pathItem) {
            foreach ($pathItem as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $this->assertTrue(!isset($operation['security']) || SiteRouteUtils::isSecurityUsableAnonymously($operation['security']), $operation['operationId'] . ' should be public');
                }
            }
        }
    }

    /**
     * The editor bootstraps its operation registry from this spec with
     * cookies only, so to the API it looks anonymous. Without ?profile it
     * must always get every operation or editing breaks.
     */
    public function testDefaultSpecIsCompleteForAnonymousAndLoggedInCallers(): void
    {
        $anon = $this->operationIds($this->fetchSpec(false));
        $authed = $this->operationIds($this->fetchSpec(true));
        $this->assertContains('createItem', $anon);
        $this->assertContains('updateItem', $anon);
        $this->assertSame(count($anon), count($authed));
        $this->assertArrayNotHasKey('x-haxcms-profile', $this->fetchSpec(false));
        $this->assertGreaterThan(count($this->operationIds($this->fetchSpec(false, array('profile' => 'public')))), count($anon));
    }

    public function testServerUrlIsTheSiteBase(): void
    {
        $spec = $this->fetchSpec(false, array(), 'https://example.org/sites/biology/x/api');
        $this->assertSame('https://example.org/sites/biology/', $spec['servers'][0]['url']);
    }

    /**
     * haxtheweb/issues#3211: login is optional for search. The editor's
     * registry tries the bearer requirement first and falls back to {}, and
     * the served JSON keeps {} (not []) so it matches Node.
     */
    public function testSearchDeclaresOptionalBearerAuthAndStaysPublic(): void
    {
        $_GET = array();
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'openapi.json', '/x/api', false);
        $handler = include dirname(__DIR__, 2) . '/lib/siteRoutes/discovery/openapi.php';
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        $spec = json_decode($raw);
        $security = $spec->paths->{'/x/api/v1/search'}->get->security;
        $this->assertCount(2, $security);
        $this->assertSame(array(), $security[0]->bearerAuth);
        $this->assertInstanceOf(\stdClass::class, $security[1]);
        $this->assertSame(array(), get_object_vars($security[1]));
        $publicSpec = $this->fetchSpec(false, array('profile' => 'public'));
        $this->assertArrayHasKey('/x/api/v1/search', $publicSpec['paths']);
        $this->assertArrayNotHasKey('/x/api/v1/files', $publicSpec['paths']);
        $this->assertSame('public', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/search', 'GET'));
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
