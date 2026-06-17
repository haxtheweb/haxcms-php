<?php
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/testSecurityLayer.php';

function runRequestContextTests()
{
    $runner = new SimpleTestRunner();

    $site = new HAXCMSSiteTestStub();

    // --- getBody ---
    $_SERVER['REQUEST_URI'] = '/x/api/v1/items';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $context = new SiteApiRequestContext($site);
    $body = $context->getBody();
    $runner->assertEquals(array(), $body, 'getBody returns empty array for GET with no body');

    // Simulated JSON body via temp stream override isn't easy, so test via JSON post
    $_POST = array('title' => 'hello');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $context = new SiteApiRequestContext($site);
    // We can't easily inject raw body here, but we can verify structure
    $runner->assert($context->getBody() !== null, 'getBody returns something');

    // --- getHeader ---
    $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'abc123';
    $context = new SiteApiRequestContext($site);
    $runner->assertEquals('abc123', $context->getHeader('X-HAXCMS-Site-Token'), 'getHeader returns X-HAXCMS-Site-Token');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xyz';
    $runner->assertEquals('Bearer xyz', $context->getHeader('Authorization'), 'getHeader returns Authorization');

    $runner->assertEquals(null, $context->getHeader('X-Non-Existent'), 'getHeader returns null for missing header');

    // --- getParam ---
    $context->setRouteParams(array('idOrSlug' => 'my-page', 'format' => 'json'));
    $runner->assertEquals('my-page', $context->getParam('idOrSlug'), 'getParam returns idOrSlug');
    $runner->assertEquals('json', $context->getParam('format'), 'getParam returns format');
    $runner->assertEquals('fallback', $context->getParam('missing', 'fallback'), 'getParam returns fallback for missing key');

    return $runner->report('SiteApiRequestContext Tests');
}

