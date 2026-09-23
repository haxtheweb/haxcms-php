<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for SsrfGuard's redirect-walk + IP-pinning behavior (Workstream
 * A, parity with haxcms-nodejs src/lib/safeFetch.js). The network layer is a
 * Guzzle MockHandler so no real HTTP is made; remote URLs use a public IP
 * literal so resolveAndValidate needs no DNS. Mirrors the contract pinned by
 * haxcms-nodejs test/unit/safe-fetch.test.cjs: redirect following, hop cap
 * (SSRF_REDIRECTS), private/metadata redirect rejection (SSRF_PRIVATE), 304
 * pass-through, 3xx-no-Location pass-through, and per-hop CURLOPT_RESOLVE
 * pinning.
 */
class SsrfGuardRedirectTest extends TestCase
{
    // Public IP literal (example.com's well-known doc IP) so SSRF validation
    // passes without DNS. Same shape as StageRemoteFileTest::REMOTE.
    const REMOTE = 'http://93.184.215.14';

    private function mockClient(array $queue, &$mock)
    {
        $mock = new MockHandler($queue);
        return new Client(['handler' => HandlerStack::create($mock)]);
    }

    // ----------------------------------------------------------------
    // buildPinnedResolveEntries (pure unit, no network, no Guzzle)
    // ----------------------------------------------------------------

    public function testBuildPinnedResolveEntriesIpv4Literal(): void
    {
        $parsed = parse_url('http://93.184.215.14/path');
        $this->assertSame(
            array('93.184.215.14:80:93.184.215.14'),
            SsrfGuard::buildPinnedResolveEntries($parsed, '93.184.215.14')
        );
    }

    public function testBuildPinnedResolveEntriesHttpsDefaultPort(): void
    {
        $parsed = parse_url('https://example.com/x');
        $this->assertSame(
            array('example.com:443:1.2.3.4'),
            SsrfGuard::buildPinnedResolveEntries($parsed, '1.2.3.4')
        );
    }

    public function testBuildPinnedResolveEntriesExplicitPort(): void
    {
        $parsed = parse_url('http://example.com:8080/x');
        $this->assertSame(
            array('example.com:8080:1.2.3.4'),
            SsrfGuard::buildPinnedResolveEntries($parsed, '1.2.3.4')
        );
    }

    public function testBuildPinnedResolveEntriesIpv6BracketsAddress(): void
    {
        // parse_url returns host WITH brackets for IPv6 literals; the HOST
        // field of CURLOPT_RESOLVE must be bare, the ADDRESS field bracketed
        // per libcurl docs.
        $parsed = parse_url('http://[2001:4860:4860::8888]/');
        $this->assertSame(
            array('2001:4860:4860::8888:80:[2001:4860:4860::8888]'),
            SsrfGuard::buildPinnedResolveEntries($parsed, '2001:4860:4860::8888')
        );
    }

    public function testBuildPinnedResolveEntriesEmptyInputsReturnEmpty(): void
    {
        $this->assertSame(array(), SsrfGuard::buildPinnedResolveEntries(array(), '1.2.3.4'));
        $this->assertSame(array(), SsrfGuard::buildPinnedResolveEntries(parse_url('http://x/'), ''));
    }

    // ----------------------------------------------------------------
    // resolveRedirectUrl (pure unit)
    // ----------------------------------------------------------------

    public function testResolveRedirectUrlAbsolutePathSameHost(): void
    {
        $this->assertSame(
            'http://93.184.215.14/c/d',
            SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a/b', '/c/d')
        );
    }

    public function testResolveRedirectUrlAbsoluteNewHost(): void
    {
        $this->assertSame(
            'https://other.example.org/x',
            SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a', 'https://other.example.org/x')
        );
    }

    public function testResolveRedirectUrlRelative(): void
    {
        $this->assertSame(
            'http://93.184.215.14/a/c',
            SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a/b', 'c')
        );
    }

    public function testResolveRedirectUrlProtocolRelative(): void
    {
        $this->assertSame(
            'http://cdn.example.org/img.png',
            SsrfGuard::resolveRedirectUrl('http://93.184.215.14/a', '//cdn.example.org/img.png')
        );
    }

    public function testResolveRedirectUrlEmptyReturnsFalse(): void
    {
        $this->assertFalse(SsrfGuard::resolveRedirectUrl('', '/x'));
        $this->assertFalse(SsrfGuard::resolveRedirectUrl('http://x/', ''));
    }

    // ----------------------------------------------------------------
    // safeGuzzleRequest redirect walk (MockHandler)
    // ----------------------------------------------------------------

    public function testSafeGuzzleRequestFollowsRedirectToPublicTarget(): void
    {
        $client = $this->mockClient(array(
            new Response(302, array('Location' => 'http://93.184.215.14/final'), ''),
            new Response(200, array(), 'FINALBODY'),
        ), $mock);
        $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('FINALBODY', (string) $resp->getBody());
        $this->assertSame(0, $mock->count(), 'both responses consumed');
    }

