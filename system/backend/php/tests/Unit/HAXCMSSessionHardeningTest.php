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

    private function fam(string $user, string $family): ?array
    {
        return $this->sessions()['users'][$user]['families'][$family] ?? null;
    }

    private function writeStore(array $raw): void
    {
        file_put_contents($this->tmpConfigDir . '/settings/refreshSessions.json', json_encode($raw));
    }

    // ---------------------------------------------------------------
    // 2. refresh-session store (v2, per-family): tombstones + legacy policy
    // ---------------------------------------------------------------

    public function testTwoBrowsersBothRefreshAndLogoutOnAOnlyAffectsA(): void
    {
        $h = $this->bareHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $ops = new Operations();
        // browser A logs in, then browser B logs in
        $cookieA = $h->getRefreshToken('alice', true);
        $cookieB = $h->getRefreshToken('alice', true);
        $famA = $h->decodeRefreshToken($cookieA)->family;
        $famB = $h->decodeRefreshToken($cookieB)->family;
        $this->assertNotSame($famA, $famB);
        // both refresh successfully (rotation of A doesn't disturb B and v.v.)
        $_COOKIE['haxcms_refresh_token'] = $cookieA;
        $resA = $ops->refreshAccessToken();
        $this->assertSame(200, $resA['status']);
        $_COOKIE['haxcms_refresh_token'] = $cookieB;
        $resB = $ops->refreshAccessToken();
        $this->assertSame(200, $resB['status']);
        // rotate A once more using its (now previous, in-grace) jti is fine,
        // and A's current family entry is live
        $this->assertArrayNotHasKey('revoked', $this->fam('alice', $famA));
        // logout on A (presenting A's original cookie, same family)
        $_COOKIE['haxcms_refresh_token'] = $cookieA;
        $ops->logout();
        $this->assertTrue($this->fam('alice', $famA)['revoked']);
        $this->assertArrayNotHasKey('revoked', $this->fam('alice', $famB));
        // A is out, B still refreshes and still authenticates
        $_COOKIE['haxcms_refresh_token'] = $cookieA;
        $this->assertNull($h->getAuthenticatedUserName());
        $this->assertSame(401, $ops->refreshAccessToken()['__failed']['status']);
        $decodedB = $h->decodeRefreshToken($cookieB);
        $this->assertTrue($h->validateRefreshSession('alice', $decodedB->family, $decodedB->jti),
            'B previous jti still inside the grace window');
        $this->assertArrayNotHasKey('revoked', $this->fam('alice', $famB));
    }

    public function testV1StoreIsMigratedOnLoadAndSessionsSurvive(): void
    {
        $h = $this->bareHaxcms();
        $exp = time() + 3600;
        $this->writeStore(array(
            'alice' => array(
                'family' => 'fam-v1',
                'currentJtiHash' => hash('sha256', 'jti-cur'),
                'previousJtiHash' => hash('sha256', 'jti-prev'),
                'previousValidUntil' => time() + 20,
                'exp' => $exp,
            ),
            'bob' => array('garbage' => true),
            '__proto__' => array('family' => 'x', 'exp' => $exp),
        ));
        $this->assertTrue($h->validateRefreshSession('alice', 'fam-v1', 'jti-cur'));
        $this->assertTrue($h->validateRefreshSession('alice', 'fam-v1', 'jti-prev'));
        // first write persists the v2 shape
        $this->assertTrue($h->rotateRefreshSession('alice', 'fam-v1', 'jti-cur', 'jti-new', $exp));
        $store = $this->sessions();
        $this->assertSame(2, $store['version']);
        $this->assertSame(array('alice'), array_keys($store['users']));
        $this->assertSame(hash('sha256', 'jti-new'), $store['users']['alice']['families']['fam-v1']['currentJtiHash']);
        $this->assertTrue($h->validateRefreshSession('alice', 'fam-v1', 'jti-new'));
    }

    public function testLogoutTombstoneRejectsEveryLaterAttemptForThatFamily(): void
    {
        $h = $this->bareHaxcms();
        $token = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertTrue($h->validateRefreshSession('alice', $token->family, $token->jti));
        $h->revokeRefreshSession('alice', $token->family);
        $entry = $this->fam('alice', $token->family);
        $this->assertTrue($entry['revoked']);
        $this->assertIsInt($entry['revokedAt']);
        $this->assertGreaterThanOrEqual(time() + 86400 + 60 - 2, $entry['exp']);
        $this->assertFalse($h->validateRefreshSession('alice', $token->family, $token->jti));
        $this->assertFalse($h->rotateRefreshSession('alice', $token->family, $token->jti, 'n', time() + 100));
        $this->assertNull($h->rotateRefreshTokenAndCookie($token));
        $this->assertTrue($this->fam('alice', $token->family)['revoked']);
    }

    public function testFreshLoginAfterLogoutDoesNotRevivePreLogoutToken(): void
    {
        $h = $this->bareHaxcms();
        $stolen = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $h->revokeRefreshSession('alice', $stolen->family);
        $fresh = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertTrue($h->validateRefreshSession('alice', $fresh->family, $fresh->jti));
        $this->assertFalse($h->validateRefreshSession('alice', $stolen->family, $stolen->jti));
        $this->assertNull($h->rotateRefreshTokenAndCookie($stolen));
        $this->assertTrue($h->validateRefreshSession('alice', $fresh->family, $fresh->jti));
    }

    public function testFamilyTokenWithoutStoreEntryIsRejectedAndNotSeeded(): void
    {
        $h = $this->bareHaxcms();
        $decoded = (object) array('user' => 'alice', 'family' => 'fam-x', 'jti' => 'jti-x');
        $this->assertFalse($h->validateRefreshSession('alice', 'fam-x', 'jti-x'));
        $this->assertNull($h->rotateRefreshTokenAndCookie($decoded));
        $this->assertNull($this->fam('alice', 'fam-x'));
    }

    public function testLegacyTokenIsAcceptedAndRotatesIntoNewFamily(): void
    {
        $h = $this->bareHaxcms();
        // another device already has a live family: legacy is still accepted
        $h->recordRefreshSession('alice', 'other-device', 'jo', time() + 3600);
        $legacy = $h->decodeRefreshToken($h->getRefreshToken('alice', false));
        $this->assertFalse(isset($legacy->family));
        $this->assertTrue($h->validateRefreshSession('alice', null, null, $legacy->iat));
        $this->assertNotNull($h->rotateRefreshTokenAndCookie($legacy));
        $families = $this->sessions()['users']['alice']['families'];
        $this->assertCount(2, $families, 'a NEW family was seeded; other device untouched');
        $this->assertArrayHasKey('other-device', $families);
    }

    public function testLegacyLogoutSetsLegacyRevokedAt(): void
    {
        $h = $this->bareHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $legacyCookie = $this->signRefresh($h, array('user' => 'alice', 'iat' => time() - 30));
        $h->recordRefreshSession('alice', 'other-device', 'jo', time() + 3600);
        $_COOKIE['haxcms_refresh_token'] = $legacyCookie;
        (new Operations())->logout();
        $user = $this->sessions()['users']['alice'];
        $this->assertIsInt($user['legacyRevokedAt']);
        $legacy = $h->decodeRefreshToken($legacyCookie);
        $this->assertFalse($h->validateRefreshSession('alice', null, null, $legacy->iat));
        $this->assertNull($h->rotateRefreshTokenAndCookie($legacy));
        $this->assertNull($h->getAuthenticatedUserName());
        // the family-bearing session on the other device is unaffected
        $this->assertTrue($h->validateRefreshSession('alice', 'other-device', 'jo'));
    }

    public function testStaleJtiCheckIsPureButRotationTombstones(): void
    {
        $h = $this->bareHaxcms();
        $t = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertFalse($h->validateRefreshSession('alice', $t->family, 'stale-jti'));
        $this->assertArrayNotHasKey('revoked', $this->fam('alice', $t->family), 'validate is pure');
        $this->assertNull($h->rotateRefreshTokenAndCookie((object) array('user' => 'alice', 'family' => $t->family, 'jti' => 'stale-jti')));
        $this->assertTrue($this->fam('alice', $t->family)['revoked']);
        $this->assertFalse($h->validateRefreshSession('alice', $t->family, $t->jti));
    }

    public function testPreviousJtiOutsideGraceIsReuse(): void
    {
        $h = $this->bareHaxcms();
        $h->recordRefreshSession('alice', 'fam', 'a', time() + 3600);
        $this->assertTrue($h->rotateRefreshSession('alice', 'fam', 'a', 'b', time() + 3600));
        $s = $this->sessions();
        $s['users']['alice']['families']['fam']['previousValidUntil'] = time() - 1;
        $this->writeStore($s);
        $this->assertFalse($h->rotateRefreshSession('alice', 'fam', 'a', 'c', time() + 3600));
        $this->assertTrue($this->fam('alice', 'fam')['revoked']);
        $this->assertFalse($h->validateRefreshSession('alice', 'fam', 'b'));
    }

    public function testPruningOnWrite(): void
    {
        $h = $this->bareHaxcms();
        $now = time();
        $this->writeStore(array('version' => 2, 'users' => array(
            'old' => array('families' => array(
                'f1' => array('revoked' => true, 'revokedAt' => $now - 90000, 'exp' => $now - 10),
            )),
            'mixed' => array(
                'families' => array(
                    'dead' => array('currentJtiHash' => 'x', 'exp' => $now - 1),
                    'live' => array('revoked' => true, 'revokedAt' => $now, 'exp' => $now + 1000),
                ),
                'legacyRevokedAt' => $now - 90000,
                'legacyRevokedExp' => $now - 5,
            ),
            'legacyonly' => array('families' => array(), 'legacyRevokedAt' => $now, 'legacyRevokedExp' => $now + 500),
        )));
        $h->recordRefreshSession('alice', 'f', 'j', $now + 3600);
        $users = $this->sessions()['users'];
        $this->assertArrayNotHasKey('old', $users, 'users left empty are dropped');
        $this->assertSame(array('live'), array_keys($users['mixed']['families']));
        $this->assertArrayNotHasKey('legacyRevokedAt', $users['mixed']);
        $this->assertArrayHasKey('legacyonly', $users);
        $this->assertArrayHasKey('alice', $users);
    }

    public function testStoreWriteFailureFallsBackToLegacyToken(): void
    {
        $h = $this->bareHaxcms();
        // make settings/ a file so the store can't be written
        $this->rrmdir($this->tmpConfigDir . '/settings');
        file_put_contents($this->tmpConfigDir . '/settings', 'not a dir');
        $decoded = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertSame('alice', $decoded->user);
        $this->assertFalse(isset($decoded->family), 'login still works with a legacy token');
        $this->assertFalse(isset($decoded->jti));
        // legacy rotation also falls back to another legacy token
        $this->assertNotNull($h->rotateRefreshTokenAndCookie($decoded));
        @unlink($this->tmpConfigDir . '/settings');
        mkdir($this->tmpConfigDir . '/settings', 0777, true);
    }

    public function testGetAuthenticatedUserNameConsultsStore(): void
    {
        $h = $this->bareHaxcms();
        $_COOKIE['haxcms_refresh_token'] = $h->getRefreshToken('alice', true);
        $this->assertSame('alice', $h->getAuthenticatedUserName());
        $h->revokeRefreshSession('alice', $h->decodeRefreshToken($_COOKIE['haxcms_refresh_token'])->family);
        $this->assertNull($h->getAuthenticatedUserName(), 'revoked refresh cookie must not authenticate');
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
        $h->revokeRefreshSession('alice', $h->decodeRefreshToken($_COOKIE['haxcms_refresh_token'])->family);
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
            // scheme is deliberately NOT compared (NodeJS parity): both
            // directions of server/browser scheme disagreement must pass
            'scheme mismatch (TLS proxy, no trustedProxies)' => array(array('HTTP_ORIGIN' => 'https://example.test', 'HTTP_X_FORWARDED_PROTO' => 'https'), true),
            'Apache plain http, protossl=""'      => array(array('HTTP_ORIGIN' => 'http://example.test', 'protossl' => ''), true),
            'Apache https, protossl="s", http origin' => array(array('HTTP_ORIGIN' => 'http://example.test', 'protossl' => 's'), true),
            'foreign host over matching scheme'   => array(array('HTTP_ORIGIN' => 'http://example.test.evil.test'), false),
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

    public function testRefreshResponseCarriesUserAndSiteTokens(): void
    {
        $h = $this->bareHaxcms();
        $h->basePath = '/';
        $h->sitesDirectory = '_sites';
        $GLOBALS['HAXCMS'] = $h;
        $_COOKIE['haxcms_refresh_token'] = $h->getRefreshToken('alice', true);
        $_SERVER['HTTP_REFERER'] = 'http://example.test/sites/my-site/page';
        $ops = new Operations();
        $ops->params = array();
        $res = $ops->refreshAccessToken();
        $this->assertSame(200, $res['status']);
        $this->assertSame($h->getRequestToken('alice'), $res['userToken']);
        $this->assertSame($h->getRequestToken('alice:my-site'), $res['siteToken']);
        // explicit body site wins; no resolvable site -> no siteToken key
        $this->assertSame('other', $h->resolveSessionSiteName(array('site' => array('name' => 'other'))));
        unset($_SERVER['HTTP_REFERER']);
        $this->assertArrayNotHasKey('siteToken', $h->getSessionRequestTokens('alice', array()));
        $this->assertSame('', $h->resolveSessionSiteName(array('siteName' => '../etc')));
    }

    // ---------------------------------------------------------------
    // grace path: two tabs refresh with the same cookie
    // ---------------------------------------------------------------

    /**
     * Tab 1 and tab 2 both refresh with the SAME cookie (jti J0). The second
     * is a grace-path accept that must re-issue the jti tab 1 received
     * (deterministic successor) without rotating again, so whichever
     * Set-Cookie the browser keeps refreshes successfully next time.
     */
    #[DataProvider('cookieOrderProvider')]
    public function testConcurrentTabsGracePathKeepsBothCookiesValid(string $keep): void
    {
        $h = $this->bareHaxcms();
        $t0 = $h->decodeRefreshToken($h->getRefreshToken('alice', true));
        $this->assertNotNull($h->rotateRefreshTokenAndCookie($t0), 'tab 1 rotates');
        $afterTab1 = $this->fam('alice', $t0->family);
        $this->assertNotNull($h->rotateRefreshTokenAndCookie($t0), 'tab 2 grace accept');
        $afterTab2 = $this->fam('alice', $t0->family);
        // grace accept left current/previous untouched
        $this->assertSame($afterTab1['currentJtiHash'], $afterTab2['currentJtiHash']);
        $this->assertSame($afterTab1['previousJtiHash'], $afterTab2['previousJtiHash']);
        $successor = $h->successorJti($t0->family, $t0->jti);
        $this->assertSame(hash('sha256', $successor), $afterTab2['currentJtiHash']);
        // both Set-Cookies carried the same jti; whichever the browser kept
        // ($keep only labels the order -- the jti is identical) rotates fine
        $kept = (object) array('user' => 'alice', 'family' => $t0->family, 'jti' => $successor, 'iat' => time(), 'exp' => time() + 3600);
        $this->assertNotNull($h->rotateRefreshTokenAndCookie($kept), "kept cookie from $keep refreshes");
        $this->assertArrayNotHasKey('revoked', $this->fam('alice', $t0->family));
        // and the chain continues: the next successor is now current
        $this->assertSame(hash('sha256', $h->successorJti($t0->family, $successor)), $this->fam('alice', $t0->family)['currentJtiHash']);
    }

    public static function cookieOrderProvider(): array
    {
        return array('tab 1 Set-Cookie kept' => array('tab1'), 'tab 2 Set-Cookie kept' => array('tab2'));
    }

    public function testSuccessorJtiMatchesNodeDerivation(): void
    {
        $h = $this->bareHaxcms();
        $expected = substr(hash_hmac('sha256', 'haxcms-refresh-successor:fam:jti', 'rpk' . 's'), 0, 32);
        $this->assertSame($expected, $h->successorJti('fam', 'jti'));
    }

    // ---------------------------------------------------------------
    // store locking / atomic write / unreadable store
    // ---------------------------------------------------------------

    public function testCorruptStoreIsAnErrorNotAnEmptyStore(): void
    {
        $h = $this->bareHaxcms();
        $GLOBALS['HAXCMS'] = $h;
        $path = $this->tmpConfigDir . '/settings/refreshSessions.json';
        foreach (array('{"version":2,"users":{"al', '') as $corrupt) {
            file_put_contents($path, $corrupt);
            $check = $h->checkRefreshSession((object) array('user' => 'alice', 'family' => 'f', 'jti' => 'j'));
            $this->assertSame('store_error', $check['reason']);
            $this->assertTrue($h->refreshStoreError);
            // mutators refuse to overwrite the unreadable store
            $this->assertFalse($h->recordRefreshSession('alice', 'f2', 'j2', time() + 100));
            $h->revokeRefreshSession('alice', 'f');
            $this->assertFalse($h->rotateRefreshSession('alice', 'f', 'j', 'k', time() + 100));
            $this->assertSame($corrupt, file_get_contents($path));
            // refresh answers 503 and does NOT clear/tombstone
            $_COOKIE['haxcms_refresh_token'] = $this->signRefresh($h, array('user' => 'alice', 'family' => 'f', 'jti' => 'j'));
            $res = (new Operations())->refreshAccessToken();
            $this->assertSame(503, $res['__failed']['status']);
            $this->assertSame($corrupt, file_get_contents($path));
            // and the cookie doesn't authenticate this request (fail closed)
            $this->assertNull($h->getAuthenticatedUserName());
        }
    }

    public function testSaveIsAtomicAndLeavesNoTempFiles(): void
    {
        $h = $this->bareHaxcms();
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($h->recordRefreshSession('alice', 'f' . $i, 'j', time() + 100));
        }
        $files = array_values(array_diff(scandir($this->tmpConfigDir . '/settings'), array('.', '..')));
        sort($files);
        $this->assertSame(array('refreshSessions.json', 'refreshSessions.json.lock'), $files);
        $this->assertSame('600', substr(sprintf('%o', fileperms($this->tmpConfigDir . '/settings/refreshSessions.json')), -3));
    }

    /**
     * N parallel PHP processes each rotate their OWN family many times
     * against the same store. Without the exclusive lock held across
     * load -> rotate -> save, lost updates would leave some family on a
     * stale current jti (and the next rotation would tombstone it).
     */
    public function testParallelRotationsOfDifferentFamiliesAllSurvive(): void
    {
        $workers = 6;
        $rounds = 15;
        $h = $this->bareHaxcms();
        for ($w = 0; $w < $workers; $w++) {
            $this->assertTrue($h->recordRefreshSession('alice', 'fam' . $w, 'jti' . $w . '-0', time() + 3600));
        }
        $lib = dirname(__DIR__, 2) . '/lib';
        $child = $this->tmpConfigDir . '/child.php';
        file_put_contents($child, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define("HAXCMS_ROOT", ' . var_export($this->tmpConfigDir, true) . ');
$_SERVER["SERVER_SOFTWARE"] = "child";
include_once ' . var_export($lib . '/HAXCMS.php', true) . ';
$h = (new ReflectionClass("HAXCMS"))->newInstanceWithoutConstructor();
$h->privateKey = "pk"; $h->refreshPrivateKey = "rpk"; $h->salt = "s";
$h->config = new stdClass(); $h->configDirectory = HAXCMS_ROOT;
$fam = $argv[1]; $jti = $argv[2]; $rounds = (int) $argv[3]; $ok = 0;
for ($i = 0; $i < $rounds; $i++) {
  $next = $h->successorJti($fam, $jti);
  if ($h->rotateRefreshSession("alice", $fam, $jti, $next, time() + 3600)) { $ok++; $jti = $next; }
}
echo $ok . " " . $jti;
');
        $procs = array();
        for ($w = 0; $w < $workers; $w++) {
            $cmd = array(PHP_BINARY, $child, 'fam' . $w, 'jti' . $w . '-0', (string) $rounds);
            $pipes = array();
            $procs[$w] = array(proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes), $pipes);
        }
        $finals = array();
        foreach ($procs as $w => $p) {
            $out = stream_get_contents($p[1][1]);
            $err = stream_get_contents($p[1][2]);
            fclose($p[1][1]);
            fclose($p[1][2]);
            proc_close($p[0]);
            $this->assertSame('', trim($err), "worker $w stderr");
            list($ok, $finalJti) = explode(' ', trim($out));
            $this->assertSame($rounds, (int) $ok, "worker $w: every rotation accepted");
            $finals[$w] = $finalJti;
        }
        foreach ($finals as $w => $finalJti) {
            $entry = $this->fam('alice', 'fam' . $w);
            $this->assertArrayNotHasKey('revoked', $entry, "fam$w not tombstoned");
            $this->assertSame(hash('sha256', $finalJti), $entry['currentJtiHash'], "fam$w update not lost");
        }
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
