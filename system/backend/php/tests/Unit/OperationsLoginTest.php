<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteLogin trait
 * (lib/operations/login.php).
 *
 * The trait provides the login() entrypoint and supporting private
 * methods for rate-limited credential login and JWT revalidation.
 *
 * A LoginTestHaxcms stub is installed as $GLOBALS['HAXCMS'] with
 * configurable behavior for testLogin, getJWT, validateJWT, etc.
 * A REAL Cache instance (pointed at a temp dir) is used for
 * $GLOBALS['HAXCMS']->cache so rate-limit state transitions are
 * exercised authentically via store/retrieve/erase.
 *
 * NOTE: header() output (e.g. Retry-After on 429) cannot be
 * introspected under PHP CLI SAPI — headers_list() returns empty.
 * Rate-limit 429 behavior is pinned via returned status/message
 * and cache-stored attempt entry state, consistent with the
 * established pattern in HAXCMSRequestHardeningTest.
 */
class OperationsLoginTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpCacheDir;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->tmpCacheDir = sys_get_temp_dir() . '/haxcms_login_cache_' . uniqid();
        mkdir($this->tmpCacheDir, 0777, true);

        $this->haxcms = new LoginTestHaxcms();
        $this->haxcms->cache = new Cache();
        $this->haxcms->cache->setCachePath($this->tmpCacheDir . '/');
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        unset($_SERVER['REMOTE_ADDR']);
        $this->rrmdir($this->tmpCacheDir);
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

    /**
     * Call a private trait method via reflection.
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod('Operations', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->ops, $args);
    }

    // =========================================================================
    // login() — missing credentials
    // =========================================================================

    public function testLoginReturns401WhenNoCredentialsOrJwtProvided(): void
    {
        $this->ops->params = array();
        $result = $this->ops->login();
        $this->assertSame(401, $result['__failed']['status']);
        $this->assertSame('Login is required', $result['__failed']['message']);
    }

    // =========================================================================
    // login() — invalid credentials
    // =========================================================================

    public function testLoginInvalidCredentialsReturns401AccessDenied(): void
    {
        $this->haxcms->testLoginResult = false;
        $this->ops->params = array(
            'username' => 'baduser',
            'password' => 'wrongpass',
        );
        $result = $this->ops->login();
        $this->assertSame(401, $result['__failed']['status']);
        $this->assertSame('Access denied', $result['__failed']['message']);
    }

    public function testLoginInvalidCredentialsRegistersFailedAttemptInCache(): void
    {
        $this->haxcms->testLoginResult = false;
        $this->ops->params = array(
            'username' => 'baduser',
            'password' => 'wrongpass',
        );
        $this->ops->login();

        // Verify the failed attempt was persisted in the cache
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['baduser']);
        $entry = $this->haxcms->cache->retrieve($key);
        $this->assertIsArray($entry);
        $this->assertSame(1, (int) $entry['failedAttempts']);
    }

    // =========================================================================
    // login() — valid credentials happy path
    // =========================================================================

    public function testLoginValidCredentialsReturns200WithJwt(): void
    {
        $this->haxcms->testLoginResult = true;
        $this->haxcms->jwtValue = 'test-jwt-token';
        $this->ops->params = array(
            'username' => 'testuser',
            'password' => 'correctpass',
        );
        $result = $this->ops->login();
        $this->assertSame(200, $result['status']);
        $this->assertSame('test-jwt-token', $result['jwt']);
    }

    public function testLoginValidCredentialsClearsRateLimitCache(): void
    {
        $this->haxcms->testLoginResult = true;
        // Pre-seed a failed attempt entry
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['testuser']);
        $this->haxcms->cache->store($key, array(
            'firstAttempt' => 0,
            'failedAttempts' => 3,
            'blockedUntil' => 0,
        ), 3600);

        $this->ops->params = array(
            'username' => 'testuser',
            'password' => 'correctpass',
        );
        $this->ops->login();

        // After successful login, the entry should be erased
        $entry = $this->haxcms->cache->retrieve($key);
        $this->assertNull($entry);
    }

    public function testLoginValidCredentialsSetsRefreshTokenCookie(): void
    {
        $this->haxcms->testLoginResult = true;
        $this->haxcms->refreshTokenValue = 'refresh-token-123';
        $this->ops->params = array(
            'username' => 'testuser',
            'password' => 'correctpass',
        );
        $this->ops->login();
        $this->assertSame('refresh-token-123', $this->haxcms->lastRefreshTokenCookie);
    }

    // =========================================================================
    // login() — rate limit 429
    // =========================================================================

    public function testLoginRateLimitedReturns429(): void
    {
        $this->haxcms->testLoginResult = true;
        // Pre-seed a blocked entry in the cache
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['testuser']);
        $nowMs = intval(round(microtime(true) * 1000));
        $this->haxcms->cache->store($key, array(
            'firstAttempt' => $nowMs,
            'failedAttempts' => 0,
            'blockedUntil' => $nowMs + 60000, // blocked for 60 seconds
        ), 3600);

        $this->ops->params = array(
            'username' => 'testuser',
            'password' => 'correctpass',
        );
        $result = $this->ops->login();
        $this->assertSame(429, $result['__failed']['status']);
        $this->assertSame(
            'Too many login attempts. Please retry later.',
            $result['__failed']['message']
        );
    }

    public function testLoginRateLimitNotTriggeredWhenDisabled(): void
    {
        $this->haxcms->testLoginResult = true;
        $this->haxcms->rateLimitEnabled = false;

        // Pre-seed a blocked entry even though rate limiting is disabled
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['testuser']);
        $nowMs = intval(round(microtime(true) * 1000));
        $this->haxcms->cache->store($key, array(
            'firstAttempt' => $nowMs,
            'failedAttempts' => 0,
            'blockedUntil' => $nowMs + 60000,
        ), 3600);

        $this->ops->params = array(
            'username' => 'testuser',
            'password' => 'correctpass',
        );
        $result = $this->ops->login();
        // Should succeed because rate limiting is disabled
        $this->assertSame(200, $result['status']);
    }

    // =========================================================================
    // registerFailedLoginAttempt — blocks after maxAttempts
    // =========================================================================

    public function testRegisterFailedLoginAttemptBlocksAfterMaxAttempts(): void
    {
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $nowMs = intval(round(microtime(true) * 1000));

        // Simulate (maxAttempts - 1) failures, then one more should trigger block
        $entry = array(
            'firstAttempt' => $nowMs,
            'failedAttempts' => $settings->maxAttempts - 1,
            'blockedUntil' => 0,
        );

        $updated = $this->invokePrivate('registerFailedLoginAttempt', [
            $entry, $nowMs, $settings,
        ]);

        $this->assertSame(0, (int) $updated['failedAttempts'], 'failedAttempts reset to 0 after block');
        $this->assertGreaterThan($nowMs, (int) $updated['blockedUntil'], 'blockedUntil set to future time');
        $this->assertSame($nowMs, (int) $updated['firstAttempt'], 'firstAttempt reset to now');
    }

    public function testRegisterFailedLoginAttemptIncrementsWithoutBlocking(): void
    {
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $nowMs = intval(round(microtime(true) * 1000));

        $entry = array(
            'firstAttempt' => $nowMs,
            'failedAttempts' => 1,
            'blockedUntil' => 0,
        );

        $updated = $this->invokePrivate('registerFailedLoginAttempt', [
            $entry, $nowMs, $settings,
        ]);

        $this->assertSame(2, (int) $updated['failedAttempts']);
        $this->assertSame(0, (int) $updated['blockedUntil'], 'not blocked yet');
    }

    // =========================================================================
    // getLoginAttemptEntry — window expiration reset
    // =========================================================================

    public function testGetLoginAttemptEntryResetsAfterWindowExpires(): void
    {
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $nowMs = intval(round(microtime(true) * 1000));

        // An old entry whose firstAttempt is well beyond the window
        $oldEntry = array(
            'firstAttempt' => $nowMs - intval($settings->windowMs) - 10000,
            'failedAttempts' => 4,
            'blockedUntil' => 0,
        );
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['windowuser']);
        $this->haxcms->cache->store($key, $oldEntry, 3600);

        $entry = $this->invokePrivate('getLoginAttemptEntry', [
            $key, $nowMs, $settings,
        ]);

        $this->assertSame(0, (int) $entry['failedAttempts'], 'failedAttempts reset after window expiry');
        $this->assertSame($nowMs, (int) $entry['firstAttempt'], 'firstAttempt reset to now');
        $this->assertSame(0, (int) $entry['blockedUntil'], 'blockedUntil cleared after window expiry');
    }

    public function testGetLoginAttemptEntryReturnsFreshWhenNoCache(): void
    {
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $nowMs = intval(round(microtime(true) * 1000));

        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['freshuser']);
        $entry = $this->invokePrivate('getLoginAttemptEntry', [
            $key, $nowMs, $settings,
        ]);

        $this->assertSame(0, (int) $entry['failedAttempts']);
        $this->assertSame($nowMs, (int) $entry['firstAttempt']);
        $this->assertSame(0, (int) $entry['blockedUntil']);
    }

    // =========================================================================
    // clearLoginAttemptEntry
    // =========================================================================

    public function testClearLoginAttemptEntryErasesFromCache(): void
    {
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['clearuser']);
        $this->haxcms->cache->store($key, array(
            'firstAttempt' => 0,
            'failedAttempts' => 2,
            'blockedUntil' => 0,
        ), 3600);

        $this->assertNotNull($this->haxcms->cache->retrieve($key));
        $this->invokePrivate('clearLoginAttemptEntry', [$key]);
        $this->assertNull($this->haxcms->cache->retrieve($key));
    }

    // =========================================================================
    // getClientIP
    // =========================================================================

    public function testGetClientIPDelegatesToHaxcmsResolveClientIP(): void
    {
        $this->haxcms->resolvedClientIP = '10.0.0.99';
        $ip = $this->invokePrivate('getClientIP');
        $this->assertSame('10.0.0.99', $ip);
    }

    public function testGetClientIPFallsBackToRemoteAddrWhenNoHaxcmsMethod(): void
    {
        // Temporarily replace $GLOBALS['HAXCMS'] with a bare object that
        // has no resolveClientIP method
        $bare = new stdClass();
        $bare->cache = null;
        $GLOBALS['HAXCMS'] = $bare;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';

        $ip = $this->invokePrivate('getClientIP');
        $this->assertSame('192.168.1.50', $ip);
    }

    // =========================================================================
    // getLoginAttemptCacheKey
    // =========================================================================

    public function testGetLoginAttemptCacheKeyIsStableForSameIPAndUser(): void
    {
        $this->haxcms->resolvedClientIP = '10.0.0.1';
        $key1 = $this->invokePrivate('getLoginAttemptCacheKey', ['alice']);
        $key2 = $this->invokePrivate('getLoginAttemptCacheKey', ['alice']);
        $this->assertSame($key1, $key2);
        $this->assertStringStartsWith('login-rate:', $key1);
    }

    public function testGetLoginAttemptCacheKeyDiffersForDifferentUsers(): void
    {
        $this->haxcms->resolvedClientIP = '10.0.0.1';
        $key1 = $this->invokePrivate('getLoginAttemptCacheKey', ['alice']);
        $key2 = $this->invokePrivate('getLoginAttemptCacheKey', ['bob']);
        $this->assertNotSame($key1, $key2);
    }

    public function testGetLoginAttemptCacheKeyDiffersForDifferentIPs(): void
    {
        $this->haxcms->resolvedClientIP = '10.0.0.1';
        $key1 = $this->invokePrivate('getLoginAttemptCacheKey', ['alice']);
        $this->haxcms->resolvedClientIP = '10.0.0.2';
        $key2 = $this->invokePrivate('getLoginAttemptCacheKey', ['alice']);
        $this->assertNotSame($key1, $key2);
    }

    // =========================================================================
    // login() — JWT revalidation
    // =========================================================================

    public function testLoginJwtRevalidationValidTokenReturns200(): void
    {
        $this->haxcms->validateJWTResult = true;
        $this->haxcms->jwtValue = 'fresh-jwt';
        $this->haxcms->activeUserName = 'revalidateduser';
        $this->ops->params = array(
            'jwt' => 'existing-valid-jwt',
        );
        $result = $this->ops->login();
        $this->assertSame(200, $result['status']);
        $this->assertSame('fresh-jwt', $result['jwt']);
    }

    public function testLoginJwtRevalidationInvalidTokenReturns401(): void
    {
        $this->haxcms->validateJWTResult = false;
        $this->ops->params = array(
            'jwt' => 'expired-or-invalid-jwt',
        );
        $result = $this->ops->login();
        $this->assertSame(401, $result['__failed']['status']);
        $this->assertSame('Invalid token', $result['__failed']['message']);
    }

    public function testLoginJwtRevalidationSetsSessionJwtFromBody(): void
    {
        $this->haxcms->validateJWTResult = true;
        $this->haxcms->jwtValue = 'fresh-jwt';
        $this->ops->params = array(
            'jwt' => '  body-jwt-value  ',
        );
        $this->ops->login();
        $this->assertSame('body-jwt-value', $this->haxcms->sessionJwt);
    }

    public function testLoginJwtRevalidationEmptyStringJwtDoesNotSetSessionJwt(): void
    {
        $this->haxcms->validateJWTResult = true;
        $this->haxcms->jwtValue = 'fresh-jwt';
        $this->haxcms->sessionJwt = 'pre-existing';
        $this->ops->params = array(
            'jwt' => '   ',
        );
        $this->ops->login();
        // Empty after trim, so sessionJwt should not be overwritten
        $this->assertSame('pre-existing', $this->haxcms->sessionJwt);
    }

    // =========================================================================
    // processCredentialLogin — legacy mode returns raw JWT string
    // =========================================================================

    public function testProcessCredentialLoginLegacyReturnsJwtString(): void
    {
        $this->haxcms->testLoginResult = true;
        $this->haxcms->jwtValue = 'legacy-jwt-string';
        $result = $this->invokePrivate('processCredentialLogin', [
            'testuser', 'correctpass', true,
        ]);
        $this->assertSame('legacy-jwt-string', $result);
    }

    // =========================================================================
    // processCredentialLogin — rate limit blocks before testLogin is called
    // =========================================================================

    public function testProcessCredentialLoginRateLimitSkipsTestLogin(): void
    {
        $this->haxcms->testLoginResult = true;
        $key = $this->invokePrivate('getLoginAttemptCacheKey', ['blockeduser']);
        $nowMs = intval(round(microtime(true) * 1000));
        $this->haxcms->cache->store($key, array(
            'firstAttempt' => $nowMs,
            'failedAttempts' => 0,
            'blockedUntil' => $nowMs + 60000,
        ), 3600);

        $result = $this->invokePrivate('processCredentialLogin', [
            'blockeduser', 'anypass', false,
        ]);
        $this->assertSame(429, $result['__failed']['status']);
        // testLogin should NOT have been called
        $this->assertFalse($this->haxcms->testLoginWasCalled);
    }
}

/**
 * HAXCMS collaborator stub for login trait tests.
 *
 * Provides configurable behavior for all methods referenced by
 * OperationsRouteLogin. Uses a real Cache instance (set by the test)
 * for authentic rate-limit state management.
 */
