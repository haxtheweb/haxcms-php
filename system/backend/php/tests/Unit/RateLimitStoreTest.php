<?php
use PHPUnit\Framework\TestCase;

/**
 * SEC-RATELIMIT: throttles and budgets outside the HAXCMS class reach the
 * shared store, and a budget with no store that outlives the request refuses
 * instead of counting per request.
 */
class RateLimitStoreTest extends TestCase
{
    private $savedMode;

    protected function setUp(): void
    {
        $this->savedMode = LinkChecker::$noStoreBudgetMode;
        LinkChecker::resetForTesting();
    }

    protected function tearDown(): void
    {
        LinkChecker::$noStoreBudgetMode = $this->savedMode;
        LinkChecker::resetForTesting();
    }

    private function settings($max = 2)
    {
        return (object) array('enabled' => true, 'windowMs' => 60000, 'max' => $max);
    }

    public function testCacheStoreIsReachableFromOutsideTheClass(): void
    {
        $h = (new ReflectionClass(HAXCMS::class))->newInstanceWithoutConstructor();
        $this->assertNull($h->getCacheStore());
        $cache = new Cache();
        (new ReflectionProperty(HAXCMS::class, 'cache'))->setValue($h, $cache);
        $this->assertSame($cache, $h->getCacheStore());
        $this->assertSame('cache', $h->loginThrottleBackend());
    }

    public function testNothingOutsideHaxcmsReadsTheCachePropertyDirectly(): void
    {
        $libDir = dirname(__DIR__, 2) . '/lib';
        $found = array();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (strpos(ltrim($line), '//') === 0 || strpos(ltrim($line), '*') === 0) {
                    continue;
                }
                if (preg_match('/\$GLOBALS\[\'HAXCMS\'\]->cache\b(?!Buster)/', $line)) {
                    $found[] = basename($file->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        // operations/login.php keeps a fallback path that only runs when the
        // HAXCMS object has no loginThrottle* methods (test doubles)
        $found = array_values(array_filter($found, function ($hit) {
            return strpos($hit, 'login.php:') !== 0;
        }));
        $this->assertSame(array(), $found, 'use HAXCMS::getCacheStore(); a private/protected property reads as unset from outside');
    }

    public function testBudgetWithNoSharedStoreRefusesAndSpendsNothing(): void
    {
        if (function_exists('apcu_fetch') && ini_get('apc.enable_cli')) {
            $this->markTestSkipped('APCu is the store here');
        }
        LinkChecker::$noStoreBudgetMode = 'refuse';
        $result = LinkChecker::changeBudget(null, 'u::site', 1, 1000, $this->settings());
        $this->assertFalse($result['allowed']);
        $this->assertSame(60, $result['retryAfterSeconds']);
        $this->assertTrue(LinkChecker::changeBudget(null, 'u::site', -1, 1000, $this->settings())['allowed'], 'giving checks back never fails');
        $this->assertTrue(LinkChecker::changeBudget(null, 'u::site', 0, 1000, $this->settings())['allowed'], 'nothing to spend');
    }

    public function testBudgetCountsInTheSharedCacheAcrossCalls(): void
    {
        LinkChecker::$noStoreBudgetMode = 'refuse';
        $cache = new class {
            public $data = array();
            public function retrieve($k) { return isset($this->data[$k]) ? $this->data[$k] : null; }
            public function store($k, $v, $ttl = 0) { $this->data[$k] = $v; }
        };
        $this->assertTrue(LinkChecker::changeBudget($cache, 'u::site', 2, 1000, $this->settings())['allowed']);
        $over = LinkChecker::changeBudget($cache, 'u::site', 1, 2000, $this->settings());
        $this->assertFalse($over['allowed']);
        $this->assertSame(59, $over['retryAfterSeconds']);
        $this->assertTrue(LinkChecker::changeBudget($cache, 'u::site', 1, 61000, $this->settings())['allowed'], 'a new window starts fresh');
    }
}
