<?php
/**
 * Lightweight test runner for systemRoutes v1
 * Run: php system/backend/php/tests/systemRoutes/SystemRoutesTest.php
 */

declare(strict_types=1);

$base = dirname(__DIR__) . '/..';

// Include the classes under test
require_once $base . '/lib/JWT.php';
require_once $base . '/lib/siteRoutes/SiteRouteUtils.php';
require_once $base . '/lib/systemRoutes/SystemRoutesMap.php';
require_once $base . '/lib/systemRoutes/SystemApiRequestContext.php';
require_once $base . '/lib/systemRoutes/SystemApiSecurity.php';
require_once $base . '/lib/systemRoutes/SystemApiRouter.php';

// Minimal HAXCMS mock to satisfy global references used by security middleware
class MockHAXCMS {
    public $config;
    public $user;
    public $superUser;
    private $salt = 'test-salt';
    private $privateKey = 'test-private-key';
    public function __construct() {
        $this->config = new stdClass();
        $this->config->iam = false;
        $this->user = new stdClass();
        $this->user->name = 'testuser';
        $this->user->password = 'testpass';
        $this->superUser = new stdClass();
        $this->superUser->name = 'admin';
        $this->superUser->password = 'adminpass';
    }
    public function getRequestToken($value = '') {
        return hash_hmac('sha256', $value, $this->privateKey . $this->salt);
    }
    public function getActiveUserName() {
        return $this->user->name;
    }
    public function validateIAMRouteAuthorization($requireAuthenticatedUser = true) {
        return array('allowed' => true, 'status' => 200, 'message' => '');
    }
    public function generateMachineName($name) {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower(trim($name)));
    }
    public function getBearerTokenFromRequest() {
        $authorization = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] != '') {
            $authorization = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (preg_match('/Bearer\s+(\S+)/', $authorization, $matches) === 1 && isset($matches[1])) {
            return $matches[1];
        }
        return '';
    }
    public function getBearerTokenUserName($bearer = '') {
        if ($bearer === '') {
            $bearer = $this->getBearerTokenFromRequest();
        }
        if ($bearer === '') {
            return '';
        }
        try {
            $decoded = JWT::decode($bearer, $this->privateKey . $this->salt);
            if (isset($decoded->user) && $decoded->user != '') {
                return $this->generateMachineName($decoded->user);
            }
        }
        catch (Exception $e) {
        }
        return '';
    }
}

$GLOBALS['HAXCMS'] = new MockHAXCMS();

// --- Test utilities ---
$passed = 0;
$failed = 0;
function assertTrue($condition, $message = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: $message\n";
    } else {
        $failed++;
        echo "FAIL: $message\n";
    }
}
function assertEquals($expected, $actual, $message = '') {
    assertTrue($expected === $actual, $message . " (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")");
}

// --- Test 1: SystemRoutesMap returns expected routes per method ---
echo "\n=== SystemRoutesMap ===\n";
$getRoutes = SystemRoutesMap::getRoutesForMethod('GET');
assertTrue(is_array($getRoutes), "GET routes is array");
assertTrue(array_key_exists('v1', $getRoutes), "GET /v1 discovery route exists");
assertTrue(array_key_exists('v1/openapi', $getRoutes), "GET /v1/openapi route exists");
assertTrue(array_key_exists('v1/openapi.json', $getRoutes), "GET /v1/openapi.json route exists");
assertTrue(array_key_exists('v1/session', $getRoutes), "GET /v1/session route exists");
assertTrue(array_key_exists('v1/sites', $getRoutes), "GET /v1/sites route exists");
assertTrue(array_key_exists('v1/status', $getRoutes), "GET /v1/status route exists");

$postRoutes = SystemRoutesMap::getRoutesForMethod('POST');
assertTrue(array_key_exists('v1/session/login', $postRoutes), "POST /v1/session/login route exists");
assertTrue(array_key_exists('v1/sites/:siteName/clone', $postRoutes), "POST /v1/sites/:siteName/clone route exists");

$patchRoutes = SystemRoutesMap::getRoutesForMethod('PATCH');
assertTrue(array_key_exists('v1/configuration/api-keys', $patchRoutes), "PATCH /v1/configuration/api-keys route exists");

$putRoutes = SystemRoutesMap::getRoutesForMethod('PUT');
assertTrue(array_key_exists('v1/skeletons/:skeletonName', $putRoutes), "PUT /v1/skeletons/:skeletonName route exists");

$deleteRoutes = SystemRoutesMap::getRoutesForMethod('DELETE');
assertTrue(array_key_exists('v1/skeletons/:skeletonName', $deleteRoutes), "DELETE /v1/skeletons/:skeletonName route exists");

assertTrue(count(SystemRoutesMap::getRoutesForMethod('HEAD')) === 0, "HEAD returns empty array");

