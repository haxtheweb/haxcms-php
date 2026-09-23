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
    // --- CGNAT 100.64.0.0/10 (RFC 6598) parity with haxcms-nodejs ---
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('100.64.0.1'), '100.64.x CGNAT is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('100.127.255.255'), '100.127.x CGNAT upper bound is private');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('100.63.0.1'), '100.63.x is NOT private (below CGNAT)');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('100.128.0.1'), '100.128.x is NOT private (above CGNAT)');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('100.0.0.1'), '100.0.x is NOT private (outside CGNAT)');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::1'), 'IPv6 ::1 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:169.254.169.254'), 'IPv4-mapped metadata is private');

    // --- SSRF hex-form bypass (HAX-SEC-007): IPv4-mapped/compatible IPv6
    // spelled with hex groups instead of dotted-quad must still be flagged.
    // The previous text-based match only recognized ::ffff:a.b.c.d, so
    // ::ffff:7f00:1 (127.0.0.1), ::ffff:a9fe:a9fe (169.254.169.254), and
    // ::ffff:c0a8:0001 (192.168.0.1) slipped through as public.
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:7f00:1'), 'hex-mapped loopback 127.0.0.1 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:a9fe:a9fe'), 'hex-mapped metadata 169.254.169.254 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:c0a8:7'), 'hex-mapped 192.168.0.7 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:ac10:1'), 'hex-mapped 172.16.0.1 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:0a00:1'), 'hex-mapped 10.0.0.1 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::ffff:6440:1'), 'hex-mapped CGNAT 100.64.0.1 is private');
    // IPv4-compatible (::/96) hex spelling — same bypass class
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::7f00:1'), 'hex-compat loopback 127.0.0.1 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::a9fe:a9fe'), 'hex-compat metadata 169.254.169.254 is private');
    // Public mapped/compat must remain public (no over-blocking)
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('::ffff:8.8.8.8'), 'mapped public 8.8.8.8 is NOT private');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('::8.8.8.8'), 'compat public 8.8.8.8 is NOT private');
    // Public v6 whose trailing 4 bytes look like an IPv4 address must stay
    // public — the prefix check prevents false positives on 2001:db8::1.2.3.4.
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('2001:db8::1.2.3.4'), 'public v6 with dotted tail is NOT private');
    // Loopback alternate spellings are caught via packed-byte matching
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('::0001'), 'loopback alt spelling ::0001 is private');
    $runner->assertEquals(true, SsrfGuard::isPrivateOrReservedIP('0:0:0:0:0:0:0:1'), 'loopback long form is private');
    $runner->assertEquals(false, SsrfGuard::isPrivateOrReservedIP('2606:4700:4700::1111'), 'public v6 is NOT private');

    // --- validateUrlNotSSRF rejects loopback / metadata / private literals ---
    $rejected = array(
        'http://127.0.0.1/' => 'loopback literal',
        'http://127.0.0.1:3000/' => 'loopback literal with port',
        'http://169.254.169.254/latest/meta-data/' => 'metadata literal',
        'http://10.0.0.5/' => 'private 10.x literal',
        'http://192.168.1.1/' => 'private 192.168.x literal',
        'http://172.16.0.1/' => 'private 172.16.x literal',
        // Hex-form IPv4-mapped/compatible literals in bracketed URL form —
        // the exact SSRF bypass from the report. curl routes these to the
        // same socket target as the dotted-quad private address.
        'http://[::ffff:7f00:1]:9999/' => 'hex-mapped loopback literal',
        'http://[::ffff:a9fe:a9fe]/latest/meta-data/' => 'hex-mapped metadata literal',
        'http://[::ffff:c0a8:7]/' => 'hex-mapped 192.168.x literal',
        'http://[::ffff:ac10:1]/' => 'hex-mapped 172.16.x literal',
        'http://[::ffff:0a00:1]/' => 'hex-mapped 10.x literal',
        'http://[::ffff:6440:1]/' => 'hex-mapped CGNAT literal',
        'http://[::7f00:1]/' => 'hex-compat loopback literal',
        'http://[::a9fe:a9fe]/' => 'hex-compat metadata literal',
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

    // --- Workstream A: redirect walk + IP pinning (parity with safeFetch.js) ---
    // Stub fetcher returning a scripted sequence of [status, location, body]
    // tuples. Uses public-IP-literal URLs so resolveAndValidate needs no DNS.
    $walkStub = function ($script) {
        $i = 0;
        return function ($url, $parsed, $pinnedIp) use ($script, &$i) {
            $step = $script[min($i, count($script) - 1)];
            $i++;
            return array(
                'status' => $step[0],
                'location' => isset($step[1]) ? $step[1] : '',
                'body' => isset($step[2]) ? $step[2] : '',
            );
        };
    };

    // single 200 -> body returned
    $runner->assertEquals('OK', SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub(array(array(200, '', 'OK')))), 'walkRedirects returns 200 body');

    // 302 -> public -> 200 (redirect followed and re-validated)
    $runner->assertEquals('FINAL', SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub(array(
        array(302, 'http://93.184.215.14/b', ''),
        array(200, '', 'FINAL'),
    ))), 'walkRedirects follows 302 to public target');

    // 302 -> private -> rejected mid-chain (SSRF_PRIVATE)
    $threw = false;
    try {
        SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub(array(
            array(302, 'http://127.0.0.1/secret', ''),
        )));
    } catch (SsrfGuardException $e) {
        $threw = ($e->ssrfCode === 'SSRF_PRIVATE');
    }
    $runner->assert($threw, 'walkRedirects rejects redirect to private target');

    // 302 -> metadata -> rejected mid-chain (SSRF_PRIVATE)
    $threw = false;
    try {
        SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub(array(
            array(302, 'http://169.254.169.254/latest/meta-data/', ''),
        )));
    } catch (SsrfGuardException $e) {
        $threw = ($e->ssrfCode === 'SSRF_PRIVATE');
    }
    $runner->assert($threw, 'walkRedirects rejects redirect to metadata target');

    // hop cap (6 redirects, hops 0..5) -> SSRF_REDIRECTS
    $threw = false;
    try {
        $six = array();
        for ($i = 0; $i < 6; $i++) {
            $six[] = array(302, 'http://93.184.215.14/h' . $i, '');
        }
        SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub($six));
    } catch (SsrfGuardException $e) {
        $threw = ($e->ssrfCode === 'SSRF_REDIRECTS');
    }
    $runner->assert($threw, 'walkRedirects rejects hop cap exceeded');

    // 304 -> returned as-is (final)
    $r304 = SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub(array(array(304, '', ''))));
    $runner->assert($r304 === '' || $r304 === null, 'walkRedirects returns 304 as final');

    // 302 with empty Location -> returned as-is (no redirect to follow)
    $runner->assertEquals('EMPTYLOC', SsrfGuard::walkRedirects('http://93.184.215.14/a', $walkStub(array(array(302, '', 'EMPTYLOC')))), 'walkRedirects returns 3xx with no Location as-is');

    // --- buildPinnedResolveEntries (CURLOPT_RESOLVE entry builder) ---
    $runner->assertEquals(array('93.184.215.14:80:93.184.215.14'), SsrfGuard::buildPinnedResolveEntries(parse_url('http://93.184.215.14/x'), '93.184.215.14'), 'pin entry ipv4 literal');
    $runner->assertEquals(array('example.com:443:1.2.3.4'), SsrfGuard::buildPinnedResolveEntries(parse_url('https://example.com/x'), '1.2.3.4'), 'pin entry https default port');
    $runner->assertEquals(array('example.com:8080:1.2.3.4'), SsrfGuard::buildPinnedResolveEntries(parse_url('http://example.com:8080/x'), '1.2.3.4'), 'pin entry explicit port');
    $runner->assertEquals(array(), SsrfGuard::buildPinnedResolveEntries(array(), '1.2.3.4'), 'pin entry empty parsed returns empty');
    // IPv6: HOST field bare, ADDRESS field bracketed per libcurl docs.
    $runner->assertEquals(array('2001:4860:4860::8888:80:[2001:4860:4860::8888]'), SsrfGuard::buildPinnedResolveEntries(parse_url('http://[2001:4860:4860::8888]/'), '2001:4860:4860::8888'), 'pin entry ipv6 brackets address');

    // --- resolveRedirectUrl (Location resolution parity with new URL(loc, base)) ---
    $runner->assertEquals('http://93.184.215.14/c/d', SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a/b', '/c/d'), 'resolveRedirectUrl absolute path');
    $runner->assertEquals('https://other.example.org/x', SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a', 'https://other.example.org/x'), 'resolveRedirectUrl absolute new host');
    $runner->assertEquals('http://93.184.215.14/a/c', SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a/b', 'c'), 'resolveRedirectUrl relative');
    $runner->assertEquals('http://cdn.example.org/img.png', SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a', '//cdn.example.org/img.png'), 'resolveRedirectUrl protocol-relative');
    $runner->assertEquals(false, SsrfGuard::resolveRedirectUrl('', '/x'), 'resolveRedirectUrl empty base false');
    $runner->assertEquals(false, SsrfGuard::resolveRedirectUrl('http://x/', ''), 'resolveRedirectUrl empty location false');

    return $runner->report('SSRF Guard Tests');
}

if ((php_sapi_name() === 'cli' || !isset($_SERVER['SERVER_SOFTWARE'])) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_NAME'])) {
    $ok = runSsrfGuardTests();
    exit($ok ? 0 : 1);
}
