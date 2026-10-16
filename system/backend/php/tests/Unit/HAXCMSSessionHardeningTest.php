<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Security (SEC-SESSION) regression tests for PR3 session hardening:
 *   1. connection-settings omits siteToken/userToken (and the token header
 *      entries) for callers without a valid Bearer JWT or store-checked
 *      refresh session.
 *   2. refresh revocation writes tombstones; family-bearing tokens without a
 *      store entry are rejected; only no-family legacy tokens seed the store;
 *      reuse of a stale jti tombstones the family; getAuthenticatedUserName
 *      consults the store.
 *   3. isSameOriginRequest (session/refresh + session/connection-test gate),
 *      including Sec-Fetch-Site: cross-site.
 *   5. login throttle prunes expired cache entries; bootstrap ignores
 *      client X-Forwarded-Proto unless REMOTE_ADDR is a trusted proxy.
 */
class HAXCMSSessionHardeningTest extends TestCase
{
    private $tmpConfigDir;
    private $savedServer = array();
    private $savedCookie;
    private $savedGlobalHaxcms;
    private $serverKeys = array(
        'SERVER_SOFTWARE', 'HTTP_HOST', 'REQUEST_URI', 'REMOTE_ADDR', 'HTTPS',
        'REQUEST_SCHEME', 'protossl', 'HTTP_X_FORWARDED_PROTO', 'HTTP_ORIGIN',
        'HTTP_REFERER', 'HTTP_SEC_FETCH_SITE', 'HTTP_AUTHORIZATION', 'HTTP_USESSL',
        'HTTP_HTTPS_ENABLED',
    );

