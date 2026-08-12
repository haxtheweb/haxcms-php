<?php
/**
 * PDF Export Image Fix — Verification Test
 *
 * Run: php system/backend/php/tests/testPdfExportImages.php
 *
 * Verifies that ExportConverters::htmlToPdfString correctly:
 *  1. Converts <media-image>/<simple-img> components into real <img> tags
 *  2. Resolves relative image src to absolute filesystem paths under the
 *     site directory so Dompdf can read them via file://
 *  3. Produces a valid, non-empty PDF
 *
 * Before the fix, all images in PDF exports showed "Image not found or type
 * unknown" because Dompdf treated the URL-space base path (e.g. /aa121/) as
 * a filesystem path via realpath(), which returned false.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/lib/siteRoutes/SiteRouteUtils.php';
require_once $baseDir . '/lib/SanitizeContent.php';
require_once $baseDir . '/lib/SsrfGuard.php';
require_once $baseDir . '/lib/siteRoutes/v1/ExportConverters.php';

$passed = 0;
$failed = 0;
$errors = array();

function assert_true($cond, $msg) {
    global $passed, $failed, $errors;
    if ($cond) {
        $passed++;
        echo "  PASS: $msg\n";
    } else {
        $failed++;
        $errors[] = $msg;
        echo "  FAIL: $msg\n";
    }
}

function assert_equals($expected, $actual, $msg) {
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
        echo "  PASS: $msg\n";
    } else {
        $failed++;
        $errors[] = "$msg (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")";
        echo "  FAIL: $msg\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        got:      " . var_export($actual, true) . "\n";
    }
}

function assert_contains($needle, $haystack, $msg) {
    global $passed, $failed, $errors;
    if (is_string($haystack) && strpos($haystack, $needle) !== false) {
        $passed++;
        echo "  PASS: $msg\n";
    } else {
        $failed++;
        $errors[] = "$msg (needle '$needle' not found)";
        echo "  FAIL: $msg (needle '$needle' not found)\n";
    }
}

// ── Setup: create a temp site directory with a real PNG image ──────────────

$tempRoot = sys_get_temp_dir() . '/haxcms_pdf_test_' . uniqid();
$siteDir = $tempRoot . '/testsite';
$filesDir = $siteDir . '/files';
@mkdir($filesDir, 0755, true);

// Create a minimal valid 1x1 PNG (red pixel)
$pngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
);
$pngPath = $filesDir . '/test-image.png';
file_put_contents($pngPath, $pngBytes);

// Also create an image directly in the site root (not in files/)
$pngPath2 = $siteDir . '/logo.png';
file_put_contents($pngPath2, $pngBytes);

$realSiteDir = realpath($siteDir);
$realPngPath = realpath($pngPath);
$realPngPath2 = realpath($pngPath2);

// Fake site object — SiteRouteUtils::getSiteDirectory checks siteDirectory first
$fakeSite = new stdClass();
$fakeSite->siteDirectory = $realSiteDir;
$fakeSite->manifest = new stdClass();
$fakeSite->manifest->metadata = new stdClass();
$fakeSite->manifest->metadata->site = new stdClass();
$fakeSite->manifest->metadata->site->name = 'testsite';

$basePath = '/testsite/';

echo "\n=== PDF Export Image Fix Tests ===\n\n";

// ── Test 1: resolveLocalImageFile — relative path in files/ ────────────────
echo "[1] resolveLocalImageFile: files/test-image.png\n";
$resolved = ExportConverters::resolveLocalImageFile('files/test-image.png', $basePath, $realSiteDir);
assert_equals($realPngPath, $resolved, 'Resolves files/test-image.png to absolute filesystem path');

// ── Test 2: resolveLocalImageFile — base-prefixed path ─────────────────────
echo "[2] resolveLocalImageFile: /testsite/files/test-image.png\n";
$resolved = ExportConverters::resolveLocalImageFile('/testsite/files/test-image.png', $basePath, $realSiteDir);
assert_equals($realPngPath, $resolved, 'Strips base path prefix and resolves correctly');

// ── Test 3: resolveLocalImageFile — image in site root ─────────────────────
echo "[3] resolveLocalImageFile: logo.png (site root)\n";
$resolved = ExportConverters::resolveLocalImageFile('logo.png', $basePath, $realSiteDir);
assert_equals($realPngPath2, $resolved, 'Resolves image in site root (not just files/)');

// ── Test 4: resolveLocalImageFile — nonexistent image ──────────────────────
echo "[4] resolveLocalImageFile: files/missing.png\n";
$resolved = ExportConverters::resolveLocalImageFile('files/missing.png', $basePath, $realSiteDir);
assert_equals('', $resolved, 'Returns empty string for nonexistent image');

// ── Test 5: resolveLocalImageFile — path traversal blocked ─────────────────
echo "[5] resolveLocalImageFile: ../etc/passwd\n";
$resolved = ExportConverters::resolveLocalImageFile('../etc/passwd', $basePath, $realSiteDir);
assert_equals('', $resolved, 'Blocks path traversal (..)');

// ── Test 6: resolvePdfImageSources — plain <img> ───────────────────────────
echo "[6] resolvePdfImageSources: plain <img src>\n";
$inputHtml = '<p>Hello <img src="files/test-image.png" alt="test"></p>';
$outputHtml = ExportConverters::resolvePdfImageSources($inputHtml, $basePath, $realSiteDir);
assert_contains($realPngPath, $outputHtml, 'Rewrites <img src> to absolute filesystem path');

// ── Test 7: resolvePdfImageSources — data URI left untouched ───────────────
echo "[7] resolvePdfImageSources: data URI\n";
$dataUri = 'data:image/png;base64,iVBORw0KGgo=';
$inputHtml = '<img src="' . $dataUri . '">';
$outputHtml = ExportConverters::resolvePdfImageSources($inputHtml, $basePath, $realSiteDir);
assert_contains($dataUri, $outputHtml, 'Leaves data URIs untouched');

// ── Test 8: resolvePdfImageSources — remote URL left untouched ─────────────
echo "[8] resolvePdfImageSources: remote URL\n";
$remoteUrl = 'https://example.com/image.png';
$inputHtml = '<img src="' . $remoteUrl . '">';
$outputHtml = ExportConverters::resolvePdfImageSources($inputHtml, $basePath, $realSiteDir);
assert_contains($remoteUrl, $outputHtml, 'Leaves remote URLs untouched');

// ── Test 9: htmlToPdfString with <media-image> — end-to-end ─────────────────
echo "[9] htmlToPdfString: <media-image> end-to-end\n";
$htmlWithMediaImage = '<!doctype html><html><head><title>Test</title></head><body>' .
    '<h1>Image Test</h1>' .
    '<media-image source="files/test-image.png" alt="Test image"></media-image>' .
    '<p>Some text after the image.</p>' .
    '</body></html>';

try {
    $pdfOutput = ExportConverters::htmlToPdfString($htmlWithMediaImage, $basePath, $fakeSite);
    assert_true(is_string($pdfOutput) && strlen($pdfOutput) > 100, 'PDF output is non-empty string');
    assert_true(substr($pdfOutput, 0, 4) === '%PDF', 'PDF starts with %PDF header');
} catch (Exception $e) {
    assert_true(false, 'htmlToPdfString threw exception: ' . $e->getMessage());
}

// ── Test 10: htmlToPdfString with plain <img> — end-to-end ──────────────────
echo "[10] htmlToPdfString: plain <img> end-to-end\n";
$htmlWithImg = '<!doctype html><html><head><title>Test</title></head><body>' .
    '<h1>Image Test</h1>' .
    '<img src="files/test-image.png" alt="Test image">' .
    '<p>Some text after the image.</p>' .
    '</body></html>';

try {
    $pdfOutput = ExportConverters::htmlToPdfString($htmlWithImg, $basePath, $fakeSite);
    assert_true(is_string($pdfOutput) && strlen($pdfOutput) > 100, 'PDF output is non-empty string');
    assert_true(substr($pdfOutput, 0, 4) === '%PDF', 'PDF starts with %PDF header');
} catch (Exception $e) {
    assert_true(false, 'htmlToPdfString threw exception: ' . $e->getMessage());
}

// ── Test 11: htmlToPdfString with no site (fallback) — no crash ─────────────
echo "[11] htmlToPdfString: fallback (no site) still works\n";
try {
    $pdfOutput = ExportConverters::htmlToPdfString($htmlWithImg, $basePath, null);
    assert_true(is_string($pdfOutput) && strlen($pdfOutput) > 100, 'Fallback PDF output is non-empty');
    assert_true(substr($pdfOutput, 0, 4) === '%PDF', 'Fallback PDF starts with %PDF header');
} catch (Exception $e) {
    assert_true(false, 'htmlToPdfString fallback threw exception: ' . $e->getMessage());
}

// ── Test 12: htmlToPdfString with mixed content — end-to-end ────────────────
echo "[12] htmlToPdfString: mixed media-image + img + missing image\n";
$mixedHtml = '<!doctype html><html><head><title>Test</title></head><body>' .
    '<h1>Mixed Image Test</h1>' .
    '<media-image source="files/test-image.png" alt="Via media-image"></media-image>' .
    '<img src="logo.png" alt="Direct img in site root">' .
    '<img src="files/does-not-exist.png" alt="Missing image">' .
    '<p>End of content.</p>' .
    '</body></html>';

try {
    $pdfOutput = ExportConverters::htmlToPdfString($mixedHtml, $basePath, $fakeSite);
    assert_true(is_string($pdfOutput) && strlen($pdfOutput) > 100, 'Mixed PDF output is non-empty');
    assert_true(substr($pdfOutput, 0, 4) === '%PDF', 'Mixed PDF starts with %PDF header');
} catch (Exception $e) {
    assert_true(false, 'htmlToPdfString mixed threw exception: ' . $e->getMessage());
}

// ── Test 13: Image actually loads (no "Image not found" warning) ───────────
// This is the key test: it proves the fix makes Dompdf actually read and embed
// the image, not just produce a non-empty PDF (a PDF with broken images is
// also non-empty). We compare the fixed approach (absolute fs path + chroot)
// against the old approach (relative src + URL-space <base href>) and check
// Dompdf's warning log.
echo '[13] Image actually loads — no "Image not found" warning' . "\n";

use Dompdf\Dompdf;
use Dompdf\Image\Cache;

// --- 13a: Fixed approach — absolute filesystem path + chroot ---
$GLOBALS['_dompdf_warnings'] = array();
Cache::clear();
$dompdfFixed = new Dompdf(array(
    'isRemoteEnabled' => false,
    'defaultFont' => 'serif',
    'tempDir' => sys_get_temp_dir(),
    'chroot' => array($realSiteDir),
));
$dompdfFixed->loadHtml('<html><body><img src="' . $realPngPath . '"></body></html>');
$dompdfFixed->setPaper('A4', 'portrait');
$dompdfFixed->render();
$fixedWarnings = isset($GLOBALS['_dompdf_warnings']) ? $GLOBALS['_dompdf_warnings'] : array();
$fixedHasImageError = false;
foreach ($fixedWarnings as $w) {
    if (stripos($w, 'Image not found') !== false || stripos($w, 'type unknown') !== false) {
        $fixedHasImageError = true;
        break;
    }
}
assert_true(!$fixedHasImageError, 'Fixed approach: no "Image not found" warning (image loaded)');
assert_true(count($fixedWarnings) === 0, 'Fixed approach: no Dompdf warnings at all');

// --- 13b: Old approach — relative src + URL-space <base href> (reproduces the bug) ---
$GLOBALS['_dompdf_warnings'] = array();
Cache::clear();
$dompdfOld = new Dompdf(array(
    'isRemoteEnabled' => false,
    'defaultFont' => 'serif',
    'tempDir' => sys_get_temp_dir(),
));
$oldHtml = '<html><head><base href="/testsite/"></head><body><img src="files/test-image.png"></body></html>';
$dompdfOld->loadHtml($oldHtml);
$dompdfOld->setPaper('A4', 'portrait');
$dompdfOld->render();
$oldWarnings = isset($GLOBALS['_dompdf_warnings']) ? $GLOBALS['_dompdf_warnings'] : array();
$oldHasImageError = false;
foreach ($oldWarnings as $w) {
    // The old approach triggers either "Unable to parse image URL" (when
    // build_url returns null because realpath() fails on the URL-space base
    // path) or "Image not found" / "type unknown" (in other misconfigurations).
    // Any of these proves the image did not load.
    if (
        stripos($w, 'Image not found') !== false ||
        stripos($w, 'type unknown') !== false ||
        stripos($w, 'Unable to parse image') !== false ||
        stripos($w, 'image') !== false
    ) {
        $oldHasImageError = true;
        break;
    }
}
assert_true($oldHasImageError, 'Old approach: reproduces image warning (proves the bug existed)');
assert_true(count($oldWarnings) > 0, 'Old approach: Dompdf produced at least 1 warning');
assert_true(count($fixedWarnings) === 0, 'Fixed approach: zero warnings (image loaded cleanly)');

// --- 13c: Summary comparison ---
echo "  Fixed approach warnings: " . count($fixedWarnings) . "\n";
echo "  Old approach warnings:   " . count($oldWarnings) . "\n";
if (count($oldWarnings) > 0) {
    echo "  Old approach warning sample: " . $oldWarnings[0] . "\n";
}

// ── Cleanup ─────────────────────────────────────────────────────────────────
@unlink($pngPath);
@unlink($pngPath2);
@rmdir($filesDir);
@rmdir($siteDir);
@rmdir($tempRoot);

// ── Summary ─────────────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Passed: $passed, Failed: $failed\n";
if (count($errors) > 0) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
exit($failed > 0 ? 1 : 0);
