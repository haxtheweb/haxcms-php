<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the SystemApiSecurity seam.
 *
 * Covers the public methods NOT exercised by the standalone SystemRoutesTest:
 *   - validateSystemV1RouteAccess (referer gate for site-scoped admin routes)
 *   - getSystemReadUserTokenRoutes / isSystemReadUserTokenRoute
 *   - enforceProviderSearchSiteToken (X-HAXCMS-Site-Token on app-store search)
 *   - enforceSystemReadUserTokenHeader (X-HAXCMS-User-Token on canonical reads)
 *   - enforceSystemApiUserTokenHeader (spec-driven X-HAXCMS-User-Token enforcement)
 *   - getSystemUserTokenPolicyMap (spec-derived policy contract)
 *
 * Expected values come from the security contract: the referer-gate spec
 * (site-scoped admin route from a site-scoped referer is blocked), the
 * canonical read-route list, and the OpenAPI system-spec.yaml security
 * declarations (consulted as the independent source of truth, like a worked
 * example — not copied from the implementation). HAXCMS is a mocked
 * collaborator so these tests pin the decision logic, not the token crypto.
 */
class SystemApiSecurityTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $savedGet;
    private $savedReferer;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->haxcms = new SecurityTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->savedGet = $_GET;
        $this->savedReferer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
        unset($_SERVER['HTTP_REFERER']);
        $_GET = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_GET = $this->savedGet;
        if ($this->savedReferer !== null) {
            $_SERVER['HTTP_REFERER'] = $this->savedReferer;
        } else {
            unset($_SERVER['HTTP_REFERER']);
        }
    }

    // --- validateSystemV1RouteAccess ---

    public function testValidateSystemV1RouteAccessAllowsNonAdminRoute(): void
    {
        $ctx = new SecurityTestContext(array(), '/system/api/v1/session/login');
        $this->assertTrue(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/session/login'));
    }

    public function testValidateSystemV1RouteAccessAllowsAdminRouteAtRootPath(): void
    {
        // root system API URL is NOT site-scoped (does not start with /_sites/)
        $ctx = new SecurityTestContext(array(), '/system/api/v1/sites');
        $this->assertTrue(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/sites'));
    }

    public function testValidateSystemV1RouteAccessBlocksSiteScopedAdminFromSiteReferer(): void
    {
        // site-scoped URL + site-scoped referer -> blocked (tenant dashboard must
        // not drive system admin operations)
        $ctx = new SecurityTestContext(array(), '/_sites/mysite/system/api/v1/sites');
        $_SERVER['HTTP_REFERER'] = 'https://host/_sites/mysite/some/page';
        $this->assertFalse(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/sites'));
    }

    public function testValidateSystemV1RouteAccessAllowsSiteScopedAdminFromDashboardReferer(): void
    {
        $ctx = new SecurityTestContext(array(), '/_sites/mysite/system/api/v1/sites');
        $_SERVER['HTTP_REFERER'] = 'https://host/system/dashboard';
        $this->assertTrue(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/sites'));
    }

    public function testValidateSystemV1RouteAccessBlocksSiteScopedAdminWithNoReferer(): void
    {
        // missing referer is NOT a dashboard referer -> blocked
        $ctx = new SecurityTestContext(array(), '/_sites/mysite/system/api/v1/sites');
        $this->assertFalse(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/sites'));
    }

    // --- getSystemReadUserTokenRoutes / isSystemReadUserTokenRoute ---

    public function testGetSystemReadUserTokenRoutesReturnsCanonicalReadMap(): void
    {
        $routes = SystemApiSecurity::getSystemReadUserTokenRoutes();
        $this->assertArrayHasKey('v1/sites', $routes);
        $this->assertSame(['GET'], $routes['v1/sites']);
        $this->assertArrayHasKey('v1/sites/:siteName', $routes);
        $this->assertSame(['GET', 'POST'], $routes['v1/sites/:siteName']);
        $this->assertArrayHasKey('v1/status', $routes);
    }

    public static function readUserTokenRouteProvider(): array
    {
        return [
            'v1/sites GET required' => ['v1/sites', 'GET', true],
            'v1/sites POST not a read-user-token route' => ['v1/sites', 'POST', false],
            'v1/sites/:siteName GET required' => ['v1/sites/:siteName', 'GET', true],
            'v1/sites/:siteName POST required' => ['v1/sites/:siteName', 'POST', true],
            'v1/sites/mysite GET resolved form required' => ['v1/sites/mysite', 'GET', true],
            'v1/session/login POST not required' => ['v1/session/login', 'POST', false],
            'v1/status GET required' => ['v1/status', 'GET', true],
            'v1/status POST required' => ['v1/status', 'POST', true],
            'unknown route not required' => ['v1/totally/fake', 'GET', false],
        ];
    }

    #[DataProvider('readUserTokenRouteProvider')]
    public function testIsSystemReadUserTokenRoute(string $route, string $method, bool $expected): void
    {
        $this->assertSame($expected, SystemApiSecurity::isSystemReadUserTokenRoute($route, $method));
    }

    // --- enforceProviderSearchSiteToken ---

    public function testEnforceProviderSearchSiteTokenSkipsNonGet(): void
    {
        $ctx = new SecurityTestContext(array('X-HAXCMS-Site-Token' => 'tok'));
        $this->assertNull(SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'POST',
            $ctx
        ));
    }

    public function testEnforceProviderSearchSiteTokenSkipsOtherRoutes(): void
    {
        $ctx = new SecurityTestContext(array());
        $this->assertNull(SystemApiSecurity::enforceProviderSearchSiteToken('v1/sites', 'GET', $ctx));
    }

    public function testEnforceProviderSearchSiteTokenRequiresHeader(): void
    {
        $ctx = new SecurityTestContext(array());
        $result = SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'GET',
            $ctx
        );
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('X-HAXCMS-Site-Token', $result['message']);
    }

    public function testEnforceProviderSearchSiteTokenDefersWhenSiteNameMissing(): void
    {
        // header present but no siteName query -> defer to handler siteName validation
        $ctx = new SecurityTestContext(array('X-HAXCMS-Site-Token' => 'tok'));
        $this->assertNull(SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'GET',
            $ctx
        ));
    }

    public function testEnforceProviderSearchSiteTokenAcceptsValidToken(): void
    {
        $this->haxcms->validSiteToken = true;
        $ctx = new SecurityTestContext(array('X-HAXCMS-Site-Token' => 'good'));
        $_GET['siteName'] = 'mysite';
        $this->assertNull(SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'GET',
            $ctx
        ));
    }

    public function testEnforceProviderSearchSiteTokenRejectsInvalidToken(): void
    {
        $this->haxcms->validSiteToken = false;
        $ctx = new SecurityTestContext(array('X-HAXCMS-Site-Token' => 'bad'));
        $_GET['siteName'] = 'mysite';
        $result = SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'GET',
            $ctx
        );
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid X-HAXCMS-Site-Token header', $result['message']);
    }

    // --- enforceSystemReadUserTokenHeader ---

    public function testEnforceSystemReadUserTokenHeaderSkipsNonReadRoute(): void
    {
        $this->assertNull(SystemApiSecurity::enforceSystemReadUserTokenHeader(
            'v1/session/login',
            'POST',
            'anything'
        ));
    }

    public function testEnforceSystemReadUserTokenHeaderRequiresToken(): void
    {
        $result = SystemApiSecurity::enforceSystemReadUserTokenHeader('v1/sites', 'GET', '');
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('X-HAXCMS-User-Token', $result['message']);
    }

    public function testEnforceSystemReadUserTokenHeaderAcceptsValidToken(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->assertNull(SystemApiSecurity::enforceSystemReadUserTokenHeader(
            'v1/sites',
            'GET',
            'good-token'
        ));
    }

    public function testEnforceSystemReadUserTokenHeaderRejectsInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $result = SystemApiSecurity::enforceSystemReadUserTokenHeader('v1/sites', 'GET', 'bad-token');
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid X-HAXCMS-User-Token header', $result['message']);
    }

    // --- enforceSystemApiUserTokenHeader (spec-driven) ---

    public function testEnforceSystemApiUserTokenHeaderSkipsRouteNotRequiringToken(): void
    {
        $ctx = new SecurityTestContext(array());
        $this->assertNull(SystemApiSecurity::enforceSystemApiUserTokenHeader(
            'v1/session/login',
            'POST',
            $ctx
        ));
    }

    public function testEnforceSystemApiUserTokenHeaderRequiresHeader(): void
    {
        $ctx = new SecurityTestContext(array());
        $result = SystemApiSecurity::enforceSystemApiUserTokenHeader('v1/sites', 'GET', $ctx);
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('X-HAXCMS-User-Token', $result['message']);
    }

    public function testEnforceSystemApiUserTokenHeaderAcceptsValidHeader(): void
    {
        $this->haxcms->validRequestToken = true;
        $ctx = new SecurityTestContext(array('X-HAXCMS-User-Token' => 'good'));
        $this->assertNull(SystemApiSecurity::enforceSystemApiUserTokenHeader('v1/sites', 'GET', $ctx));
    }

    public function testEnforceSystemApiUserTokenHeaderRejectsInvalidHeader(): void
    {
        $this->haxcms->validRequestToken = false;
        $ctx = new SecurityTestContext(array('X-HAXCMS-User-Token' => 'bad'));
        $result = SystemApiSecurity::enforceSystemApiUserTokenHeader('v1/sites', 'GET', $ctx);
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid X-HAXCMS-User-Token header', $result['message']);
    }

    public function testEnforceSystemApiUserTokenHeaderAcceptsParameterizedRoute(): void
    {
        $this->haxcms->validRequestToken = true;
        $ctx = new SecurityTestContext(array('X-HAXCMS-User-Token' => 'good'), '', array('siteName' => 'mysite'));
        $this->assertNull(SystemApiSecurity::enforceSystemApiUserTokenHeader(
            'v1/sites/:siteName',
            'GET',
            $ctx
        ));
    }

    // --- getSystemUserTokenPolicyMap (spec contract) ---

    public function testGetSystemUserTokenPolicyMapIsSpecContract(): void
    {
        $map = SystemApiSecurity::getSystemUserTokenPolicyMap();
        $this->assertIsArray($map);
        $this->assertNotEmpty($map);
        // Grounded in system-spec.yaml: these routes declare userTokenHeader.
        $this->assertArrayHasKey('GET:v1/sites', $map);
        $this->assertTrue($map['GET:v1/sites']);
        $this->assertArrayHasKey('GET:v1/sites/:siteName', $map);
        $this->assertTrue($map['GET:v1/sites/:siteName']);
        // public routes do not require the user token.
        if (isset($map['POST:v1/session/login'])) {
            $this->assertFalse($map['POST:v1/session/login']);
        }
    }
}

