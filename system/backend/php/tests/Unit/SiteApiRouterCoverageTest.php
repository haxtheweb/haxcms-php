<?php
/**
 * Coverage tests for lib/siteRoutes/SiteApiRouter.php and lib/siteRoutes/SiteApiSecurity.php.
 *
 * Unlike the SiteRoutes*Test.php suite (which invokes route handler closures
 * directly), these tests exercise SiteApiRouter::dispatch() end-to-end by
 * shaping $_SERVER so that SiteApiRequestContext resolves a real site API
 * request. http_response_code() works in CLI; header() is a silent no-op.
 */

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

class SiteApiRouterCoverageTest extends PHPUnit\Framework\TestCase
{
    /**
     * @var array
     */
    private $serverBackup;
    /**
     * @var array
     */
    private $getBackup;
    /**
     * @var array
     */
    private $cookieBackup;
    /**
     * @var mixed
     */
    private $haxcmsBackup;
    /**
     * Reflection-built HAXCMS (no constructor) for token / auth helpers.
     */
    private $haxcms;
    /**
     * Per-test temporary cache directory.
     */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->cookieBackup = $_COOKIE;
        $this->haxcmsBackup = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->tmpDir = sys_get_temp_dir() . '/cov-site-api-router-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        $_SERVER = array(
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/x/api/',
        );
        $_GET = array();
        $_COOKIE = array();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_COOKIE = $this->cookieBackup;
        if ($this->haxcmsBackup === null) {
            unset($GLOBALS['HAXCMS']);
        } else {
            $GLOBALS['HAXCMS'] = $this->haxcmsBackup;
        }
        http_response_code(200);
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    /**
     * Reflection HAXCMS instance with JWT/token/privateKey basics + local cache.
     */
    private function makeHaxcms()
    {
        if ($this->haxcms !== null) {
            return $this->haxcms;
        }
        $reflection = new ReflectionClass('HAXCMS');
        $haxcms = $reflection->newInstanceWithoutConstructor();
        $privateKey = new ReflectionProperty('HAXCMS', 'privateKey');
        $privateKey->setAccessible(true);
        $privateKey->setValue($haxcms, 'cov-router-private-key');
        $salt = new ReflectionProperty('HAXCMS', 'salt');
        $salt->setAccessible(true);
        $salt->setValue($haxcms, 'cov-router-salt');
        $haxcms->user = new stdClass();
        $haxcms->user->name = 'admin';
        $haxcms->user->password = password_hash('cov-router-pass', PASSWORD_DEFAULT);
        $haxcms->superUser = new stdClass();
        $haxcms->superUser->name = null;
        $cache = new Cache(array(
            'name' => 'cov-site-api-router',
            'path' => $this->tmpDir . '/',
            'extension' => '.cache',
        ));
        $cacheProperty = new ReflectionProperty('HAXCMS', 'cache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($haxcms, $cache);
        $GLOBALS['HAXCMS'] = $haxcms;
        $this->haxcms = $haxcms;
        return $haxcms;
    }

    /**
     * Run SiteApiRouter::dispatch() against a shaped request and return
     * [result, output buffer, response code, decoded json or null].
     */
    private function runRouter($method, $uri, $server = array(), $site = null)
    {
        $this->makeHaxcms();
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        foreach ($server as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        if ($site === null) {
            $site = new SiteRoutesFakeSite();
        }
        ob_start();
        $result = SiteApiRouter::dispatch($site);
        $out = ob_get_clean();
        $decoded = json_decode($out, true);
        return array($result, $out, http_response_code(), $decoded);
    }

    /**
     * Build a request context without going through dispatch.
     */
    private function makeContext($site, $routeSuffix, $method = 'GET', $apiBasePath = '/x/api')
    {
        return makeSiteRouteContext($site, array(), $routeSuffix, $apiBasePath, true, $method);
    }

    // ------------------------------------------------------------------
    // dispatch(): request gating + handler resolution
    // ------------------------------------------------------------------

    public function testDispatchIgnoresNonSiteApiRequests()
    {
        list($result, $out, $code) = $this->runRouter('GET', '/some/regular/page');
        $this->assertFalse($result);
        $this->assertSame('', $out);
        $this->assertSame(200, $code);
    }

    public function testDispatchRequiresAuthenticationForDiscovery()
    {
        // The '' discovery route is not declared in site-spec.yaml, so it
        // fail-closes to 'authenticated' and never leaks the link map.
        list($result, $out, $code, $data) = $this->runRouter('GET', '/x/api/');
        $this->assertTrue($result);
        $this->assertSame(401, $code);
        $this->assertSame(
            'Authorization bearer token or basic credentials are required for this endpoint',
            $data['data']['message']
        );
    }

    public function testDispatchRunsDiscoveryHandlerForApiRoot()
    {
        $haxcms = $this->makeHaxcms();
        list($result, $out, $code, $data) = $this->runRouter(
            'GET',
            '/x/api/',
            array('HTTP_AUTHORIZATION' => 'Bearer ' . $haxcms->getJWT('admin'))
        );
        $this->assertTrue($result);
        $this->assertSame(200, $code);
        $this->assertSame(200, $data['status']);
        $this->assertSame('HAXcms Site API', $data['data']['name']);
        $this->assertSame('/x/api', $data['data']['links']['self']);
        $this->assertSame('/x/api/v1/site', $data['data']['links']['site']);
    }

    public function testDispatchUsesHaxcmsDomainForAbsoluteApiLinks()
    {
        $haxcms = $this->makeHaxcms();
        $haxcms->domain = 'localhost';
        list($result, $out, $code, $data) = $this->runRouter(
            'GET',
            '/x/api/',
            array('HTTP_AUTHORIZATION' => 'Bearer ' . $haxcms->getJWT('admin'))
        );
        $this->assertTrue($result);
        $this->assertSame('http://localhost/x/api', $data['data']['absoluteLinks']['self']);
    }

    public function testDispatchPrefersForwardedProtoForAbsoluteApiLinks()
    {
        $haxcms = $this->makeHaxcms();
        $haxcms->domain = 'localhost';
        list($result, $out, $code, $data) = $this->runRouter(
            'GET',
            '/x/api/',
            array(
                'HTTP_AUTHORIZATION' => 'Bearer ' . $haxcms->getJWT('admin'),
                'HTTP_X_FORWARDED_PROTO' => 'https',
            )
        );
        $this->assertTrue($result);
        $this->assertSame('https://localhost/x/api', $data['data']['absoluteLinks']['self']);
    }

    public function testDispatchResolvesRelativePathUnderSubdirectoryScript()
    {
        // SCRIPT_NAME points into /sub, so the router must strip the script
        // directory before matching. A bare stdClass site makes the v1/site
        // handler respond with its own 404 which proves the route matched
        // (versus the router-level "Unknown site API route" 404).
        list($result, $out, $code, $data) = $this->runRouter(
            'GET',
            '/sub/x/api/v1/site',
            array('SCRIPT_NAME' => '/sub/index.php'),
            new stdClass()
        );
        $this->assertTrue($result);
        $this->assertSame(404, $code);
        $this->assertSame(
            'Unable to resolve site context for /x/api/v1/site',
            $data['data']['message']
        );
    }

    public function testDispatchRunsSiteHandlerForFakeSite()
    {
        list($result, $out, $code, $data) = $this->runRouter('GET', '/x/api/v1/site');
        $this->assertTrue($result);
        $this->assertSame(200, $code);
        $this->assertSame('testsite', $data['data']['name']);
        $this->assertSame(0, $data['data']['counts']['items']);
        $this->assertSame(0, $data['data']['counts']['files']);
    }

    // ------------------------------------------------------------------
    // dispatch(): method negotiation (OPTIONS / 405 / 404)
    // ------------------------------------------------------------------

    public function testDispatchOptionsListsAllAllowedMethodsForRoute()
    {
        list($result, $out, $code, $data) = $this->runRouter('OPTIONS', '/x/api/v1/site');
        $this->assertTrue($result);
        $this->assertSame(200, $code);
        $this->assertSame(200, $data['status']);
        $this->assertSame(array('GET', 'OPTIONS', 'PATCH'), $data['data']['methods']);
    }

    public function testDispatchOptionsDefaultsToGetForUnknownRoute()
    {
        list($result, $out, $code, $data) = $this->runRouter('OPTIONS', '/x/api/v1/does-not-exist');
        $this->assertTrue($result);
        $this->assertSame(200, $code);
        $this->assertSame(array('GET', 'OPTIONS'), $data['data']['methods']);
    }

    public function testDispatchResponds405WithAllowMethodsForKnownRoute()
    {
        list($result, $out, $code, $data) = $this->runRouter('PUT', '/x/api/v1/site');
        $this->assertTrue($result);
        $this->assertSame(405, $code);
        $this->assertSame(405, $data['status']);
        $this->assertSame('Method not allowed', $data['data']['message']);
        $this->assertSame(array('GET', 'PATCH'), $data['data']['methods']);
        $this->assertSame('v1/site', $data['data']['route']);
    }

    public function testDispatchResponds405WithAllowMethodsForParamRoute()
    {
        list($result, $out, $code, $data) = $this->runRouter('POST', '/x/api/v1/items/some-item');
        $this->assertTrue($result);
        $this->assertSame(405, $code);
        $this->assertSame('Method not allowed', $data['data']['message']);
        $this->assertSame('v1/items/some-item', $data['data']['route']);
        $this->assertNotEmpty($data['data']['methods']);
    }

    public function testDispatchResponds404ForUnknownRoutes()
    {
        list($result, $out, $code, $data) = $this->runRouter('GET', '/x/api/v1/does-not-exist');
        $this->assertTrue($result);
        $this->assertSame(404, $code);
        $this->assertSame(404, $data['status']);
        $this->assertSame('Unknown site API route', $data['data']['message']);
    }

    public function testDispatchResponds405ForEmptySuffixOnMethodWithoutDiscovery()
    {
        // GET has an '' (discovery) route; other methods do not, so PUT on the
        // API root is a 405 advertising GET only (never a bare 404).
        list($result, $out, $code, $data) = $this->runRouter('PUT', '/x/api');
        $this->assertTrue($result);
        $this->assertSame(405, $code);
        $this->assertSame('Method not allowed', $data['data']['message']);
        $this->assertSame(array('GET'), $data['data']['methods']);
        $this->assertSame('', $data['data']['route']);
    }

    // ------------------------------------------------------------------
    // dispatch(): security gate for authenticated routes
    // ------------------------------------------------------------------

    public function testDispatchRequiresAuthenticationForNormalizeSlugs()
    {
        list($result, $out, $code, $data) = $this->runRouter('POST', '/x/api/v1/site/normalize-slugs');
        $this->assertTrue($result);
        $this->assertSame(401, $code);
        $this->assertSame(
            'Authorization bearer token or basic credentials are required for this endpoint',
            $data['data']['message']
        );
    }

    public function testDispatchRejectsInvalidBearerToken()
    {
        list($result, $out, $code, $data) = $this->runRouter(
            'POST',
            '/x/api/v1/site/normalize-slugs',
            array('HTTP_AUTHORIZATION' => 'Bearer not-a-real-token')
        );
        $this->assertTrue($result);
        $this->assertSame(403, $code);
        $this->assertSame('Invalid bearer token', $data['data']['message']);
    }

    public function testDispatchRejectsInvalidBasicCredentials()
    {
        list($result, $out, $code, $data) = $this->runRouter(
            'POST',
            '/x/api/v1/site/normalize-slugs',
            array(
                'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('covu:wrong-password'),
                'REMOTE_ADDR' => '127.0.0.1',
            )
        );
        $this->assertTrue($result);
        $this->assertSame(401, $code);
        $this->assertSame('Invalid basic authorization credentials', $data['data']['message']);
    }

    public function testDispatchRateLimitsRepeatedFailedBasicAuth()
    {
        $haxcms = $this->makeHaxcms();
        $cacheProperty = new ReflectionProperty('HAXCMS', 'cache');
        $cacheProperty->setAccessible(true);
        $cache = $cacheProperty->getValue($haxcms);
        // Pre-store an active block (blockedUntil in the future) for the
        // IP::username attempt key used by HAXCMS::authenticateBasicAuthorization.
        $attemptKey = 'login-rate:' . sha1('127.0.0.1::covu');
        $cache->store(
            $attemptKey,
            array(
                'firstAttempt' => time() * 1000,
                'failedAttempts' => 0,
                'blockedUntil' => (time() + 120) * 1000,
            ),
            99999999
        );
        list($result, $out, $code, $data) = $this->runRouter(
            'POST',
            '/x/api/v1/site/normalize-slugs',
            array(
                'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('covu:wrong-password'),
                'REMOTE_ADDR' => '127.0.0.1',
            )
        );
        $this->assertTrue($result);
        $this->assertSame(429, $code);
        $this->assertSame(429, $data['status']);
        $this->assertStringContainsString(
            'Too many failed login attempts',
            $data['data']['message']
        );
    }

    public function testDispatchNormalizeSlugsHandler404WithoutManifest()
    {
        // Valid bearer + site token get past the authenticated-site policy
        // (site name falls back to $site->name); the handler then 404s on the
        // missing manifest instead of touching Operations/manifest writes.
        $haxcms = $this->makeHaxcms();
        $site = new stdClass();
        $site->name = 'testsite';
        list($result, $out, $code, $data) = $this->runRouter(
            'POST',
            '/x/api/v1/site/normalize-slugs',
            array(
                'HTTP_AUTHORIZATION' => 'Bearer ' . $haxcms->getJWT('admin'),
                'HTTP_X_HAXCMS_SITE_TOKEN' => $haxcms->getRequestToken('admin:testsite'),
            ),
            $site
        );
        $this->assertTrue($result);
        $this->assertSame(404, $code);
        $this->assertSame(
            'Unable to resolve site context for /x/api/v1/site/normalize-slugs',
            $data['data']['message']
        );
    }

    public function testDispatchNormalizeSlugsHandler400WithoutManifestSiteName()
    {
        // Manifest present but manifest->metadata->site->name missing: the
        // handler 400s before its site-token check or any manifest mutation.
        $haxcms = $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        unset($site->manifest->metadata->site->name);
        list($result, $out, $code, $data) = $this->runRouter(
            'POST',
            '/x/api/v1/site/normalize-slugs',
            array(
                'HTTP_AUTHORIZATION' => 'Bearer ' . $haxcms->getJWT('admin'),
                'HTTP_X_HAXCMS_SITE_TOKEN' => $haxcms->getRequestToken('admin:testsite'),
            ),
            $site
        );
        $this->assertTrue($result);
        $this->assertSame(400, $code);
        $this->assertSame(
            'Unable to resolve site name for /x/api/v1/site/normalize-slugs',
            $data['data']['message']
        );
    }

    // ------------------------------------------------------------------
    // matchRoute(): pattern matching
    // ------------------------------------------------------------------

    public function testMatchRouteExactMatch()
    {
        $match = SiteApiRouter::matchRoute('v1/site', array('v1/site' => '/tmp/handler.php'));
        $this->assertNotNull($match);
        $this->assertSame('/tmp/handler.php', $match['file']);
        $this->assertSame('v1/site', $match['route']);
        $this->assertSame(array(), $match['params']);
    }

    public function testMatchRouteCapturesAndUrlDecodesParams()
    {
        $match = SiteApiRouter::matchRoute(
            'v1/items/hello%20world',
            array('v1/items/:idOrSlug' => '/tmp/item.php')
        );
        $this->assertNotNull($match);
        $this->assertSame('v1/items/:idOrSlug', $match['route']);
        $this->assertSame(array('idOrSlug' => 'hello world'), $match['params']);
    }

    public function testMatchRouteReturnsNullWhenPartCountsDiffer()
    {
        $this->assertNull(
            SiteApiRouter::matchRoute(
                'v1/items/one/two',
                array('v1/items/:idOrSlug' => '/tmp/item.php')
            )
        );
    }

    public function testMatchRouteReturnsNullWhenNothingMatches()
    {
        $this->assertNull(
            SiteApiRouter::matchRoute('v1/unknown', array('v1/site' => '/tmp/site.php'))
        );
    }

    public function testMatchRouteHandlesEmptySuffixAgainstEmptyRouteKey()
    {
        $match = SiteApiRouter::matchRoute('', array('' => '/tmp/discovery.php'));
        $this->assertNotNull($match);
        $this->assertSame('/tmp/discovery.php', $match['file']);
        $this->assertSame(array(), $match['params']);
    }

    public function testMatchRouteSkipsPatternsNamingEmptyParams()
    {
        $this->assertNull(
            SiteApiRouter::matchRoute('v1/anything', array('v1/:' => '/tmp/weird.php'))
        );
    }

    public function testMatchRouteTrimsTrailingSlashes()
    {
        $match = SiteApiRouter::matchRoute(
            'v1/site/',
            array('v1/site' => '/tmp/site.php')
        );
        $this->assertNotNull($match);
        $this->assertSame('v1/site', $match['route']);
    }

    // ------------------------------------------------------------------
    // matchSpecialCaseRoutes(): private static fallbacks
    // ------------------------------------------------------------------

    private function invokeSpecialCases($targetRoute, $routes)
    {
        $method = new ReflectionMethod('SiteApiRouter', 'matchSpecialCaseRoutes');
        $method->setAccessible(true);
        return $method->invoke(null, $targetRoute, $routes);
    }

    public function testSpecialCaseCapturesItemExportParams()
    {
        $match = $this->invokeSpecialCases(
            'v1/items/my%20item/export/pdf',
            array('v1/items/:idOrSlug/export/:format' => '/tmp/export.php')
        );
        $this->assertNotNull($match);
        $this->assertSame('/tmp/export.php', $match['file']);
        $this->assertSame('my item', $match['params']['idOrSlug']);
        $this->assertSame('pdf', $match['params']['format']);
    }

    public function testSpecialCaseCapturesRevisionRestoreParams()
    {
        $match = $this->invokeSpecialCases(
            'v1/items/my-item/revisions/42/restore',
            array('v1/items/:idOrSlug/revisions/:revisionId/restore' => '/tmp/restore.php')
        );
        $this->assertNotNull($match);
        $this->assertSame('my-item', $match['params']['idOrSlug']);
        $this->assertSame('42', $match['params']['revisionId']);
    }

    public function testSpecialCaseCapturesRevisionListParams()
    {
        $match = $this->invokeSpecialCases(
            'v1/items/my-item/revisions',
            array('v1/items/:idOrSlug/revisions' => '/tmp/revisions.php')
        );
        $this->assertNotNull($match);
        $this->assertSame('my-item', $match['params']['idOrSlug']);
        $this->assertArrayNotHasKey('revisionId', $match['params']);
    }

    public function testSpecialCaseCapturesContentSlug()
    {
        $match = $this->invokeSpecialCases(
            'v1/content/some%20page',
            array('v1/content/:idOrSlug' => '/tmp/content.php')
        );
        $this->assertNotNull($match);
        $this->assertSame('some page', $match['params']['idOrSlug']);
    }

    public function testSpecialCaseSkipsWhenRouteKeyAbsentFromMap()
    {
        // The special case only applies when its route pattern is a key in
        // the map; otherwise it must not match.
        $this->assertNull(
            $this->invokeSpecialCases(
                'v1/items/my-item/revisions',
                array('v1/items/:idOrSlug' => '/tmp/item.php')
            )
        );
    }

    public function testSpecialCaseSkipsNonMatchingPrefixes()
    {
        $this->assertNull(
            $this->invokeSpecialCases(
                'v1/other/my-item/revisions',
                array('v1/items/:idOrSlug/revisions' => '/tmp/revisions.php')
            )
        );
    }

    // ------------------------------------------------------------------
    // SiteApiSecurity policy checks (direct context calls)
    // ------------------------------------------------------------------

    public function testValidateSiteApiAccessPublicRouteAnonymous()
    {
        $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        $context = $this->makeContext($site, 'v1/site', 'GET');
        $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site', 'GET');
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['authenticated']);
        $this->assertNull($result['userName']);
        $this->assertSame(200, $result['status']);
    }

    public function testValidateSiteApiAccessPublicRouteWithOptionalBearerIdentity()
    {
        $haxcms = $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        $context = $this->makeContext($site, 'v1/site', 'GET');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $haxcms->getJWT('admin');
        $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site', 'GET');
        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame('admin', $result['userName']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testValidateSiteApiAccessAuthenticatedSitePolicySucceeds()
    {
        $haxcms = $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        $context = $this->makeContext($site, 'v1/site/normalize-slugs', 'POST');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $haxcms->getJWT('admin');
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = $haxcms->getRequestToken('admin:testsite');
        $result = SiteApiSecurity::validateSiteApiAccess(
            $context,
            'v1/site/normalize-slugs',
            'POST'
        );
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    public function testValidateSiteApiAccessRequiresSiteTokenHeader()
    {
        $haxcms = $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        $context = $this->makeContext($site, 'v1/site/normalize-slugs', 'POST');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $haxcms->getJWT('admin');
        $result = SiteApiSecurity::validateSiteApiAccess(
            $context,
            'v1/site/normalize-slugs',
            'POST'
        );
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('X-HAXCMS-Site-Token', $result['message']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testValidateSiteApiAccessRejectsInvalidSiteToken()
    {
        $haxcms = $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        $context = $this->makeContext($site, 'v1/site/normalize-slugs', 'POST');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $haxcms->getJWT('admin');
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'definitely-not-the-token';
        $result = SiteApiSecurity::validateSiteApiAccess(
            $context,
            'v1/site/normalize-slugs',
            'POST'
        );
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid X-HAXCMS-Site-Token header', $result['message']);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    public function testValidateSiteApiAccessFailsClosedWithoutSiteName()
    {
        $haxcms = $this->makeHaxcms();
        $context = $this->makeContext(new stdClass(), 'v1/site/normalize-slugs', 'POST');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $haxcms->getJWT('admin');
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'anything';
        $result = SiteApiSecurity::validateSiteApiAccess(
            $context,
            'v1/site/normalize-slugs',
            'POST'
        );
        $this->assertFalse($result['allowed']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Unable to resolve site name', $result['message']);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    public function testValidateSiteApiAccessOptionsPolicyIsPublic()
    {
        $this->makeHaxcms();
        $site = new SiteRoutesFakeSite();
        $context = $this->makeContext($site, 'v1/site/normalize-slugs', 'OPTIONS');
        $result = SiteApiSecurity::validateSiteApiAccess(
            $context,
            'v1/site/normalize-slugs',
            'OPTIONS'
        );
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['authenticated']);
    }
}
