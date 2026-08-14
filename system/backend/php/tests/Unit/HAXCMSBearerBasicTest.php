<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the HAXCMS bearer-token and Basic-auth seams.
 *
 * Covers three HAXCMS public methods (lib/HAXCMS.php):
 *   - getBearerTokenFromRequest(): reads the Authorization header (HTTP_AUTHORIZATION
 *     -> REDIRECT_HTTP_AUTHORIZATION -> apache_request_headers()) and extracts the
 *     token after 'Bearer ' via the case-sensitive regex /Bearer\s+(\S+)/.
 *   - getBearerTokenUserName($bearer=''): when $bearer='' reads the request; decodes
 *     via JWT::decode($bearer, privateKey.salt); enforces validateAccessTokenClaims
 *     (exp mandatory; exp/nbf/iat validated with 60s leeway); returns
 *     generateMachineName(decoded->user) on success, '' on any failure.
 *   - authenticateBasicAuthorization(): reads Authorization for 'Basic '; base64-decodes
 *     credentials; rate-limits repeated failures against a file-backed Cache; returns an
 *     array with attempted/authenticated/userName/blocked/retryAfterSeconds.
 *
 * Expected values come from the contract / independent computation, NOT by re-running
 * the production code. The known-good username literals ('alice', 'alice-bob') are
 * computed without importing the HAXCMS class (standalone php -r replicating the
 * generateMachineName transformation), so a bug in that transform would disagree with
 * the literal. JWTs are crafted with JWT::encode + the access key for SETUP only; the
 * assertions target decode / claim-validation / extraction behavior.
 *
 * Instances use newInstanceWithoutConstructor (skip the stateful god-class constructor);
 * the tested methods read only privateKey/salt/config/user/superUser/configDirectory/cache.
 * $_SERVER['SERVER_SOFTWARE'] is set so isCLI() is false (these methods do not CLI
 * short-circuit, but testLogin/validateUser parity is cleaner with a web-sapi signal).
 *
 * NOTE: the apache_request_headers() fallback branch of getBearerTokenFromRequest is not
 * exercised here -- that function does not exist under the PHPUnit CLI sapi, so the
 * branch is unreachable without polluting the global namespace with a stub.
 */
class HAXCMSBearerBasicTest extends TestCase
{
    private $haxcms;
    private $savedServer = array();
    private $tmpConfigDir;
    private $tmpCacheDir;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->privateKey = 'pk';
        $this->haxcms->salt = 's';
        $this->haxcms->config = new stdClass();
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'secret';
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = 'adminpass';
        // configDirectory so maybeUpgradePlaintextPassword (on a successful login)
        // can no-op cleanly against a temp dir with no config.php.
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_bb_cfg_' . uniqid();
        mkdir($this->tmpConfigDir . '/settings', 0777, true);
        $this->haxcms->configDirectory = $this->tmpConfigDir;
        $this->tmpCacheDir = null;
        // Save & restore the $_SERVER entries we touch so tests are isolated.
        foreach (array('SERVER_SOFTWARE', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REMOTE_ADDR') as $k) {
            $this->savedServer[$k] = isset($_SERVER[$k]) ? $_SERVER[$k] : null;
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $k => $v) {
            if ($v !== null) {
                $_SERVER[$k] = $v;
            } else {
                unset($_SERVER[$k]);
            }
        }
        $this->rrmdir($this->tmpConfigDir);
        if ($this->tmpCacheDir !== null) {
            $this->rrmdir($this->tmpCacheDir);
        }
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function accessKey(): string
    {
        return $this->haxcms->privateKey . $this->haxcms->salt;
    }

    /**
     * $cache is a private property; set it via reflection so the rate-limit
     * tests can wire a real file-backed Cache without touching production code.
     */
    private function setCache($cache): void
    {
        $prop = new ReflectionProperty(HAXCMS::class, 'cache');
        $prop->setAccessible(true);
        $prop->setValue($this->haxcms, $cache);
    }

    /**
     * Craft a fresh (unexpired) access JWT signed with privateKey.salt.
     */
    private function freshToken(array $extraClaims = array()): string
    {
        $base = array('iat' => time(), 'exp' => time() + 600);
        return JWT::encode((object)($base + $extraClaims), $this->accessKey());
    }

    // ---- getBearerTokenFromRequest ----

    public function testGetBearerTokenFromValidHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
        $this->assertSame('abc123', $this->haxcms->getBearerTokenFromRequest());
    }