    public function testSafeGuzzleRequestRejectsRedirectToPrivateTarget(): void
    {
        // Second response must never be reached: the private redirect target
        // is rejected by resolveAndValidate before a second fetch happens.
        $client = $this->mockClient(array(
            new Response(302, array('Location' => 'http://127.0.0.1/secret'), ''),
            new Response(200, array(), 'SHOULD_NOT_REACH'),
        ), $mock);
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
            $this->fail('Expected SsrfGuardException was not thrown');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_PRIVATE', $e->ssrfCode);
        }
        $this->assertSame(1, $mock->count(), 'only the first (public) hop was fetched; the private redirect was rejected before a second fetch');
    }

    public function testSafeGuzzleRequestRejectsRedirectToMetadataTarget(): void
    {
        $client = $this->mockClient(array(
            new Response(302, array('Location' => 'http://169.254.169.254/latest/meta-data/'), ''),
            new Response(200, array(), 'SHOULD_NOT_REACH'),
        ), $mock);
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
            $this->fail('Expected SsrfGuardException was not thrown');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_PRIVATE', $e->ssrfCode);
        }
        $this->assertSame(1, $mock->count(), 'the metadata redirect was rejected before a second fetch');
    }

    public function testSafeGuzzleRequestRejectsHopCapExceeded(): void
    {
        // 6 redirect responses (hops 0..5); on hop 5 a redirect triggers
        // SSRF_REDIRECTS, matching SAFE_FETCH_MAX_REDIRECTS=5 in safeFetch.js.
        $queue = array();
        for ($i = 0; $i < 6; $i++) {
            $queue[] = new Response(302, array('Location' => self::REMOTE . '/hop' . ($i + 1)), '');
        }
        $client = $this->mockClient($queue, $mock);
        try {
            SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
            $this->fail('Expected SsrfGuardException was not thrown');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_REDIRECTS', $e->ssrfCode);
        }
        $this->assertSame(0, $mock->count(), 'all 6 redirect responses consumed');
    }

    public function testSafeGuzzleRequestReturns304AsFinal(): void
    {
        $client = $this->mockClient(array(new Response(304, array(), '')), $mock);
        $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
        $this->assertSame(304, $resp->getStatusCode());
        $this->assertSame(0, $mock->count());
    }

    public function testSafeGuzzleRequestReturns3xxWithoutLocationAsFinal(): void
    {
        $client = $this->mockClient(array(new Response(300, array(), 'NOTMOD')), $mock);
        $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
        $this->assertSame(300, $resp->getStatusCode());
        $this->assertSame('NOTMOD', (string) $resp->getBody());
        $this->assertSame(0, $mock->count());
    }

    public function testSafeGuzzleRequestReturns302WithEmptyLocationAsFinal(): void
    {
        $client = $this->mockClient(array(new Response(302, array('Location' => ''), 'EMPTYLOC')), $mock);
        $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('EMPTYLOC', (string) $resp->getBody());
        $this->assertSame(0, $mock->count());
    }

    // ----------------------------------------------------------------
    // IP pinning: assert the CURLOPT_RESOLVE entry reaches Guzzle's
    // handler options and redirects are forced off.
    // ----------------------------------------------------------------

    public function testSafeGuzzleRequestPinsConnectionToValidatedIp(): void
    {
        if (!defined('CURLOPT_RESOLVE')) {
            $this->markTestSkipped('curl extension with CURLOPT_RESOLVE required');
        }
        $container = array();
        $history = Middleware::history($container);
        $mock = new MockHandler(array(new Response(200, array(), 'OK')));
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = new Client(array('handler' => $stack));

        SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
        $this->assertCount(1, $container);
        $opts = $container[0]['options'];
        $this->assertArrayHasKey('curl', $opts);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $opts['curl']);
        $this->assertContains('93.184.215.14:80:93.184.215.14', $opts['curl'][CURLOPT_RESOLVE]);
        // Redirects are forced off so the manual walk is the only redirect path.
        $this->assertFalse($opts['allow_redirects']);
    }

    public function testSafeGuzzleRequestRePinsEachHop(): void
    {
        if (!defined('CURLOPT_RESOLVE')) {
            $this->markTestSkipped('curl extension with CURLOPT_RESOLVE required');
        }
        // Two hops: first to a different public literal IP, then final. Each
        // hop's recorded options must carry a CURLOPT_RESOLVE pin for that
        // hop's own host:port, proving the pin is re-applied per hop.
        $container = array();
        $history = Middleware::history($container);
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://1.2.3.4/final'), ''),
            new Response(200, array(), 'OK'),
        ));
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = new Client(array('handler' => $stack));

        SsrfGuard::safeGuzzleRequest($client, 'GET', self::REMOTE . '/start');
        $this->assertCount(2, $container);
        $this->assertContains(
            '93.184.215.14:80:93.184.215.14',
            $container[0]['options']['curl'][CURLOPT_RESOLVE]
        );
        $this->assertContains(
            '1.2.3.4:80:1.2.3.4',
            $container[1]['options']['curl'][CURLOPT_RESOLVE]
        );
    }
}
