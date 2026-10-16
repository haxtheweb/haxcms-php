<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

include_once dirname(__DIR__, 2) . '/lib/SsrfGuard.php';

/**
 * Security (SEC-SSRF): SsrfGuard IP range coverage (packed-address CIDR
 * comparison, NAT64 / 6to4 embedded-IPv4 decoding) and the response byte cap
 * (Guzzle on_headers + capped sink; curl MAXFILESIZE + progress abort).
 *
 * The range table is the parity contract with haxcms-nodejs
 * HAXCMSFile.isPrivateOrReservedIP. The curl byte-cap tests talk to a local
 * `php -S` server; SsrfGuard refuses loopback, so the URL uses a public IP
 * literal and CURLOPT_CONNECT_TO (a caller extra option, applied after the
 * pin) steers the TCP connect to 127.0.0.1. They are skipped if the server
 * cannot be started.
 */
class SsrfGuardRangesAndCapTest extends TestCase
{
    const PUBLIC_LITERAL = '93.184.215.14';

    private static $serverProc = null;
    private static $serverPort = 0;
    private static $serverDir = '';

    public static function blockedProvider(): array
    {
        return array(
            // IPv4 additions
            '0.0.0.0/8 low' => array('0.0.0.1'),
            '0.0.0.0/8 high' => array('0.255.255.255'),
            '192.0.0.0/24' => array('192.0.0.8'),
            '192.0.2.0/24 TEST-NET-1' => array('192.0.2.10'),
            '198.18.0.0/15 low' => array('198.18.0.1'),
            '198.18.0.0/15 high' => array('198.19.255.254'),
            '198.51.100.0/24 TEST-NET-2' => array('198.51.100.7'),
            '203.0.113.0/24 TEST-NET-3' => array('203.0.113.200'),
            '224.0.0.0/4 multicast low' => array('224.0.0.1'),
            '224.0.0.0/4 multicast high' => array('239.255.255.255'),
            '240.0.0.0/4 reserved' => array('240.0.0.1'),
            'broadcast' => array('255.255.255.255'),
            // IPv4 pre-existing
            'loopback' => array('127.0.0.1'),
            'loopback 127/8 high' => array('127.255.255.254'),
            'rfc1918 10' => array('10.1.2.3'),
            'rfc1918 172.16' => array('172.16.0.1'),
            'rfc1918 172.31' => array('172.31.255.255'),
            'rfc1918 192.168' => array('192.168.0.1'),
            'cgnat' => array('100.64.0.1'),
            'metadata' => array('169.254.169.254'),
            // IPv6
            'v6 unspecified' => array('::'),
            'v6 loopback' => array('::1'),
            'v6 ULA fc' => array('fc00::1'),
            'v6 ULA fd' => array('fdff:ffff::1'),
            'v6 link-local fe80' => array('fe80::1'),
            'v6 link-local fe9x (was missed by the fe80 string prefix)' => array('fe90::1'),
            'v6 link-local febf' => array('febf:ffff::1'),
            'v6 link-local uppercase' => array('FE80::ABCD'),
            'v6 multicast ff02' => array('ff02::1'),
            'v6 multicast ff0e' => array('ff0e::1234'),
            'v6 discard 100::/64' => array('100::1'),
            'v6 discard 100::/64 high' => array('100::ffff:ffff:ffff:ffff'),
            'v6 local-use NAT64 64:ff9b:1::/48' => array('64:ff9b:1::808:808'),
            // NAT64 well-known prefix with private embedded IPv4
            'NAT64 loopback' => array('64:ff9b::7f00:1'),
            'NAT64 loopback dotted' => array('64:ff9b::127.0.0.1'),
            'NAT64 metadata' => array('64:ff9b::a9fe:a9fe'),
            'NAT64 rfc1918' => array('64:ff9b::10.0.0.1'),
            // 6to4 with private embedded IPv4
            '6to4 loopback' => array('2002:7f00:1::'),
            '6to4 metadata' => array('2002:a9fe:a9fe::1'),
            '6to4 rfc1918' => array('2002:c0a8:0101::'),
            // mapped / compat (HAX-SEC-007 regression guard)
            'mapped hex loopback' => array('::ffff:7f00:1'),
            'mapped dotted metadata' => array('::ffff:169.254.169.254'),
            'compat hex loopback' => array('::7f00:1'),
            'mapped TEST-NET' => array('::ffff:203.0.113.1'),
            // fail closed on anything that is not an IP literal
            'hostname' => array('example.com'),
            'zone id' => array('fe80::1%eth0'),
            'octal-ish v4' => array('0127.0.0.1'),
            'empty' => array(''),
        );
    }

