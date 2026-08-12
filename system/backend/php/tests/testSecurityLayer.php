<?php
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/../lib/systemRoutes/SystemApiSecurity.php';

function runSecurityLayerTests()
{
    $runner = new SimpleTestRunner();

    // --- 1. getBearerTokenFromRequest ---
    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-token';
    $token = SiteRouteUtils::getBearerTokenFromRequest();
    $runner->assertEquals('valid-token', $token, 'Bearer token extracted from Authorization header');

    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
    $token = SiteRouteUtils::getBearerTokenFromRequest();
    $runner->assertEquals(null, $token, 'Empty bearer token returns null');

    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
    $token = SiteRouteUtils::getBearerTokenFromRequest();
    $runner->assertEquals(null, $token, 'Basic auth returns null');

    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'bearer lowercase-token';
    $token = SiteRouteUtils::getBearerTokenFromRequest();
    $runner->assertEquals('lowercase-token', $token, 'Case-insensitive bearer extraction');

    resetServerVars();
    $token = SiteRouteUtils::getBearerTokenFromRequest();
    $runner->assertEquals(null, $token, 'Missing Authorization header returns null');

    // --- 2. validateSiteToken ---
    resetServerVars();
    $valid = SiteRouteUtils::validateSiteToken('testsite', 'valid-site-token');
    $runner->assert($valid === true, 'valid-site-token should pass for testsite');

    $invalid = SiteRouteUtils::validateSiteToken('testsite', 'invalid-token');
    $runner->assert($invalid === false, 'invalid token should fail');

    // --- 3. SiteApiSecurity route policies ---
    // Public route should allow without auth
    $site = new HAXCMSSiteTestStub();
    $context = new SiteApiRequestContext($site);
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/site should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/items should be public');

    // Pass the matched route PATTERN (e.g. v1/items/:idOrSlug), matching how
    // SiteApiRouter::dispatch calls validateSiteApiAccess with $match['route'].
    // Passing a concrete slug would miss the spec policy map key and fall
    // through to 'authenticated'.
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/:idOrSlug', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/items/:idOrSlug should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/content/:idOrSlug', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/content/:idOrSlug should be public');

    // v1/files GET is authenticated-site per site-spec.yaml (bearerAuth +
    // siteTokenHeader) — it is NOT public, so an unauthenticated request is
    // denied. The old regex table left it bare 'authenticated'; the spec-driven
    // reader correctly elevates it to authenticated-site.
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/files', 'GET');
    $runner->assert($result['allowed'] === false, 'GET v1/files requires auth (authenticated-site)');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/search', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/search should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/custom-elements', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/custom-elements should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/blocks', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/blocks should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/regions', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/regions should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/themes', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/themes should be public');

    // v1/reports GET is authenticated-site per site-spec.yaml (bearerAuth +
    // siteTokenHeader) — it is NOT public, so an unauthenticated request is
    // denied.
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/reports', 'GET');
    $runner->assert($result['allowed'] === false, 'GET v1/reports requires auth (authenticated-site)');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/analytics', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/analytics should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/views', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/views should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/displays', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/displays should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/entities', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/entities should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/schemas', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/schemas should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/export/:format', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/site/export/:format should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/:idOrSlug/export/:format', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/items/:idOrSlug/export/:format should be public');

    // Mutation routes should require auth + site token
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'POST');
    $runner->assert($result['allowed'] === false, 'POST v1/items requires auth');
    $runner->assertEquals(401, $result['status'], 'POST v1/items returns 401');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/items/:idOrSlug requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug', 'DELETE');
    $runner->assert($result['allowed'] === false, 'DELETE v1/items/:idOrSlug requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/content/some-slug', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/content/:idOrSlug requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/appearance', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site/appearance requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/platform', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site/platform requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/blocks', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site/blocks requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/editor', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site/editor requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/seo', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site/seo requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/outline', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/site/outline requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/files', 'POST');
    $runner->assert($result['allowed'] === false, 'POST v1/files requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/files/some-uuid', 'PATCH');
    $runner->assert($result['allowed'] === false, 'PATCH v1/files/:fileUuid requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/files/some-uuid', 'DELETE');
    $runner->assert($result['allowed'] === false, 'DELETE v1/files/:fileUuid requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug/revisions', 'GET');
    $runner->assert($result['allowed'] === false, 'GET v1/items/:idOrSlug/revisions requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug/revisions/abc123', 'GET');
    $runner->assert($result['allowed'] === false, 'GET v1/items/:idOrSlug/revisions/:revisionId requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug/revisions/abc123/restore', 'POST');
    $runner->assert($result['allowed'] === false, 'POST v1/items/:idOrSlug/revisions/:revisionId/restore requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/export/zip', 'POST');
    $runner->assert($result['allowed'] === false, 'POST v1/site/export/:format requires auth');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/import/docx', 'POST');
    $runner->assert($result['allowed'] === false, 'POST v1/site/import/docx requires auth');
    $runner->assertEquals(401, $result['status'], 'POST v1/site/import/docx returns 401');

    // --- 4. Authenticated with valid Bearer but missing site token ---
    $jwt = buildTestJWT('testuser', 'test-secret-key', 'test-salt');
    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
    $context = new SiteApiRequestContext($site);
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'POST');
    $runner->assert($result['allowed'] === false, 'Authenticated POST without site token still denied');
    $runner->assertEquals(403, $result['status'], 'Missing site token returns 403');

    // --- 5. Authenticated with valid Bearer + valid site token ---
    $jwt = buildTestJWT('testuser', 'test-secret-key', 'test-salt');
    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
    $context = new SiteApiRequestContext($site);
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'POST');
    $runner->assert($result['allowed'] === false, 'Still denied because X-HAXCMS-Site-Token is missing');
    $runner->assertEquals(403, $result['status'], 'Missing X-HAXCMS-Site-Token returns 403');

    // Set the site token via header
    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
    $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'valid-site-token';
    $context = new SiteApiRequestContext($site);
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'POST');
    $runner->assert($result['allowed'] === true, 'Valid Bearer + valid site token allows POST');
    $runner->assertEquals('testuser', $result['userName'], 'userName returned in result');

    // --- 6. Invalid Bearer token ---
    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid.jwt.token';
    $context = new SiteApiRequestContext($site);
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'POST');
    $runner->assert($result['allowed'] === false, 'Invalid Bearer token denied');
    $runner->assertEquals(403, $result['status'], 'Invalid Bearer returns 403');

    // --- 7. OPTIONS preflight should be public regardless of route ---
    resetServerVars();
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'OPTIONS');
    $runner->assert($result['allowed'] === true, 'OPTIONS is always allowed');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug', 'OPTIONS');
    $runner->assert($result['allowed'] === true, 'OPTIONS for item detail allowed');

    // --- 8. Auth context attached to context ---
    resetServerVars();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
    $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'valid-site-token';
    $context = new SiteApiRequestContext($site);
    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'POST');
    $runner->assert($result['allowed'] === true, 'Auth context allowed');
    $runner->assert(isset($result['userName']), 'Auth result has userName');

    return $runner->report('Security Layer Tests');
}