    #[DataProvider('bearerExtractionProvider')]
    public function testGetBearerTokenExtraction(string $header, string $expected): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
        $this->assertSame($expected, $this->haxcms->getBearerTokenFromRequest());
    }

    public static function bearerExtractionProvider(): array
    {
        return array(
            'valid Bearer'              => array('Bearer abc123', 'abc123'),
            'Basic (not Bearer)'        => array('Basic dXNlcjpwYXNz', ''),
            'empty Bearer'              => array('Bearer ', ''),
            'Bearer then only spaces'   => array('Bearer   ', ''),
            'lowercase bearer (scheme is case-insensitive per RFC 7235)' =>
                array('bearer x', 'x'),
            'no Bearer keyword'         => array('Token xyz', ''),
            'Bearer with dot segments'  => array('Bearer a.b.c', 'a.b.c'),
            'Bearer tab-separated'      => array("Bearer\ttabtok", 'tabtok'),
        );
    }

    public function testGetBearerTokenFromMissingHeaderReturnsEmpty(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $this->assertSame('', $this->haxcms->getBearerTokenFromRequest());
    }

    public function testGetBearerTokenFallsBackToRedirectHeader(): void
    {
        // HTTP_AUTHORIZATION unset -> REDIRECT_HTTP_AUTHORIZATION is consulted.
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer fromredirect';
        $this->assertSame('fromredirect', $this->haxcms->getBearerTokenFromRequest());
    }

    public function testGetBearerTokenFallsBackWhenHttpAuthorizationIsEmptyString(): void
    {
        // HTTP_AUTHORIZATION set but '' -> the non-empty guard fails, falls through.
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer fallback';
        $this->assertSame('fallback', $this->haxcms->getBearerTokenFromRequest());
    }

    public function testGetBearerTokenPrefersHttpAuthorizationOverRedirect(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer primary';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer secondary';
        $this->assertSame('primary', $this->haxcms->getBearerTokenFromRequest());
    }

    // ---- getBearerTokenUserName ----

    public function testGetBearerTokenUserNameWithValidFreshTokenReturnsMachineName(): void
    {
        // Known-good literal 'alice' = generateMachineName('alice'), computed
        // independently (trivial: no transformation). The JWT is crafted with the
        // access key; the assertion targets decode + temporal-claim validation.
        $token = $this->freshToken(array('user' => 'alice'));
        $this->assertSame('alice', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameAppliesMachineNameTransform(): void
    {
        // Known-good literal 'alice-bob' = generateMachineName('Alice Bob'),
        // computed independently via standalone php -r (no HAXCMS import): the
        // space becomes '-' and the result is lowercased. This pins that the
        // decoded `user` claim is run through generateMachineName, not returned
        // verbatim -- so 'Alice Bob' never leaks through as 'Alice Bob'.
        $token = $this->freshToken(array('user' => 'Alice Bob'));
        $this->assertSame('alice-bob', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithExpiredTokenReturnsEmpty(): void
    {
        // exp well in the past, beyond the 60s leeway -> validateAccessTokenClaims throws.
        $token = JWT::encode(
            (object)array('user' => 'alice', 'iat' => time() - 2000, 'exp' => time() - 1000),
            $this->accessKey()
        );
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithNotBeforeInFutureReturnsEmpty(): void
    {
        // nbf in the future -> "Token is not yet valid" (with 60s leeway still future).
        $token = JWT::encode(
            (object)array('user' => 'alice', 'iat' => time(), 'nbf' => time() + 3600, 'exp' => time() + 7200),
            $this->accessKey()
        );
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithIssuedAtInFutureReturnsEmpty(): void
    {
        // iat in the future -> "Token issued in the future" (with 60s leeway still future).
        $token = JWT::encode(
            (object)array('user' => 'alice', 'iat' => time() + 3600, 'exp' => time() + 7200),
            $this->accessKey()
        );
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithMissingExpReturnsEmpty(): void
    {
        // exp is mandatory for access tokens; without it validateAccessTokenClaims throws.
        $token = JWT::encode(
            (object)array('user' => 'alice', 'iat' => time()),
            $this->accessKey()
        );
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithWrongKeyReturnsEmpty(): void
    {
        // Signed with a different key -> JWT::decode signature verification fails.
        $token = JWT::encode(
            (object)array('user' => 'alice', 'iat' => time(), 'exp' => time() + 600),
            'wrong-key' . $this->haxcms->salt
        );
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithNonStringBearerReturnsEmpty(): void
    {
        // An int bearer: (int !== '') so the request-seam is skipped; JWT::decode
        // coerces it to a non-3-segment string and throws (caught) -> ''. (null
        // would also work but emits a PHP 8.1+ deprecation for the explode arg,
        // so an int is the clean non-string probe.)
        $this->assertSame('', $this->haxcms->getBearerTokenUserName(12345));
    }

    public function testGetBearerTokenUserNameWithNoUserClaimReturnsEmpty(): void
    {
        // Valid, fresh, correct key, but no `user` claim -> isset fails -> ''.
        $token = $this->freshToken(array());
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameWithEmptyUserClaimReturnsEmpty(): void
    {
        // `user` present but '' -> the non-empty guard fails -> ''.
        $token = $this->freshToken(array('user' => ''));
        $this->assertSame('', $this->haxcms->getBearerTokenUserName($token));
    }

    public function testGetBearerTokenUserNameReadsFromRequestWhenArgEmpty(): void
    {
        // Default arg '' -> method calls getBearerTokenFromRequest() and decodes that.
        $token = $this->freshToken(array('user' => 'alice'));
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $this->assertSame('alice', $this->haxcms->getBearerTokenUserName());
    }

    public function testGetBearerTokenUserNameReturnsEmptyWhenNoBearerPresent(): void
    {
        // No header + default arg -> getBearerTokenFromRequest() returns '' -> ''.
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $this->assertSame('', $this->haxcms->getBearerTokenUserName());
    }

    // ---- authenticateBasicAuthorization ----

    public function testAuthenticateBasicNoHeaderReturnsUnattempted(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertIsArray($result);
        $this->assertFalse($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['userName']);
        $this->assertFalse($result['blocked']);
        $this->assertSame(0, $result['retryAfterSeconds']);
    }

    public function testAuthenticateBasicNonBasicHeaderReturnsUnattempted(): void
    {
        // 'Bearer ...' does not start with 'Basic ' -> attempted stays false.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer somejwt';
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertFalse($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['userName']);
    }

    public function testAuthenticateBasicBadBase64ReturnsAttemptedNotAuthenticated(): void
    {
        // 'Basic @@@@': base64_decode (lenient) discards invalid chars -> '' which
        // has no ':' -> attempted=true, authenticated=false.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic @@@@';
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['userName']);
    }

    public function testAuthenticateBasicCredentialsWithoutColonReturnAttempted(): void
    {
        // Valid base64 of a string with no ':' -> attempted, not authenticated.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('nocolonhere');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['userName']);
    }

    public function testAuthenticateBasicEmptyNameOrPassReturnAttempted(): void
    {
        // 'alice:' -> pass is '' after explode -> attempted, not authenticated.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['authenticated']);
    }

    public function testAuthenticateBasicValidCredentialsMatchingUser(): void
    {
        // user->name='alice', user->password='secret'; testLogin + validateUser both pass.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:secret');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame('alice', $result['userName']);
        $this->assertFalse($result['blocked']);
        $this->assertSame(0, $result['retryAfterSeconds']);
    }

    public function testAuthenticateBasicValidCredentialsMatchingSuperUser(): void
    {
        // superUser->name='admin', superUser->password='adminpass'; adminFallback=true
        // lets testLogin match the super user; validateUser matches superUser->name.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:adminpass');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame('admin', $result['userName']);
    }

    public function testAuthenticateBasicWrongPasswordReturnsNotAuthenticated(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:wrongpass');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['userName']);
    }

    public function testAuthenticateBasicUnknownUserReturnsNotAuthenticated(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('nobody:whatever');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['userName']);
    }

    public function testAuthenticateBasicUsesRedirectHeaderFallback(): void
    {
        // HTTP_AUTHORIZATION unset -> REDIRECT_HTTP_AUTHORIZATION supplies the Basic creds.
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:secret');
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($result['authenticated']);
        $this->assertSame('alice', $result['userName']);
    }

    public function testAuthenticateBasicHasApacheRequestHeadersFallbackStructuralParity(): void
    {
        // A7: authenticateBasicAuthorization must fall back to
        // apache_request_headers() when neither HTTP_AUTHORIZATION nor
        // REDIRECT_HTTP_AUTHORIZATION is set, mirroring getBearerTokenFromRequest
        // (structural parity). On servers where the Authorization header is only
        // exposed via apache headers, Basic auth is silently not attempted without
        // this fallback. The branch is not exercisable under the PHPUnit CLI sapi
        // (apache_request_headers is typically unavailable), and stubbing the
        // global function would pollute the namespace and interfere with other
        // tests -- so we verify the fallback is present structurally via
        // reflection on the method source.
        $r = new ReflectionMethod(HAXCMS::class, 'authenticateBasicAuthorization');
        $start = $r->getStartLine();
        $end = $r->getEndLine();
        $lines = file($r->getFileName());
        $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        $this->assertStringContainsString(
            'function_exists',
            $source,
            'authenticateBasicAuthorization must guard the apache_request_headers() fallback with function_exists()'
        );
        $this->assertStringContainsString(
            'apache_request_headers',
            $source,
            'authenticateBasicAuthorization must include the apache_request_headers() fallback for structural parity with getBearerTokenFromRequest'
        );
        $this->assertStringContainsString(
            "headers['Authorization']",
            $source,
            'authenticateBasicAuthorization must check both Authorization and authorization keys from apache_request_headers()'
        );
    }

    public function testAuthenticateBasicRateLimitBlocksAfterMaxAttempts(): void
    {
        // Real file-backed Cache so failed-attempt counters persist across calls.
        // Default settings (no config->security->loginRateLimit): enabled,
        // maxAttempts=5, blockMs=15min. The counter trips blockedUntil on the
        // 5th failure; the NEXT (6th) call sees blockedUntil>now -> blocked.
        $this->tmpCacheDir = sys_get_temp_dir() . '/haxcms_bb_cache_' . uniqid();
        $this->setCache(new Cache(array(
            'name' => 'haxcms-bb-test',
            'path' => $this->tmpCacheDir . '/',
            'extension' => '.cache',
        )));
        $badHeader = 'Basic ' . base64_encode('alice:wrongpass');
        for ($i = 0; $i < 5; $i++) {
            $_SERVER['HTTP_AUTHORIZATION'] = $badHeader;
            $r = $this->haxcms->authenticateBasicAuthorization();
            $this->assertTrue($r['attempted'], "attempt $i should be attempted");
            $this->assertFalse($r['authenticated'], "attempt $i should not authenticate");
            $this->assertFalse($r['blocked'], "attempt $i should not be blocked yet");
        }
        // 6th attempt: blockedUntil has been set -> blocked, with a positive retry window.
        $_SERVER['HTTP_AUTHORIZATION'] = $badHeader;
        $blocked = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($blocked['attempted']);
        $this->assertFalse($blocked['authenticated']);
        $this->assertTrue($blocked['blocked']);
        $this->assertGreaterThan(0, $blocked['retryAfterSeconds']);
    }

    public function testAuthenticateBasicSuccessfulLoginClearsRateLimitCounter(): void
    {
        // A few failed attempts below the threshold, then a successful login erases
        // the cache entry; a subsequent wrong attempt starts fresh (not blocked).
        $this->tmpCacheDir = sys_get_temp_dir() . '/haxcms_bb_cache_' . uniqid();
        $this->setCache(new Cache(array(
            'name' => 'haxcms-bb-test',
            'path' => $this->tmpCacheDir . '/',
            'extension' => '.cache',
        )));
        for ($i = 0; $i < 3; $i++) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:wrongpass');
            $r = $this->haxcms->authenticateBasicAuthorization();
            $this->assertFalse($r['authenticated']);
            $this->assertFalse($r['blocked']);
        }
        // Successful login -> cache entry erased.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:secret');
        $ok = $this->haxcms->authenticateBasicAuthorization();
        $this->assertTrue($ok['authenticated']);
        $this->assertSame('alice', $ok['userName']);
        // Subsequent wrong attempt is not blocked (counter was cleared).
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:wrongpass');
        $after = $this->haxcms->authenticateBasicAuthorization();
        $this->assertFalse($after['blocked']);
        $this->assertFalse($after['authenticated']);
    }
}
