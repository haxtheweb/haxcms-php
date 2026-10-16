<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage for HAXCMS auth seams: request tokens, IAM token normalization,
 * JWT/refresh validation, authenticated-user-name resolution, IAM route
 * authorization, plaintext password upgrade and rate-limit settings.
 *
 * The $_SERVER['SERVER_SOFTWARE'] trick flips isCLI() to false so the non-CLI
 * branches of these methods run (php_sapi_name() is always 'cli' under
 * PHPUnit, but isCLI() checks SERVER_SOFTWARE first). Only FALSE is passed to
 * validateJWT()/validateRefreshToken() because TRUE triggers exit().
 */
class HAXCMSAuthCoverageTest extends TestCase
{
    private $haxcms;
    private $savedGlobalsHaxcms;
    private $tmpDir;
    private $backupServer;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->config = new stdClass();
        $this->haxcms->privateKey = 'cov-private';
        $this->haxcms->salt = 'cov-salt';
        $this->haxcms->refreshPrivateKey = 'cov-refresh-private';
        $this->haxcms->sitesDirectory = 'sites';
        $this->haxcms->basePath = '/';
        $this->haxcms->configDirectory = null;
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->haxcms, null);
        $this->haxcms->sessionJwt = null;
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = null;
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = null;
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedGlobalsHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_auth_cov_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->backupServer = $_SERVER;
        // flips isCLI() to false for the whole test
        $_SERVER['SERVER_SOFTWARE'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->backupServer;
        $_POST = array();
        $_COOKIE = array();
        if (isset($this->savedGlobalsHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedGlobalsHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function invokePrivate($method, ...$args)
    {
        $ref = new ReflectionMethod('HAXCMS', $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->haxcms, ...$args);
    }

    // ---- request token ----

    public function testValidateRequestTokenComparesHmac()
    {
        // mismatched token -> false
        $this->assertFalse($this->haxcms->validateRequestToken('bogus', ''));
        // token supplied via $_POST wins
        $_POST['token'] = $this->haxcms->getRequestToken('user');
        $this->assertTrue($this->haxcms->validateRequestToken(null, 'user'));
        // explicit token parameter also works
        $this->assertTrue($this->haxcms->validateRequestToken($this->haxcms->getRequestToken('user'), 'user'));
        unset($_POST['token']);
    }

    // ---- IAM token normalization ----

    public function testNormalizeIAMTokenValueVariants()
    {
        $h = $this->haxcms;
        // iam disabled -> value unchanged
        $this->assertSame('raw', $this->invokePrivate('normalizeIAMTokenValue', 'raw'));
        // iam enabled, empty value short circuits
        $h->config->iam = true;
        $this->assertSame('', $this->invokePrivate('normalizeIAMTokenValue', ''));
        // no resolvable user -> value unchanged
        $this->assertSame('raw', $this->invokePrivate('normalizeIAMTokenValue', 'raw'));
        // active user === token user -> value unchanged
        $h->user->name = 'alice';
        $this->assertSame('raw', $this->invokePrivate('normalizeIAMTokenValue', 'raw'));
    }

    // ---- authenticated user name resolution ----

    public function testGetAuthenticatedUserNameWithoutCredentials()
    {
        $this->assertFalse($this->haxcms->isCLI());
        $this->assertNull($this->haxcms->getAuthenticatedUserName());
    }

    public function testGetAuthenticatedUserNameFromValidRefreshCookie()
    {
        $_COOKIE['haxcms_refresh_token'] = $this->haxcms->getRefreshToken('alice', false);
        $this->assertSame('alice', $this->haxcms->getAuthenticatedUserName());
    }

    public function testGetAuthenticatedUserNameRejectsInvalidRefreshCookie()
    {
        $_COOKIE['haxcms_refresh_token'] = 'not-a-jwt';
        $this->assertNull($this->haxcms->getAuthenticatedUserName());
    }

    public function testGetAuthenticatedUserNameRequiresAgreementWithSessionJwt()
    {
        // validateUser must pass for the session JWT identity to count
        $this->haxcms->user->name = 'bob';
        $_COOKIE['haxcms_refresh_token'] = $this->haxcms->getRefreshToken('alice', false);
        $this->haxcms->sessionJwt = $this->haxcms->getJWT('bob');
        $this->assertNull($this->haxcms->getAuthenticatedUserName());
    }

    public function testGetAuthenticatedUserNameFromSessionJwtAlone()
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->sessionJwt = $this->haxcms->getJWT('alice');
        $this->assertSame('alice', $this->haxcms->getAuthenticatedUserName());
    }

    // ---- direct token validation ----

    public function testValidateRefreshTokenFalseVariants()
    {
        $this->assertFalse($this->invokePrivate('validateRefreshToken', FALSE));
        $_COOKIE['haxcms_refresh_token'] = 'garbage';
        $this->assertFalse($this->invokePrivate('validateRefreshToken', FALSE));
    }

    public function testValidateRefreshTokenDecodesPayload()
    {
        $_COOKIE['haxcms_refresh_token'] = $this->haxcms->getRefreshToken('alice', false);
        $payload = $this->invokePrivate('validateRefreshToken', FALSE);
        $this->assertIsObject($payload);
        $this->assertSame('alice', $payload->user);
    }

    public function testValidateRefreshTokenRejectsExpired()
    {
        $issued = time();
        $expired = JWT::encode(
            array(
                'iat' => $issued - 10000,
                'exp' => $issued - 5000,
                'user' => 'alice',
                'id' => 'sess-1',
            ),
            $this->haxcms->refreshPrivateKey . $this->haxcms->salt
        );
        $_COOKIE['haxcms_refresh_token'] = $expired;
        $this->assertFalse($this->invokePrivate('validateRefreshToken', FALSE));
    }

    public function testValidateJWTFalseVariants()
    {
        $this->assertFalse($this->invokePrivate('validateJWT', FALSE));
        $this->haxcms->sessionJwt = 'garbage';
        $this->assertFalse($this->invokePrivate('validateJWT', FALSE));
    }

    public function testValidateJWTValidForKnownUser()
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->sessionJwt = $this->haxcms->getJWT('alice');
        $this->assertTrue($this->invokePrivate('validateJWT', FALSE));
    }

    // ---- active / token / path user names ----

    public function testGetActiveUserNameFallbackChain()
    {
        $h = $this->haxcms;
        // nothing set
        $this->assertNull($h->getActiveUserName());
        // super user fallback
        $h->superUser->name = 'admin';
        $this->assertSame('admin', $h->getActiveUserName());
        // authenticated session wins over the config fallbacks
        $h->user->name = 'alice';
        $h->sessionJwt = $h->getJWT('alice');
        $this->assertSame('alice', $h->getActiveUserName());
    }

    public function testGetRequestTokenUserNameMatchesAuthenticated()
    {
        $h = $this->haxcms;
        $this->assertNull($h->getRequestTokenUserName());
        $h->user->name = 'alice';
        $h->sessionJwt = $h->getJWT('alice');
        $this->assertSame('alice', $h->getRequestTokenUserName());
    }

    public function testGetRequestPathUserNameVariants()
    {
        $h = $this->haxcms;
        // no REQUEST_URI -> null
        $this->assertNull($h->getRequestPathUserName());
        $_SERVER['REQUEST_URI'] = '/alice/page/1';
        $this->assertSame('alice', $h->getRequestPathUserName());
        // query strings are stripped
        $_SERVER['REQUEST_URI'] = '/alice/page?x=1';
        $this->assertSame('alice', $h->getRequestPathUserName());
        // root path only
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertNull($h->getRequestPathUserName());
        // system api routes are never user paths
        $_SERVER['REQUEST_URI'] = '/system/api/v1/x';
        $this->assertNull($h->getRequestPathUserName());
        // basePath is honored
        $h->basePath = '/sub';
        $_SERVER['REQUEST_URI'] = '/sub/alice/page';
        $this->assertSame('alice', $h->getRequestPathUserName());
        unset($_SERVER['REQUEST_URI']);
        $h->basePath = '/';
    }

    public function testGetIAMTenantUserNameFallsBackToPath()
    {
        $h = $this->haxcms;
        // iam disabled -> null
        $this->assertNull($h->getIAMTenantUserName());
        // iam enabled: no tenant store under HAXCMS_ROOT, path used instead
        $h->config->iam = true;
        $_SERVER['REQUEST_URI'] = '/alice/page';
        $this->assertSame('alice', $h->getIAMTenantUserName());
        unset($_SERVER['REQUEST_URI']);
    }

    public function testValidateIAMRouteAuthorizationVariants()
    {
        $h = $this->haxcms;
        // iam off -> allowed
        $this->assertTrue($h->validateIAMRouteAuthorization(false)['allowed']);
        // iam on, auth required, no authenticated user -> 403
        $h->config->iam = true;
        $denied = $h->validateIAMRouteAuthorization(true);
        $this->assertFalse($denied['allowed']);
        $this->assertSame(403, $denied['status']);
        // iam on, no auth required but path tenant differs from authenticated user -> 403
        $_SERVER['REQUEST_URI'] = '/alice/page';
        $h->user->name = 'bob';
        $h->sessionJwt = $h->getJWT('bob');
        $this->assertFalse($h->validateIAMRouteAuthorization(false)['allowed']);
        // matching user -> allowed
        $h->user->name = 'alice';
        $h->sessionJwt = $h->getJWT('alice');
        $this->assertTrue($h->validateIAMRouteAuthorization(false)['allowed']);
        unset($_SERVER['REQUEST_URI']);
    }

    // ---- plaintext password upgrade ----

    public function testMaybeUpgradePlaintextPasswordRewritesConfigPhp()
    {
        $h = $this->haxcms;
        $h->configDirectory = $this->tmpDir;
        $h->user->password = 'oldpass';
        file_put_contents(
            $this->tmpDir . '/config.php',
            "<?php\n\$HAXCMS->user->password = 'oldpass';\n"
        );
        $this->invokePrivate('maybeUpgradePlaintextPassword', 'user', 'oldpass');
        $this->assertNotSame('oldpass', $h->user->password);
        $this->assertTrue(password_verify('oldpass', $h->user->password));
        $contents = file_get_contents($this->tmpDir . '/config.php');
        // the plaintext line is replaced with the freshly generated hash
        $this->assertStringContainsString($h->user->password, $contents);
        $this->assertStringNotContainsString("\$HAXCMS->user->password = 'oldpass';", $contents);
    }

    public function testMaybeUpgradePlaintextPasswordNoOps()
    {
        $h = $this->haxcms;
        // invalid account slot
        $h->configDirectory = $this->tmpDir;
        $h->user->password = 'oldpass';
        $this->assertNull($this->invokePrivate('maybeUpgradePlaintextPassword', 'nobody', 'oldpass'));
        // no config.php present
        $this->assertNull($this->invokePrivate('maybeUpgradePlaintextPassword', 'user', 'oldpass'));
        // config.php exists but stored value is not a string
        file_put_contents($this->tmpDir . '/config.php', "<?php\n\$HAXCMS->user->password = 'oldpass';\n");
        $h->user = new stdClass();
        $this->assertNull($this->invokePrivate('maybeUpgradePlaintextPassword', 'user', 'oldpass'));
        // stored value already hashed -> nothing rewritten
        $h->user = new stdClass();
        $h->user->password = password_hash('oldpass', PASSWORD_DEFAULT);
        $before = file_get_contents($this->tmpDir . '/config.php');
        $this->assertNull($this->invokePrivate('maybeUpgradePlaintextPassword', 'user', 'oldpass'));
        $this->assertSame($before, file_get_contents($this->tmpDir . '/config.php'));
        // empty submission -> ignored
        $h->user = new stdClass();
        $h->user->password = 'oldpass';
        $this->assertNull($this->invokePrivate('maybeUpgradePlaintextPassword', 'user', ''));
    }

    // ---- rate-limit settings ----

    public function testGetFileOpsRateLimitSettingsDefaults()
    {
        $settings = $this->haxcms->getFileOpsRateLimitSettings();
        $this->assertTrue($settings->enabled);
        $this->assertSame(500, $settings->max);
        $this->assertSame(300000, $settings->windowMs);
        $this->assertSame(300000, $settings->blockMs);
    }

    public function testGetFileOpsRateLimitSettingsConfiguredAndClamped()
    {
        $c = $this->haxcms->config;
        $c->security = new stdClass();
        $c->security->fileOpsRateLimit = (object) array(
            'enabled' => false,
            'max' => 2,
            'windowMs' => 5,
            'blockMs' => 0,
        );
        $settings = $this->haxcms->getFileOpsRateLimitSettings();
        $this->assertFalse($settings->enabled);
        $this->assertSame(2, $settings->max);
        $this->assertSame(10000, $settings->windowMs);
        $this->assertSame(10000, $settings->blockMs);
        // huge values are clamped
        $c->security->fileOpsRateLimit->max = 99999999;
        $c->security->fileOpsRateLimit->windowMs = 999999999;
        $c->security->fileOpsRateLimit->blockMs = 999999999;
        $settings = $this->haxcms->getFileOpsRateLimitSettings();
        $this->assertSame(100000, $settings->max);
        $this->assertSame(86400000, $settings->windowMs);
        $this->assertSame(86400000, $settings->blockMs);
    }

    public function testGetLoginRateLimitSettingsDefaults()
    {
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $this->assertTrue($settings->enabled);
        $this->assertSame(5, $settings->maxAttempts);
        $this->assertSame(900000, $settings->windowMs);
        $this->assertSame(900000, $settings->blockMs);
    }

    // ---- basic auth + shared rate limiter ----

    public function testAuthenticateBasicAuthorizationResetsExpiredWindow()
    {
        $h = $this->haxcms;
        $cache = new Cache(array(
            'name' => 'covauth',
            'path' => $this->tmpDir . '/',
            'extension' => '.cache',
        ));
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($h, $cache);
        $c = $h->config;
        $c->security = new stdClass();
        $c->security->loginRateLimit = (object) array(
            'enabled' => true,
            'maxAttempts' => 5,
            'windowMs' => 60000,
            'blockMs' => 300000,
        );
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('covuser:wrongpass');
        // stale attempt entry far outside the window -> counter reset (no 429)
        $key = 'login-rate:' . sha1($h->resolveClientIP() . '::covuser');
        $cache->store($key, array(
            'firstAttempt' => time() * 1000 - 99999999,
            'failedAttempts' => 3,
            'blockedUntil' => 0,
        ), 99999999);
        $h->user->name = 'covuser';
        $h->user->password = password_hash('rightpass', PASSWORD_DEFAULT);
        $result = $h->authenticateBasicAuthorization();
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['authenticated']);
        $this->assertFalse($result['blocked']);
        $stored = $cache->retrieve($key);
        $this->assertSame(1, $stored['failedAttempts']);
    }

    public function testAuthenticateBasicAuthorizationSuccessErasesCounter()
    {
        $h = $this->haxcms;
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($h, new Cache(array(
            'name' => 'covauth2',
            'path' => $this->tmpDir . '/',
            'extension' => '.cache',
        )));
        $c = $h->config;
        $c->security = new stdClass();
        $c->security->loginRateLimit = (object) array('enabled' => true);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('covuser2:rightpass');
        $h->user->name = 'covuser2';
        $h->user->password = password_hash('rightpass', PASSWORD_DEFAULT);
        $result = $h->authenticateBasicAuthorization();
        $this->assertTrue($result['authenticated']);
        $this->assertSame('covuser2', $result['userName']);
    }

    public function testAuthenticateBasicAuthorizationIgnoresNonBasicHeader()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer something';
        $result = $this->haxcms->authenticateBasicAuthorization();
        $this->assertFalse($result['attempted']);
    }

    public function testValidateAccessTokenClaimsRejectsNonObjects()
    {
        $this->expectException(UnexpectedValueException::class);
        $this->invokePrivate('validateAccessTokenClaims', 'not-an-object');
    }
}
