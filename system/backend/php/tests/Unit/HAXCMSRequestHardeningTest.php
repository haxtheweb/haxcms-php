<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the HAXCMS request-hardening seam.
 *
 * Covers the public methods on lib/HAXCMS.php that read config->security
 * and/or $_SERVER to harden trust decisions behind a configurable proxy
 * allowlist (M2 client-IP, M4 host/protocol validation, M3 refresh cookie).
 *
 * Expected values are derived from the SECURITY CONTRACT stated in each
 * method's docblock (what the function SHOULD do), not by copying the
 * implementation:
 *   - trusted proxies / allowed hosts: only honored from config->security,
 *     trimmed, non-empty, strings-only; unset -> empty list.
 *   - client IP: X-Forwarded-For is trusted ONLY when the immediate peer
 *     (REMOTE_ADDR) is in the allowlist; the XFF chain is walked
 *     right-to-left past chained trusted hops to the first untrusted hop.
 *   - host: X-Forwarded-Host honored only behind a trusted proxy; an
 *     allowlist mismatch falls back to allowedHosts[0] (host-header
 *     injection rejection).
 *   - protocol: server-set signals (HTTPS, REQUEST_SCHEME, protossl) are
 *     trusted directly; X-Forwarded-Proto:https honored ONLY when
 *     REMOTE_ADDR is a trusted proxy.
 *   - production runtime: true iff NODE_ENV === 'production'.
 *
 * Instances use newInstanceWithoutConstructor (skip the stateful god-class
 * constructor); these methods only read config / $_SERVER / $this->domain.
 * $_SERVER['SERVER_SOFTWARE'] is set so isCLI() is false (parity with sibling
 * tests, though none of these methods call isCLI directly). $_SERVER keys and
 * NODE_ENV are saved/restored for isolation.
 */
class HAXCMSRequestHardeningTest extends TestCase
{
    private $haxcms;
    private $savedServer = [];
    private $savedNodeEnv;