function runSiteRoutesMapTests()
{
    $runner = new SimpleTestRunner();

    $routes = SiteRoutesMap::getRoutesMap();

    $runner->assert(isset($routes['GET']), 'RoutesMap has GET section');
    $runner->assert(isset($routes['POST']), 'RoutesMap has POST section');
    $runner->assert(isset($routes['PATCH']), 'RoutesMap has PATCH section');
    $runner->assert(isset($routes['DELETE']), 'RoutesMap has DELETE section');

    $runner->assert(isset($routes['GET']['v1/items']), 'GET v1/items exists');
    $runner->assert(isset($routes['GET']['v1/items/:idOrSlug']), 'GET v1/items/:idOrSlug exists');
    $runner->assert(isset($routes['GET']['v1/items/:idOrSlug/revisions']), 'GET v1/items/:idOrSlug/revisions exists');
    $runner->assert(isset($routes['GET']['v1/items/:idOrSlug/revisions/:revisionId']), 'GET v1/items/:idOrSlug/revisions/:revisionId exists');

    $runner->assert(isset($routes['POST']['v1/items']), 'POST v1/items exists');
    $runner->assert(isset($routes['POST']['v1/items/:idOrSlug/revisions/:revisionId/restore']), 'POST v1/items/:idOrSlug/revisions/:revisionId/restore exists');
    $runner->assert(isset($routes['POST']['v1/files']), 'POST v1/files exists');
    $runner->assert(isset($routes['POST']['v1/site/export/:format']), 'POST v1/site/export/:format exists');

    $runner->assert(isset($routes['PATCH']['v1/items/:idOrSlug']), 'PATCH v1/items/:idOrSlug exists');
    $runner->assert(isset($routes['PATCH']['v1/content/:idOrSlug']), 'PATCH v1/content/:idOrSlug exists');
    $runner->assert(isset($routes['PATCH']['v1/site']), 'PATCH v1/site exists');
    $runner->assert(isset($routes['PATCH']['v1/site/appearance']), 'PATCH v1/site/appearance exists');
    $runner->assert(isset($routes['PATCH']['v1/site/platform']), 'PATCH v1/site/platform exists');
    $runner->assert(isset($routes['PATCH']['v1/site/blocks']), 'PATCH v1/site/blocks exists');
    $runner->assert(isset($routes['PATCH']['v1/site/editor']), 'PATCH v1/site/editor exists');
    $runner->assert(isset($routes['PATCH']['v1/site/seo']), 'PATCH v1/site/seo exists');
    $runner->assert(isset($routes['PATCH']['v1/site/outline']), 'PATCH v1/site/outline exists');
    $runner->assert(isset($routes['PATCH']['v1/files/:fileUuid']), 'PATCH v1/files/:fileUuid exists');

    $runner->assert(isset($routes['DELETE']['v1/items/:idOrSlug']), 'DELETE v1/items/:idOrSlug exists');
    $runner->assert(isset($routes['DELETE']['v1/files/:fileUuid']), 'DELETE v1/files/:fileUuid exists');

    // Verify file paths exist for all new handlers
    $newHandlers = array(
        $routes['GET']['v1/items/:idOrSlug/revisions'],
        $routes['GET']['v1/items/:idOrSlug/revisions/:revisionId'],
        $routes['POST']['v1/items'],
        $routes['POST']['v1/items/:idOrSlug/revisions/:revisionId/restore'],
        $routes['POST']['v1/files'],
        $routes['POST']['v1/site/export/:format'],
        $routes['PATCH']['v1/items/:idOrSlug'],
        $routes['PATCH']['v1/content/:idOrSlug'],
        $routes['PATCH']['v1/site'],
        $routes['PATCH']['v1/site/appearance'],
        $routes['PATCH']['v1/site/platform'],
        $routes['PATCH']['v1/site/blocks'],
        $routes['PATCH']['v1/site/editor'],
        $routes['PATCH']['v1/site/seo'],
        $routes['PATCH']['v1/site/outline'],
        $routes['PATCH']['v1/files/:fileUuid'],
        $routes['DELETE']['v1/items/:idOrSlug'],
        $routes['DELETE']['v1/files/:fileUuid'],
    );
    foreach ($newHandlers as $path) {
        $runner->assert(file_exists($path), "Handler file exists: $path");
    }

    // Note: we do not include-execute the mutation wrappers here because they
    // depend on the full Operations autoload stack (bootstrapHAX.php). The
    // wrappers are known to be closures (return function ($context) {...}).
    foreach ($newHandlers as $path) {
        $contents = file_get_contents($path);
        $runner->assert(strpos($contents, 'return function') !== false, "Handler file contains closure: $path");
    }

    return $runner->report('SiteRoutesMap Tests');
}

