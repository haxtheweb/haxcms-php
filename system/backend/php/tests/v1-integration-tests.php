<?php
/**
 * HAXcms PHP v1 API Integration Test Runner
 *
 * Run from CLI: php system/backend/php/tests/v1-integration-tests.php
 *
 * This tests the v1 system API infrastructure, connection settings,
 * route maps, discovery shapes, OpenAPI spec loading, and HAXIAM validation
 * without requiring a running HTTP server.
 */

$testDir = dirname(__FILE__);
$repoRoot = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
$testResults = array(
    'passed' => 0,
    'failed' => 0,
    'errors' => array(),
);

function assertTrue($condition, $message) {
    global $testResults;
    if ($condition) {
        $testResults['passed']++;
    } else {
        $testResults['failed']++;
        $testResults['errors'][] = $message;
        echo "FAIL: {$message}\n";
    }
}

function assertEquals($expected, $actual, $message) {
    assertTrue($expected === $actual, $message . " (expected: " . json_encode($expected) . ", got: " . json_encode($actual) . ")");
}

function assertContains($needle, $haystack, $message) {
    assertTrue(strpos($haystack, $needle) !== false, $message . " (needle: {$needle})");
}

function assertJsonValid($json, $message) {
    $decoded = json_decode($json);
    assertTrue($decoded !== null && json_last_error() === JSON_ERROR_NONE, $message . " (json_error: " . json_last_error_msg() . ")");
}

echo "=== HAXcms PHP v1 API Integration Tests ===\n\n";

// Test 1: Bootstrap HAXCMS
echo "[1/12] Bootstrap HAXCMS...\n";
try {
    include_once $repoRoot . '/system/backend/php/bootstrapHAX.php';
    include_once $HAXCMS->configDirectory . '/config.php';
    assertTrue(isset($HAXCMS) && is_object($HAXCMS), 'HAXCMS object exists after bootstrap');
    assertTrue(isset($HAXCMS->basePath), 'HAXCMS basePath is set');
} catch (Exception $e) {
    assertTrue(false, 'Bootstrap exception: ' . $e->getMessage());
}

// Test 2: HAXCMS has v1 connection settings
echo "[2/13] v1 connectionSettings paths...\n";
$settings = $HAXCMS->appJWTConnectionSettings();
assertContains('system/api/v1/session/login', $settings->login, 'login path is v1');
assertContains('system/api/v1/session/logout', $settings->logout, 'logout path is v1');
assertContains('system/api/v1/session/refresh', $settings->refreshUrl, 'refreshUrl path is v1');
assertContains('x/api', $settings->siteApiBasePath, 'siteApiBasePath is present');
assertContains('system/api/v1', $settings->systemApiBasePath, 'systemApiBasePath is present');
assertContains('system/api/v1/openapi.json', $settings->systemOpenApiPath, 'systemOpenApiPath is v1');
assertTrue(isset($settings->appStore) && is_object($settings->appStore), 'appStore settings object exists');
assertContains('system/api/v1/integrations/app-store', $settings->appStore->url, 'appStore url is v1');
assertTrue(isset($settings->appStore->params) && is_object($settings->appStore->params), 'appStore params object exists');
assertTrue(isset($settings->appStore->headers) && is_object($settings->appStore->headers), 'appStore headers object exists');
// Query tokens should be removed from primary paths
assertTrue(strpos($settings->login, '?site_token=') === false, 'login v1 path has no query token');
assertTrue(strpos($settings->logout, '?site_token=') === false, 'logout v1 path has no query token');
assertTrue(strpos($settings->systemApiBasePath, '?user_token=') === false, 'systemApiBasePath has no query token');
$existingReferer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
 $existingRequestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_REFERER'] = 'https://example.com/';
