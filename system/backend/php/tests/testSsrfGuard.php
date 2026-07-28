<?php
include_once __DIR__ . '/bootstrap.php';
include_once dirname(__DIR__) . '/lib/SsrfGuard.php';

/**
 * Standalone replica of Operations::normalizeSiteFilePath + the
 * $safeSiteFileExtensionPattern, so the extension allow-list (CWE-434) can be
 * unit-tested without loading the full Operations/HAXCMS autoload stack
 * (which is not bootstrapped by tests/bootstrap.php). The pattern string is
 * kept byte-identical to lib/Operations.php so a drift is detectable by eye.
 */
function ssrfGuardTestNormalizeSiteFilePath($relativePath)
{
    $safeSiteFileExtensionPattern = '/\.(css|js|html?|json|md|txt|svg|png|jpe?g|gif|webp|webm|mp4|mp3|mov|vtt|woff2?|ttf|eot|csv|pdf)$/i';
    if (!is_string($relativePath)) {
        return false;
    }
    $normalized = trim(str_replace('\\', '/', $relativePath));
    if (
        $normalized === '' ||
        strpos($normalized, "\0") !== false ||
        strpos($normalized, '..') !== false ||
        substr($normalized, 0, 1) === '/' ||
        !(strpos($normalized, 'theme/') === 0 || strpos($normalized, 'custom/') === 0)
    ) {
        return false;
    }
    $parts = explode('/', $normalized);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    if (preg_match($safeSiteFileExtensionPattern, $normalized) !== 1) {
        return false;
    }
    return $normalized;
}

function runSsrfGuardTests()
{
    $runner = new SimpleTestRunner();

    // --- isPrivateOrReservedIP ---
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('169.254.169.254'), 'metadata IP is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('127.0.0.1'), 'loopback is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('10.0.0.1'), '10.x is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('192.168.1.1'), '192.168.x is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('172.16.0.1'), '172.16.x is private');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('172.15.0.1'), '172.15.x is NOT private');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('8.8.8.8'), '8.8.8.8 is NOT private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::1'), 'IPv6 ::1 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:169.254.169.254'), 'IPv4-mapped metadata is private');

    // --- validateUrlNotSSRF rejects loopback / metadata / private literals ---
    $rejected = array(
        'http://127.0.0.1/' => 'loopback literal',
        'http://127.0.0.1:3000/' => 'loopback literal with port',
        'http://169.254.169.254/latest/meta-data/' => 'metadata literal',
        'http://10.0.0.5/' => 'private 10.x literal',
        'http://192.168.1.1/' => 'private 192.168.x literal',
        'http://172.16.0.1/' => 'private 172.16.x literal',
    );
    foreach ($rejected as $url => $label) {
        $threw = false;
        try {
            SsrfGuard::validateUrlNotSSRF($url);
        } catch (SsrfGuardException $e) {
            $threw = true;
        }
        $runner->assert($threw, "validateUrlNotSSRF rejects $label ($url)");
    }

    // --- validateUrlNotSSRF rejects non-http schemes ---
    $threw = false;
    try {
        SsrfGuard::validateUrlNotSSRF('ftp://example.com/');
    } catch (SsrfGuardException $e) {
        $threw = true;
    }
    $runner->assert($threw, 'validateUrlNotSSRF rejects ftp:// scheme');

    // --- validateUrlNotSSRF rejects malformed URL ---
    $threw = false;
    try {
        SsrfGuard::validateUrlNotSSRF('not-a-url');
    } catch (SsrfGuardException $e) {
        $threw = true;
    }
    $runner->assert($threw, 'validateUrlNotSSRF rejects malformed URL');

    // --- normalizeSiteFilePath extension allow-list (CWE-434) ---
    // Allowed theme/custom asset extensions pass
    $allowedPaths = array(
        'theme/theme.css',
        'theme/theme.html',
        'custom/build/custom.es6.js',
        'theme/site.json',
        'custom/page.md',
        'theme/banner.png',
        'custom/fonts.woff2',
    );
    foreach ($allowedPaths as $p) {
        $result = ssrfGuardTestNormalizeSiteFilePath($p);
        $runner->assert($result !== false, "allowed extension accepted: $p");
    }
    // Server-executable / disallowed extensions are rejected before any fetch
    $rejectedPaths = array(
        'custom/x.php' => 'php',
        'custom/x.phtml' => 'phtml',
        'custom/x.phar' => 'phar',
        'custom/x.cgi' => 'cgi',
        'custom/x.pl' => 'perl',
        'custom/x.py' => 'python',
        'custom/x.sh' => 'shell',
        'custom/x.asp' => 'asp',
        'custom/x.aspx' => 'aspx',
        'custom/x.jsp' => 'jsp',
        'custom/x.exe' => 'exe',
        'theme/x.bat' => 'bat',
    );
    foreach ($rejectedPaths as $p => $label) {
        $result = ssrfGuardTestNormalizeSiteFilePath($p);
        $runner->assert($result === false, "executable extension rejected (CWE-434): $label ($p)");
    }

    // --- normalizeSiteFilePath still rejects traversal / non theme|custom ---
    $runner->assertEquals(false, ssrfGuardTestNormalizeSiteFilePath('../etc/passwd'), 'traversal rejected');
    $runner->assertEquals(false, ssrfGuardTestNormalizeSiteFilePath('/etc/passwd'), 'absolute rejected');
    $runner->assertEquals(false, ssrfGuardTestNormalizeSiteFilePath('files/x.png'), 'non-theme/custom prefix rejected');
    $runner->assertEquals(false, ssrfGuardTestNormalizeSiteFilePath("theme/x\0.png"), 'null byte rejected');

    return $runner->report('SSRF Guard Tests');
}

if ((php_sapi_name() === 'cli' || !isset($_SERVER['SERVER_SOFTWARE'])) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_NAME'])) {
    $ok = runSsrfGuardTests();
    exit($ok ? 0 : 1);
}
