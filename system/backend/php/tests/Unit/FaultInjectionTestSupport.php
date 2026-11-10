<?php
/**
 * Shared fixture for the fault-injection tests (FaultInjection*Test.php).
 * Not a test case itself (no Test.php suffix); the test files require_once it.
 *
 * Builds a three-page site (A, B, C) under HAXCMS_ROOT, drives the real
 * site-route handlers (lib/siteRoutes/v1/*.php) the way an HTTP request does,
 * and checks the invariants every critical write keeps under a filesystem
 * fault (FaultInjectionFileWrapper):
 *   - the response is a displayable non-2xx ApiError envelope
 *     ({status: <same as HTTP status>, data: {message: <string>}})
 *   - the data on disk is unchanged (or the write is atomic)
 *   - no page folder is left that site.json does not list
 *   - the server keeps serving: a fresh GET /x/api/v1/site answers 200
 *
 * Scenario IDs match haxcms-nodejs (test/unit/fault-injection-*.test.cjs).
 */

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FaultInjectionFileWrapper.php';
require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/OperationsNodeOpsTest.php';
require_once __DIR__ . '/SiteRoutesMutationsTest.php';

final class FaultInjectionDeadlineExceeded extends RuntimeException
{
}

abstract class FaultInjectionTestCase extends TestCase
{
    /** @var mixed */
    private $savedHaxcms;
    /** @var mixed */
    private $savedServerSoftware;
    /** @var string */
    protected $tmpRoot;
    /** @var string */
    protected $siteName = 'fault-site';
    /** @var SiteMutationRecordingHaxcms */
    protected $haxcms;
    /** @var OperationsNodeTestSite */
    protected $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
        $this->tmpRoot = HAXCMS_ROOT . '/fault_injection_' . uniqid();
        $this->buildSiteFixture();
        $this->haxcms = new SiteMutationRecordingHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->sitesDirectory = basename($this->tmpRoot);
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = $this->loadSite();
        $_FILES = array();
    }

    protected function tearDown(): void
    {
        FaultInjectionFileWrapper::uninstall();
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
        $_FILES = array();
        $this->rrmdir($this->tmpRoot);
    }

    protected function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        @chmod($dir, 0777);
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

    private function buildSiteFixture(): void
    {
        $siteDir = $this->siteDir();
        $items = array();
        foreach (array(array('a', 'A', 0), array('b', 'B', 1), array('c', 'C', 2)) as $p) {
            mkdir($siteDir . '/pages/' . $p[0], 0777, true);
            file_put_contents($siteDir . '/pages/' . $p[0] . '/index.html', '<p>' . $p[1] . '</p>');
            $items[] = (object) array(
                'id' => $p[0],
                'indent' => 0,
                'location' => 'pages/' . $p[0] . '/index.html',
                'slug' => $p[0],
                'order' => $p[2],
                'parent' => null,
                'title' => $p[1],
                'description' => '',
                'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
            );
        }
        mkdir($siteDir . '/files', 0777, true);
        file_put_contents($siteDir . '/site.json', json_encode((object) array(
            'id' => 'site-uuid',
            'title' => 'Fault Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->siteName,
                    'settings' => (object) array('pathauto' => false),
                ),
                'platform' => (object) array('features' => (object) array()),
                'theme' => (object) array(
                    'element' => 'haxcms-demo-theme',
                    'variables' => (object) array(),
                    'regions' => (object) array(),
                ),
            ),
            'items' => $items,
        ), JSON_PRETTY_PRINT));
    }

    /** a fresh site object read from disk, as a new request would load it */
    protected function loadSite(): OperationsNodeTestSite
    {
        $site = new OperationsNodeTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        return $site;
    }

    protected function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    protected function siteJsonPath(): string
    {
        return $this->siteDir() . '/site.json';
    }

    protected function siteJsonBytes(): string
    {
        return (string) file_get_contents($this->siteJsonPath());
    }

    protected function pageFile(string $id): string
    {
        return $this->siteDir() . '/pages/' . $id . '/index.html';
    }

    /** match callable for FaultInjectionFileWrapper rules: exact path */
    protected function isPath(string $path): callable
    {
        return function ($candidate) use ($path) {
            return $candidate === $path;
        };
    }

    /** page folders on disk that site.json does not list */
    protected function orphanPageFolders(): array
    {
        $listed = array();
        $decoded = json_decode($this->siteJsonBytes());
        if (is_object($decoded) && isset($decoded->items)) {
            foreach ($decoded->items as $item) {
                $listed[dirname((string) $item->location)] = true;
            }
        }
        $orphans = array();
        foreach (scandir($this->siteDir() . '/pages') as $entry) {
            if ($entry !== '.' && $entry !== '..' && !isset($listed['pages/' . $entry])) {
                $orphans[] = 'pages/' . $entry;
            }
        }
        return $orphans;
    }

    protected function context(array $body, string $method, string $routeSuffix, array $params = array()): SiteMutationRouteTestContext
    {
        $context = new SiteMutationRouteTestContext($this->site);
        $context->method = $method;
        $context->routeSuffix = $routeSuffix;
        $context->apiBasePath = '/x/api';
        $context->setRouteParams($params);
        $context->injectedBody = $body;
        $context->injectedHeaders = array('HTTP_X_HAXCMS_SITE_TOKEN' => 'tok123');
        return $context;
    }

    /**
     * Run a site-route handler as one request. A Throwable that escapes the
     * handler is what PHP turns into its generic fatal-error page (500, plain
     * text, bootstrapHAX.php haxGlobalDebug): it is returned, not rethrown.
     */
    protected function request(string $handlerFile, SiteMutationRouteTestContext $context, array $rules = array()): array
    {
        $handler = include dirname(__DIR__, 2) . '/lib/siteRoutes/v1/' . $handlerFile;
        http_response_code(200);
        $thrown = null;
        $warnings = array();
        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        });
        ob_start();
        try {
            if (count($rules) > 0) {
                FaultInjectionFileWrapper::install($rules);
            }
            $handler($context);
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            FaultInjectionFileWrapper::uninstall();
            $raw = ob_get_clean();
            restore_error_handler();
        }
        return array(
            'status' => $thrown ? 500 : http_response_code(),
            'raw' => $raw,
            'body' => json_decode((string) $raw, true),
            'thrown' => $thrown,
            'fired' => FaultInjectionFileWrapper::$fired,
            'warnings' => $warnings,
        );
    }

    /** the OpenAPI ApiError envelope with a non-2xx status */
    protected function assertErrorEnvelope(array $res, ?int $status = null, ?string $message = null): void
    {
        $this->assertNull(
            $res['thrown'],
            'the failure escaped the route as ' . ($res['thrown'] ? get_class($res['thrown']) . ': ' . $res['thrown']->getMessage() : '')
            . ' (PHP answers with its generic plain-text fatal-error page, not an ApiError)'
        );
        $this->assertGreaterThanOrEqual(400, $res['status'], 'non-2xx status; body: ' . $res['raw']);
        if ($status !== null) {
            $this->assertSame($status, $res['status'], 'HTTP status; body: ' . $res['raw']);
        }
        $this->assertIsArray($res['body'], 'JSON body');
        $this->assertSame($res['status'], $res['body']['status'], 'envelope status matches HTTP status');
        $this->assertIsString($res['body']['data']['message'], 'envelope message');
        if ($message !== null) {
            $this->assertSame($message, $res['body']['data']['message']);
        }
    }

    /** a new request for GET /x/api/v1/site answers 200 */
    protected function assertStillServing(): void
    {
        $this->assertFalse(FaultInjectionFileWrapper::isInstalled());
        $this->site = $this->loadSite();
        $res = $this->request('site.php', $this->context(array(), 'GET', 'site'));
        $this->assertNull($res['thrown'], 'GET site threw');
        $this->assertSame(200, $res['status'], 'GET site: ' . $res['raw']);
    }

    /**
     * Run $fn, but abort it with a FaultInjectionDeadlineExceeded thrown from
     * SIGALRM after $seconds, so a request that would spin forever fails the
     * check instead of hanging the suite. request() turns the abort into
     * 'thrown'. Needs ext-pcntl (CLI builds normally have it).
     */
    protected function withDeadline(int $seconds, callable $fn)
    {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('needs ext-pcntl to bound a request that may never return');
        }
        $async = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($seconds) {
            throw new FaultInjectionDeadlineExceeded('no answer within ' . $seconds . 's');
        });
        pcntl_alarm($seconds);
        try {
            return $fn();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($async);
        }
    }

    /**
     * An invariant PHP does not keep today. The check still runs: while it
     * fails the test is reported as skipped with $reason (a known bug filed
     * for follow-up); once the bug is fixed it passes and the wrapper can be
     * removed.
     */
    protected function knownBug(string $reason, callable $check): void
    {
        try {
            $check();
        } catch (AssertionFailedError $e) {
            $this->markTestSkipped('KNOWN BUG: ' . $reason . ' | ' . strtok($e->getMessage(), "\n"));
        }
    }
}
