<?php
include_once __DIR__ . '/bootstrap.php';

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

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/items/:idOrSlug should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/content/some-slug', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/content/:idOrSlug should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/files', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/files should be public');

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

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/reports', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/reports should be public');

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

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/export/zip', 'GET');
    $runner->assert($result['allowed'] === true, 'GET v1/site/export/:format should be public');

    $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items/some-slug/export/pdf', 'GET');
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
    $runner->assertEquals(401, $result['status'], 'Invalid Bearer returns 401');

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

if (php_sapi_name() === 'cli' || !isset($_SERVER['SERVER_SOFTWARE'])) {
    $ok = runSecurityLayerTests();
    exit($ok ? 0 : 1);
}
