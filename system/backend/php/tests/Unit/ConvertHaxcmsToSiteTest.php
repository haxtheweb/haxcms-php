<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertHaxcmsToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * Coverage: the missing/invalid repoUrl validation branches, and the pure
 * staging-path helpers (haxcms_import_get_staging_root,
 * haxcms_import_stage_remote_file) using a mocked $GLOBALS['HAXCMS'], per
 * the pattern established in HAXCMSFileBulkImportTest.php. The site.json
 * fetch happy path requires live network access and is out of scope.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertHaxcmsToSite.php';

class ConvertHaxcmsToSiteTest extends TestCase
{
    private $savedHaxcms;
    private $tmpBase;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if ($this->tmpBase !== null && is_dir($this->tmpBase)) {
            $this->rrmdir($this->tmpBase);
            $this->tmpBase = null;
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
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeContext(array $body = []): stdClass
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = $body;
        return $context;
    }

    private function call(stdClass $context): array
    {
        ob_start();
        haxcmsImportConvertHaxcmsToSite($context);
        return json_decode(ob_get_clean(), true);
    }

    public function testMissingRepoUrlReturns400(): void
    {
        $response = $this->call($this->makeContext());

        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame([], $response['data']['items']);
        $this->assertNull($response['data']['filename']);
    }

    public function testEmptyRepoUrlReturns400(): void
    {
        $response = $this->call($this->makeContext(['repoUrl' => '']));
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
    }

    public function testUnparsableRepoUrlReturns400(): void
    {
        // parse_url() returns false/no host for a malformed URL, triggering
        // the "Invalid repoUrl" branch before any network call is attempted.
        $response = $this->call($this->makeContext(['repoUrl' => 'not a url ://???']));

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid repoUrl', $response['data']['error']);
    }

    // ------------------------------------------------------------------
    // haxcms_import_get_staging_root
    // ------------------------------------------------------------------

    public function testGetStagingRootReturnsFalseWhenHaxcmsGlobalMissing(): void
    {
        unset($GLOBALS['HAXCMS']);
        $this->assertFalse(haxcms_import_get_staging_root());
    }

    public function testGetStagingRootReturnsFalseWhenConfigDirectoryMissing(): void
    {
        $GLOBALS['HAXCMS'] = new stdClass();
        $this->assertFalse(haxcms_import_get_staging_root());
    }

    public function testGetStagingRootCreatesAndReturnsTmpImportsDirectory(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/haxcms_import_test_' . uniqid();
        mkdir($this->tmpBase, 0777, true);

        $mock = new stdClass();
        $mock->configDirectory = $this->tmpBase;
        $GLOBALS['HAXCMS'] = $mock;

        $root = haxcms_import_get_staging_root();

        $this->assertSame($this->tmpBase . '/tmp/imports', $root);
        $this->assertDirectoryExists($root);
    }

    // ------------------------------------------------------------------
    // haxcms_import_stage_remote_file
    // ------------------------------------------------------------------

    public function testStageRemoteFileReturnsFalseWhenStagingRootUnavailable(): void
    {
        unset($GLOBALS['HAXCMS']);
        $client = new \GuzzleHttp\Client();
        $this->assertFalse(haxcms_import_stage_remote_file($client, 'https://example.com/file.txt', 'file.txt'));
    }
}