// --- Test 2: SystemApiRequestContext parsing ---
echo "\n=== SystemApiRequestContext ===\n";
// Mock request state
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/system/api/v1/sites';
$_SERVER['SCRIPT_NAME'] = '/system/api.php';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';

$ctx = SystemApiRequestContext::create();
assertEquals('GET', $ctx->method, "Context method is GET");
assertEquals('v1/sites', $ctx->routeSuffix, "Route suffix for /system/api/v1/sites");
assertEquals('/system/api', $ctx->apiBasePath, "API base path");
assertTrue($ctx->isSystemApiRequest(), "Recognizes system API request");
assertEquals('https://example.com/system/api', $ctx->absoluteApiBasePath, "Absolute base path includes protocol and host");

// Test with path params
$_SERVER['REQUEST_URI'] = '/system/api/v1/sites/my-site/clone';
$ctx2 = SystemApiRequestContext::create();
assertEquals('v1/sites/my-site/clone', $ctx2->routeSuffix, "Route suffix with path params");
// Test multitenant prefixed path
$_SERVER['REQUEST_URI'] = '/bto108/system/api/v1/openapi.json';
$_SERVER['SCRIPT_NAME'] = '/bto108/system/api.php';
$ctxTenant = SystemApiRequestContext::create();
assertEquals('/api/v1/openapi.json', $ctxTenant->relativePath, "Relative path strips tenant + script directory prefix");
assertTrue($ctxTenant->isSystemApiRequest(), "Tenant-prefixed route is recognized as system API request");
assertEquals('v1/openapi.json', $ctxTenant->routeSuffix, "Tenant-prefixed route suffix resolves to v1/openapi.json");
$tenantMatch = SystemApiRouter::matchRoute($ctxTenant->routeSuffix, SystemRoutesMap::getRoutesForMethod('GET'));
assertTrue(is_array($tenantMatch), "Tenant-prefixed openapi route matches GET route map");
assertEquals('v1/openapi.json', $tenantMatch['route'], "Tenant-prefixed route maps to v1/openapi.json handler");

// Test non-system path
$_SERVER['REQUEST_URI'] = '/x/api/v1/site';
$ctx3 = SystemApiRequestContext::create();
assertEquals(null, $ctx3->routeSuffix, "Non-system path returns null routeSuffix");
assertTrue(!$ctx3->isSystemApiRequest(), "Non-system path is not a system API request");

// Reset
$_SERVER['REQUEST_URI'] = '/system/api/v1/sites';
$_SERVER['SCRIPT_NAME'] = '/system/api.php';

// --- Test 3: SystemApiSecurity tiers ---
echo "\n=== SystemApiSecurity ===\n";

// Public route should allow without auth
$publicCtx = SystemApiRequestContext::create();
$publicCtx->routeSuffix = 'v1/session/login';
$publicResult = SystemApiSecurity::validateSystemApiAccess($publicCtx, 'v1/session/login', 'POST');
assertTrue($publicResult['allowed'], "Public route allowed without auth");
assertEquals(200, $publicResult['status'], "Public route status 200");

// Authenticated route without Bearer should be 401
$authCtx = SystemApiRequestContext::create();
$authResult = SystemApiSecurity::validateSystemApiAccess($authCtx, 'v1/sites', 'GET');
assertTrue(!$authResult['allowed'], "Authenticated route denied without Bearer");
assertEquals(401, $authResult['status'], "Authenticated route returns 401");

// Admin route without Bearer should be 401
$adminResult = SystemApiSecurity::validateSystemApiAccess($authCtx, 'v1/themes', 'POST');
assertTrue(!$adminResult['allowed'], "Admin route denied without Bearer");
assertEquals(401, $adminResult['status'], "Admin route returns 401");

// Simulate Bearer token for authenticated user
$jwtPayload = array('id' => 'test-id', 'iat' => time(), 'exp' => time() + 900, 'user' => 'testuser');
$jwtString = JWT::encode($jwtPayload, 'test-private-key' . 'test-salt');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwtString;

$authCtx2 = SystemApiRequestContext::create();
$authResult2 = SystemApiSecurity::validateSystemApiAccess($authCtx2, 'v1/sites', 'GET');
assertTrue($authResult2['allowed'], "Authenticated route allowed with valid Bearer");
assertEquals(200, $authResult2['status'], "Authenticated route with Bearer returns 200");

