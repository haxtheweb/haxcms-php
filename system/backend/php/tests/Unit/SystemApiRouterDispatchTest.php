<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for lib/systemRoutes/SystemApiRouter.php (the system API dispatcher).
 *
 * dispatch() is driven end-to-end by shaping $_SERVER so that
 * SystemApiRequestContext::create() (the real constructor) resolves the
 * method / requestPath / relativePath / routeSuffix, and by installing
 * RouterDispatchFakeHaxcms as $GLOBALS['HAXCMS'] for the security gates.
 *
 * Covered via dispatch():
 * - non-system-API request short-circuits to false (lines 11-13)
 * - OPTIONS short-circuit via sendOptionsResponse (lines 14-17, 213-219)
 * - method-not-allowed 405 with Allow header + sorted methods (27-46)
 * - unknown route 404 (47-61)
 * - site-scoped referer gate 403 (71-83) and dashboard-referer pass-through
 * - auth-tier rejections: 401 missing auth / 403 invalid bearer / 429
 *   blocked basic-auth (84-100, incl. Retry-After branch 86-88)
 * - X-HAXCMS-User-Token enforcement 403s (105-118)
 * - full end-to-end handler include + call via the REAL route map
 *   (v1/system/version -> settings.php -> 200 version envelope), which also
 *   exercises matchRoute pattern/params plumbing into the handler context.
 *
 * Lines 137-149 (handler file missing) and 151-163 (handler not callable)
 * are unreachable with the real SystemRoutesMap: every mapped file exists on
 * disk and returns a closure, so the defensive 500s cannot fire. Documented
 * here rather than duplicated via an alternate map.
 *
 * matchRoute()/matchPattern() are additionally covered directly (they are
 * pure static functions) for literal, parameterized, urldecoded, empty, and
 * mismatched shapes.
 */
