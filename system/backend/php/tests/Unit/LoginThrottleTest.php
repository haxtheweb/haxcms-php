<?php
use PHPUnit\Framework\TestCase;

/**
 * HAXCMS::loginThrottle* -- the brute-force limiter shared by the
 * credential login route and Basic authorization.
 *
 *  - IP::username AND a per-username counter (distributed guessing against
 *    one account from many addresses is still blocked)
 *  - with no shared store (cache unavailable, no APCu) it fails CLOSED:
 *    each failure sleeps and a request cannot keep trying
 *  - Basic auth and the login route share the counters
 */
class LoginThrottleTest extends TestCase
{
    private $tmp;
    private $savedServer = array();
    private $savedGlobal;
    private $savedSleep;

    protected function setUp(): void
    {
        foreach (array('REMOTE_ADDR', 'SERVER_SOFTWARE', 'HTTP_AUTHORIZATION') as $k) {
            $this->savedServer[$k] = array_key_exists($k, $_SERVER) ? $_SERVER[$k] : null;
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->savedGlobal = $GLOBALS['HAXCMS'] ?? null;
        $this->tmp = sys_get_temp_dir() . '/haxcms_login_throttle_' . uniqid();
        mkdir($this->tmp . '/cache', 0777, true);
        $this->savedSleep = HAXCMS::$loginThrottleFallbackSleepUs;
        // keep the fallback path fast under test
        HAXCMS::$loginThrottleFallbackSleepUs = 1000;
        $this->resetRequestAttempts();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        if ($this->savedGlobal !== null) {
            $GLOBALS['HAXCMS'] = $this->savedGlobal;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        HAXCMS::$loginThrottleFallbackSleepUs = $this->savedSleep;
        $this->resetRequestAttempts();
        foreach (glob($this->tmp . '/cache/*') ?: array() as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp . '/cache');
        @rmdir($this->tmp);
    }

    private function resetRequestAttempts(): void
    {
        $p = new ReflectionProperty(HAXCMS::class, 'loginThrottleRequestAttempts');
        $p->setAccessible(true);
        $p->setValue(null, 0);
        $w = new ReflectionProperty(HAXCMS::class, 'loginThrottleBackendWarned');
        $w->setAccessible(true);
        $w->setValue(null, true);
    }

    private function haxcms(bool $withCache): HAXCMS
    {
        $h = (new ReflectionClass(HAXCMS::class))->newInstanceWithoutConstructor();
        $h->privateKey = 'pk';
        $h->refreshPrivateKey = 'rpk';
        $h->salt = 's';
        $h->config = new stdClass();
        $h->config->security = new stdClass();
        $h->config->security->loginRateLimit = (object) array(
            'enabled' => true,
            'windowMs' => 60000,
            'maxAttempts' => 3,
            'blockMs' => 60000,
        );
        $h->user = new stdClass();
        $h->user->name = 'alice';
        $h->user->password = password_hash('correct-horse', PASSWORD_DEFAULT);
        $h->superUser = new stdClass();
        $h->superUser->name = 'alice';
        $h->superUser->password = $h->user->password;
        $h->configDirectory = $this->tmp;
        $cacheProp = new ReflectionProperty(HAXCMS::class, 'cache');
        $cacheProp->setAccessible(true);
        if ($withCache) {
            $cacheProp->setValue($h, new Cache(array('name' => 'haxcms', 'path' => $this->tmp . '/cache/', 'extension' => '.cache')));
        } else {
            $cacheProp->setValue($h, null);
        }
        return $h;
    }

    public function testPerIpCounterBlocksAfterMaxAttempts(): void
    {
        $h = $this->haxcms(true);
        $this->assertSame('cache', $h->loginThrottleBackend());
        for ($i = 0; $i < 2; $i++) {
            $h->loginThrottleRecordFailure('alice');
            $this->assertSame(0, $h->loginThrottleRetryAfter('alice'));
        }
        $h->loginThrottleRecordFailure('alice');
        $this->assertGreaterThan(0, $h->loginThrottleRetryAfter('alice'));
        // another account from the same address is not affected
        $this->assertSame(0, $h->loginThrottleRetryAfter('bob'));
    }

    public function testPerUsernameCounterBlocksDistributedGuessing(): void
    {
        $h = $this->haxcms(true);
        // 4 x maxAttempts failures, each from a fresh address: no single
        // IP::username counter ever reaches maxAttempts
        for ($i = 0; $i < 12; $i++) {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . ($i + 1);
            $this->assertSame(0, $h->loginThrottleRetryAfter('alice'), 'attempt ' . $i);
            $h->loginThrottleRecordFailure('alice');
        }
        $_SERVER['REMOTE_ADDR'] = '198.51.100.250';
        $this->assertGreaterThan(0, $h->loginThrottleRetryAfter('alice'), 'username counter must block');
        $this->assertSame(0, $h->loginThrottleRetryAfter('bob'));
    }

    public function testSuccessClearsOnlyTheIpCounter(): void
    {
        $h = $this->haxcms(true);
        $h->loginThrottleRecordFailure('alice');
        $h->loginThrottleRecordFailure('alice');
        $h->loginThrottleClear('alice');
        $keys = $h->loginThrottleKeys('alice');
        $settings = $h->getLoginRateLimitSettings();
        $now = intval(round(microtime(true) * 1000));
        $this->assertSame(0, $h->loginThrottleEntry($keys[0], $now, $settings)['failedAttempts']);
        $this->assertSame(2, $h->loginThrottleEntry($keys[1], $now, $settings)['failedAttempts']);
    }

    public function testNoStoreFailsClosedWithDelayAndPerRequestCap(): void
    {
        $h = $this->haxcms(false);
        if ($h->loginThrottleBackend() === 'apcu') {
            $this->markTestSkipped('APCu is enabled for CLI here; the no-store fallback is not reachable');
        }
        $this->assertSame('none', $h->loginThrottleBackend());
        $start = microtime(true);
        for ($i = 0; $i < HAXCMS::$loginThrottleFallbackMaxPerRequest; $i++) {
            $this->assertSame(0, $h->loginThrottleRetryAfter('alice'));
            $h->loginThrottleRecordFailure('alice');
        }
        $elapsedUs = (microtime(true) - $start) * 1000000;
        $this->assertGreaterThanOrEqual(HAXCMS::$loginThrottleFallbackSleepUs * HAXCMS::$loginThrottleFallbackMaxPerRequest * 0.5, $elapsedUs, 'each failure must sleep');
        $this->assertGreaterThan(0, $h->loginThrottleRetryAfter('alice'), 'request is capped once the per-request budget is spent');
        $this->assertGreaterThan(0, $h->loginThrottleRetryAfter('bob'), 'the cap is per request, not per user');
    }

    public function testBasicAuthSharesCountersWithLoginRoute(): void
    {
        $h = $this->haxcms(true);
        $GLOBALS['HAXCMS'] = $h;
        // three failures through the login route ...
        $ops = new Operations();
        for ($i = 0; $i < 3; $i++) {
            $ops->params = array('username' => 'alice', 'password' => 'wrong');
            $res = $ops->login();
            $this->assertSame(401, $res['__failed']['status']);
        }
        // ... and Basic auth with the RIGHT password is now blocked
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:correct-horse');
        $basic = $h->authenticateBasicAuthorization();
        $this->assertTrue($basic['attempted']);
        $this->assertTrue($basic['blocked']);
        $this->assertFalse($basic['authenticated']);
        $this->assertGreaterThan(0, $basic['retryAfterSeconds']);
        // and the login route itself answers 429
        $ops->params = array('username' => 'alice', 'password' => 'correct-horse');
        $this->assertSame(429, $ops->login()['__failed']['status']);
    }

    public function testBasicAuthFailuresCountWhenCacheIsGone(): void
    {
        $h = $this->haxcms(false);
        if ($h->loginThrottleBackend() === 'apcu') {
            $this->markTestSkipped('APCu is enabled for CLI here');
        }
        $GLOBALS['HAXCMS'] = $h;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:wrong');
        for ($i = 0; $i < HAXCMS::$loginThrottleFallbackMaxPerRequest; $i++) {
            $basic = $h->authenticateBasicAuthorization();
            $this->assertFalse($basic['authenticated']);
            $this->assertFalse($basic['blocked']);
        }
        // the budget for this request is gone: even the right password waits
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:correct-horse');
        $basic = $h->authenticateBasicAuthorization();
        $this->assertTrue($basic['blocked']);
        $this->assertFalse($basic['authenticated']);
    }
}
