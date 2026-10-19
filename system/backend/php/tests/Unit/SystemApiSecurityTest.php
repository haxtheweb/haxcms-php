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
 *   - validateSystemApiAccess (all spec-derived tiers: public,
 *     site-token-only, authenticated-user, authenticated-site, admin, plus
 *     bearer/basic resolution and HAXiam authorization)
 *
 * The trailing 'Unknown security level' 403 of validateSystemApiAccess is
 * defensive-only: every policy string normalizeSecurityPolicy can produce
 * now has a handler branch, so it is unreachable through the public API.
 * The $sitesDirectory === '' guards in isSiteScopedSystemApiRequest /
 * isDashboardRefererRequest are likewise dead (getSitesDirectory never
 * returns '' for an object HAXCMS: the property must be a non-empty string,
 * else the '_sites' default applies).
 *
 * In getSystemUserTokenPolicyMap, the missing/empty/unparseable-spec early
 * returns, the non-system-path / non-array / empty-route-key loop skips,
 * the operation-without-security path-level inheritance, and the
 * securityRequiresUserToken non-array/empty-requirement branches are all
 * unreachable against the real (valid, all-declared) system-spec.yaml.
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

    public function testEnforceSystemReadUserTokenHeaderFallsBackToActiveUserName(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->haxcms->requestTokenUserName = '';
        $result = SystemApiSecurity::enforceSystemReadUserTokenHeader('v1/sites', 'GET', 'bad');
        $this->assertSame(403, $result['status']);
        // The token was validated against the active user fallback.
        $this->assertSame(array(array('bad', 'testuser')), $this->haxcms->tokenCalls);
    }

    public function testEnforceSystemReadUserTokenHeaderHandlesConcreteSiteRoute(): void
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

    // --- validateSystemApiAccess (spec-derived tiers) ---

    public function testValidateSystemApiAccessAllowsPublicDiscoveryRoute(): void
    {
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1', 'GET');
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
    }

    public function testValidateSystemApiAccessTreatsOptionsAsPublic(): void
    {
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'OPTIONS');
        $this->assertTrue($result['allowed']);
    }

    public function testValidateSystemApiAccessAllowsSiteTokenOnlyAppStoreRoute(): void
    {
        // system-spec.yaml declares siteTokenHeader (no bearerAuth) for the
        // app-store manifest route; no bearer JWT is needed to reach it.
        $result = SystemApiSecurity::validateSystemApiAccess(
            new SecurityTestContext(),
            'v1/integrations/app-store',
            'GET'
        );
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
    }

    public function testValidateSystemApiAccessAllowsAuthenticatedUserRouteWithValidBearer(): void
    {
        $this->haxcms->bearerToken = 'valid-jwt';
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'GET');
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
    }

    public function testValidateSystemApiAccessRejectsInvalidBearerWithRefreshable403(): void
    {
        $this->haxcms->bearerToken = 'expired-jwt';
        $this->haxcms->bearerTokenUserName = '';
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'GET');
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid bearer token', $result['message']);
    }

    public function testValidateSystemApiAccessReturns401WithoutCredentials(): void
    {
        $this->haxcms->basicAuthResult = array('authenticated' => false);
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'GET');
        $this->assertFalse($result['allowed']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('Authentication required', $result['message']);
    }

    public function testValidateSystemApiAccessReturns429ForBlockedBasicAuth(): void
    {
        $this->haxcms->basicAuthResult = array('blocked' => true, 'retryAfterSeconds' => 30);
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'GET');
        $this->assertFalse($result['allowed']);
        $this->assertSame(429, $result['status']);
        $this->assertSame('Too many failed login attempts. Please try again later.', $result['message']);
        $this->assertSame(30, $result['retryAfterSeconds']);
    }

    public function testValidateSystemApiAccessAllowsAuthenticatedSiteTier(): void
    {
        // provider-search declares bearerAuth + siteTokenHeader in the spec;
        // the bearer authenticates and the router owns the site-token check.
        $this->haxcms->bearerToken = 'valid-jwt';
        $result = SystemApiSecurity::validateSystemApiAccess(
            new SecurityTestContext(),
            'v1/integrations/app-store/providers/:provider/search',
            'GET'
        );
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
    }

    public function testValidateSystemApiAccessStillRequiresAuthForAuthenticatedSiteTier(): void
    {
        $this->haxcms->basicAuthResult = array('authenticated' => false);
        $result = SystemApiSecurity::validateSystemApiAccess(
            new SecurityTestContext(),
            'v1/integrations/app-store/providers/:provider/search',
            'GET'
        );
        $this->assertFalse($result['allowed']);
        $this->assertSame(401, $result['status']);
    }

    public function testValidateSystemApiAccessDeniesNonSuperUserOnAdminMutation(): void
    {
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/blocks', 'POST');
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Admin access required', $result['message']);
    }

    public function testValidateSystemApiAccessDeniesNonSuperUserOnElevatedRead(): void
    {
        // API keys expose system-wide credentials -> GET is admin-only too.
        $result = SystemApiSecurity::validateSystemApiAccess(
            new SecurityTestContext(),
            'v1/configuration/api-keys',
            'GET'
        );
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Admin access required', $result['message']);
    }

    public function testValidateSystemApiAccessAllowsSuperUserOnAdminRoute(): void
    {
        $this->haxcms->bearerToken = 'valid-jwt';
        $this->haxcms->bearerTokenUserName = 'admin';
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/blocks', 'POST');
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
    }

    public function testValidateSystemApiAccessHonorsIamDenialOnAdminRoute(): void
    {
        $this->haxcms->bearerToken = 'valid-jwt';
        $this->haxcms->bearerTokenUserName = 'admin';
        $this->haxcms->config->iam = true;
        $this->haxcms->iamAuthorizationResult = array('allowed' => false, 'status' => 403, 'message' => 'IAM denied');
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/blocks', 'POST');
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('IAM denied', $result['message']);
    }

    public function testValidateSystemApiAccessHonorsIamAllowanceOnAdminRoute(): void
    {
        $this->haxcms->bearerToken = 'valid-jwt';
        $this->haxcms->bearerTokenUserName = 'admin';
        $this->haxcms->config->iam = true;
        $this->haxcms->iamAuthorizationResult = array('allowed' => true);
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/blocks', 'POST');
        $this->assertTrue($result['allowed']);
    }

    public function testValidateSystemApiAccessHonorsIamDenialOnAuthenticatedRoute(): void
    {
        $this->haxcms->bearerToken = 'valid-jwt';
        $this->haxcms->config->iam = true;
        $this->haxcms->iamAuthorizationResult = array('allowed' => false, 'status' => 403, 'message' => 'IAM denied');
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'GET');
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('IAM denied', $result['message']);
    }

    public function testValidateSystemApiAccessHonorsIamAllowanceOnAuthenticatedRoute(): void
    {
        $this->haxcms->bearerToken = 'valid-jwt';
        $this->haxcms->config->iam = true;
        $this->haxcms->iamAuthorizationResult = array('allowed' => true);
        $result = SystemApiSecurity::validateSystemApiAccess(new SecurityTestContext(), 'v1/system/version', 'GET');
        $this->assertTrue($result['allowed']);
    }

    // --- validateSystemV1RouteAccess edge shapes ---

    public function testValidateSystemV1RouteAccessUsesDefaultSitesDirectoryWithoutHaxcms(): void
    {
        // With no HAXCMS global the '_sites' default still applies.
        $GLOBALS['HAXCMS'] = null;
        $_SERVER['HTTP_REFERER'] = 'https://host/_sites/mysite/some/page';
        $ctx = new SecurityTestContext(array(), '/_sites/mysite/system/api/v1/sites');
        $this->assertFalse(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/sites'));
    }

    public function testValidateSystemV1RouteAccessTreatsMissingRequestPathAsRootScoped(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://host/_sites/mysite/some/page';
        $this->assertTrue(SystemApiSecurity::validateSystemV1RouteAccess(new stdClass(), 'v1/sites'));
    }

    public function testValidateSystemV1RouteAccessTreatsNonStringRequestPathAsRootScoped(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://host/_sites/mysite/some/page';
        $ctx = new SecurityTestContext(array(), '/_sites/mysite/system/api/v1/sites');
        $ctx->requestPath = null;
        $this->assertTrue(SystemApiSecurity::validateSystemV1RouteAccess($ctx, 'v1/sites'));
    }

    // --- enforceSystemApiUserTokenHeader fallbacks + :param resolution ---

    public function testEnforceSystemApiUserTokenHeaderFallsBackToActiveUserName(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->haxcms->requestTokenUserName = '';
        $ctx = new SecurityTestContext(array('X-HAXCMS-User-Token' => 'bad'));
        $result = SystemApiSecurity::enforceSystemApiUserTokenHeader('v1/sites', 'GET', $ctx);
        $this->assertSame(403, $result['status']);
        // The token was validated against the active user fallback.
        $this->assertSame(array(array('bad', 'testuser')), $this->haxcms->tokenCalls);
    }

    public function testEnforceSystemApiUserTokenHeaderResolvesConcreteRouteFromParams(): void
    {
        // The parameterized name is absent from the policy map, so the :param
        // placeholder resolves to the concrete spec path (vitepress import
        // declares userTokenHeader) before the header check runs.
        $ctx = new SecurityTestContext(array(), '', array('fake' => 'vitepress'));
        $result = SystemApiSecurity::enforceSystemApiUserTokenHeader('v1/site/import/:fake', 'POST', $ctx);
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('X-HAXCMS-User-Token', $result['message']);
    }

    public function testEnforceSystemApiUserTokenHeaderReturnsNullWhenConcreteRouteUnknown(): void
    {
        $ctx = new SecurityTestContext(array(), '', array('fake' => 'no-such-platform'));
        $this->assertNull(SystemApiSecurity::enforceSystemApiUserTokenHeader(
            'v1/site/import/:fake',
            'POST',
            $ctx
        ));
    }

    public function testEnforceSystemApiUserTokenHeaderSkipsResolutionWhenParamsEmpty(): void
    {
        $ctx = new SecurityTestContext(array(), '', array());
        $this->assertNull(SystemApiSecurity::enforceSystemApiUserTokenHeader(
            'v1/site/import/:fake',
            'POST',
            $ctx
        ));
    }

    public function testEnforceSystemApiUserTokenHeaderSkipsResolutionForNonStringParamNames(): void
    {
        $ctx = new SecurityTestContext(array(), '', array(0 => 'vitepress'));
        $this->assertNull(SystemApiSecurity::enforceSystemApiUserTokenHeader(
            'v1/site/import/:fake',
            'POST',
            $ctx
        ));
    }

    // --- enforceProviderSearchSiteToken SiteRouteUtils fallback ---

    public function testProviderSearchTokenValidViaSiteRouteUtilsFallback(): void
    {
        // Fake lacks validateSiteToken -> SiteRouteUtils::validateSiteToken
        // resolves the token user and delegates to validateRequestToken.
        $GLOBALS['HAXCMS'] = new SecurityTestHaxcmsNoSiteTokenMethod();
        $_GET['siteName'] = 'mysite';
        $ctx = new SecurityTestContext(array('X-HAXCMS-Site-Token' => 'client-token'));
        $this->assertNull(SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'GET',
            $ctx
        ));
        $this->assertSame(array(array('client-token', 'testuser:mysite')), $GLOBALS['HAXCMS']->tokenCalls);
    }

    public function testProviderSearchTokenInvalidViaSiteRouteUtilsFallback(): void
    {
        $GLOBALS['HAXCMS'] = new SecurityTestHaxcmsNoSiteTokenMethod();
        $_GET['siteName'] = 'mysite';
        $ctx = new SecurityTestContext(array('X-HAXCMS-Site-Token' => 'bad-token'));
        $result = SystemApiSecurity::enforceProviderSearchSiteToken(
            'v1/integrations/app-store/providers/:provider/search',
            'GET',
            $ctx
        );
        $this->assertIsArray($result);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid X-HAXCMS-Site-Token header', $result['message']);
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
    public function testGetSystemUserTokenPolicyMapIsCached(): void
    {
        $this->assertSame(
            SystemApiSecurity::getSystemUserTokenPolicyMap(),
            SystemApiSecurity::getSystemUserTokenPolicyMap()
        );
    }

    public function testGetSystemUserTokenPolicyMapCoversParameterizedAndPublicKeys(): void
    {
        $map = SystemApiSecurity::getSystemUserTokenPolicyMap();
        // The generic {platform} import path declares userTokenHeader.
        $this->assertArrayHasKey('POST:v1/site/import/:platform', $map);
        $this->assertTrue($map['POST:v1/site/import/:platform']);
        // Discovery + session probe declare empty security -> no user token.
        $this->assertFalse($map['GET:v1']);
        $this->assertFalse($map['GET:v1/session']);
        // OPTIONS never lands in the map (methods are get/post/put/patch/delete).
        $this->assertArrayNotHasKey('OPTIONS:v1/session', $map);
    }
}