    protected function setUp(): void
    {
        foreach ($this->serverKeys as $k) {
            $this->savedServer[$k] = array_key_exists($k, $_SERVER) ? $_SERVER[$k] : null;
            unset($_SERVER[$k]);
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['REQUEST_URI'] = '/system/api/v1/session/connection-settings';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->savedCookie = $_COOKIE['haxcms_refresh_token'] ?? null;
        unset($_COOKIE['haxcms_refresh_token']);
        $this->savedGlobalHaxcms = $GLOBALS['HAXCMS'] ?? null;
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_session_hardening_' . uniqid();
        mkdir($this->tmpConfigDir . '/settings', 0777, true);
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
        if ($this->savedCookie !== null) {
            $_COOKIE['haxcms_refresh_token'] = $this->savedCookie;
        } else {
            unset($_COOKIE['haxcms_refresh_token']);
        }
        if ($this->savedGlobalHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedGlobalHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpConfigDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Lightweight instance for store-level tests (no constructor). */
    private function bareHaxcms(): HAXCMS
    {
        $h = (new ReflectionClass(HAXCMS::class))->newInstanceWithoutConstructor();
        $h->privateKey = 'pk';
        $h->refreshPrivateKey = 'rpk';
        $h->salt = 's';
        $h->config = new stdClass();
        $h->user = new stdClass();
        $h->user->name = 'alice';
        $h->superUser = new stdClass();
        $h->superUser->name = 'alice';
        $h->configDirectory = $this->tmpConfigDir;
        return $h;
    }

    /** Fully constructed instance (needed for appJWTConnectionSettings). */
    private function fullHaxcms(): HAXCMS
    {
        $h = new HAXCMS();
        $h->privateKey = 'pk';
        $h->refreshPrivateKey = 'rpk';
        $h->salt = 's';
        $h->configDirectory = $this->tmpConfigDir;
        $h->user = new stdClass();
        $h->user->name = 'alice';
        $h->superUser = new stdClass();
        $h->superUser->name = 'alice';
        return $h;
    }

    private function sessions(): array
    {
        $p = $this->tmpConfigDir . '/settings/refreshSessions.json';
        if (!file_exists($p)) {
            return array();
        }
        $d = json_decode(file_get_contents($p), true);
        return is_array($d) ? $d : array();
    }

    private function signRefresh(HAXCMS $h, array $payload): string
    {
        $payload += array('iat' => time() - 5, 'exp' => time() + 3600);
        return JWT::encode($payload, $h->refreshPrivateKey . $h->salt);
    }

    // ---------------------------------------------------------------
    // 2. refresh-session store: tombstones + legacy policy
    // ---------------------------------------------------------------

    public function testLogoutTombstoneRejectsEveryLaterAttempt(): void
    {
        $h = $this->bareHaxcms();
        $token = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertTrue($h->validateRefreshSession('alice', $token->family, $token->jti));
        $h->revokeRefreshSession('alice');
        $entry = $this->sessions()['alice'];
        $this->assertTrue($entry['revoked']);
        $this->assertIsInt($entry['revokedAt']);
        $this->assertGreaterThanOrEqual(time() + 24 * 60 * 60 - 5, $entry['exp']);
        // current jti, legacy token, rotation, and the connection-test path
        // via rotateRefreshTokenAndCookie are all rejected now
        $this->assertFalse($h->validateRefreshSession('alice', $token->family, $token->jti));
        $this->assertFalse($h->validateRefreshSession('alice', null, null));
        $this->assertFalse($h->rotateRefreshSession('alice', $token->family, $token->jti, 'n', time() + 100));
        $this->assertNull($h->rotateRefreshTokenAndCookie($token));
        $this->assertNull($h->rotateRefreshTokenAndCookie((object) array('user' => 'alice')));
        // tombstone is still in place after all those attempts
        $this->assertTrue($this->sessions()['alice']['revoked']);
    }

    public function testFreshLoginAfterLogoutDoesNotRevivePreLogoutToken(): void
    {
        $h = $this->bareHaxcms();
        $stolen = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $h->revokeRefreshSession('alice');
        $fresh = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertTrue($h->validateRefreshSession('alice', $fresh->family, $fresh->jti));
        $this->assertFalse($h->validateRefreshSession('alice', $stolen->family, $stolen->jti));
        $this->assertNull($h->rotateRefreshTokenAndCookie($stolen));
        // the stolen token's failure didn't disturb the fresh session
        $this->assertTrue($h->validateRefreshSession('alice', $fresh->family, $fresh->jti));
    }

    public function testFamilyTokenWithoutStoreEntryIsRejectedAndNotSeeded(): void
    {
        $h = $this->bareHaxcms();
        $decoded = (object) array('user' => 'alice', 'family' => 'fam-x', 'jti' => 'jti-x');
        $this->assertFalse($h->validateRefreshSession('alice', 'fam-x', 'jti-x'));
        $this->assertNull($h->rotateRefreshTokenAndCookie($decoded));
        $this->assertSame(array(), $this->sessions());
    }

    public function testLegacyTokenWithNoEntryIsAcceptedAndSeeded(): void
    {
        $h = $this->bareHaxcms();
        $legacy = $h->decodeRefreshToken($h->getRefreshToken('alice', false));
        $this->assertFalse(isset($legacy->family));
        $this->assertTrue($h->validateRefreshSession('alice', null, null));
        $access = $h->rotateRefreshTokenAndCookie($legacy);
        $this->assertNotNull($access);
        $entry = $this->sessions()['alice'];
        $this->assertNotEmpty($entry['family']);
        $this->assertArrayNotHasKey('revoked', $entry);
        // the same legacy token cannot seed/rotate again now that an entry exists
        $this->assertFalse($h->validateRefreshSession('alice', null, null));
        $this->assertNull($h->rotateRefreshTokenAndCookie($legacy));
        // and that rejection did not tombstone the newly-seeded family
        $this->assertSame($entry, $this->sessions()['alice']);
    }

    public function testStaleJtiTombstonesFamilyViaValidate(): void
    {
        $h = $this->bareHaxcms();
        $t = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertFalse($h->validateRefreshSession('alice', $t->family, 'stale-jti'));
        $this->assertTrue($this->sessions()['alice']['revoked']);
        $this->assertFalse($h->validateRefreshSession('alice', $t->family, $t->jti));
    }

    public function testPreviousJtiOutsideGraceIsReuse(): void
    {
        $h = $this->bareHaxcms();
        $h->recordRefreshSession('alice', 'fam', 'a', time() + 3600);
        $this->assertTrue($h->rotateRefreshSession('alice', 'fam', 'a', 'b', time() + 3600));
        // force the grace window into the past
        $s = $this->sessions();
        $s['alice']['previousValidUntil'] = time() - 1;
        file_put_contents($this->tmpConfigDir . '/settings/refreshSessions.json', json_encode($s));
        $this->assertFalse($h->rotateRefreshSession('alice', 'fam', 'a', 'c', time() + 3600));
        $this->assertTrue($this->sessions()['alice']['revoked']);
        $this->assertFalse($h->validateRefreshSession('alice', 'fam', 'b'));
    }

    public function testExpiredTombstonesArePrunedOnWrite(): void
    {
        $h = $this->bareHaxcms();
        file_put_contents($this->tmpConfigDir . '/settings/refreshSessions.json', json_encode(array(
            'old' => array('revoked' => true, 'revokedAt' => time() - 90000, 'exp' => time() - 10),
            'live' => array('revoked' => true, 'revokedAt' => time(), 'exp' => time() + 1000),
        )));
        $h->recordRefreshSession('alice', 'f', 'j', time() + 3600);
        $s = $this->sessions();
        $this->assertArrayNotHasKey('old', $s);
        $this->assertArrayHasKey('live', $s);
        $this->assertArrayHasKey('alice', $s);
    }

    public function testGetAuthenticatedUserNameConsultsStore(): void
    {
        $h = $this->bareHaxcms();
        $_COOKIE['haxcms_refresh_token'] = $h->getRefreshToken('alice', true);
        $this->assertSame('alice', $h->getAuthenticatedUserName());
        $h->revokeRefreshSession('alice');
        $this->assertNull($h->getAuthenticatedUserName(), 'revoked refresh cookie must not authenticate');
        // a signature-valid family token that the store never saw
        $_COOKIE['haxcms_refresh_token'] = $this->signRefresh($h, array('user' => 'bob', 'family' => 'f', 'jti' => 'j'));
        $this->assertNull($h->getAuthenticatedUserName());
    }

    // ---------------------------------------------------------------
    // 1. connection-settings token omission
    // ---------------------------------------------------------------

    private function assertNoTokens($settings): void
    {
        $this->assertFalse(property_exists($settings, 'siteToken'));
        $this->assertFalse(property_exists($settings, 'userToken'));
        $this->assertFalse(property_exists($settings->getUserDataHeaders, 'X-HAXCMS-User-Token'));
        $this->assertFalse(property_exists($settings->appStore->headers, 'X-HAXCMS-Site-Token'));
        // the rest of the payload is still served so the editor can boot
        $this->assertNotEmpty($settings->login);
        $this->assertNotEmpty($settings->systemApiBasePath);
    }

    private function assertTokens(HAXCMS $h, $settings, string $user): void
    {
        $this->assertSame($h->getRequestToken($user), $settings->userToken);
        $this->assertSame($settings->userToken, $settings->getUserDataHeaders->{'X-HAXCMS-User-Token'});
        $this->assertSame($settings->siteToken, $settings->appStore->headers->{'X-HAXCMS-Site-Token'});
        $this->assertNotSame('', $settings->siteToken);
    }

    public function testConnectionSettingsOmitsTokensForAnonymousCaller(): void
    {
        $h = $this->fullHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $this->assertNoTokens($h->appJWTConnectionSettings('/'));
    }

    public function testConnectionSettingsOmitsTokensForRevokedOrUnknownRefreshCookie(): void
    {
        $h = $this->fullHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $_COOKIE['haxcms_refresh_token'] = $h->getRefreshToken('alice', true);
        $h->revokeRefreshSession('alice');
        $this->assertNoTokens($h->appJWTConnectionSettings('/'));
        $_COOKIE['haxcms_refresh_token'] = $this->signRefresh($h, array('user' => 'alice', 'family' => 'nope', 'jti' => 'nope'));
        $this->assertNoTokens($h->appJWTConnectionSettings('/'));
        $_COOKIE['haxcms_refresh_token'] = 'garbage';
        $this->assertNoTokens($h->appJWTConnectionSettings('/'));
    }

    public function testConnectionSettingsOmitsTokensForInvalidBearer(): void
    {
        $h = $this->fullHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $h->sessionJwt = 'not-a-jwt';
        $this->assertNoTokens($h->appJWTConnectionSettings('/'));
    }

    public function testConnectionSettingsIncludesTokensForValidRefreshSession(): void
    {
        $h = $this->fullHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $_COOKIE['haxcms_refresh_token'] = $h->getRefreshToken('alice', true);
        $this->assertTokens($h, $h->appJWTConnectionSettings('/'), 'alice');
    }

    public function testConnectionSettingsIncludesTokensForValidBearer(): void
    {
        $h = $this->fullHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $h->sessionJwt = $h->getJWT('alice');
        $this->assertTokens($h, $h->appJWTConnectionSettings('/'), 'alice');
    }

    // ---------------------------------------------------------------
    // 3. same-origin gate
    // ---------------------------------------------------------------

    public static function sameOriginProvider(): array
    {
        return array(
            'no origin, no referer'               => array(array(), true),
            'matching origin'                     => array(array('HTTP_ORIGIN' => 'http://example.test'), true),
            'matching origin, case-insensitive'   => array(array('HTTP_ORIGIN' => 'http://EXAMPLE.test'), true),
            'matching referer (no origin)'        => array(array('HTTP_REFERER' => 'http://example.test/sites/a/'), true),
            'foreign origin'                      => array(array('HTTP_ORIGIN' => 'https://evil.test'), false),
            'scheme mismatch'                     => array(array('HTTP_ORIGIN' => 'https://example.test'), false),
            'port mismatch'                       => array(array('HTTP_ORIGIN' => 'http://example.test:8080'), false),
            'foreign referer'                     => array(array('HTTP_REFERER' => 'http://evil.test/x'), false),
            'origin null'                         => array(array('HTTP_ORIGIN' => 'null'), false),
            'sec-fetch-site cross-site only'      => array(array('HTTP_SEC_FETCH_SITE' => 'cross-site'), false),
            'sec-fetch cross-site + good origin'  => array(array('HTTP_SEC_FETCH_SITE' => 'Cross-Site', 'HTTP_ORIGIN' => 'http://example.test'), false),
            'sec-fetch same-origin'               => array(array('HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'http://example.test'), true),
            'sec-fetch none (typed URL)'          => array(array('HTTP_SEC_FETCH_SITE' => 'none'), true),
            'sec-fetch same-site + foreign origin'=> array(array('HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => 'http://sub.example.test'), false),
        );
    }

    #[DataProvider('sameOriginProvider')]
    public function testIsSameOriginRequest(array $server, bool $expected): void
    {
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $this->assertSame($expected, $this->bareHaxcms()->isSameOriginRequest());
    }

    public function testRefreshOperationRejectsCrossSiteBeforeTouchingStore(): void
    {
        $h = $this->bareHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $_COOKIE['haxcms_refresh_token'] = $h->getRefreshToken('alice', true);
        $before = $this->sessions();
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        $ops = new Operations();
        $res = $ops->refreshAccessToken();
        $this->assertSame(403, $res['__failed']['status']);
        $this->assertSame('Cross-origin request denied', $res['__failed']['message']);
        $this->assertSame($before, $this->sessions(), 'no rotation on cross-site');
        // same-origin still rotates
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
        $res = $ops->refreshAccessToken();
        $this->assertSame(200, $res['status']);
        $this->assertNotEmpty($res['jwt']);
    }

    // ---------------------------------------------------------------
    // 5. login throttle cache pruning + XFP bootstrap
    // ---------------------------------------------------------------

    public function testLoginThrottleWritePrunesExpiredEntriesWhenCacheIsLarge(): void
    {
        $cacheDir = $this->tmpConfigDir . '/cache/';
        $cache = new Cache();
        $cache->setCachePath($cacheDir);
        $data = array();
        for ($i = 0; $i < 600; $i++) {
            $data['login-rate:' . sha1('old' . $i)] = array(
                'time' => time() - 10000,
                'expire' => 100,
                'data' => json_encode(array('firstAttempt' => 0, 'failedAttempts' => 1, 'blockedUntil' => 0)),
            );
        }
        $data['login-rate:live'] = array('time' => time(), 'expire' => 1000, 'data' => json_encode(array('x' => 1)));
        file_put_contents($cache->getCacheDir(), json_encode($data));
        $this->assertGreaterThan(65536, filesize($cache->getCacheDir()));
        $haxcms = new stdClass();
        $haxcms->cache = $cache;
        $GLOBALS['HAXCMS'] = $haxcms;
        $settings = (object) array('windowMs' => 60000, 'blockMs' => 60000);
        $m = new ReflectionMethod(Operations::class, 'saveLoginAttemptEntry');
        $m->setAccessible(true);
        $m->invoke(new Operations(), 'login-rate:new', array('firstAttempt' => 1, 'failedAttempts' => 1, 'blockedUntil' => 0), $settings);
        $all = $cache->retrieveAll(true);
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('login-rate:live', $all);
        $this->assertArrayHasKey('login-rate:new', $all);
    }

    public function testCacheEraseExpiredSkipsMalformedEntries(): void
    {
        $cache = new Cache();
        $cache->setCachePath($this->tmpConfigDir . '/cache2/');
        file_put_contents($cache->getCacheDir(), json_encode(array(
            'bad' => 'string',
            'old' => array('time' => time() - 1000, 'expire' => 10, 'data' => '1'),
        )));
        $this->assertSame(1, $cache->eraseExpired());
        $this->assertArrayHasKey('bad', $cache->retrieveAll(true));
    }

    public function testBootstrapIgnoresClientForwardedProtoWithoutTrustedProxy(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HTTPS_ENABLED'] = '1';
        $h = new HAXCMS();
        $this->assertSame('http', $h->protocol);
        // a trusted proxy may still assert https through resolveTrustedProtocol
        $h->config->security = (object) array('trustedProxies' => array('203.0.113.5'));
        $this->assertSame('https', $h->resolveTrustedProtocol());
    }
}
