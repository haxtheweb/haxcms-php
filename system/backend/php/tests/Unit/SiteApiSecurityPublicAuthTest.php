<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteApiSecurity.php';

/**
 * Public site API routes must resolve an OPTIONAL identity instead of
 * treating every caller as authenticated. The router copies
 * $result['authenticated'] into $context->auth, which is what the D41
 * anonymous-visibility guards (isAnonymousSiteApiRequest) read.
 * Mirrors Node validateSiteApiRouteAccess for policy === 'public'.
 */
class SiteApiSecurityPublicAuthStubHaxcms
{
    public $bearerUser = '';
    public $basicResult = array('attempted' => false, 'authenticated' => false, 'userName' => '');
    public function getBearerTokenUserName()
    {
        return $this->bearerUser;
    }
    public function authenticateBasicAuthorization()
    {
        return $this->basicResult;
    }
}

class SiteApiSecurityPublicAuthTest extends TestCase
{
    private $savedServer = array();

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        putenv('HTTP_AUTHORIZATION');
        $GLOBALS['HAXCMS'] = new SiteApiSecurityPublicAuthStubHaxcms();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        unset($GLOBALS['HAXCMS']);
    }

    private function validatePublicItemsRoute()
    {
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'v1/items');
        return SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'GET');
    }

    public function testPublicRouteWithoutCredentialsIsAnonymous(): void
    {
        $result = $this->validatePublicItemsRoute();
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['authenticated']);
        $this->assertNull($result['userName']);
    }

    public function testPublicRouteWithValidBearerIsAuthenticated(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-token';
        $GLOBALS['HAXCMS']->bearerUser = 'alice';
        $result = $this->validatePublicItemsRoute();
        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame('alice', $result['userName']);
    }

    public function testPublicRouteWithInvalidBearerStaysAnonymousAndAllowed(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired-token';
        $GLOBALS['HAXCMS']->bearerUser = '';
        $result = $this->validatePublicItemsRoute();
        // an invalid token must not block a public read, but it also must not
        // unlock content an anonymous visitor cannot see
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['authenticated']);
    }

    public function testPublicRouteWithValidBasicIsAuthenticated(): void
    {
        $GLOBALS['HAXCMS']->basicResult = array('attempted' => true, 'authenticated' => true, 'userName' => 'bob');
        $result = $this->validatePublicItemsRoute();
        $this->assertTrue($result['authenticated']);
        $this->assertSame('bob', $result['userName']);
    }

    public function testRouterContextReflectsAnonymousPublicRequest(): void
    {
        // Simulate the SiteApiRouter assignment so isAnonymousSiteApiRequest
        // sees the same value handlers will see in production.
        $result = $this->validatePublicItemsRoute();
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'v1/items');
        $context->auth = array(
            'authenticated' => array_key_exists('authenticated', $result) ? ($result['authenticated'] === true) : true,
            'userName' => isset($result['userName']) ? $result['userName'] : '',
        );
        $this->assertTrue(SiteRouteUtils::isAnonymousSiteApiRequest($context));
    }
}
