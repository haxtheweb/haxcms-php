<?php
/**
 * Test that cloneSite's realpath + mirror produces a standalone directory
 * when the source site is a symlink (e.g. a HAXiam shared site).
 *
 * Run: php system/backend/php/tests/CloneSiteSymlinkTest.php
 */

$repoRoot = dirname(dirname(__FILE__));
require_once $repoRoot . '/vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;

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

$tmpBase = sys_get_temp_dir() . '/haxcms_clone_test_' . uniqid();

// --- Set up original site directory ---
$originalSite = $tmpBase . '/original_user/sites/original-site';
mkdir($originalSite . '/files', 0777, true);
mkdir($originalSite . '/pages', 0777, true);
file_put_contents($originalSite . '/site.json', '{"name":"original-site"}');
file_put_contents($originalSite . '/pages/index.html', '<p>Hello</p>');
file_put_contents($originalSite . '/files/image.png', 'fake-image-data');

// --- Create a symlink simulating a HAXiam shared site ---
$sharedUserSites = $tmpBase . '/shared_user/sites';
mkdir($sharedUserSites, 0777, true);
$symlinkPath = $sharedUserSites . '/original-site';
$relativeTarget = '../../original_user/sites/original-site';
symlink($relativeTarget, $symlinkPath);

// Verify the symlink was set up correctly
assertTrue(is_link($symlinkPath), 'Shared site path is a symlink');
assertTrue(is_dir($symlinkPath), 'Symlink resolves to a directory');
assertTrue(readlink($symlinkPath) === $relativeTarget, 'Symlink points to the correct relative path');

// --- Simulate the cloneSite fix: resolve with realpath, then mirror ---
$resolvedSource = realpath($symlinkPath);
$clonePath = $sharedUserSites . '/cloned-site';

assertTrue($resolvedSource !== false, 'realpath() resolves the symlink to an actual path');
assertTrue(is_dir($resolvedSource) && !is_link($resolvedSource), 'realpath() returns the real directory, not a symlink');

$fs = new Filesystem();
$fs->mirror($resolvedSource, $clonePath);

// --- Assertions: the clone must be a real directory, not a symlink ---
assertTrue(is_dir($clonePath), 'Clone directory exists');
assertTrue(!is_link($clonePath), 'Clone is NOT a symlink (it is a real directory)');

// --- Assertions: contents were copied, not linked ---
assertTrue(file_exists($clonePath . '/site.json'), 'Clone contains site.json');
assertTrue(file_exists($clonePath . '/pages/index.html'), 'Clone contains pages/index.html');
assertTrue(file_exists($clonePath . '/files/image.png'), 'Clone contains files/image.png');
assertTrue(
    file_get_contents($clonePath . '/site.json') === '{"name":"original-site"}',
    'Clone site.json contents match original'
);

// --- Independence: modifying the clone must not affect the original ---
file_put_contents($clonePath . '/site.json', '{"name":"cloned-site"}');
assertTrue(
    file_get_contents($originalSite . '/site.json') === '{"name":"original-site"}',
    'Original site.json is untouched after modifying the clone'
);

// --- Cleanup ---
$fs->remove($tmpBase);
assertTrue(!is_dir($tmpBase), 'Test temp directory cleaned up');

echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