    #[DataProvider('blockedProvider')]
    public function testBlocked(string $ip): void
    {
        $this->assertTrue(SsrfGuard::isPrivateOrReservedIP($ip), $ip . ' must be blocked');
    }

    public static function allowedProvider(): array
    {
        return array(
            'google dns' => array('8.8.8.8'),
            'cloudflare' => array('1.1.1.1'),
            'just above 0/8' => array('1.0.0.1'),
            'below 198.18/15' => array('198.17.255.255'),
            'above 198.18/15' => array('198.20.0.1'),
            'below multicast' => array('223.255.255.255'),
            '192.0.1.x (between 192.0.0/24 and 192.0.2/24)' => array('192.0.1.1'),
            '172.15' => array('172.15.255.255'),
            '172.32' => array('172.32.0.1'),
            '100.63' => array('100.63.255.255'),
            '100.128' => array('100.128.0.1'),
            'v6 public' => array('2606:4700:4700::1111'),
            'v6 google' => array('2001:4860:4860::8888'),
            'v6 doc w/ dotted tail (not an embedded-v4 prefix)' => array('2001:db8::1.2.3.4'),
            'v6 fec0 (outside fe80::/10)' => array('fec0::1'),
            'v6 just outside 100::/64' => array('100:0:0:1::1'),
            'NAT64 public' => array('64:ff9b::808:808'),
            'NAT64 public dotted' => array('64:ff9b::1.1.1.1'),
            '6to4 public' => array('2002:808:808::1'),
            'mapped public' => array('::ffff:8.8.8.8'),
            'compat public' => array('::8.8.8.8'),
        );
    }

    #[DataProvider('allowedProvider')]
    public function testAllowed(string $ip): void
    {
        $this->assertFalse(SsrfGuard::isPrivateOrReservedIP($ip), $ip . ' must be allowed');
    }

    public function testDecodeEmbeddedIPv4(): void
    {
        $this->assertSame('127.0.0.1', SsrfGuard::decodeEmbeddedIPv4('64:ff9b::7f00:1'));
        $this->assertSame('8.8.8.8', SsrfGuard::decodeEmbeddedIPv4('64:ff9b::808:808'));
        $this->assertSame('169.254.169.254', SsrfGuard::decodeEmbeddedIPv4('2002:a9fe:a9fe::'));
        $this->assertSame('192.168.1.1', SsrfGuard::decodeEmbeddedIPv4('2002:c0a8:101:ffff::1'));
        $this->assertSame('127.0.0.1', SsrfGuard::decodeEmbeddedIPv4('::ffff:7f00:1'));
        $this->assertNull(SsrfGuard::decodeEmbeddedIPv4('2606:4700:4700::1111'));
        $this->assertNull(SsrfGuard::decodeEmbeddedIPv4('8.8.8.8'));
        $this->assertNull(SsrfGuard::decodeEmbeddedIPv4('nonsense'));
    }

    public function testPackedCidrComparisonIsBitExact(): void
    {
        // /15 boundary: 198.18.0.0 - 198.19.255.255
        $this->assertTrue(SsrfGuard::packedIpInCidr(inet_pton('198.19.0.0'), '198.18.0.0/15'));
        $this->assertFalse(SsrfGuard::packedIpInCidr(inet_pton('198.20.0.0'), '198.18.0.0/15'));
        // /10 boundary inside a byte: fe80::/10 covers fe80-febf
        $this->assertTrue(SsrfGuard::packedIpInCidr(inet_pton('febf::'), 'fe80::/10'));
        $this->assertFalse(SsrfGuard::packedIpInCidr(inet_pton('fec0::'), 'fe80::/10'));
        // family mismatch never matches
        $this->assertFalse(SsrfGuard::packedIpInCidr(inet_pton('127.0.0.1'), '::/96'));
    }

