<?php
use PHPUnit\Framework\TestCase;

/**
 * OpenApiSpecCache returns exactly what Yaml::parse() returns, reuses a JSON
 * copy across requests only while the spec's SHA-1 matches, and falls back
 * to parsing on any cache problem. All cache files live in a per-test temp
 * _config directory.
 */
class OpenApiSpecCacheTest extends TestCase
{
    private $tmpDir;
    private $savedHaxcms;
    private $hadHaxcms;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_openapi_cache_' . uniqid();
        mkdir($this->tmpDir . '/_config', 0755, true);
        $this->hadHaxcms = array_key_exists('HAXCMS', $GLOBALS);
        $this->savedHaxcms = $this->hadHaxcms ? $GLOBALS['HAXCMS'] : null;
        $GLOBALS['HAXCMS'] = (object) array('configDirectory' => $this->tmpDir . '/_config');
        OpenApiSpecCache::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecCache::reset();
        if ($this->hadHaxcms) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
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
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function realSpecs(): array
    {
        $lib = dirname(__DIR__, 2) . '/lib';
        return array(
            $lib . '/siteRoutes/openapi/site-spec.yaml',
            $lib . '/systemRoutes/openapi/system-spec.yaml',
        );
    }

    private function writeSpec(string $name, string $yaml): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $yaml);
        return $path;
    }

    public function testRealSpecsMatchAFreshParseFromMemoAndFromTheJsonCopy(): void
    {
        foreach ($this->realSpecs() as $specPath) {
            $expected = \Symfony\Component\Yaml\Yaml::parseFile($specPath);
            // first load parses and writes the JSON copy
            $this->assertSame($expected, OpenApiSpecCache::load($specPath));
            $cacheFile = OpenApiSpecCache::getCacheFilePath($specPath);
            $this->assertFileExists($cacheFile);
            // in-request memo
            $this->assertSame($expected, OpenApiSpecCache::load($specPath));
            // next request: served from the JSON copy, identical array
            OpenApiSpecCache::reset();
            $this->assertSame($expected, OpenApiSpecCache::load($specPath));
        }
    }

    public function testNextRequestReadsTheJsonCopyInsteadOfParsing(): void
    {
        $specPath = $this->writeSpec('spec.yaml', "paths:\n  /x/api/v1/a:\n    get:\n      operationId: a\n");
        OpenApiSpecCache::load($specPath);
        $cacheFile = OpenApiSpecCache::getCacheFilePath($specPath);
        // prove the copy is what gets used: same hash, marker content
        $copy = json_decode(file_get_contents($cacheFile), true);
        $copy['spec'] = array('marker' => true);
        file_put_contents($cacheFile, json_encode($copy));
        OpenApiSpecCache::reset();
        $this->assertSame(array('marker' => true), OpenApiSpecCache::load($specPath));
    }

    public function testAChangedSpecIsParsedAgainEvenWithTheSameMtime(): void
    {
        $specPath = $this->writeSpec('spec.yaml', "paths:\n  /x/api/v1/a:\n    get:\n      operationId: first\n");
        $mtime = filemtime($specPath);
        $this->assertSame('first', OpenApiSpecCache::load($specPath)['paths']['/x/api/v1/a']['get']['operationId']);
        file_put_contents($specPath, "paths:\n  /x/api/v1/a:\n    get:\n      operationId: second\n");
        touch($specPath, $mtime);
        clearstatcache();
        OpenApiSpecCache::reset();
        $this->assertSame('second', OpenApiSpecCache::load($specPath)['paths']['/x/api/v1/a']['get']['operationId']);
        // and the copy was refreshed to the new hash
        $copy = json_decode(file_get_contents(OpenApiSpecCache::getCacheFilePath($specPath)), true);
        $this->assertSame(sha1_file($specPath), $copy['sha1']);
    }

    public function testCorruptOrMismatchedCopiesAreIgnored(): void
    {
        $specPath = $this->writeSpec('spec.yaml', "openapi: 3.0.0\npaths: {}\n");
        $expected = \Symfony\Component\Yaml\Yaml::parseFile($specPath);
        $cacheFile = OpenApiSpecCache::getCacheFilePath($specPath);
        mkdir(dirname($cacheFile), 0755, true);
        foreach (array(
            '{ not json',
            json_encode(array('spec' => array('evil' => true))),
            json_encode(array('sha1' => str_repeat('0', 40), 'spec' => array('evil' => true))),
            json_encode(array('sha1' => sha1_file($specPath))),
            '',
        ) as $bad) {
            file_put_contents($cacheFile, $bad);
            OpenApiSpecCache::reset();
            $this->assertSame($expected, OpenApiSpecCache::load($specPath), 'bad copy: ' . $bad);
        }
    }

    public function testMissingEmptyAndInvalidSpecsBehaveLikeTheOldReaders(): void
    {
        $this->assertNull(OpenApiSpecCache::load($this->tmpDir . '/nope.yaml'));
        $this->assertNull(OpenApiSpecCache::load($this->writeSpec('empty.yaml', '')));
        $invalid = $this->writeSpec('invalid.yaml', "paths:\n  - a\n b: [\n");
        $this->expectException(\Symfony\Component\Yaml\Exception\ParseException::class);
        OpenApiSpecCache::load($invalid);
    }

    public function testNoConfigDirectoryMeansNoCacheFile(): void
    {
        $GLOBALS['HAXCMS'] = (object) array('configDirectory' => $this->tmpDir . '/missing-config');
        $specPath = $this->writeSpec('spec.yaml', "paths: {}\n");
        $this->assertSame(array('paths' => array()), OpenApiSpecCache::load($specPath));
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/missing-config');
    }

    public function testAuthPolicyReadersStillResolveFromTheSpec(): void
    {
        // a secured mutation and the mutation guard, read through the cached spec
        $this->assertSame('authenticated-site', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/site/outline', 'patch'));
        $this->assertSame(array(), SiteRouteUtils::assertSiteApiMutationRoutesAreSecured(SiteRoutesMap::getRoutesMap()));
        $this->assertNotSame('', CommitContext::getOperationId('site', 'patch', 'v1/site/outline'));
    }
}