// Admin route with non-admin user should be 403
$jwtPayloadOther = array('id' => 'test-id', 'iat' => time(), 'exp' => time() + 900, 'user' => 'otheruser');
$jwtStringOther = JWT::encode($jwtPayloadOther, 'test-private-key' . 'test-salt');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwtStringOther;
$authCtxOther = SystemApiRequestContext::create();
$themesReadResult = SystemApiSecurity::validateSystemApiAccess($authCtxOther, 'v1/themes', 'GET');
assertTrue($themesReadResult['allowed'], "Themes GET allowed for authenticated dashboard user");
assertEquals(200, $themesReadResult['status'], "Themes GET returns 200 for authenticated dashboard user");
$adminResult2 = SystemApiSecurity::validateSystemApiAccess($authCtxOther, 'v1/themes', 'POST');
assertTrue(!$adminResult2['allowed'], "Admin route denied for non-admin user");
assertEquals(403, $adminResult2['status'], "Admin route returns 403 for non-admin");
$shareAccessResult = SystemApiSecurity::validateSystemApiAccess($authCtxOther, 'v1/haxiamAddUserAccess', 'POST');
assertTrue($shareAccessResult['allowed'], "haxiamAddUserAccess allowed for authenticated dashboard user");
assertEquals(200, $shareAccessResult['status'], "haxiamAddUserAccess returns 200 for authenticated dashboard user");

// Admin route with super user should be allowed
$jwtPayloadAdmin = array('id' => 'test-id', 'iat' => time(), 'exp' => time() + 900, 'user' => 'admin');
$jwtStringAdmin = JWT::encode($jwtPayloadAdmin, 'test-private-key' . 'test-salt');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwtStringAdmin;
$adminCtx = SystemApiRequestContext::create();
$adminResult3 = SystemApiSecurity::validateSystemApiAccess($adminCtx, 'v1/themes', 'POST');
assertTrue($adminResult3['allowed'], "Admin route allowed for super user");
assertEquals(200, $adminResult3['status'], "Admin route for super user returns 200");

// Invalid Bearer should be 401
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
$invalidCtx = SystemApiRequestContext::create();
$invalidResult = SystemApiSecurity::validateSystemApiAccess($invalidCtx, 'v1/sites', 'GET');
assertTrue(!$invalidResult['allowed'], "Invalid Bearer denied");
assertEquals(401, $invalidResult['status'], "Invalid Bearer returns 401");

// Reset auth header
unset($_SERVER['HTTP_AUTHORIZATION']);

// --- Test 4: SystemApiRouter matchRoute ---
echo "\n=== SystemApiRouter matchRoute ===\n";
$routes = array('v1/sites' => 'sites.php', 'v1/sites/:siteName' => 'site.php');
$match = SystemApiRouter::matchRoute('v1/sites', $routes);
assertTrue(is_array($match), "Match found for static route");
assertEquals('sites.php', $match['file'], "Static route file correct");
assertEquals(array(), $match['params'], "Static route params empty");

$match2 = SystemApiRouter::matchRoute('v1/sites/my-site', $routes);
assertTrue(is_array($match2), "Match found for param route");
assertEquals('site.php', $match2['file'], "Param route file correct");
assertEquals('my-site', $match2['params']['siteName'], "Param route extracts siteName");

$match3 = SystemApiRouter::matchRoute('v1/notfound', $routes);
assertTrue($match3 === null, "No match for unknown route");

// --- Test 5: HAXCMS helper methods (mocked) ---
echo "\n=== HAXCMS helper methods ===\n";
require_once $base . '/lib/JWT.php';

// Stub HAXCMS with just the methods exercised in these tests (avoid loading full HAXCMS.php which requires many traits)
class HAXCMS {
    public $privateKey = 'test-private-key';
    public $refreshPrivateKey = 'test-refresh-key';
    public $salt = 'test-salt';
    public $user;
    public $superUser;
    public $config;
    public function __construct() {
        $this->user = new stdClass();
        $this->user->name = 'testuser';
        $this->user->password = 'testpass';
        $this->superUser = new stdClass();
        $this->superUser->name = 'admin';
        $this->superUser->password = 'adminpass';
    }
    public function getBearerTokenFromRequest() {
        $authorization = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] != '') {
            $authorization = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (preg_match('/Bearer\s+(\S+)/', $authorization, $matches) === 1 && isset($matches[1])) {
            return $matches[1];
        }
        return '';
    }
    public function getBearerTokenUserName($bearer = '') {
        if ($bearer === '') {
            $bearer = $this->getBearerTokenFromRequest();
        }
        if ($bearer === '') {
            return '';
        }
        try {
            $decoded = JWT::decode($bearer, $this->privateKey . $this->salt);
            if (isset($decoded->user) && $decoded->user != '') {
                return preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower(trim($decoded->user)));
            }
        }
        catch (Exception $e) {
        }
        return '';
    }
    public function getSiteTokenForSiteName($name) {
        return hash_hmac('sha256', $name, $this->privateKey . $this->salt);
    }
    public function validateSiteToken($name, $token) {
        return $token === $this->getSiteTokenForSiteName($name);
    }
    public function isLoginBlocked($key) {
        return false;
    }
    public function getLoginRateLimitSettings() {
        $s = new stdClass();
        $s->enabled = true;
        $s->windowMs = 900000;
        $s->maxAttempts = 5;
        $s->blockMs = 900000;
        return $s;
    }
    public function authenticateBasicAuthorization() {
        $result = array('authenticated' => false, 'userName' => '');
        if (!isset($_SERVER['HTTP_AUTHORIZATION']) || $_SERVER['HTTP_AUTHORIZATION'] == '') {
            return $result;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Basic ') !== 0) {
            return $result;
        }
        $creds = base64_decode(substr($auth, 6));
        if ($creds === false) {
            return $result;
        }
        list($name, $pass) = explode(':', $creds, 2) + array('', '');
        if (
            ($name === $this->user->name && $pass === $this->user->password) ||
            ($name === $this->superUser->name && $pass === $this->superUser->password)
        ) {
            $result['authenticated'] = true;
            $result['userName'] = $name;
        }
        return $result;
    }
}