/**
 * HAXCMS collaborator mock lacking validateSiteToken so the
 * SiteRouteUtils::validateSiteToken fallback path is exercised.
 */
class SecurityTestHaxcmsNoSiteTokenMethod
{
    public $requestTokenUserName = 'testuser';
    public $tokenCalls = array();

    public function getRequestTokenUserName()
    {
        return $this->requestTokenUserName;
    }

    public function validateRequestToken($token, $value)
    {
        $this->tokenCalls[] = array($token, $value);
        return $token === 'client-token';
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
    public $bearerToken = '';
    public $bearerTokenUserName = 'testuser';
    public $basicAuthResult = array('authenticated' => true, 'userName' => 'testuser');
    public $iamAuthorizationResult = array('allowed' => true);
    public $tokenCalls = array();

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
        $this->tokenCalls[] = array($token, $value);
        return $this->validRequestToken;
    }

    public function getBearerTokenFromRequest()
    {
        return $this->bearerToken;
    }

    public function getBearerTokenUserName($token)
    {
        return $this->bearerTokenUserName;
    }

    public function authenticateBasicAuthorization()
    {
        return $this->basicAuthResult;
    }

    public function validateIAMRouteAuthorization($isSystemRoute = false)
    {
        return $this->iamAuthorizationResult;
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