/**
 * Minimal HAXCMS collaborator mock for the security decision seam.
 * Configurable properties control token-validation returns so tests pin the
 * decision logic (403 on missing/invalid, null on valid) without coupling to
 * the real HMAC token crypto.
 */
class SecurityTestHaxcms
{
    public $sitesDirectory = '_sites';
    public $config;
    public $superUser;
    public $user;
    public $validSiteToken = true;
    public $requestTokenUserName = 'testuser';
    public $activeUserName = 'testuser';
    public $validRequestToken = true;

    public function __construct()
    {
        $this->config = new stdClass();
        $this->config->iam = false;
        $this->superUser = new stdClass();
        $this->superUser->name = 'admin';
        $this->user = new stdClass();
        $this->user->name = 'testuser';
    }

    public function validateSiteToken($siteName, $token)
    {
        return $this->validSiteToken;
    }

    public function getRequestTokenUserName()
    {
        return $this->requestTokenUserName;
    }

    public function getActiveUserName()
    {
        return $this->activeUserName;
    }

    public function validateRequestToken($token, $value)
    {
        return $this->validRequestToken;
    }
}

/**
 * Minimal request-context mock: exposes requestPath, params, and a
 * case-insensitive getHeader() — the surface SystemApiSecurity reads.
 */
class SecurityTestContext
{
    public $requestPath;
    public $params;
    private $headers;

    public function __construct(array $headers = array(), string $requestPath = '', array $params = array())
    {
        $this->headers = $headers;
        $this->requestPath = $requestPath;
        $this->params = $params;
    }

    public function getHeader($name)
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