    public function testUrlsToNewRangesAreRejectedBeforeAnyRequest(): void
    {
        $urls = array(
            'http://0.0.0.0/',
            'http://255.255.255.255/',
            'http://198.18.0.1/',
            'http://[64:ff9b::7f00:1]/',
            'http://[2002:a9fe:a9fe::]/',
            'http://[fe90::1]/',
            'http://[ff02::1]/',
            'http://[100::1]/',
        );
        foreach ($urls as $url) {
            try {
                SsrfGuard::validateUrlNotSSRF($url);
                $this->fail('expected SSRF_PRIVATE for ' . $url);
            } catch (SsrfGuardException $e) {
                $this->assertSame('SSRF_PRIVATE', $e->ssrfCode, $url);
            }
        }
    }

    // ------------------------------------------------------------------
    // byte cap — Guzzle wrapper (MockHandler)
    // ------------------------------------------------------------------

    private function mockClient(array $queue)
    {
        return new Client(array('handler' => HandlerStack::create(new MockHandler($queue))));
    }

    public function testDefaultCapIsTwentyMiB(): void
    {
        $this->assertSame(20 * 1024 * 1024, SsrfGuard::DEFAULT_MAX_RESPONSE_BYTES);
        $this->assertSame(20 * 1024 * 1024, SsrfGuard::resolveMaxBytes(null));
        $this->assertSame(20 * 1024 * 1024, SsrfGuard::resolveMaxBytes(0));
        $this->assertSame(20 * 1024 * 1024, SsrfGuard::resolveMaxBytes(-5));
        $this->assertSame(1234, SsrfGuard::resolveMaxBytes(1234));
        $this->assertSame(1234, SsrfGuard::resolveMaxBytes('1234'));
    }