function runSystemV1RefererGateTests()
{
    $runner = new SimpleTestRunner();

    // validateSystemV1RouteAccess blocks system v1 admin routes that arrive
    // via a site-scoped URL (starts with /{sitesDirectory}/) from a
    // site-scoped referer. isSiteScopedSystemApiRequest MUST use a startsWith
    // check (=== 0), not contains (!== false), so a root system API URL like
    // /system/api/v1/sites/<name>/clone (which has /_sites/ in the middle of
    // the path) is NOT falsely flagged as site-scoped and blocked with 403
    // when no dashboard Referer is present.

    $adminRoute = 'v1/sites/:siteName/clone';
    $nonAdminRoute = 'v1/session/login';

    // 1. Root system API URL with /sites/ in the middle, NO referer -> ALLOWED.
    //    Regression for the contains-anywhere false positive that blocked
    //    direct API clone/archive calls with 403.
    resetServerVars();
    $ctx = new stdClass();
    $ctx->requestPath = '/system/api/v1/sites/mydemo/clone';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $adminRoute) === true,
        'Root system API URL (/system/api/v1/sites/<name>/clone) with no referer is NOT site-scoped (startsWith fix)'
    );

    // 2. Root system API URL, dashboard referer -> ALLOWED.
    resetServerVars();
    $_SERVER['HTTP_REFERER'] = 'http://haxcms.ddev.site/';
    $ctx = new stdClass();
    $ctx->requestPath = '/system/api/v1/sites/mydemo/clone';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $adminRoute) === true,
        'Root system API URL with dashboard referer is allowed'
    );

    // 3. Site-scoped URL (starts with /_sites/), site-scoped referer -> BLOCKED.
    resetServerVars();
    $_SERVER['HTTP_REFERER'] = 'http://haxcms.ddev.site/_sites/mydemo/';
    $ctx = new stdClass();
    $ctx->requestPath = '/_sites/mydemo/system/api/v1/sites/mydemo/clone';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $adminRoute) === false,
        'Site-scoped URL with site-scoped referer is blocked (the gate intent)'
    );

    // 4. Site-scoped URL, dashboard referer -> ALLOWED.
    resetServerVars();
    $_SERVER['HTTP_REFERER'] = 'http://haxcms.ddev.site/';
    $ctx = new stdClass();
    $ctx->requestPath = '/_sites/mydemo/system/api/v1/sites/mydemo/clone';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $adminRoute) === true,
        'Site-scoped URL with dashboard referer is allowed'
    );

    // 5. Site-scoped URL, NO referer -> BLOCKED (missing referer is not dashboard).
    resetServerVars();
    $ctx = new stdClass();
    $ctx->requestPath = '/_sites/mydemo/system/api/v1/sites/mydemo/clone';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $adminRoute) === false,
        'Site-scoped URL with no referer is blocked (missing referer is not dashboard)'
    );

    // 6. Non-admin route with site-scoped URL + referer -> ALLOWED (gate only
    //    applies to SystemV1AdminRoutes).
    resetServerVars();
    $_SERVER['HTTP_REFERER'] = 'http://haxcms.ddev.site/_sites/mydemo/';
    $ctx = new stdClass();
    $ctx->requestPath = '/_sites/mydemo/system/api/v1/session/login';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $nonAdminRoute) === true,
        'Non-admin route (v1/session/login) is not gated'
    );

    // 7. Empty requestPath -> NOT site-scoped (allowed).
    resetServerVars();
    $ctx = new stdClass();
    $ctx->requestPath = '';
    $runner->assert(
        SystemApiSecurity::validateSystemV1RouteAccess($ctx, $adminRoute) === true,
        'Empty requestPath is not site-scoped (allowed)'
    );

    return $runner->report('System v1 Referer Gate Tests');
}

if ((php_sapi_name() === 'cli' || !isset($_SERVER['SERVER_SOFTWARE'])) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_NAME'])) {
    $ok = runSecurityLayerTests();
    exit($ok ? 0 : 1);
}