class LoginTestHaxcms
{
    public $cache = null;
    public $config;
    public $sessionJwt = null;
    public $activeUserName = 'testuser';

    // Configurable return values
    public $testLoginResult = false;
    public $testLoginWasCalled = false;
    public $validateJWTResult = false;
    public $jwtValue = 'test-jwt';
    public $refreshTokenValue = 'test-refresh-token';
    public $resolvedClientIP = '127.0.0.1';
    public $rateLimitEnabled = true;
    public $lastRefreshTokenCookie = null;

    public function __construct()
    {
        $this->config = new stdClass();
    }

    public function getLoginRateLimitSettings()
    {
        $settings = new stdClass();
        $settings->enabled = $this->rateLimitEnabled;
        $settings->windowMs = 900000;   // 15 minutes
        $settings->maxAttempts = 5;
        $settings->blockMs = 900000;    // 15 minutes
        return $settings;
    }

    public function resolveClientIP()
    {
        return $this->resolvedClientIP;
    }

    public function testLogin($username, $password, $fallback = true)
    {
        $this->testLoginWasCalled = true;
        return $this->testLoginResult;
    }

    public function getJWT($name)
    {
        return $this->jwtValue;
    }

    public function getRefreshToken($name)
    {
        return $this->refreshTokenValue;
    }

    public function setRefreshTokenCookie($token)
    {
        $this->lastRefreshTokenCookie = $token;
    }

    public function validateJWT($endOnInvalid = true)
    {
        return $this->validateJWTResult;
    }

    public function getActiveUserName()
    {
        return $this->activeUserName;
    }
}
