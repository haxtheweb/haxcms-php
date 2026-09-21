<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Unit tests for lib/stageRemoteFile.php (#3060): the bulk-import staging
 * helpers shared by the site importers and createSite. Mirrors
 * haxcms-nodejs test/unit/stageRemoteFile.test.cjs.
 *
 * ConvertHaxcmsToSiteTest covers the staging root itself (missing HAXCMS,
 * missing configDirectory, directory creation) and the unavailable-root case;
 * these tests cover the download: what is staged, how it is named, and each
 * way it declines. The network is a Guzzle MockHandler, and remote URLs use a
 * public IP literal so the SSRF check needs no DNS.
 */
class StageRemoteFileTest extends TestCase
{
    const REMOTE = 'http://93.184.215.14';

    private $savedHaxcms;
    private $tmpRoot;
    private $stagingRoot;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_srf_' . uniqid();
        $haxcms = new OperationsTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        $GLOBALS['HAXCMS'] = $haxcms;
        $this->stagingRoot = $haxcms->configDirectory . '/tmp/imports';
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        }
        else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpRoot);
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
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            }
            else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // a client that answers from a queue of responses or exceptions
    private function mockClient(array $queue, &$mock)
    {
        $mock = new MockHandler($queue);
        return new Client(array('handler' => HandlerStack::create($mock)));
    }

    private function stagedFiles()
    {
        if (!is_dir($this->stagingRoot)) {
            return array();
        }
        return array_values(array_diff(scandir($this->stagingRoot), array('.', '..')));
    }

    public function testStagesTheResponseBodyUnderTheImportRootWithTheKeysExtension(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), 'PNGBYTES')), $mock);
        $staged = haxcms_import_stage_remote_file($client, self::REMOTE . '/img/chart.png?v=2', 'files/chart.png');
        $this->assertIsString($staged);
        $this->assertSame($this->stagingRoot, dirname($staged));
        $this->assertMatchesRegularExpression('/^haxbi_[0-9a-f]+\.png$/', basename($staged));
        $this->assertSame('PNGBYTES', file_get_contents($staged));
        $this->assertSame(0, $mock->count(), 'the URL was fetched once');
    }

    public function testEachDownloadGetsItsOwnStagedFile(): void
    {
        $client = $this->mockClient(array(
            new Response(200, array(), 'A'),
            new Response(200, array(), 'B'),
        ), $mock);
        $first = haxcms_import_stage_remote_file($client, self::REMOTE . '/a.png', 'a.png');
        $second = haxcms_import_stage_remote_file($client, self::REMOTE . '/b.png', 'b.png');
        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->stagedFiles());
    }

    public function testKeyWithoutAnExtensionIsStagedWithoutOne(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), 'TEXT')), $mock);
        $staged = haxcms_import_stage_remote_file($client, self::REMOTE . '/README', 'files/README');
        $this->assertIsString($staged);
        $this->assertSame('', pathinfo($staged, PATHINFO_EXTENSION));
    }

    public function testDeclinesWithoutARequestWhenTheStagingRootCannotBeCreated(): void
    {
        // a file where the config tree should be makes the directory impossible
        @mkdir($this->tmpRoot, 0777, true);
        file_put_contents($this->tmpRoot . '/blocker', '');
        $GLOBALS['HAXCMS']->configDirectory = $this->tmpRoot . '/blocker/config';
        $client = $this->mockClient(array(new Response(200, array(), 'X')), $mock);
        $this->assertFalse(haxcms_import_stage_remote_file($client, self::REMOTE . '/x.png', 'x.png'));
        $this->assertSame(1, $mock->count(), 'nothing was fetched');
    }

    public function testDeclinesErrorResponsesEmptyBodiesAndNetworkFailures(): void
    {
        $client = $this->mockClient(array(
            new Response(404),
            new Response(500),
            new Response(200, array(), ''),
            new ConnectException('socket hang up', new Request('GET', self::REMOTE . '/x.png')),
        ), $mock);
        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse(haxcms_import_stage_remote_file($client, self::REMOTE . '/x.png', 'x.png'), 'answer ' . $i);
        }
        $this->assertSame(0, $mock->count(), 'each answer was used');
        $this->assertSame(array(), $this->stagedFiles(), 'nothing was staged');
    }

    public function testRefusesPrivateLoopbackAndMetadataAddressesWithoutARequest(): void
    {
        $client = $this->mockClient(array(
            new Response(200, array(), 'secret'),
            new Response(200, array(), 'secret'),
            new Response(200, array(), 'secret'),
        ), $mock);
        $targets = array(
            'http://127.0.0.1:8080/s.png',
            'http://localhost/s.png',
            'http://169.254.169.254/latest/meta-data/s.png',
        );
        foreach ($targets as $target) {
            $this->assertFalse(haxcms_import_stage_remote_file($client, $target, 's.png'), $target);
        }
        $this->assertSame(3, $mock->count(), 'no request was sent to any of them');
        $this->assertSame(array(), $this->stagedFiles());
    }

    public function testRefusesNonHttpSchemes(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), 'secret')), $mock);
        $this->assertFalse(haxcms_import_stage_remote_file($client, 'file:///etc/passwd', 'passwd.txt'));
        $this->assertFalse(haxcms_import_stage_remote_file($client, 'gopher://example.org/x.png', 'x.png'));
        $this->assertSame(1, $mock->count(), 'nothing was fetched');
    }
}