if (!class_exists('RouterDispatchFakeHaxcms')) {
    class RouterDispatchFakeHaxcms
    {
        public $sitesDirectory = '_sites';
        public $safeGet = array();
        public $superUser;
        public $requestTokenUserName = 'testuser';
        public $tokenCalls = array();
        public $bearerToken = '';
        public $bearerTokenUserName = 'testuser';
        public $basicAuthResult = array('authenticated' => true, 'userName' => 'testuser');
        public function __construct()
        {
            $this->superUser = new stdClass();
            $this->superUser->name = 'admin';
        }
        public function getActiveUserName()
        {
            return 'testuser';
        }
        public function getRequestTokenUserName()
        {
            return $this->requestTokenUserName;
        }
        public function getRequestToken($userName)
        {
            return 'server-token-for-' . $userName;
        }
        public function validateRequestToken($token, $userName)
        {
            $this->tokenCalls[] = array($token, $userName);
            return $token === 'client-token';
        }
        public function getHAXCMSVersion()
        {
            return '0.0.0-test';
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
        public function resolveTrustedHost()
        {
            return 'localhost.test';
        }
    }
}

final class SystemApiRouterDispatchTest extends TestCase
{
    private $haxcmsBackup;
    private $serverBackup;
    private $getBackup;
    private $postBackup;

    protected function setUp(): void
    {
        $this->haxcmsBackup = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $GLOBALS['HAXCMS'] = new RouterDispatchFakeHaxcms();
    }

    protected function tearDown(): void
    {
        $GLOBALS['HAXCMS'] = $this->haxcmsBackup;
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
    }

    /**
     * Shape the superglobals so SystemApiRequestContext::create() builds a
     * real context, then run dispatch() capturing its printed output.
     * SCRIPT_NAME defaults to '/index.php' so dirname('/') strips to an
     * empty script directory and relativePath == requestPath.
     */
    private function runDispatch($uri, $method = 'GET', $serverOverrides = array())
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        foreach ($serverOverrides as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        ob_start();
        $result = SystemApiRouter::dispatch();
        $output = ob_get_clean();
        return array($result, $output);
    }

    public function testDispatchReturnsFalseForNonSystemApiRequest(): void
    {
        list($result, $output) = $this->runDispatch('/sites/mysite/index.html', 'GET');
        $this->assertFalse($result);
        $this->assertSame('', $output);
    }

    public function testDispatchOptionsShortCircuitsWithAllowedMethods(): void
    {
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'OPTIONS');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame(
            array('GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'),
            $decoded['data']['methods']
        );
    }

    public function testDispatchOptionsShortCircuitsEvenForUnknownSuffix(): void
    {
        list($result, $output) = $this->runDispatch('/system/api/v1/no-such-route', 'OPTIONS');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(200, $decoded['status']);
    }

    public function testDispatchReturns405WithSortedAllowedMethods(): void
    {
        list($result, $output) = $this->runDispatch('/system/api/v1/sites', 'DELETE');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(405, $decoded['status']);
        $this->assertSame('Method not allowed', $decoded['data']['message']);
        $this->assertSame('v1/sites', $decoded['data']['route']);
        $this->assertSame(array('GET', 'POST'), $decoded['data']['methods']);
    }

    public function testDispatchHeadMethodReturns405WithAllowedMethods(): void
    {
        // HEAD has no route map entry, so getRoutesForMethod returns an empty
        // map; the suffix still matches GET/POST routes so a 405 is emitted.
        list($result, $output) = $this->runDispatch('/system/api/v1/sites', 'HEAD');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(405, $decoded['status']);
        $this->assertSame(array('GET', 'POST'), $decoded['data']['methods']);
    }

    public function testDispatchReturns404ForUnknownRoute(): void
    {
        list($result, $output) = $this->runDispatch('/system/api/v1/bogus', 'GET');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(404, $decoded['status']);
        $this->assertSame('Unknown system API route', $decoded['data']['message']);
        $this->assertSame('v1/bogus', $decoded['data']['route']);
    }

    public function testDispatchBlocksSiteScopedRefererFromAdminRoute(): void
    {
        // Site-scoped URL (requestPath under /_sites/ via a site router
        // SCRIPT_NAME) whose referer is also site-scoped is blocked from
        // v1 admin routes with the Node-parity 403 message.
        list($result, $output) = $this->runDispatch(
            '/_sites/mysite/system/api/v1/system/version',
            'GET',
            array(
                'SCRIPT_NAME' => '/_sites/mysite/index.php',
                'HTTP_REFERER' => 'https://courses.example.edu/_sites/mysite/x/y',
                'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
            )
        );
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame(
            'system admin route requires system dashboard access',
            $decoded['data']['message']
        );
    }

    public function testDispatchAllowsSiteScopedRequestFromDashboardReferer(): void
    {
        // Same site-scoped URL but a dashboard (non-site-scoped) referer
        // passes the gate and reaches the handler.
        list($result, $output) = $this->runDispatch(
            '/_sites/mysite/system/api/v1/system/version',
            'GET',
            array(
                'SCRIPT_NAME' => '/_sites/mysite/index.php',
                'HTTP_REFERER' => 'https://courses.example.edu/app-dashboard',
                'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
            )
        );
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(200, $decoded['status']);
    }

    public function testDispatchReturns401WhenAuthenticationIsMissing(): void
    {
        $GLOBALS['HAXCMS']->basicAuthResult = array('authenticated' => false);
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'GET');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('Authentication required', $decoded['data']['message']);
    }

    public function testDispatchReturns403ForInvalidBearerToken(): void
    {
        $GLOBALS['HAXCMS']->bearerToken = 'expired-jwt';
        $GLOBALS['HAXCMS']->bearerTokenUserName = '';
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'GET');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Invalid bearer token', $decoded['data']['message']);
    }

    public function testDispatchReturns429WhenBasicAuthIsBlocked(): void
    {
        $GLOBALS['HAXCMS']->basicAuthResult = array('blocked' => true, 'retryAfterSeconds' => 42);
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'GET');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(429, $decoded['status']);
        $this->assertSame(
            'Too many failed login attempts. Please try again later.',
            $decoded['data']['message']
        );
    }

    public function testDispatchReturns403WhenUserTokenHeaderMissing(): void
    {
        // Authenticated principal but no X-HAXCMS-User-Token header on a
        // route that declares userTokenHeader in system-spec.yaml.
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'GET');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame(
            'X-HAXCMS-User-Token header is required for this endpoint',
            $decoded['data']['message']
        );
    }

    public function testDispatchSkeletonReadsStillRequireAuthentication(): void
    {
        $GLOBALS['HAXCMS']->basicAuthResult = array('authenticated' => false);
        foreach (array('GET', 'POST') as $method) {
            list($result, $output) = $this->runDispatch('/system/api/v1/skeletons', $method);
            $this->assertTrue($result);
            $decoded = json_decode($output, true);
            $this->assertSame(401, $decoded['status'], $method);
            $this->assertSame('Authentication required', $decoded['data']['message'], $method);
        }
    }

    public function testDispatchSkeletonReadsRejectInvalidBearer(): void
    {
        $GLOBALS['HAXCMS']->bearerToken = 'expired-jwt';
        $GLOBALS['HAXCMS']->bearerTokenUserName = '';
        list($result, $output) = $this->runDispatch('/system/api/v1/skeletons', 'GET');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Invalid bearer token', $decoded['data']['message']);
    }

    public function testDispatchSkeletonPatchStillRequiresUserTokenHeader(): void
    {
        $GLOBALS['HAXCMS']->bearerToken = 'valid-jwt';
        $GLOBALS['HAXCMS']->bearerTokenUserName = 'admin';
        list($result, $output) = $this->runDispatch('/system/api/v1/skeletons', 'PATCH');
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame(
            'X-HAXCMS-User-Token header is required for this endpoint',
            $decoded['data']['message']
        );
    }

    public function testDispatchReturns403ForInvalidUserTokenHeader(): void
    {
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'GET', array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'wrong-token',
        ));
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Invalid X-HAXCMS-User-Token header', $decoded['data']['message']);
        // The attempted token was validated against the resolved token user.
        $this->assertSame(
            array(array('wrong-token', 'testuser')),
            $GLOBALS['HAXCMS']->tokenCalls
        );
    }

    public function testDispatchReturns403WhenProviderSearchSiteTokenMissing(): void
    {
        // Provider-search GET requires X-HAXCMS-Site-Token (router-level Q14
        // enforcement); the spec declares siteToken-only security for this
        // route so no bearer auth is needed to reach the check.
        list($result, $output) = $this->runDispatch(
            '/system/api/v1/integrations/app-store/providers/nl/search',
            'GET'
        );
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame(
            'X-HAXCMS-Site-Token header is required for this endpoint',
            $decoded['data']['message']
        );
    }

    public function testDispatchReturns403WhenProviderSearchSiteTokenInvalid(): void
    {
        $_GET['siteName'] = 'valid-site';
        list($result, $output) = $this->runDispatch(
            '/system/api/v1/integrations/app-store/providers/nl/search',
            'GET',
            array('HTTP_X_HAXCMS_SITE_TOKEN' => 'bad-token')
        );
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Invalid X-HAXCMS-Site-Token header', $decoded['data']['message']);
        // SiteRouteUtils::validateSiteToken validated against the resolved
        // token user + site name (fake lacks validateSiteToken method).
        $this->assertSame(
            array(array('bad-token', 'testuser:valid-site')),
            $GLOBALS['HAXCMS']->tokenCalls
        );
    }

    public function testDispatchRunsVersionRouteHandlerEndToEnd(): void
    {
        // Real route map -> settings.php handler -> real envelope. Exercises
        // the handler include + call_user_func($handler, $context) path with
        // route params/apiBasePath plumbed through the real context.
        list($result, $output) = $this->runDispatch('/system/api/v1/system/version', 'GET', array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));
        $this->assertTrue($result);
        $decoded = json_decode($output, true);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame('0.0.0-test', $decoded['data']['version']);
    }

    public function testMatchRouteMatchesLiteralPattern(): void
    {
        $match = SystemApiRouter::matchRoute('v1/system/version', array(
            'v1/system/version' => '/tmp/fake-handler.php',
        ));
        $this->assertSame(array(
            'file' => '/tmp/fake-handler.php',
            'params' => array(),
            'route' => 'v1/system/version',
        ), $match);
    }

    public function testMatchRouteTrimsSlashesOnBothSides(): void
    {
        $match = SystemApiRouter::matchRoute('/v1/sites/', array('v1/sites' => '/tmp/fake-handler.php'));
        $this->assertNotNull($match);
        $this->assertSame('v1/sites', $match['route']);
    }

    public function testMatchRouteCapturesUrlDecodedParams(): void
    {
        $match = SystemApiRouter::matchRoute('v1/sites/My%20Site', array(
            'v1/sites/:siteName' => '/tmp/fake-handler.php',
        ));
        $this->assertNotNull($match);
        $this->assertSame(array('siteName' => 'My Site'), $match['params']);
        $this->assertSame('v1/sites/:siteName', $match['route']);
    }

    public function testMatchRouteCapturesParamsFromMultiSegmentPattern(): void
    {
        $match = SystemApiRouter::matchRoute('v1/sites/a/clone', array(
            'v1/sites/:siteName/clone' => '/tmp/fake-handler.php',
        ));
        $this->assertNotNull($match);
        $this->assertSame(array('siteName' => 'a'), $match['params']);
    }

    public function testMatchRouteReturnsNullWhenNoPatternMatches(): void
    {
        $this->assertNull(SystemApiRouter::matchRoute('v1/bogus', array(
            'v1/sites' => '/tmp/fake-handler.php',
        )));
    }

    public function testMatchRouteReturnsNullOnSegmentCountMismatch(): void
    {
        $this->assertNull(SystemApiRouter::matchRoute('v1/sites/a/b', array(
            'v1/sites/:siteName' => '/tmp/fake-handler.php',
        )));
    }

    public function testMatchRouteMatchesEmptySuffixAgainstEmptyPattern(): void
    {
        $match = SystemApiRouter::matchRoute('', array('' => '/tmp/fake-handler.php'));
        $this->assertNotNull($match);
        $this->assertSame(array(), $match['params']);
        $this->assertSame('', $match['route']);
    }

    public function testMatchRouteRejectsPatternWithEmptyParamName(): void
    {
        $this->assertNull(SystemApiRouter::matchRoute('v1/x', array(
            'v1/:' => '/tmp/fake-handler.php',
        )));
    }
}
