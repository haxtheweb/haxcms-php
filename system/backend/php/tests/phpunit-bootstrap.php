<?php
/**
 * PHPUnit bootstrap for haxcms-php backend.
 *
 * The application loads lib/ classes via include_once (composer.json has no
 * PSR-4 autoload mapping for lib/), so we require the classes under test
 * explicitly here, mirroring how the standalone test scripts work. composer's
 * autoloader is also loaded for any third-party dependencies a class may need.
 */
$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

// Recursively include_once every PHP file under lib/ so any test can exercise
// any class without touching this shared bootstrap (avoids file contention
// across parallel test authors). include_once makes this idempotent; the
// procedural route handlers under */v1/ are function_exists-guarded so loading
// them at bootstrap is safe. HAXCMS.php's top-level $fileSystem global is
// benign. Operations.php loads the operations/*.php traits itself, but
// include_once keeps that idempotent.
$libDir = $base . '/lib';
$rit = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($libDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($rit as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    // Skip the operations/ traits and Operations.php itself: Operations.php
    // loads the traits in a specific order (MethodMap -> foreach trait file ->
    // OperationsMethods which `use`s them) that the alphabetical glob would
    // violate (OperationsMethods would load before its traits). Require
    // Operations.php last so its own ordering holds.
    if (strpos($path, '/operations/') !== false || substr($path, -strlen('Operations.php')) === 'Operations.php') {
        continue;
    }
    // Skip the procedural route-handler dirs (siteRoutes/v1, systemRoutes/v1):
    // several define the SAME function without a function_exists guard (e.g.
    // haxcmsSystemConvertDocxXmlToHtml in both convertDocxToHtml.php and
    // importDocx.php), so loading them all triggers a redeclare fatal. Pull in
    // the one class-based file we test (ExportConverters) explicitly below.
    if (strpos($path, '/siteRoutes/v1/') !== false || strpos($path, '/systemRoutes/v1/') !== false) {
        continue;
    }
    require_once $path;
}
// Load Operations.php last so it pulls in the operations/ traits in order.
require_once $base . '/lib/Operations.php';
// Class-based file that lives under a skipped procedural dir.
require_once $base . '/lib/siteRoutes/v1/ExportConverters.php';