    // $_SERVER keys this suite may mutate.
    private $serverKeys = [
        'REMOTE_ADDR',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_HOST',
        'HTTPS',
        'REQUEST_SCHEME',
        'protossl',
        'SERVER_SOFTWARE',
    ];

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->config = new stdClass();
        // Save every $_SERVER key we might touch (preserving original unset
        // state vs. set-to-falsy state via isset).
        $this->savedServer = [];
        foreach ($this->serverKeys as $k) {
            $this->savedServer[$k] = isset($_SERVER[$k]) ? $_SERVER[$k] : null;
        }
        // Force isCLI() false (parity with sibling tests).
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        // Save NODE_ENV so isProductionRuntime tests are isolated.
        $this->savedNodeEnv = getenv('NODE_ENV');
        // Start each test with a clean (unset) NODE_ENV unless a test sets it.
        putenv('NODE_ENV');
    }

    protected function tearDown(): void
    {
        foreach ($this->serverKeys as $k) {
            if ($this->savedServer[$k] !== null) {
                $_SERVER[$k] = $this->savedServer[$k];
            } else {
                unset($_SERVER[$k]);
            }
        }
        if ($this->savedNodeEnv === false) {
            putenv('NODE_ENV');
        } else {
            putenv('NODE_ENV=' . $this->savedNodeEnv);
        }
    }

    /**
     * Set config->security with the given public properties (stdClass).
     */
    private function setSecurity(array $props): void
    {
        $security = new stdClass();
        foreach ($props as $k => $v) {
            $security->$k = $v;
        }
        $this->haxcms->config = new stdClass();
        $this->haxcms->config->security = $security;
    }

    /**
     * Unset a list of $_SERVER keys (for "header not present" cases).
     */
    private function unsetServerKeys(array $keys): void
    {
        foreach ($keys as $k) {
            unset($_SERVER[$k]);
        }
    }

    // ------------------------------------------------------------------
    // getTrustedProxies
    // ------------------------------------------------------------------

    public function testGetTrustedProxiesEmptyWhenSecurityUnset(): void
    {
        // config has no ->security at all
        $this->assertSame([], $this->haxcms->getTrustedProxies());
    }

    public function testGetTrustedProxiesEmptyWhenPropertyUnset(): void
    {
        $this->setSecurity([]);
        $this->assertSame([], $this->haxcms->getTrustedProxies());
    }

    public function testGetTrustedProxiesFromString(): void
    {
        $this->setSecurity(['trustedProxies' => '10.0.0.1']);
        $this->assertSame(['10.0.0.1'], $this->haxcms->getTrustedProxies());
    }

    public static function trustedProxiesArrayProvider(): array
    {
        // Contract: array -> trimmed, non-empty, string-only entries.
        return [
            'simple array' => [
                ['10.0.0.1', '10.0.0.2'],
                ['10.0.0.1', '10.0.0.2'],
            ],
            'whitespace trimmed' => [
                ['  10.0.0.1  ', '10.0.0.2'],
                ['10.0.0.1', '10.0.0.2'],
            ],
            'empty entries dropped' => [
                ['10.0.0.1', '', '10.0.0.2'],
                ['10.0.0.1', '10.0.0.2'],
            ],
            'whitespace-only entries dropped' => [
                ['10.0.0.1', '   ', '10.0.0.2'],
                ['10.0.0.1', '10.0.0.2'],
            ],
            'all empty -> empty' => [
                ['', '  '],
                [],
            ],
            'empty array -> empty' => [
                [],
                [],
            ],
            'non-string entries skipped' => [
                ['10.0.0.1', 123, true, null, '10.0.0.2'],
                ['10.0.0.1', '10.0.0.2'],
            ],
        ];
    }

    #[DataProvider('trustedProxiesArrayProvider')]
    public function testGetTrustedProxiesFromArray($raw, array $expected): void
    {
        $this->setSecurity(['trustedProxies' => $raw]);
        $this->assertSame($expected, $this->haxcms->getTrustedProxies());
    }

    // ------------------------------------------------------------------
    // isTrustedProxy
    // ------------------------------------------------------------------

    public function testIsTrustedProxyFalseForEmptyString(): void
    {
        $this->setSecurity(['trustedProxies' => ['10.0.0.1']]);
        $this->assertFalse($this->haxcms->isTrustedProxy(''));
    }

    public static function nonStringIpProvider(): array
    {
        return [
            'null' => [null],
            'integer' => [123],
            'boolean' => [true],
            'array' => [['10.0.0.1']],
        ];
    }

    #[DataProvider('nonStringIpProvider')]
    public function testIsTrustedProxyFalseForNonString($ip): void
    {
        $this->setSecurity(['trustedProxies' => ['10.0.0.1']]);
        $this->assertFalse($this->haxcms->isTrustedProxy($ip));
    }

    public function testIsTrustedProxyFalseWhenAllowlistEmpty(): void
    {
        // No trustedProxies configured -> nothing is trusted.
        $this->setSecurity([]);
        $this->assertFalse($this->haxcms->isTrustedProxy('10.0.0.1'));
    }

    public function testIsTrustedProxyTrueOnExactMatch(): void
    {
        $this->setSecurity(['trustedProxies' => ['10.0.0.1', '10.0.0.2']]);
        $this->assertTrue($this->haxcms->isTrustedProxy('10.0.0.1'));
        $this->assertTrue($this->haxcms->isTrustedProxy('10.0.0.2'));
    }

    public function testIsTrustedProxyFalseOnMismatch(): void
    {
        $this->setSecurity(['trustedProxies' => ['10.0.0.1']]);
        $this->assertFalse($this->haxcms->isTrustedProxy('10.0.0.99'));
    }

    public function testIsTrustedProxyDoesNotTrimInput(): void
    {
        // Characterization: isTrustedProxy uses EXACT (in_array strict) match
        // against the trimmed allowlist but does NOT trim the input $ip, so a
        // stray-space input does not match a clean allowlist entry. This is
        // consistent with the docblock's "exact match" contract — callers are
        // expected to supply a clean IP.
        $this->setSecurity(['trustedProxies' => ['10.0.0.1']]);
        $this->assertFalse($this->haxcms->isTrustedProxy('10.0.0.1 '));
        $this->assertFalse($this->haxcms->isTrustedProxy(' 10.0.0.1'));
    }

    // ------------------------------------------------------------------
    // resolveClientIP
    // ------------------------------------------------------------------

    /**
     * Columns: [trustedProxies, REMOTE_ADDR|null(unset), XFF|null(unset), expected]
     */
    public static function resolveClientIPProvider(): array
    {
        return [
            'no trusted proxy, remote set -> remote' => [
                [], '203.0.113.5', null, '203.0.113.5',
            ],
            'no trusted proxy, remote unset -> unknown' => [
                [], null, null, 'unknown',
            ],
            'no trusted proxy, remote empty string -> unknown' => [
                [], '', null, 'unknown',
            ],
            'remote not trusted, spoofed XFF ignored -> remote' => [
                ['10.0.0.1'], '203.0.113.5', '9.9.9.9', '203.0.113.5',
            ],
            'remote trusted, single XFF hop -> that hop' => [
                ['10.0.0.1'], '10.0.0.1', '203.0.113.5', '203.0.113.5',
            ],
            'remote trusted, chained proxies -> first untrusted hop (client)' => [
                ['10.0.0.1', '10.0.0.2'], '10.0.0.1',
                '203.0.113.5,10.0.0.2,10.0.0.1', '203.0.113.5',
            ],
            'remote trusted, XFF empty -> remote' => [
                ['10.0.0.1'], '10.0.0.1', '', '10.0.0.1',
            ],
            'remote trusted, no XFF header -> remote' => [
                ['10.0.0.1'], '10.0.0.1', null, '10.0.0.1',
            ],
            'remote trusted, XFF all-trusted chain -> leftmost hop' => [
                ['10.0.0.1', '10.0.0.2'], '10.0.0.1',
                '10.0.0.2,10.0.0.1', '10.0.0.2',
            ],
            'remote trusted, XFF whitespace-only hops -> remote' => [
                ['10.0.0.1'], '10.0.0.1', '  ,  ,  ', '10.0.0.1',
            ],
            'remote trusted, XFF with internal spaces trimmed -> first untrusted' => [
                ['10.0.0.1'], '10.0.0.1',
                ' 203.0.113.5 , 10.0.0.1 ', '203.0.113.5',
            ],
        ];
    }

    #[DataProvider('resolveClientIPProvider')]
    public function testResolveClientIP(
        $trustedProxies,
        $remoteAddr,
        $xff,
        string $expected
    ): void {
        if ($trustedProxies === []) {
            $this->setSecurity([]);
        } else {
            $this->setSecurity(['trustedProxies' => $trustedProxies]);
        }
        if ($remoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        }
        if ($xff === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
        }
        $this->assertSame($expected, $this->haxcms->resolveClientIP());
    }

    public function testResolveClientIPChainedProxyCase(): void
    {
        // Explicit named test for the canonical chained-proxy scenario from
        // the spec: REMOTE_ADDR=trusted proxy1, XFF='client,proxy2,proxy1'
        // where proxy1 and proxy2 are both trusted. Walking right-to-left
        // past proxy1 and proxy2 lands on the untrusted 'client'.
        $this->setSecurity(['trustedProxies' => ['proxy1', 'proxy2']]);
        $_SERVER['REMOTE_ADDR'] = 'proxy1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'client,proxy2,proxy1';
        $this->assertSame('client', $this->haxcms->resolveClientIP());
    }

    // ------------------------------------------------------------------
    // getAllowedHosts
    // ------------------------------------------------------------------

    public function testGetAllowedHostsEmptyWhenSecurityUnset(): void
    {
        $this->assertSame([], $this->haxcms->getAllowedHosts());
    }

    public function testGetAllowedHostsEmptyWhenPropertyUnset(): void
    {
        $this->setSecurity([]);
        $this->assertSame([], $this->haxcms->getAllowedHosts());
    }

    public function testGetAllowedHostsFromString(): void
    {
        $this->setSecurity(['allowedHosts' => 'example.com']);
        $this->assertSame(['example.com'], $this->haxcms->getAllowedHosts());
    }

    public static function allowedHostsArrayProvider(): array
    {
        return [
            'simple array' => [
                ['example.com', 'foo.bar'],
                ['example.com', 'foo.bar'],
            ],
            'whitespace trimmed' => [
                ['  example.com  ', 'foo.bar'],
                ['example.com', 'foo.bar'],
            ],
            'empty entries dropped' => [
                ['example.com', '', 'foo.bar'],
                ['example.com', 'foo.bar'],
            ],
            'all empty -> empty' => [
                ['', '  '],
                [],
            ],
            'empty array -> empty' => [
                [],
                [],
            ],
            'host with port preserved' => [
                ['example.com:8080', 'foo.bar'],
                ['example.com:8080', 'foo.bar'],
            ],
        ];
    }

    #[DataProvider('allowedHostsArrayProvider')]
    public function testGetAllowedHostsFromArray($raw, array $expected): void
    {
        $this->setSecurity(['allowedHosts' => $raw]);
        $this->assertSame($expected, $this->haxcms->getAllowedHosts());
    }

    // ------------------------------------------------------------------
    // resolveTrustedHost
    // ------------------------------------------------------------------

    /**
     * Columns (associative for clarity):
     *   trustedProxies, allowedHosts, REMOTE_ADDR, XFH, HTTP_HOST, domain, expected
     */
    public static function resolveTrustedHostProvider(): array
    {
        return [
            'not behind proxy, HTTP_HOST, no allowlist -> HTTP_HOST' => [
                [], [], '203.0.113.5', null, 'example.com', 'fallback.test', 'example.com',
            ],
            'not behind proxy, no HTTP_HOST, no allowlist, domain -> domain' => [
                [], [], '203.0.113.5', null, null, 'fallback.test', 'fallback.test',
            ],
            'not behind proxy, no HTTP_HOST, no allowlist, no domain -> empty' => [
                [], [], '203.0.113.5', null, null, null, '',
            ],
            'behind trusted proxy, XFH, no allowlist -> XFH' => [
                ['10.0.0.1'], [], '10.0.0.1', 'proxy.example.com', 'internal.example.com', 'fallback.test', 'proxy.example.com',
            ],
            'host-header injection rejected: mismatch -> first allowed' => [
                [], ['good.com'], '203.0.113.5', null, 'evil.com', 'fallback.test', 'good.com',
            ],
            'allowlist match -> candidate' => [
                [], ['good.com'], '203.0.113.5', null, 'good.com', 'fallback.test', 'good.com',
            ],
            'behind proxy, XFH mismatch, HTTP_HOST match -> HTTP_HOST (first allowed)' => [
                ['10.0.0.1'], ['good.com'], '10.0.0.1', 'evil.com', 'good.com', 'fallback.test', 'good.com',
            ],
            'behind proxy, XFH match allowlist -> XFH' => [
                ['10.0.0.1'], ['good.com'], '10.0.0.1', 'good.com', 'internal.com', 'fallback.test', 'good.com',
            ],
            'behind proxy, XFH multi-hop -> first (leftmost)' => [
                ['10.0.0.1'], [], '10.0.0.1', 'a.com, b.com', 'internal.com', 'fallback.test', 'a.com',
            ],
            'not behind proxy, empty HTTP_HOST, allowlist present -> first allowed' => [
                [], ['good.com'], '203.0.113.5', null, '', 'fallback.test', 'good.com',
            ],
            'not behind proxy, no HTTP_HOST, allowlist present -> first allowed' => [
                [], ['good.com'], '203.0.113.5', null, null, 'fallback.test', 'good.com',
            ],
        ];
    }

    #[DataProvider('resolveTrustedHostProvider')]
    public function testResolveTrustedHost(
        $trustedProxies,
        $allowedHosts,
        $remoteAddr,
        $xfh,
        $httpHost,
        $domain,
        string $expected
    ): void {
        $security = [];
        if (!empty($trustedProxies)) {
            $security['trustedProxies'] = $trustedProxies;
        }
        if (!empty($allowedHosts)) {
            $security['allowedHosts'] = $allowedHosts;
        }
        $this->setSecurity($security);

        if ($remoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        }
        if ($xfh === null) {
            unset($_SERVER['HTTP_X_FORWARDED_HOST']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_HOST'] = $xfh;
        }
        if ($httpHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $httpHost;
        }
        // $domain is a declared class property (default null). The real
        // "domain not set" state is null (e.g. a request without HTTP_HOST),
        // not a removed property, so set null rather than unset to avoid an
        // artificial Undefined-property warning production never triggers.
        if ($domain === null) {
            $this->haxcms->domain = null;
        } else {
            $this->haxcms->domain = $domain;
        }
        $this->assertSame($expected, $this->haxcms->resolveTrustedHost());
    }

    public function testResolveTrustedHostDomainFallbackIsStringGuarded(): void
    {
        // When domain is null (the declared default when HTTP_HOST was never
        // set, e.g. a CLI request), is_string($this->domain) is false so the
        // fallback yields '' rather than a type error.
        $this->setSecurity([]);
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_X_FORWARDED_HOST']);
        unset($_SERVER['HTTP_HOST']);
        $this->haxcms->domain = null;
        $this->assertSame('', $this->haxcms->resolveTrustedHost());
    }

    // ------------------------------------------------------------------
    // resolveTrustedProtocol
    // ------------------------------------------------------------------

    /**
     * Columns: [trustedProxies, REMOTE_ADDR, HTTPS, REQUEST_SCHEME, protossl, XFP, expected]
     */
    public static function resolveTrustedProtocolProvider(): array
    {
        return [
            'no signals -> http' => [
                [], '203.0.113.5', null, null, null, null, 'http',
            ],
            'HTTPS=on -> https' => [
                [], '203.0.113.5', 'on', null, null, null, 'https',
            ],
            'HTTPS=1 -> https' => [
                [], '203.0.113.5', '1', null, null, null, 'https',
            ],
            'HTTPS=off -> http (off excluded)' => [
                [], '203.0.113.5', 'off', null, null, null, 'http',
            ],
            'HTTPS=no -> http (no excluded)' => [
                [], '203.0.113.5', 'no', null, null, null, 'http',
            ],
            'HTTPS=On (mixed case) -> https' => [
                [], '203.0.113.5', 'On', null, null, null, 'https',
            ],
            'REQUEST_SCHEME=https -> https' => [
                [], '203.0.113.5', null, 'https', null, null, 'https',
            ],
            'REQUEST_SCHEME=http -> http' => [
                [], '203.0.113.5', null, 'http', null, null, 'http',
            ],
            'protossl set -> https' => [
                [], '203.0.113.5', null, null, '1', null, 'https',
            ],
            'remote trusted + XFP=https -> https' => [
                ['10.0.0.1'], '10.0.0.1', null, null, null, 'https', 'https',
            ],
            'remote NOT trusted + XFP=https -> http (XFP ignored)' => [
                ['10.0.0.1'], '203.0.113.5', null, null, null, 'https', 'http',
            ],
            'remote trusted + XFP=http -> http' => [
                ['10.0.0.1'], '10.0.0.1', null, null, null, 'http', 'http',
            ],
            'remote trusted + XFP=HTTPS (uppercase) -> https' => [
                ['10.0.0.1'], '10.0.0.1', null, null, null, 'HTTPS', 'https',
            ],
            'remote trusted + XFP=https,http (comma list) -> https (first)' => [
                ['10.0.0.1'], '10.0.0.1', null, null, null, 'https,http', 'https',
            ],
            'HTTPS precedence over REQUEST_SCHEME=http' => [
                [], '203.0.113.5', 'on', 'http', null, null, 'https',
            ],
            'no allowlist + XFP=https (untrusted remote) -> http' => [
                [], '203.0.113.5', null, null, null, 'https', 'http',
            ],
        ];
    }

    #[DataProvider('resolveTrustedProtocolProvider')]
    public function testResolveTrustedProtocol(
        $trustedProxies,
        $remoteAddr,
        $https,
        $requestScheme,
        $protossl,
        $xfp,
        string $expected
    ): void {
        if (empty($trustedProxies)) {
            $this->setSecurity([]);
        } else {
            $this->setSecurity(['trustedProxies' => $trustedProxies]);
        }
        $this->unsetServerKeys(['REMOTE_ADDR', 'HTTPS', 'REQUEST_SCHEME', 'protossl', 'HTTP_X_FORWARDED_PROTO']);
        if ($remoteAddr !== null) {
            $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        }
        if ($https !== null) {
            $_SERVER['HTTPS'] = $https;
        }
        if ($requestScheme !== null) {
            $_SERVER['REQUEST_SCHEME'] = $requestScheme;
        }
        if ($protossl !== null) {
            $_SERVER['protossl'] = $protossl;
        }
        if ($xfp !== null) {
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = $xfp;
        }
        $this->assertSame($expected, $this->haxcms->resolveTrustedProtocol());
    }

    // ------------------------------------------------------------------
    // isProductionRuntime
    // ------------------------------------------------------------------

    /**
     * Columns: [nodeEnvValue|null(unset), expected]
     */
    public static function isProductionRuntimeProvider(): array
    {
        return [
            'unset -> false' => [null, false],
            'production -> true' => ['production', true],
            'PRODUCTION -> true (case-insensitive)' => ['PRODUCTION', true],
            '  production  -> true (trimmed)' => ['  production  ', true],
            'development -> false' => ['development', false],
            'empty string -> false' => ['', false],
            'prod -> false (not exact)' => ['prod', false],
            'production-like -> false' => ['production-like', false],
        ];
    }

    #[DataProvider('isProductionRuntimeProvider')]
    public function testIsProductionRuntime($nodeEnv, bool $expected): void
    {
        if ($nodeEnv === null) {
            putenv('NODE_ENV');
        } else {
            putenv('NODE_ENV=' . $nodeEnv);
        }
        $this->assertSame($expected, $this->haxcms->isProductionRuntime());
    }

    // ------------------------------------------------------------------
    // setRefreshTokenCookie
    // ------------------------------------------------------------------

    public function testSetRefreshTokenCookieReturnsTrue(): void
    {
        // setcookie() returns true when headers have not been sent (the norm
        // under PHPUnit's output buffering). @ suppresses the "headers already
        // sent" warning if a prior test disrupted buffering, so the assertion
        // pins the observable return value rather than a warning.
        if (headers_sent()) {
            $this->markTestSkipped('headers already sent before test — cannot observe setcookie return');
        }
        $result = @$this->haxcms->setRefreshTokenCookie('token-abc');
        $this->assertTrue($result);
    }

    public function testSetRefreshTokenCookieWithExplicitExpiresReturnsTrue(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('headers already sent before test — cannot observe setcookie return');
        }
        $result = @$this->haxcms->setRefreshTokenCookie('clear-me', 1);
        $this->assertTrue($result);
    }

    public function testSetRefreshTokenCookieForceSecureCookieOverridePathRuns(): void
    {
        // Characterizes the forceSecureCookie override branch: with
        // isProductionRuntime() false but forceSecureCookie true, the method
        // must still execute and return the setcookie result without error.
        if (headers_sent()) {
            $this->markTestSkipped('headers already sent before test — cannot observe setcookie return');
        }
        $this->setSecurity(['forceSecureCookie' => true]);
        // NODE_ENV is unset in setUp so isProductionRuntime() is false here.
        $this->assertFalse($this->haxcms->isProductionRuntime());
        $result = @$this->haxcms->setRefreshTokenCookie('token-xyz');
        $this->assertTrue($result);
    }

    public function testSetRefreshTokenCookieInProductionRuntimeReturnsTrue(): void
    {
        // With NODE_ENV=production, isProductionRuntime() is true so the
        // Secure flag defaults on; the method must still return the setcookie
        // result.
        if (headers_sent()) {
            $this->markTestSkipped('headers already sent before test — cannot observe setcookie return');
        }
        putenv('NODE_ENV=production');
        $this->assertTrue($this->haxcms->isProductionRuntime());
        $result = @$this->haxcms->setRefreshTokenCookie('token-prod');
        $this->assertTrue($result);
    }

    public function testSetRefreshTokenCookieSecureFlagValueNotIntrospectableInCli(): void
    {
        // The actual Secure / HttpOnly / SameSite flag VALUES passed to
        // setcookie() cannot be introspected in the CLI sapi: headers_list()
        // is empty under CLI (headers are "sent" immediately and not
        // captured), and no function-interception extension (uopz, runkit,
        // xdebug) is available to wrap the global setcookie(). The decision
        // logic (isProductionRuntime + forceSecureCookie) is characterized
        // directly via the isProductionRuntime data provider above and by the
        // override-path/production-path execution tests. This test is kept as
        // an explicit skip so the gap is visible rather than silently absent.
        if (function_exists('xdebug_get_cookie') || extension_loaded('uopz') || extension_loaded('runkit7')) {
            $this->markTestSkipped('flag-introspection extension present but not wired in this test');
        }
        $this->markTestSkipped(
            'setcookie() arguments cannot be introspected in CLI sapi without '
            . 'uopz/runkit/xdebug; Secure-flag value asserted via decision logic instead'
        );
    }
}