$dashboardRefererSettings = $HAXCMS->appJWTConnectionSettings();
assertContains('system/api/v1/session/login', $dashboardRefererSettings->login, 'dashboard-page login path is v1');
assertContains('system/api/v1/session/logout', $dashboardRefererSettings->logout, 'dashboard-page logout path is v1');
assertTrue(strpos($dashboardRefererSettings->login, '//') !== 0, 'dashboard-page login path does not start with protocol-relative //');
assertTrue(strpos($dashboardRefererSettings->logout, '//') !== 0, 'dashboard-page logout path does not start with protocol-relative //');
if ($HAXCMS->basePath === '/') {
    assertTrue(strpos($dashboardRefererSettings->login, '/') !== 0, 'dashboard-page login path remains base-path relative at root install');
    assertTrue(strpos($dashboardRefererSettings->logout, '/') !== 0, 'dashboard-page logout path remains base-path relative at root install');
    assertTrue(strpos($dashboardRefererSettings->systemApiBasePath, '/') !== 0, 'dashboard-page systemApiBasePath remains base-path relative at root install');
}
$_SERVER['HTTP_REFERER'] = 'https://example.com/sites/demo/';
$dashboardWithSiteReferer = $HAXCMS->appJWTConnectionSettings();
assertContains('system/api/v1/session/login', $dashboardWithSiteReferer->login, 'dashboard-page with site referer login path is v1');
assertContains('system/api/v1/session/logout', $dashboardWithSiteReferer->logout, 'dashboard-page with site referer logout path is v1');
assertTrue(strpos($dashboardWithSiteReferer->login, '//') !== 0, 'dashboard-page with site referer login path does not start with protocol-relative //');
assertTrue(strpos($dashboardWithSiteReferer->logout, '//') !== 0, 'dashboard-page with site referer logout path does not start with protocol-relative //');
if ($HAXCMS->basePath === '/') {
    assertTrue(strpos($dashboardWithSiteReferer->login, '/') !== 0, 'dashboard-page with site referer login path remains base-path relative at root install');
    assertTrue(strpos($dashboardWithSiteReferer->logout, '/') !== 0, 'dashboard-page with site referer logout path remains base-path relative at root install');
    assertTrue(strpos($dashboardWithSiteReferer->systemApiBasePath, '/') !== 0, 'dashboard-page with site referer systemApiBasePath remains base-path relative at root install');
}
$_SERVER['REQUEST_URI'] = '/system/api/v1/session/connection-settings';
$_SERVER['HTTP_REFERER'] = 'https://example.com/sites/demo/';
$siteRefererSettings = $HAXCMS->appJWTConnectionSettings();
assertContains('system/api/v1/session/login', $siteRefererSettings->login, 'site-context login path is v1');
assertContains('system/api/v1/session/logout', $siteRefererSettings->logout, 'site-context logout path is v1');
assertTrue(strpos($siteRefererSettings->login, '//') !== 0, 'site-context login path does not start with protocol-relative //');
assertTrue(strpos($siteRefererSettings->logout, '//') !== 0, 'site-context logout path does not start with protocol-relative //');
if ($HAXCMS->basePath === '/') {
    assertTrue(strpos($siteRefererSettings->login, '/') === 0, 'site-context login path is root-absolute at root install');
    assertTrue(strpos($siteRefererSettings->logout, '/') === 0, 'site-context logout path is root-absolute at root install');
    assertTrue(strpos($siteRefererSettings->systemApiBasePath, '/') === 0, 'site-context systemApiBasePath is root-absolute at root install');
}
if ($existingReferer !== null) {
    $_SERVER['HTTP_REFERER'] = $existingReferer;
}
else {
    unset($_SERVER['HTTP_REFERER']);
}
if ($existingRequestUri !== null) {
    $_SERVER['REQUEST_URI'] = $existingRequestUri;
}
else {
    unset($_SERVER['REQUEST_URI']);
}

// Test 3: SystemApiRouter file existence and route map
echo "[3/13] SystemApiRouter route map...\n";
include_once $repoRoot . '/system/backend/php/lib/systemRoutes/SystemApiRouter.php';
$routes = SystemRoutesMap::getRoutesMap();
assertTrue(isset($routes['GET']) && isset($routes['POST']), 'SystemRoutesMap has GET and POST arrays');
assertTrue(isset($routes['GET']['v1/session/login']), 'GET v1/session/login route exists');
assertTrue(isset($routes['POST']['v1/session/login']), 'POST v1/session/login route exists');
assertTrue(isset($routes['GET']['v1/sites']), 'GET v1/sites route exists');
assertTrue(isset($routes['POST']['v1/sites']), 'POST v1/sites route exists');
assertTrue(isset($routes['GET']['v1/status']), 'GET v1/status route exists');
assertTrue(isset($routes['GET']['v1/configuration/api-keys']), 'GET v1/configuration/api-keys route exists');
assertTrue(isset($routes['GET']['v1/blocks']), 'GET v1/blocks route exists');
assertTrue(isset($routes['GET']['v1/skeletons']), 'GET v1/skeletons route exists');
assertTrue(isset($routes['GET']['v1/themes']), 'GET v1/themes route exists');
assertTrue(isset($routes['POST']['v1/haxiamAddUserAccess']), 'POST v1/haxiamAddUserAccess route exists');

