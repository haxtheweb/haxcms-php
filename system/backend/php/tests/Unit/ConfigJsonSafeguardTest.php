<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the config.json load safeguard (issue #2967).
 *
 * HAXCMS::loadConfigJson() (private) is the seam under test. It is exercised
 * directly via Reflection on a newInstanceWithoutConstructor() instance so
 * the heavy constructor (argv shifts, php://input reads, Cache init, etc.)
 * never runs. HAXCMS_ROOT is the process-global temp root defined by the
 * shared phpunit-bootstrap.php, with the real `system/` tree symlinked in,
 * so the boilerplate config.json fallback tier resolves to the real
 * system/boilerplate/systemsetup/config.json exactly as it does in
 * production.
 *
 * Every scenario (missing file, empty file, corrupt/unparseable JSON) MUST:
 *   - return a valid, non-null config object (never leave config null/unparsed)
 *   - perform ZERO disk writes: no config.json is ever created, and an
 *     existing (corrupt/empty) file on disk is left byte-for-byte and
 *     mtime-for-mtime untouched. This is the most important invariant the
 *     "no disk writes" constraint requires, so it is asserted explicitly in
 *     every test below.
 */
class ConfigJsonSafeguardTest extends TestCase
{
    private $haxcms;
    private $tmpConfigDir;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_config_safeguard_' . uniqid();
        mkdir($this->tmpConfigDir, 0777, true);
    }

    protected function tearDown(): void
    {
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Invoke the private HAXCMS::loadConfigJson($path) seam via Reflection.
     */
    private function invokeLoadConfigJson(string $path)
    {
        $method = new ReflectionMethod(HAXCMS::class, 'loadConfigJson');
        $method->setAccessible(true);
        return $method->invoke($this->haxcms, $path);
    }

    /**
     * Assert the returned config object has the shape every downstream
     * consumer of $this->config relies on existing (whether it came from the
     * boilerplate tier or the minimal hand-built tier).
     */
    private function assertValidFallbackConfigShape($config): void
    {
        $this->assertIsObject($config);
        $this->assertObjectHasProperty('mcp', $config);
        $this->assertObjectHasProperty('security', $config);
        $this->assertObjectHasProperty('site', $config);
        $this->assertObjectHasProperty('deploymentProfile', $config);
    }

    // ===== missing file =====

    public function testMissingConfigJsonReturnsValidInMemoryFallback(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        $this->assertFalse(file_exists($configPath));

        $config = $this->invokeLoadConfigJson($configPath);

        $this->assertValidFallbackConfigShape($config);
    }

    public function testMissingConfigJsonWritesNothingToConfigDirectory(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        $before = scandir($this->tmpConfigDir);

        $this->invokeLoadConfigJson($configPath);

        $after = scandir($this->tmpConfigDir);
        // Nothing was created: the directory listing is unchanged and, in
        // particular, no config.json now exists on disk.
        $this->assertSame($before, $after);
        $this->assertFalse(file_exists($configPath), 'A missing config.json must never be created by the loader.');
        $this->assertFalse(in_array('config.json', $after, true));
    }

    // ===== corrupt / unparseable JSON =====

    public function testCorruptConfigJsonReturnsValidInMemoryFallback(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        file_put_contents($configPath, '{ this is not valid json ]');

        $config = $this->invokeLoadConfigJson($configPath);

        $this->assertValidFallbackConfigShape($config);
    }

    public function testCorruptConfigJsonFileIsLeftByteForByteUntouched(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        $corruptContent = '{ this is not valid json ]';
        file_put_contents($configPath, $corruptContent);
        // Back-date mtime slightly so any accidental rewrite is detectable
        // even on filesystems with coarse mtime resolution.
        touch($configPath, time() - 120);
        $beforeContent = file_get_contents($configPath);
        $beforeMtime = filemtime($configPath);

        $this->invokeLoadConfigJson($configPath);

        clearstatcache(true, $configPath);
        $afterContent = file_get_contents($configPath);
        $afterMtime = filemtime($configPath);

        $this->assertSame($beforeContent, $afterContent, 'Corrupt config.json content must never be rewritten.');
        $this->assertSame($beforeMtime, $afterMtime, 'Corrupt config.json mtime must never change (no write occurred).');
        $this->assertSame($corruptContent, $afterContent);
    }

    // ===== empty file =====

    public function testEmptyConfigJsonReturnsValidInMemoryFallback(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        file_put_contents($configPath, '');

        $config = $this->invokeLoadConfigJson($configPath);

        $this->assertValidFallbackConfigShape($config);
    }

    public function testEmptyConfigJsonFileIsLeftUntouched(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        file_put_contents($configPath, '');
        touch($configPath, time() - 120);
        $beforeContent = file_get_contents($configPath);
        $beforeMtime = filemtime($configPath);

        $this->invokeLoadConfigJson($configPath);

        clearstatcache(true, $configPath);
        $afterContent = file_get_contents($configPath);
        $afterMtime = filemtime($configPath);

        $this->assertSame($beforeContent, $afterContent, 'Empty config.json content must never be rewritten.');
        $this->assertSame($beforeMtime, $afterMtime, 'Empty config.json mtime must never change (no write occurred).');
        $this->assertSame('', $afterContent);
    }

    // ===== valid file is unaffected by the safeguard =====

    public function testValidConfigJsonIsDecodedAndLeftUntouched(): void
    {
        $configPath = $this->tmpConfigDir . '/config.json';
        $validContent = json_encode(array(
            'deploymentProfile' => 'self-hosted-multi-site',
            'themes' => new stdClass(),
        ), JSON_PRETTY_PRINT);
        file_put_contents($configPath, $validContent);
        $beforeMtime = filemtime($configPath);

        $config = $this->invokeLoadConfigJson($configPath);

        $this->assertIsObject($config);
        $this->assertSame('self-hosted-multi-site', $config->deploymentProfile);
        clearstatcache(true, $configPath);
        $this->assertSame($validContent, file_get_contents($configPath));
        $this->assertSame($beforeMtime, filemtime($configPath));
    }

    // ===== minimal (last-resort) in-memory fallback tier =====

    public function testMinimalConfigFallbackShapeMatchesSpec(): void
    {
        $method = new ReflectionMethod(HAXCMS::class, 'minimalConfigFallback');
        $method->setAccessible(true);
        $config = $method->invoke($this->haxcms);

        $this->assertIsObject($config->themes);
        $this->assertIsObject($config->security);
        $this->assertIsObject($config->site);
        $this->assertIsObject($config->site->settings);
        $this->assertIsObject($config->site->git);
        $this->assertIsObject($config->site->static);
        $this->assertIsObject($config->site->publishers);
        $this->assertTrue($config->mcp->enabled);
        $this->assertTrue($config->mcp->readOnly);
        $this->assertSame('single-site', $config->deploymentProfile);
    }
}
