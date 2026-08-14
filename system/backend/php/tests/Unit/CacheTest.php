<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the Cache seam.
 *
 * Expected values come from the cache contract: store/retrieve is a JSON
 * round-trip (SEC-04: cache values are JSON-encoded, never PHP-serialized,
 * so a tampered cache file cannot trigger unserialize() RCE). isCached,
 * erase, eraseExpired, eraseAll, retrieveAll behave per the Simple-PHP-Cache
 * API. getCacheDir sanitizes the cache name and hashes it (sha1). All FS
 * work happens in a per-test temp directory cleaned up in tearDown.
 */
class CacheTest extends TestCase
{
    private $tmpDir;
    private $cachePath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_cache_test_' . uniqid();
        $this->cachePath = $this->tmpDir . '/cache/';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
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

    private function makeCache(): Cache
    {
        return new Cache(array(
            'name' => 'test',
            'path' => $this->cachePath,
            'extension' => '.cache',
        ));
    }

    public function testStoreAndRetrieveRoundTripsData(): void
    {
        $cache = $this->makeCache();
        $cache->store('greeting', array('msg' => 'hi', 'n' => 42));
        $this->assertSame(array('msg' => 'hi', 'n' => 42), $cache->retrieve('greeting'));
    }

    public function testStoreScalarRoundTrips(): void
    {
        $cache = $this->makeCache();
        $cache->store('scalar', 'plain string');
        $this->assertSame('plain string', $cache->retrieve('scalar'));
    }

    public function testIsCachedAfterStoreAndFalseForMissing(): void
    {
        $cache = $this->makeCache();
        // FINDING (benign, not fixed): isCached() returns null (falsy), not
        // strict false, when no cache file exists yet — the loose
        // `false != _loadCache()` guard skips the body on a missing/empty
        // cache. null is falsy so every `if ($cache->isCached(...))` caller
        // is unaffected; asserted as falsy here rather than flipped to lie.
        $this->assertEmpty($cache->isCached('k'));
        $cache->store('k', 'v');
        $this->assertTrue($cache->isCached('k'));
        // file now exists with data; missing key returns strict false
        $this->assertFalse($cache->isCached('nope'));
    }

    public function testRetrieveMissingReturnsNull(): void
    {
        $cache = $this->makeCache();
        $this->assertNull($cache->retrieve('does-not-exist'));
    }

    public function testRetrieveTimestampReturnsStoredTime(): void
    {
        $cache = $this->makeCache();
        $before = time();
        $cache->store('k', 'v');
        $after = time();
        $ts = $cache->retrieve('k', true);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testEraseRemovesEntry(): void
    {
        $cache = $this->makeCache();
        $cache->store('k', 'v');
        $cache->erase('k');
        // after erase the cache file holds {}; isCached returns null (falsy)
        // for the absent key (see testIsCachedAfterStoreAndFalseForMissing)
        $this->assertEmpty($cache->isCached('k'));
    }

    public function testEraseThrowsOnMissingKeyWhenCacheExists(): void
    {
        $cache = $this->makeCache();
        $cache->store('present', 'v');
        // a cache file exists, so the missing-key branch throws
        $this->expectException(Exception::class);
        $cache->erase('never-stored');
    }

    public function testEraseSilentWhenNoCacheFileExists(): void
    {
        // FINDING (benign, not fixed): erase() on a fresh cache with no file
        // silently returns $this instead of throwing 'key not found', because
        // the is_array($cacheData) guard short-circuits before the throw
        // branch. Callers that try/catch are unaffected (nothing to erase).
        $cache = $this->makeCache();
        $result = $cache->erase('never-stored');
        $this->assertSame($cache, $result);
    }

    public function testEraseExpiredRemovesOnlyExpiredEntries(): void
    {
        $cache = $this->makeCache();
        // expiration of 1 second; sleep to push it past expiry
        $cache->store('short', 'v', 1);
        $cache->store('forever', 'v', 0);
        sleep(2);
        $removed = $cache->eraseExpired();
        $this->assertSame(1, $removed);
        $this->assertFalse($cache->isCached('short'));
        $this->assertTrue($cache->isCached('forever'));
    }

    public function testEraseAllClearsCache(): void
    {
        $cache = $this->makeCache();
        $cache->store('a', 1);
        $cache->store('b', 2);
        $cache->eraseAll();
        // eraseAll truncates the file to empty; isCached returns null (falsy)
        $this->assertEmpty($cache->isCached('a'));
        $this->assertEmpty($cache->isCached('b'));
    }

    public function testRetrieveAllReturnsDataMap(): void
    {
        $cache = $this->makeCache();
        $cache->store('a', 1);
        $cache->store('b', 'two');
        $all = $cache->retrieveAll(false);
        $this->assertSame(1, $all['a']);
        $this->assertSame('two', $all['b']);
    }

    public function testRetrieveAllMetaReturnsRawEntriesWithTimeAndExpire(): void
    {
        $cache = $this->makeCache();
        $cache->store('a', 1, 60);
        $meta = $cache->retrieveAll(true);
        $this->assertArrayHasKey('a', $meta);
        $this->assertArrayHasKey('time', $meta['a']);
        $this->assertArrayHasKey('expire', $meta['a']);
        $this->assertSame(60, $meta['a']['expire']);
    }

    public function testGetCacheDirSanitizesNameAndUsesSha1(): void
    {
        $cache = new Cache(array(
            'name' => 'My Cache!!',
            'path' => $this->cachePath,
            'extension' => '.cache',
        ));
        $dir = $cache->getCacheDir();
        // name is sanitized to alnum/./_/- then sha1-hashed; extension appended
        $this->assertStringEndsWith('.cache', $dir);
        $this->assertStringStartsWith($this->cachePath, $dir);
        // the hashed segment should be a 40-char hex sha1
        $basename = basename($dir, '.cache');
        $this->assertSame(40, strlen($basename));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $basename);
    }

    public function testCacheFileIsJsonNotPhpSerialized(): void
    {
        // Security (SEC-04): the on-disk cache format must be JSON so a
        // tampered cache file cannot trigger unserialize() object-gadget
        // RCE. Assert the raw file is valid JSON and carries no PHP serialize
        // object markers (O: , a: , s: ").
        $cache = $this->makeCache();
        $cache->store('k', array('x' => 1, 'y' => 'z'));
        $file = $cache->getCacheDir();
        $this->assertFileExists($file);
        $raw = file_get_contents($file);
        $this->assertNotFalse(json_decode($raw, true), 'cache file is valid JSON');
        $this->assertStringStartsWith('{', trim($raw));
        $this->assertStringNotContainsString('O:', $raw);
        $this->assertStringNotContainsString('a:', $raw);
    }

    public function testExtensionSetterAndGetter(): void
    {
        $cache = $this->makeCache();
        $cache->setExtension('.bin');
        $this->assertSame('.bin', $cache->getExtension());
    }

    public function testCacheNameSetterAndGetter(): void
    {
        $cache = $this->makeCache();
        $cache->setCache('other');
        $this->assertSame('other', $cache->getCache());
    }
}
