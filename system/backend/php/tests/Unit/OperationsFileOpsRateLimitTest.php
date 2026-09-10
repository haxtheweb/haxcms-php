<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Security (F3) tests for the file-operations count-window rate limit wired
 * into OperationsRouteSaveFile::saveFile() (and shared by fileOperation()).
 *
 * The limiter runs AFTER token validation and BEFORE loadSite/upload-resolution,
 * so under CLI the first `max` calls pass the gate (then hit the expected
 * 'Invalid upload source' 500 from HAXCMSFile::save because is_uploaded_file()
 * is false for non-HTTP-POST temp files), and the max+1 call returns 429
 * before reaching upload resolution. This proves the gate fires at the right
 * place without false-firing on valid calls.
 */
class OperationsFileOpsRateLimitTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $savedFiles;
    private $siteName = 'ratefile-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        if (isset($_FILES)) {
            $this->savedFiles = $_FILES;
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_ratefile_root');
        }

        $this->haxcms = new FileOpsRateLimitTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
        $_FILES = array();
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
        if (isset($this->savedFiles)) {
            $_FILES = $this->savedFiles;
            $this->savedFiles = null;
        } else {
            $_FILES = array();
        }
    }

    private function setFakeUpload(): void
    {
        $_FILES['file'] = array(
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/fake-ratefile-' . uniqid(),
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
        );
    }

    private function saveFileCall()
    {
        $this->setFakeUpload();
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        return $this->ops->saveFile();
    }

    public function testUnderLimitCallsPassTheGate(): void
    {
        // max=3 in the mock settings; calls 1..3 pass the gate and fall through
        // to the CLI 'Invalid upload source' 500 (proving the gate did NOT fire).
        for ($i = 1; $i <= 3; $i++) {
            $result = $this->saveFileCall();
            $this->assertArrayHasKey('__failed', $result);
            $this->assertSame(500, $result['__failed']['status']);
            // under-limit calls fall through to 'Invalid upload source', NOT a
            // rate-limit message.
            $this->assertStringNotContainsString('rate limit', $result['__failed']['message']);
        }
    }

    public function testOverLimitCallReturns429(): void
    {
        // exhaust the budget (3 allowed)
        for ($i = 1; $i <= 3; $i++) {
            $this->saveFileCall();
        }
        // 4th call (max+1) -> blocked at the rate gate, before upload resolution
        $result = $this->saveFileCall();
        $this->assertArrayHasKey('__failed', $result);
        $this->assertSame(429, $result['__failed']['status']);
        // Dynamic message: states the limit (3 ops / 1 min from mock settings)
        // and the retry time (5 seconds from mock blockMs=5000).
        $this->assertStringContainsString('rate limit', $result['__failed']['message']);
        $this->assertStringContainsString('3 operations per 1 minute', $result['__failed']['message']);
        $this->assertStringContainsString('retry in 5 seconds', $result['__failed']['message']);
    }

    public function testDisabledSettingsDoesNotEnforce(): void
    {
        $this->haxcms->rateLimitEnabled = false;
        // many calls, none should 429
        for ($i = 1; $i <= 10; $i++) {
            $result = $this->saveFileCall();
            $this->assertNotSame(429, $result['__failed']['status']);
        }
    }

    public function testNoCacheFailsOpen(): void
    {
        $this->haxcms->cache = null;
        for ($i = 1; $i <= 10; $i++) {
            $result = $this->saveFileCall();
            $this->assertNotSame(429, $result['__failed']['status']);
        }
    }

    public function testDistinctSiteNamesAreIndependent(): void
    {
        // exhaust site-a budget
        for ($i = 1; $i <= 3; $i++) {
            $this->setFakeUpload();
            $this->ops->params = array(
                'site_token' => 'good',
                'site' => array('name' => 'site-a'),
            );
            $this->ops->saveFile();
        }
        // site-b is a different key -> still under budget, no 429
        $this->setFakeUpload();
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => 'site-b'),
        );
        $result = $this->ops->saveFile();
        $this->assertNotSame(429, $result['__failed']['status']);
    }
}

/**
 * Minimal in-memory cache fake implementing the retrieve/store/erase surface
 * that OperationsRouteFileOpsRateLimit relies on.
 */
class FileOpsRateLimitFakeCache
{
    private $data = array();

    public function retrieve($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }

    public function store($key, $value, $ttl = 0)
    {
        $this->data[$key] = $value;
    }

    public function erase($key)
    {
        unset($this->data[$key]);
    }
}

/**
 * HAXCMS mock providing a fake shared cache and a small-max rate-limit
 * settings stub so the threshold is reachable in a test.
 */
class FileOpsRateLimitTestHaxcms extends OperationsTestHaxcms
{
    public $cache;
    public $rateLimitEnabled = true;

    public function __construct()
    {
        parent::__construct();
        $this->cache = new FileOpsRateLimitFakeCache();
    }

    public function getFileOpsRateLimitSettings()
    {
        $s = new stdClass();
        $s->enabled = $this->rateLimitEnabled;
        $s->windowMs = 60000;
        $s->max = 3;
        $s->blockMs = 5000;
        return $s;
    }
}
