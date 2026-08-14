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
$existingAuthHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : null;
$existingRefreshCookie = isset($_COOKIE['haxcms_refresh_token']) ? $_COOKIE['haxcms_refresh_token'] : null;
$existingSessionJwt = isset($HAXCMS->sessionJwt) ? $HAXCMS->sessionJwt : null;
$existingIamSetting = isset($HAXCMS->config->iam) ? $HAXCMS->config->iam : null;
$existingUserName = isset($HAXCMS->user->name) ? $HAXCMS->user->name : null;
$existingUserPassword = isset($HAXCMS->user->password) ? $HAXCMS->user->password : null;
$existingServerSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_REFERER'] = 'https://example.com/';
$dashboardRefererSettings = $HAXCMS->appJWTConnectionSettings();
assertContains('system/api/v1/session/login', $dashboardRefererSettings->login, 'dashboard-page login path is v1');
assertContains('system/api/v1/session/logout', $dashboardRefererSettings->logout, 'dashboard-page logout path is v1');
assertTrue(strpos($dashboardRefererSettings->login, '//') !== 0, 'dashboard-page login path does not start with protocol-relative //');
assertTrue(strpos($dashboardRefererSettings->logout, '//') !== 0, 'dashboard-page logout path does not start with protocol-relative //');
// Regression: dashboard-page system API paths must be root-absolute at a root
// install so the browser never resolves them relative to the current page URL.
// A missing leading slash was the original HAXiam reload-loop bug — the relative
// path was resolved against /sites/{siteName}/... and 404'd as HTML, failing
// JSON parsing and looping auth rehydration. This matches the site-context
// expectation below (lines 113-117) so dashboard and site requests are
// consistent at the server root.
if ($HAXCMS->basePath === '/') {
    assertTrue(strpos($dashboardRefererSettings->login, '/') === 0, 'dashboard-page login path is root-absolute at root install');
    assertTrue(strpos($dashboardRefererSettings->logout, '/') === 0, 'dashboard-page logout path is root-absolute at root install');
    assertTrue(strpos($dashboardRefererSettings->systemApiBasePath, '/') === 0, 'dashboard-page systemApiBasePath is root-absolute at root install');
}
$_SERVER['HTTP_REFERER'] = 'https://example.com/sites/demo/';
$dashboardWithSiteReferer = $HAXCMS->appJWTConnectionSettings();
assertContains('system/api/v1/session/login', $dashboardWithSiteReferer->login, 'dashboard-page with site referer login path is v1');
assertContains('system/api/v1/session/logout', $dashboardWithSiteReferer->logout, 'dashboard-page with site referer logout path is v1');
assertTrue(strpos($dashboardWithSiteReferer->login, '//') !== 0, 'dashboard-page with site referer login path does not start with protocol-relative //');
assertTrue(strpos($dashboardWithSiteReferer->logout, '//') !== 0, 'dashboard-page with site referer logout path does not start with protocol-relative //');
if ($HAXCMS->basePath === '/') {
    assertTrue(strpos($dashboardWithSiteReferer->login, '/') === 0, 'dashboard-page with site referer login path is root-absolute at root install');
    assertTrue(strpos($dashboardWithSiteReferer->logout, '/') === 0, 'dashboard-page with site referer logout path is root-absolute at root install');
    assertTrue(strpos($dashboardWithSiteReferer->systemApiBasePath, '/') === 0, 'dashboard-page with site referer systemApiBasePath is root-absolute at root install');
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
// HAXiam subdirectory-install regression: simulates a HAXiam tenant install
// where the whole HAXcms lives under a subdirectory (e.g. /bto108/) and IAM
// mode is active, mirroring the production bootstrapHAX.php conditions that
// set config->iam = true and drive getDeploymentProfile() to 'haxiam-managed'.
// This is the exact scenario that produced the original reload loop: the
// dashboard at https://host/bto108/ must emit system API paths prefixed with
// /bto108/, not rooted at the server root, or the frontend fetches 404 against
// HTML and JSON-parsing throws, looping the auth rehydration.
$existingBasePath = $HAXCMS->basePath;
$existingDeploymentProfile = isset($HAXCMS->config->deploymentProfile) ? $HAXCMS->config->deploymentProfile : null;
$HAXCMS->basePath = '/bto108/';
$HAXCMS->config->deploymentProfile = 'haxiam-managed';
$_SERVER['REQUEST_URI'] = '/bto108/';
$_SERVER['HTTP_REFERER'] = 'https://example.com/bto108/';
$iamDashboardSettings = $HAXCMS->appJWTConnectionSettings();
assertEquals('/bto108/system/api/v1', $iamDashboardSettings->systemApiBasePath, 'HAXiam dashboard systemApiBasePath is tenant-prefixed and root-absolute');
assertEquals('/bto108/system/api/v1/openapi.json', $iamDashboardSettings->systemOpenApiPath, 'HAXiam dashboard systemOpenApiPath is tenant-prefixed and root-absolute');
assertContains('/bto108/system/api/v1/session/login', $iamDashboardSettings->login, 'HAXiam dashboard login path is tenant-prefixed');
assertContains('/bto108/system/api/v1/session/logout', $iamDashboardSettings->logout, 'HAXiam dashboard logout path is tenant-prefixed');
assertContains('/bto108/system/api/v1/session/connection-test', $iamDashboardSettings->connectionTest, 'HAXiam dashboard connectionTest path is tenant-prefixed');
assertContains('/bto108/system/api/v1/session/user', $iamDashboardSettings->getUserDataPath, 'HAXiam dashboard getUserDataPath is tenant-prefixed');
assertTrue(strpos($iamDashboardSettings->systemApiBasePath, '//') !== 0, 'HAXiam dashboard systemApiBasePath does not start with protocol-relative //');
assertTrue(strpos($iamDashboardSettings->systemOpenApiPath, '//') !== 0, 'HAXiam dashboard systemOpenApiPath does not start with protocol-relative //');
// Never resolve relative to the current page URL — must start with the tenant base.
assertTrue(strpos($iamDashboardSettings->systemApiBasePath, '/bto108/') === 0, 'HAXiam dashboard systemApiBasePath starts with tenant base path');
assertTrue(strpos($iamDashboardSettings->login, '/bto108/') === 0, 'HAXiam dashboard login path starts with tenant base path');
// Same check from a site-context referer under the tenant install.
$_SERVER['REQUEST_URI'] = '/bto108/system/api/v1/session/connection-settings';
$_SERVER['HTTP_REFERER'] = 'https://example.com/bto108/sites/demo/';
$iamSiteRefererSettings = $HAXCMS->appJWTConnectionSettings();
assertEquals('/bto108/system/api/v1', $iamSiteRefererSettings->systemApiBasePath, 'HAXiam site-context systemApiBasePath stays tenant-prefixed (no sites/ leak)');
assertContains('/bto108/system/api/v1/session/login', $iamSiteRefererSettings->login, 'HAXiam site-context login path stays tenant-prefixed');
assertTrue(strpos($iamSiteRefererSettings->systemApiBasePath, '/sites/') === false, 'HAXiam site-context systemApiBasePath has no sites/ segment');
assertTrue(strpos($iamSiteRefererSettings->systemApiBasePath, '/_sites/') === false, 'HAXiam site-context systemApiBasePath has no _sites/ segment');
// Restore basePath + deploymentProfile so the IAM block below runs against the
// real (bootstrapped) install state, not the simulated tenant subdirectory.
$HAXCMS->basePath = $existingBasePath;
if ($existingDeploymentProfile !== null) {
    $HAXCMS->config->deploymentProfile = $existingDeploymentProfile;
}
else {
    unset($HAXCMS->config->deploymentProfile);
}
$HAXCMS->config->iam = true;
$HAXCMS->user->name = null;
$HAXCMS->user->password = null;
$_SERVER['SERVER_SOFTWARE'] = 'TestServer';
$HAXCMS->addEventListener('haxcms-validate-user', function (&$usr) {
    if (isset($usr->name) && $usr->name === 'tenant-user') {
        $usr->grantAccess = true;
    }
});
$tenantJwt = $HAXCMS->getJWT('tenant-user');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tenantJwt;
$_COOKIE['haxcms_refresh_token'] = $HAXCMS->getRefreshToken('tenant-user');
$HAXCMS->sessionJwt = $tenantJwt;
$_SERVER['REQUEST_URI'] = '/tenant-user/system/api/v1/sites';
$_SERVER['HTTP_REFERER'] = 'https://example.com/tenant-user/';
$iamActiveUser = $HAXCMS->getActiveUserName();
// Current IAM token-binding model: getActiveUserName() resolves to the
// authenticated principal (tenant-user), so a token minted for the active
// user validates. The meaningful mismatch test is a token minted for a
// DIFFERENT tenant, which must NOT validate against the active user.
$otherTenantToken = $HAXCMS->getRequestToken('some-other-tenant');
assertTrue(
    !$HAXCMS->validateRequestToken($otherTenantToken, $iamActiveUser),
    'IAM token mismatch: token minted for a different tenant is invalid for the active user'
);
$requestTokenUser = $HAXCMS->getRequestTokenUserName();
$requestScopedToken = $HAXCMS->getRequestToken($requestTokenUser);
assertTrue(
    $HAXCMS->validateRequestToken($requestScopedToken, $iamActiveUser),
    'IAM token match: request-token user token validates for legacy active-user value'
);
// v1/sites GET is a canonical system READ route (SystemApiSecurity::
// isSystemReadUserTokenRoute) so the lifecycle handler feeds the client
// X-HAXCMS-User-Token header into params['user_token'] for the listSites
// validateRequestToken check. Supply the request-scoped token on the header
// to mirror a real authenticated dashboard request.
$existingUserTokenHeader = isset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']) ? $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] : null;
$_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $requestScopedToken;
$lifecycleHandler = include $repoRoot . '/system/backend/php/lib/systemRoutes/v1/lifecycle.php';
$lifecycleContext = new stdClass();
$lifecycleContext->apiBasePath = '/tenant-user/system/api';
$lifecycleContext->body = array();
$lifecycleContext->params = array();
$lifecycleContext->routeSuffix = 'v1/sites';
$lifecycleContext->method = 'GET';
ob_start();
$lifecycleHandler($lifecycleContext);
$iamSitesResponseRaw = ob_get_clean();
$iamSitesResponse = json_decode($iamSitesResponseRaw, true);
assertTrue(is_array($iamSitesResponse), 'IAM lifecycle listSites returns JSON');
assertEquals(
    200,
    isset($iamSitesResponse['status']) ? $iamSitesResponse['status'] : null,
    'IAM lifecycle listSites returns 200 with request-scoped token user'
);
$HAXCMS->addEventListener('haxcms-validate-user', false);
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
if ($existingAuthHeader !== null) {
    $_SERVER['HTTP_AUTHORIZATION'] = $existingAuthHeader;
}
else {
    unset($_SERVER['HTTP_AUTHORIZATION']);
}
if ($existingUserTokenHeader !== null) {
    $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $existingUserTokenHeader;
}
else {
    unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']);
}
if ($existingRefreshCookie !== null) {
    $_COOKIE['haxcms_refresh_token'] = $existingRefreshCookie;
}
else {
    unset($_COOKIE['haxcms_refresh_token']);
}
if ($existingSessionJwt !== null) {
    $HAXCMS->sessionJwt = $existingSessionJwt;
}
else {
    $HAXCMS->sessionJwt = null;
}
if ($existingIamSetting !== null) {
    $HAXCMS->config->iam = $existingIamSetting;
}
else {
    unset($HAXCMS->config->iam);
}
$HAXCMS->user->name = $existingUserName;
$HAXCMS->user->password = $existingUserPassword;
if ($existingServerSoftware !== null) {
    $_SERVER['SERVER_SOFTWARE'] = $existingServerSoftware;
}
else {
    unset($_SERVER['SERVER_SOFTWARE']);
}

// Test 3: SystemApiRouter file existence and route map
echo "[3/13] SystemApiRouter route map...\n";
include_once $repoRoot . '/system/backend/php/lib/systemRoutes/SystemApiRouter.php';
$routes = SystemRoutesMap::getRoutesMap();
assertTrue(isset($routes['GET']) && isset($routes['POST']), 'SystemRoutesMap has GET and POST arrays');
// v1/session/login is POST-only (credentials are submitted, never GET-exposed);
// GET session routes are v1/session, v1/session/refresh, v1/session/user, etc.
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
// GET on admin routes stays at the spec-driven base policy (authenticated)
// since system-spec.yaml declares bearerAuth for dashboard reads. Non-GET
// methods of admin routes elevate to 'admin' (see v1/themes + v1/skeletons
// POST assertions below).
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/configuration/api-keys', 'GET'), 'v1/configuration/api-keys GET is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/blocks', 'GET'), 'v1/blocks GET is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites', 'GET'), 'v1/sites is authenticated');
// Site-lifecycle mutations must NOT elevate to 'admin' — they fall through to
// the spec-driven authenticated policy so the HAXiam tenant principal (never
// the superUser) can create/clone/archive/download/save-as-template. The
// superUser-elevation set (getSystemV1SuperUserRoutes) excludes these routes.
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites', 'POST'), 'v1/sites POST is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites/:siteName/clone', 'POST'), 'v1/sites/:siteName/clone POST is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites/:siteName/archive', 'POST'), 'v1/sites/:siteName/archive POST is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites/:siteName/download-skeleton', 'POST'), 'v1/sites/:siteName/download-skeleton POST is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/sites/:siteName/save-as-template', 'POST'), 'v1/sites/:siteName/save-as-template POST is authenticated');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/themes', 'GET'), 'v1/themes GET is authenticated');
assertEquals('admin', $getRouteSecurity->invoke(null, 'v1/themes', 'POST'), 'v1/themes POST is admin');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/skeletons', 'GET'), 'v1/skeletons GET is authenticated');
assertEquals('admin', $getRouteSecurity->invoke(null, 'v1/skeletons', 'POST'), 'v1/skeletons POST is admin');
assertEquals('authenticated', $getRouteSecurity->invoke(null, 'v1/haxiamAddUserAccess', 'POST'), 'haxiamAddUserAccess is authenticated');

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
// v1/sites.php was consolidated into v1/lifecycle.php (the route map points
// v1/sites and v1/sites/:siteName at lifecycle.php), so it is intentionally
// absent from this list.
$handlers = array(
    'v1/haxiam.php',
    'v1/session.php',
    'v1/lifecycle.php',
    'v1/settings.php',
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
// SystemApiRequestContext::getApiBasePathFromRequestPath matches through
// the /v1 segment, so apiBasePath includes /v1 (confirmed by
// SystemRoutesTest.php which expects /system/api/v1). routeSuffix still
// strips down to v1/openapi.json.
$pathMatrix = array(
    array(
        'name' => 'single-site root path',
        'requestUri' => '/system/api/v1/openapi.json',
        'scriptName' => '/system/api.php',
        'expectedApiBase' => '/system/api/v1',
    ),
    array(
        'name' => 'multisite subdirectory path',
        'requestUri' => '/hax/system/api/v1/openapi.json',
        'scriptName' => '/hax/system/api.php',
        'expectedApiBase' => '/hax/system/api/v1',
    ),
    array(
        'name' => 'multitenant user-prefixed path',
        'requestUri' => '/bto108/system/api/v1/openapi.json',
        'scriptName' => '/bto108/system/api.php',
        'expectedApiBase' => '/bto108/system/api/v1',
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

// Test 14: F2/IDOR-001 — site-lifecycle object-level authorization (IDOR fix)
echo "\n[14] F2/IDOR-001 site-lifecycle IDOR guard...\n";
// Save state to restore after the IDOR assertions.
$idorSavedIam = isset($HAXCMS->config->iam) ? $HAXCMS->config->iam : null;
$idorSavedUserTokenHeader = isset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']) ? $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] : null;

// --- Non-IAM (self-hosted) mode assertions ---
$HAXCMS->config->iam = false;
// Find an existing site in the test environment sites directory.
$idorSitesDir = HAXCMS_ROOT . '/' . $HAXCMS->sitesDirectory;
$idorExistingSite = null;
if (is_dir($idorSitesDir) && ($idorHandle = opendir($idorSitesDir))) {
    while (false !== ($idorItem = readdir($idorHandle))) {
        if ($idorItem != '.' && $idorItem != '..' && is_dir($idorSitesDir . '/' . $idorItem) && file_exists($idorSitesDir . '/' . $idorItem . '/site.json')) {
            $idorExistingSite = $idorItem;
            break;
        }
    }
    closedir($idorHandle);
}
assertTrue(is_string($idorExistingSite) && $idorExistingSite !== '', 'IDOR test: test environment has at least one existing site');
if (is_string($idorExistingSite) && $idorExistingSite !== '') {
    assertTrue($HAXCMS->userCanAccessSite($idorExistingSite), 'IDOR non-IAM: userCanAccessSite returns true for existing site');
    assertTrue(!$HAXCMS->userCanAccessSite('nonexistent-idor-test-site-xyz'), 'IDOR non-IAM: userCanAccessSite returns false for non-existent site');
}
assertTrue(!$HAXCMS->userCanAccessSite(''), 'IDOR: userCanAccessSite returns false for empty site name');

// --- IAM (multi-tenant) mode assertions ---
// In the test environment HAXCMS_ROOT is not user-scoped (no /users/{user}/
// segment) and /var/www/sites/{user}/sites/{site} does not exist, so an
// existing site must be denied to the active user — this is the cross-tenant
// IDOR scenario the fix prevents.
$HAXCMS->config->iam = true;
$idorIamActiveUser = $HAXCMS->getActiveUserName();
assertTrue(is_string($idorIamActiveUser) && $idorIamActiveUser !== '', 'IDOR IAM: active user name resolves');
if (is_string($idorExistingSite) && $idorExistingSite !== '' && is_string($idorIamActiveUser) && $idorIamActiveUser !== '') {
    assertTrue(!$HAXCMS->userCanAccessSite($idorExistingSite), 'IDOR IAM: userCanAccessSite denies access to site not in user directory (cross-tenant blocked)');
}
// Restore IAM setting so subsequent assertions run in the real bootstrapped mode.
if ($idorSavedIam !== null) {
    $HAXCMS->config->iam = $idorSavedIam;
} else {
    unset($HAXCMS->config->iam);
}

// --- Lifecycle route-layer guard: clone route returns 403 for inaccessible site ---
$idorTokenUser = $HAXCMS->getRequestTokenUserName();
if (!is_string($idorTokenUser) || $idorTokenUser === '') {
    $idorTokenUser = $HAXCMS->getActiveUserName();
}
$idorServerToken = $HAXCMS->getRequestToken($idorTokenUser);
$_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $idorServerToken;
$idorLifecycleHandler = include $repoRoot . '/system/backend/php/lib/systemRoutes/v1/lifecycle.php';
$idorCloneContext = new stdClass();
$idorCloneContext->apiBasePath = '/system/api';
$idorCloneContext->body = array();
$idorCloneContext->params = array('siteName' => 'nonexistent-idor-test-site-xyz');
$idorCloneContext->routeSuffix = 'v1/sites/:siteName/clone';
$idorCloneContext->method = 'POST';
ob_start();
$idorLifecycleHandler($idorCloneContext);
$idorCloneResponseRaw = ob_get_clean();
$idorCloneResponse = json_decode($idorCloneResponseRaw, true);
assertTrue(is_array($idorCloneResponse), 'IDOR lifecycle clone route returns JSON');
assertEquals(
    403,
    isset($idorCloneResponse['status']) ? $idorCloneResponse['status'] : null,
    'IDOR lifecycle clone route returns 403 for inaccessible site (route-layer guard)'
);

// --- Defense-in-depth: each of the 5 handlers returns 403 __failed for inaccessible site ---
$idorOps = new Operations();
$idorOps->params = array(
    'user_token' => $idorServerToken,
    'site' => array('name' => 'nonexistent-idor-test-site-xyz'),
);
$idorOps->rawParams = $idorOps->params;

$idorCloneHandlerResult = $idorOps->cloneSite();
assertTrue(
    is_array($idorCloneHandlerResult) && isset($idorCloneHandlerResult['__failed']) && isset($idorCloneHandlerResult['__failed']['status']) && intval($idorCloneHandlerResult['__failed']['status']) === 403,
    'IDOR defense-in-depth: cloneSite handler returns 403 __failed for inaccessible site'
);

$idorDownloadResult = $idorOps->downloadSite();
assertTrue(
    is_array($idorDownloadResult) && isset($idorDownloadResult['__failed']) && isset($idorDownloadResult['__failed']['status']) && intval($idorDownloadResult['__failed']['status']) === 403,
    'IDOR defense-in-depth: downloadSite handler returns 403 __failed for inaccessible site'
);

$idorArchiveResult = $idorOps->archiveSite();
assertTrue(
    is_array($idorArchiveResult) && isset($idorArchiveResult['__failed']) && isset($idorArchiveResult['__failed']['status']) && intval($idorArchiveResult['__failed']['status']) === 403,
    'IDOR defense-in-depth: archiveSite handler returns 403 __failed for inaccessible site'
);

$idorOps2 = new Operations();
$idorOps2->params = array(
    'user_token' => $idorServerToken,
    'site' => array('name' => 'nonexistent-idor-test-site-xyz'),
);
$idorOps2->rawParams = $idorOps2->params;

$idorSaveTemplateResult = $idorOps2->saveSiteAsTemplate();
assertTrue(
    is_array($idorSaveTemplateResult) && isset($idorSaveTemplateResult['__failed']) && isset($idorSaveTemplateResult['__failed']['status']) && intval($idorSaveTemplateResult['__failed']['status']) === 403,
    'IDOR defense-in-depth: saveSiteAsTemplate handler returns 403 __failed for inaccessible site'
);

$idorDownloadSkeletonResult = $idorOps2->downloadSiteSkeleton();
assertTrue(
    is_array($idorDownloadSkeletonResult) && isset($idorDownloadSkeletonResult['__failed']) && isset($idorDownloadSkeletonResult['__failed']['status']) && intval($idorDownloadSkeletonResult['__failed']['status']) === 403,
    'IDOR defense-in-depth: downloadSiteSkeleton handler returns 403 __failed for inaccessible site'
);

// Restore HTTP_X_HAXCMS_USER_TOKEN
if ($idorSavedUserTokenHeader !== null) {
    $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $idorSavedUserTokenHeader;
} else {
    unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']);
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