    public function testGuzzleRejectsAnnouncedContentLengthOverCap(): void
    {
        $client = $this->mockClient(array(new Response(200, array('Content-Length' => '5000'), str_repeat('a', 5000))));
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', 'http://' . self::PUBLIC_LITERAL . '/big', array('max_bytes' => 1000));
            $this->fail('expected SSRF_TOO_LARGE');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_TOO_LARGE', $e->ssrfCode);
        }
    }

    public function testGuzzleRejectsBodyOverCapWithoutContentLength(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), str_repeat('b', 5000))));
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', 'http://' . self::PUBLIC_LITERAL . '/chunked', array('max_bytes' => 1000));
            $this->fail('expected SSRF_TOO_LARGE');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_TOO_LARGE', $e->ssrfCode);
        }
    }

    public function testGuzzleAllowsBodyAtTheCap(): void
    {
        $client = $this->mockClient(array(new Response(200, array('Content-Length' => '1000'), str_repeat('c', 1000))));
        $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', 'http://' . self::PUBLIC_LITERAL . '/ok', array('max_bytes' => 1000));
        $this->assertSame(str_repeat('c', 1000), (string) $resp->getBody());
    }

    public function testGuzzleCallerOnHeadersStillRuns(): void
    {
        $seen = 0;
        $client = $this->mockClient(array(new Response(200, array(), 'x')));
        SsrfGuard::safeGuzzleRequest($client, 'GET', 'http://' . self::PUBLIC_LITERAL . '/', array(
            'on_headers' => function () use (&$seen) { $seen++; },
        ));
        $this->assertSame(1, $seen);
    }

    public function testCappedStreamRefusesWritesPastTheLimit(): void
    {
        $this->assertTrue(class_exists('SsrfGuardCappedStream', false));
        $stream = new SsrfGuardCappedStream(\GuzzleHttp\Psr7\Utils::streamFor(''), 10);
        $this->assertSame(6, $stream->write('abcdef'));
        $this->assertFalse($stream->exceeded);
        $this->assertSame(0, $stream->write('ghijk'));
        $this->assertTrue($stream->exceeded);
        $this->assertSame(0, $stream->write('z'), 'stays closed once exceeded');
        $this->assertSame('abcdef', (string) $stream);
    }

    // ------------------------------------------------------------------
    // byte cap — real transfers against a local php -S server
    // ------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !defined('CURLOPT_CONNECT_TO')) {
            return;
        }
        self::$serverDir = sys_get_temp_dir() . '/haxcms_ssrfcap_' . uniqid();
        @mkdir(self::$serverDir, 0700, true);
        file_put_contents(self::$serverDir . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/small') { header('Content-Type: text/plain'); echo str_repeat('s', 100); return true; }
if ($path === '/big-cl') { header('Content-Type: text/plain'); header('Content-Length: 50000'); echo str_repeat('L', 50000); return true; }
if ($path === '/big-stream') {
    header('Content-Type: text/plain');
    // no Content-Length: flushed in pieces so the body arrives streamed
    for ($i = 0; $i < 50; $i++) { echo str_repeat('S', 1000); flush(); }
    return true;
}
http_response_code(404);
return true;
PHP
        );
        $sock = @stream_socket_server('tcp://127.0.0.1:0');
        if (!$sock) {
            return;
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $cmd = array(PHP_BINARY, '-S', '127.0.0.1:' . $port, self::$serverDir . '/router.php');
        $proc = @proc_open($cmd, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
        if (!is_resource($proc)) {
            return;
        }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) {
                fclose($c);
                self::$serverProc = $proc;
                self::$serverPort = $port;
                return;
            }
            usleep(100000);
        }
        proc_terminate($proc);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
        if (self::$serverDir !== '' && is_dir(self::$serverDir)) {
            @unlink(self::$serverDir . '/router.php');
            @rmdir(self::$serverDir);
        }
    }

    private function requireServer()
    {
        if (self::$serverProc === null) {
            $this->markTestSkipped('local php -S server unavailable');
        }
    }

    private function serverUrl($path)
    {
        return 'http://' . self::PUBLIC_LITERAL . ':' . self::$serverPort . $path;
    }

    private function connectTo()
    {
        return array(self::PUBLIC_LITERAL . ':' . self::$serverPort . ':127.0.0.1:' . self::$serverPort);
    }

    public function testCurlSmallBodyUnderCapIsReturned(): void
    {
        $this->requireServer();
        $body = SsrfGuard::safeCurlExec($this->serverUrl('/small'), array(CURLOPT_CONNECT_TO => $this->connectTo()), 1000);
        $this->assertSame(str_repeat('s', 100), $body);
    }

    public function testCurlContentLengthOverCapIsRejected(): void
    {
        $this->requireServer();
        try {
            SsrfGuard::safeCurlExec($this->serverUrl('/big-cl'), array(CURLOPT_CONNECT_TO => $this->connectTo()), 1000);
            $this->fail('expected SSRF_TOO_LARGE');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_TOO_LARGE', $e->ssrfCode);
        }
    }

    public function testCurlStreamedBodyOverCapIsAborted(): void
    {
        $this->requireServer();
        try {
            SsrfGuard::safeCurlExec($this->serverUrl('/big-stream'), array(CURLOPT_CONNECT_TO => $this->connectTo()), 1000);
            $this->fail('expected SSRF_TOO_LARGE');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_TOO_LARGE', $e->ssrfCode);
        }
    }

    public function testGuzzleCurlHandlerStreamedBodyOverCapIsAborted(): void
    {
        $this->requireServer();
        $client = new Client(array('handler' => HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler())));
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', $this->serverUrl('/big-stream'), array(
                'max_bytes' => 1000,
                'curl' => array(CURLOPT_CONNECT_TO => $this->connectTo()),
            ));
            $this->fail('expected SSRF_TOO_LARGE');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_TOO_LARGE', $e->ssrfCode);
        }
    }

    public function testGuzzleCurlHandlerContentLengthOverCapIsRejected(): void
    {
        $this->requireServer();
        $client = new Client(array('handler' => HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler())));
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', $this->serverUrl('/big-cl'), array(
                'max_bytes' => 1000,
                'curl' => array(CURLOPT_CONNECT_TO => $this->connectTo()),
            ));
            $this->fail('expected SSRF_TOO_LARGE');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_TOO_LARGE', $e->ssrfCode);
        }
    }

    public function testGuzzleCurlHandlerSmallBodyUnderCap(): void
    {
        $this->requireServer();
        $client = new Client(array('handler' => HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler())));
        $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', $this->serverUrl('/small'), array(
            'max_bytes' => 1000,
            'curl' => array(CURLOPT_CONNECT_TO => $this->connectTo()),
        ));
        $this->assertSame(str_repeat('s', 100), (string) $resp->getBody());
    }
}