// Test 4: Route pattern matching
echo "[4/13] Route pattern matching...\n";
$ref = new ReflectionClass('SystemApiRouter');
$matchPattern = $ref->getMethod('matchPattern');
$matchPattern->setAccessible(true);
$result = $matchPattern->invoke(null, 'v1/sites/:siteName', 'v1/sites/my-site');
assertTrue(is_array($result) && isset($result['siteName']) && $result['siteName'] === 'my-site', 'Route pattern extracts siteName parameter');
$result = $matchPattern->invoke(null, 'v1/sites/:siteName/clone', 'v1/sites/my-site/clone');
assertTrue(is_array($result) && isset($result['siteName']) && $result['siteName'] === 'my-site', 'Route pattern extracts nested siteName parameter');
$result = $matchPattern->invoke(null, 'v1/skeletons/:skeletonName', 'v1/skeletons/my-skeleton');
assertTrue(is_array($result) && isset($result['skeletonName']) && $result['skeletonName'] === 'my-skeleton', 'Route pattern extracts skeletonName parameter');

// Test 5: SystemApiSecurity route security classification
echo "[5/13] SystemApiSecurity route security...\n";
include_once $repoRoot . '/system/backend/php/lib/systemRoutes/SystemApiSecurity.php';
$refSec = new ReflectionClass('SystemApiSecurity');
$getRouteSecurity = $refSec->getMethod('getRouteSecurity');
$getRouteSecurity->setAccessible(true);
assertEquals('public', $getRouteSecurity->invoke(null, 'v1/session/login', 'POST'), 'session/login is public');
assertEquals('public', $getRouteSecurity->invoke(null, 'v1/session/logout', 'POST'), 'session/logout is public');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/status', 'GET'), 'v1/status is authenticated');
assertEquals('admin', $getRouteSecurity->invoke(null, 'v1/configuration/api-keys', 'GET'), 'v1/configuration/api-keys is admin');
assertEquals('admin', $getRouteSecurity->invoke(null, 'v1/blocks', 'GET'), 'v1/blocks is admin');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites', 'GET'), 'v1/sites is authenticated');
assertEquals('admin', $getRouteSecurity->invoke(null, 'v1/haxiamAddUserAccess', 'POST'), 'haxiamAddUserAccess is admin');

// Test 6: SystemApiSecurity bearer token validation stub (no real token)
echo "[6/13] SystemApiSecurity bearer validation (no token)...\n";
$context = new stdClass();
$context->routeSuffix = 'v1/status';
$context->method = 'GET';
$security = SystemApiSecurity::validateSystemApiAccess($context, 'v1/status', 'GET');
assertTrue(is_array($security) && isset($security['allowed']), 'validateSystemApiAccess returns array with allowed key');
assertTrue($security['allowed'] === false || $security['allowed'] === true, 'validateSystemApiAccess allowed is boolean');
// Without a bearer token, it should return 401 for non-public routes
assertEquals(401, $security['status'], 'Unauthenticated request to admin route returns 401');

// Test 7: HAXIAM authorization validation
echo "[7/13] HAXIAM route authorization...\n";
$iamResult = $HAXCMS->validateIAMRouteAuthorization(false);
assertTrue(is_array($iamResult) && isset($iamResult['allowed']) && isset($iamResult['status']) && isset($iamResult['message']), 'validateIAMRouteAuthorization returns allowed, status, message keys');
assertEquals(true, $iamResult['allowed'], 'IAM authorization allows when config->iam is not set');
assertEquals(200, $iamResult['status'], 'IAM authorization status 200 when config->iam is not set');

// Test 8: OpenAPI spec files exist and are valid YAML/JSON
echo "[8/13] OpenAPI spec files...\n";
$systemSpecPath = $repoRoot . '/system/backend/php/lib/systemRoutes/openapi/system-spec.yaml';
$siteSpecPath = $repoRoot . '/system/backend/php/lib/siteRoutes/openapi/site-spec.yaml';
assertTrue(file_exists($systemSpecPath), 'system-spec.yaml exists');
assertTrue(file_exists($siteSpecPath), 'site-spec.yaml exists');
$systemSpec = file_get_contents($systemSpecPath);
$siteSpec = file_get_contents($siteSpecPath);
assertContains('openapi: 3.0.3', $systemSpec, 'system-spec.yaml is OpenAPI 3.0.3');
assertContains('openapi: 3.0.3', $siteSpec, 'site-spec.yaml is OpenAPI 3.0.3');
assertContains('bearerAuth', $systemSpec, 'system-spec.yaml contains bearerAuth security scheme');
assertContains('bearerAuth', $siteSpec, 'site-spec.yaml contains bearerAuth security scheme');
assertContains('HAXcms System API', $systemSpec, 'system-spec.yaml title matches');
assertContains('HAXcms Site API', $siteSpec, 'site-spec.yaml title matches');