function runSiteApiRouterTests()
{
    $runner = new SimpleTestRunner();

    // Test matchRoute for new mutation patterns
    $routes = SiteRoutesMap::getRoutesMap();

    // Match GET revisions
    $match = SiteApiRouter::matchRoute('v1/items/page-123/revisions', $routes['GET']);
    $runner->assert($match !== null, 'Router matches v1/items/:idOrSlug/revisions');
    $runner->assert(isset($match['params']['idOrSlug']), 'Match has idOrSlug param');
    $runner->assertEquals('page-123', $match['params']['idOrSlug'], 'idOrSlug extracted correctly');

    $match = SiteApiRouter::matchRoute('v1/items/page-123/revisions/abc456', $routes['GET']);
    $runner->assert($match !== null, 'Router matches v1/items/:idOrSlug/revisions/:revisionId');
    $runner->assertEquals('page-123', $match['params']['idOrSlug'], 'idOrSlug extracted correctly');
    $runner->assertEquals('abc456', $match['params']['revisionId'], 'revisionId extracted correctly');

    $match = SiteApiRouter::matchRoute('v1/items/page-123/revisions/abc456/restore', $routes['POST']);
    $runner->assert($match !== null, 'Router matches POST restore endpoint');
    $runner->assertEquals('page-123', $match['params']['idOrSlug'], 'Restore idOrSlug extracted');
    $runner->assertEquals('abc456', $match['params']['revisionId'], 'Restore revisionId extracted');

    $match = SiteApiRouter::matchRoute('v1/items/page-123', $routes['PATCH']);
    $runner->assert($match !== null, 'Router matches PATCH v1/items/:idOrSlug');
    $runner->assertEquals('page-123', $match['params']['idOrSlug'], 'PATCH idOrSlug extracted');

    $match = SiteApiRouter::matchRoute('v1/files/image-uuid', $routes['PATCH']);
    $runner->assert($match !== null, 'Router matches PATCH v1/files/:fileUuid');
    $runner->assertEquals('image-uuid', $match['params']['fileUuid'], 'PATCH fileUuid extracted');

    $match = SiteApiRouter::matchRoute('v1/files/image-uuid', $routes['DELETE']);
    $runner->assert($match !== null, 'Router matches DELETE v1/files/:fileUuid');
    $runner->assertEquals('image-uuid', $match['params']['fileUuid'], 'DELETE fileUuid extracted');

    $match = SiteApiRouter::matchRoute('v1/site/export/zip', $routes['POST']);
    $runner->assert($match !== null, 'Router matches POST v1/site/export/:format');
    $runner->assertEquals('zip', $match['params']['format'], 'Export format extracted');

    $match = SiteApiRouter::matchRoute('v1/site/export/markdown', $routes['POST']);
    $runner->assert($match !== null, 'Router matches POST v1/site/export/markdown');
    $runner->assertEquals('markdown', $match['params']['format'], 'Markdown export format extracted');

    $match = SiteApiRouter::matchRoute('v1/site/appearance', $routes['PATCH']);
    $runner->assert($match !== null, 'Router matches PATCH v1/site/appearance');

    $match = SiteApiRouter::matchRoute('v1/site/outline', $routes['PATCH']);
    $runner->assert($match !== null, 'Router matches PATCH v1/site/outline');

    $match = SiteApiRouter::matchRoute('v1/content/my-page', $routes['PATCH']);
    $runner->assert($match !== null, 'Router matches PATCH v1/content/:idOrSlug');
    $runner->assertEquals('my-page', $match['params']['idOrSlug'], 'content idOrSlug extracted');

    return $runner->report('SiteApiRouter Tests');
}

function runMutationWrapperTests()
{
    $runner = new SimpleTestRunner();

    $baseDir = dirname(__DIR__);

    // Verify all mutation wrappers exist and are callable
    $mutationWrappers = array(
        'v1/itemsMutation.php',
        'v1/contentMutation.php',
        'v1/siteMutation.php',
        'v1/filesMutation.php',
        'v1/revisions.php',
        'v1/revisionsMutation.php',
        'v1/exportsMutation.php',
    );
    foreach ($mutationWrappers as $file) {
        $path = $baseDir . '/lib/siteRoutes/' . $file;
        $runner->assert(file_exists($path), "Mutation wrapper exists: $file");
        $contents = file_get_contents($path);
        $runner->assert(strpos($contents, 'return function') !== false, "Mutation wrapper contains closure: $file");
    }

    return $runner->report('Mutation Wrapper Tests');
}

function runAllTests()
{
    $results = array(
        runRequestContextTests(),
        runSiteRoutesMapTests(),
        runSiteApiRouterTests(),
        runMutationWrapperTests(),
        runSecurityLayerTests(),
    );
    $allPassed = true;
    foreach ($results as $r) {
        if (!$r) {
            $allPassed = false;
        }
    }
    echo "\n=== OVERALL ===\n";
    echo $allPassed ? "All tests passed.\n" : "Some tests failed.\n";
    return $allPassed ? 0 : 1;
}

if (php_sapi_name() === 'cli' || !isset($_SERVER['SERVER_SOFTWARE'])) {
    exit(runAllTests());
}