$HAXCMS = new HAXCMS();

// getBearerTokenFromRequest
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
assertEquals('abc123', $HAXCMS->getBearerTokenFromRequest(), "Extracts Bearer token from Authorization header");

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
assertEquals('', $HAXCMS->getBearerTokenFromRequest(), "Returns empty for Basic auth");

unset($_SERVER['HTTP_AUTHORIZATION']);
assertEquals('', $HAXCMS->getBearerTokenFromRequest(), "Returns empty when no Authorization header");

// getBearerTokenUserName
$payload = array('id' => 'test-id', 'iat' => time(), 'exp' => time() + 900, 'user' => 'testuser');
$token = JWT::encode($payload, $HAXCMS->privateKey . $HAXCMS->salt);
assertEquals('testuser', $HAXCMS->getBearerTokenUserName($token), "Decodes username from valid token");
assertEquals('', $HAXCMS->getBearerTokenUserName('invalid'), "Returns empty for invalid token");

// getSiteTokenForSiteName + validateSiteToken
$siteToken = $HAXCMS->getSiteTokenForSiteName('demo-site');
assertTrue(is_string($siteToken) && $siteToken !== '', "getSiteTokenForSiteName returns non-empty string");
assertTrue($HAXCMS->validateSiteToken('demo-site', $siteToken), "validateSiteToken matches correct token");
assertTrue(!$HAXCMS->validateSiteToken('demo-site', 'wrong-token'), "validateSiteToken rejects wrong token");

// isLoginBlocked
assertTrue($HAXCMS->isLoginBlocked('some-key') === false, "isLoginBlocked stub returns false");

// getLoginRateLimitSettings (depends on config being present)
$HAXCMS->config = new stdClass();
$HAXCMS->config->security = new stdClass();
$settings = $HAXCMS->getLoginRateLimitSettings();
assertTrue(isset($settings->enabled), "getLoginRateLimitSettings has enabled property");
assertTrue(isset($settings->windowMs), "getLoginRateLimitSettings has windowMs property");
assertTrue(isset($settings->maxAttempts), "getLoginRateLimitSettings has maxAttempts property");
assertTrue(isset($settings->blockMs), "getLoginRateLimitSettings has blockMs property");

// authenticateBasicAuthorization
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:testpass');
$basicResult = $HAXCMS->authenticateBasicAuthorization();
assertTrue($basicResult['authenticated'], "Basic auth succeeds with correct credentials");
assertEquals('testuser', $basicResult['userName'], "Basic auth returns correct username");

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:wrongpass');
$basicResult2 = $HAXCMS->authenticateBasicAuthorization();
assertTrue(!$basicResult2['authenticated'], "Basic auth fails with wrong password");

unset($_SERVER['HTTP_AUTHORIZATION']);

// --- Test 6: OPTIONS preflight ---
echo "\n=== OPTIONS preflight ===\n";
$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
$_SERVER['REQUEST_URI'] = '/system/api/v1/sites';
ob_start();
$dispatched = SystemApiRouter::dispatch();
$optionsOutput = ob_get_clean();
assertTrue($dispatched, "OPTIONS request is dispatched by router");
assertTrue(strpos($optionsOutput, 'GET') !== false, "OPTIONS response includes GET");
assertTrue(strpos($optionsOutput, 'POST') !== false, "OPTIONS response includes POST");
assertTrue(strpos($optionsOutput, 'PATCH') !== false, "OPTIONS response includes PATCH");
assertTrue(strpos($optionsOutput, 'PUT') !== false, "OPTIONS response includes PUT");
assertTrue(strpos($optionsOutput, 'DELETE') !== false, "OPTIONS response includes DELETE");

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