// Test 9: Discovery endpoint files exist and are loadable
echo "[9/13] Discovery endpoint files...\n";
$systemDiscovery = $repoRoot . '/system/backend/php/lib/systemRoutes/discovery/api.php';
$systemOpenapi = $repoRoot . '/system/backend/php/lib/systemRoutes/discovery/openapi.php';
$siteDiscovery = $repoRoot . '/system/backend/php/lib/siteRoutes/discovery/api.php';
$siteOpenapi = $repoRoot . '/system/backend/php/lib/siteRoutes/discovery/openapi.php';
assertTrue(file_exists($systemDiscovery), 'system discovery/api.php exists');
assertTrue(file_exists($systemOpenapi), 'system discovery/openapi.php exists');
assertTrue(file_exists($siteDiscovery), 'site discovery/api.php exists');
assertTrue(file_exists($siteOpenapi), 'site discovery/openapi.php exists');

// Test 10: v1 handler files exist
echo "[10/13] v1 handler files...\n";
$handlers = array(
    'v1/haxiam.php',
    'v1/session.php',
    'v1/lifecycle.php',
    'v1/settings.php',
    'v1/sites.php',
    'v1/integrations.php',
);
foreach ($handlers as $handler) {
    assertTrue(file_exists($repoRoot . '/system/backend/php/lib/systemRoutes/' . $handler), "handler {$handler} exists");
}

// Test 11: System API entry point exists
echo "[11/13] System API entry point...\n";
$entryPoint = $repoRoot . '/system/api/v1/index.php';
assertTrue(file_exists($entryPoint), 'system/api/v1/index.php entry point exists');
$entryContent = file_get_contents($entryPoint);
assertContains('SystemApiRouter', $entryContent, 'entry point includes SystemApiRouter');
assertContains('bootstrapHAX', $entryContent, 'entry point bootstraps HAXCMS');

// Test 12: .htaccess rewrite rule for v1
echo "[12/13] .htaccess rewrite rule...\n";
$htaccess = file_get_contents($repoRoot . '/.htaccess');
assertContains('system/api/v1/(.*)$', $htaccess, '.htaccess contains v1 rewrite rule');
assertContains('system/api/v1/index.php', $htaccess, '.htaccess routes to v1 entry point');
assertContains('HTTP_AUTHORIZATION', $htaccess, '.htaccess exposes Authorization header to PHP');

// Test 13: SystemApiRequestContext supports single/multisite/multitenant prefixed paths
echo "[13/13] SystemApiRequestContext path matrix...\n";
include_once $repoRoot . '/system/backend/php/lib/systemRoutes/SystemApiRequestContext.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$pathMatrix = array(
    array(
        'name' => 'single-site root path',
        'requestUri' => '/system/api/v1/openapi.json',
        'scriptName' => '/system/api.php',
        'expectedApiBase' => '/system/api',
    ),
    array(
        'name' => 'multisite subdirectory path',
        'requestUri' => '/hax/system/api/v1/openapi.json',
        'scriptName' => '/hax/system/api.php',
        'expectedApiBase' => '/hax/system/api',
    ),
    array(
        'name' => 'multitenant user-prefixed path',
        'requestUri' => '/bto108/system/api/v1/openapi.json',
        'scriptName' => '/bto108/system/api.php',
        'expectedApiBase' => '/bto108/system/api',
    ),
);
foreach ($pathMatrix as $case) {
    $_SERVER['REQUEST_URI'] = $case['requestUri'];
    $_SERVER['SCRIPT_NAME'] = $case['scriptName'];
    $ctx = SystemApiRequestContext::create();
    assertTrue($ctx->isSystemApiRequest(), $case['name'] . ' recognized as system API');
    assertEquals('v1/openapi.json', $ctx->routeSuffix, $case['name'] . ' route suffix resolves');
    assertEquals($case['expectedApiBase'], $ctx->apiBasePath, $case['name'] . ' apiBasePath resolves');
}

// Summary
echo "\n=== Results ===\n";
echo "Passed: {$testResults['passed']}\n";
echo "Failed: {$testResults['failed']}\n";

if (count($testResults['errors']) > 0) {
    echo "\nErrors:\n";
    foreach ($testResults['errors'] as $error) {
        echo "  - {$error}\n";
    }
}

exit($testResults['failed'] > 0 ? 1 : 0);
