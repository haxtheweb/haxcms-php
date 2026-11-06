<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/LinkChecker.php';

/**
 * Unit tests for the outside link checker behind POST
 * x/api/v1/site/check-links (lib/LinkChecker.php, haxtheweb/issues#3167).
 * Mirrors haxcms-nodejs test/unit/link-checker.test.cjs: only links found in
 * the site's own pages are fetched (and the fetched URL comes from the site,
 * not the request), HEAD falls back to GET, statuses and errors map to the
 * documented states, answers are cached, the per user/site budget blocks
 * once spent without counting cached answers, and links past the time budget
 * come back deferred. Remote URLs use a public IP literal so the SSRF guard
 * needs no DNS; the per-hop network call is a fake hop fetcher.
 */
class LinkCheckerTest extends TestCase
{
    const HOST = 'http://93.184.215.14';

    private $calls = array();

    protected function setUp(): void
    {
        LinkChecker::resetForTesting();
        $this->calls = array();
    }

    private function siteHtml()
    {
        return '<p><a href="' . self::HOST . '/ok">ok</a>'
            . ' <a href=" ' . self::HOST . '/gone ">gone</a>'
            . ' <a href="' . self::HOST . '/moved#part">moved</a>'
            . ' <a href="/internal">internal</a>'
            . ' <a href="mailto:a@example.org">mail</a></p>';
    }

    private function settings($max, $windowMs = 60000)
    {
        $s = new stdClass();
        $s->enabled = true;
        $s->windowMs = $windowMs;
        $s->max = $max;
        return $s;
    }

    /**
     * Fake hop fetcher: $table maps path => status, or path => callable($method)
     * returning array(status, location).
     */
    private function hopFetcher(array $table)
    {
        $calls = &$this->calls;
        return function ($url, $parsed, $pinnedIp, $method) use ($table, &$calls) {
            $calls[] = $method . ' ' . $url;
            $path = isset($parsed['path']) ? $parsed['path'] : '/';
            if (!isset($table[$path])) {
                return array('status' => 0, 'location' => '', 'errno' => 7);
            }
            $entry = $table[$path];
            if ($entry instanceof Closure) {
                return call_user_func($entry, $method);
            }
            if (is_array($entry)) {
                return $entry;
            }
            return array('status' => $entry, 'location' => '', 'errno' => 0);
        };
    }

    private function check(array $o)
    {
        return LinkChecker::checkSiteLinks(array_merge(array(
            'siteHtml' => $this->siteHtml(),
            'siteKey' => 'demo',
            'rateKey' => 'alice::demo',
            'rateSettings' => $this->settings(100),
            'cache' => null,
        ), $o));
    }

    public function testToCheckUrl(): void
    {
        $this->assertSame('https://example.org/a', LinkChecker::toCheckUrl('https://example.org/a#b'));
        $this->assertSame('https://example.org/x', LinkChecker::toCheckUrl('//example.org/x'));
        $this->assertSame('http://example.org', LinkChecker::toCheckUrl('  http://example.org  '));
        $this->assertSame('', LinkChecker::toCheckUrl('/internal'));
        $this->assertSame('', LinkChecker::toCheckUrl('mailto:a@example.org'));
        $this->assertSame('', LinkChecker::toCheckUrl('javascript:alert(1)'));
        $this->assertSame('', LinkChecker::toCheckUrl('https://' . str_repeat('a', 2100)));
        $this->assertSame('', LinkChecker::toCheckUrl(null));
    }

    public function testExtractOutsideLinks(): void
    {
        $found = LinkChecker::extractOutsideLinks($this->siteHtml());
        $keys = array_keys($found);
        sort($keys);
        $this->assertSame(array(self::HOST . '/gone', self::HOST . '/moved#part', self::HOST . '/ok'), $keys);
        $this->assertSame(self::HOST . '/moved', $found[self::HOST . '/moved#part']);
    }

    public function testClassifyStatusAndErrors(): void
    {
        $this->assertSame('ok', LinkChecker::classifyStatus(200, false)['state']);
        $this->assertSame('redirected', LinkChecker::classifyStatus(204, true)['state']);
        $this->assertSame('check', LinkChecker::classifyStatus(403, false)['state']);
        $this->assertSame('check', LinkChecker::classifyStatus(999, false)['state']);
        $this->assertSame(array('state' => 'broken', 'reason' => 'http'), LinkChecker::classifyStatus(404, false));
        $this->assertSame(array('state' => 'skipped', 'reason' => 'private'), LinkChecker::errorReason('SSRF_PRIVATE'));
        $this->assertSame(array('state' => 'broken', 'reason' => 'dns'), LinkChecker::errorReason('SSRF_DNS'));
        $this->assertSame(array('state' => 'check', 'reason' => 'timeout'), LinkChecker::errorReason('CURL_28'));
        $this->assertSame(array('state' => 'broken', 'reason' => 'tls'), LinkChecker::errorReason('CURL_60'));
        $this->assertSame(array('state' => 'broken', 'reason' => 'connection'), LinkChecker::errorReason('CURL_7'));
    }

    public function testHeadRefusedFallsBackToGetAnd404IsNotRetried(): void
    {
        $fetch = $this->hopFetcher(array(
            '/ok' => function ($method) {
                return array('status' => $method === 'HEAD' ? 405 : 200, 'location' => '', 'errno' => 0);
            },
            '/gone' => 404,
        ));
        $ok = LinkChecker::checkUrl(self::HOST . '/ok', $fetch);
        $this->assertSame('ok', $ok['state']);
        $this->assertSame(200, $ok['status']);
        $gone = LinkChecker::checkUrl(self::HOST . '/gone', $fetch);
        $this->assertSame('broken', $gone['state']);
        $this->assertSame(array(
            'HEAD ' . self::HOST . '/ok',
            'GET ' . self::HOST . '/ok',
            'HEAD ' . self::HOST . '/gone',
        ), $this->calls);
    }

    public function testRedirectReportsFinalUrl(): void
    {
        $fetch = $this->hopFetcher(array(
            '/moved' => array('status' => 301, 'location' => '/new-home', 'errno' => 0),
            '/new-home' => 200,
        ));
        $r = LinkChecker::checkUrl(self::HOST . '/moved', $fetch);
        $this->assertSame('redirected', $r['state']);
        $this->assertSame(self::HOST . '/new-home', $r['finalUrl']);
    }

    public function testRedirectToPrivateAddressIsSkippedNotFetched(): void
    {
        $fetch = $this->hopFetcher(array(
            '/moved' => array('status' => 302, 'location' => 'http://169.254.169.254/latest/meta-data', 'errno' => 0),
        ));
        $r = LinkChecker::checkUrl(self::HOST . '/moved', $fetch);
        $this->assertSame('skipped', $r['state']);
        $this->assertSame('private', $r['reason']);
        $this->assertCount(1, $this->calls);
    }

    public function testLinksNotInSiteAreSkippedAndNeverFetched(): void
    {
        $out = $this->check(array(
            'links' => array('https://evil.example/', 'http://169.254.169.254/latest/meta-data', self::HOST . '/ok'),
            'hopFetcher' => $this->hopFetcher(array('/ok' => 200)),
        ));
        $this->assertFalse($out['rateLimited']);
        $rows = array_map(function ($r) {
            return $r['href'] . ' ' . $r['state'] . ' ' . $r['reason'];
        }, $out['results']);
        $this->assertSame(array(
            'https://evil.example/ skipped not-in-site',
            'http://169.254.169.254/latest/meta-data skipped not-in-site',
            self::HOST . '/ok ok ',
        ), $rows);
        $this->assertSame(array('HEAD ' . self::HOST . '/ok'), $this->calls);
        $this->assertSame(1, $out['checked']);
    }

    public function testAnswersAreCached(): void
    {
        $fetch = $this->hopFetcher(array('/ok' => 200));
        $this->check(array('links' => array(self::HOST . '/ok'), 'hopFetcher' => $fetch));
        $again = $this->check(array('links' => array(self::HOST . '/ok'), 'hopFetcher' => $fetch));
        $this->assertCount(1, $this->calls);
        $this->assertSame(0, $again['checked']);
        $this->assertSame(1, $again['cached']);
        $this->assertTrue($again['results'][0]['cached']);
    }

    public function testBudgetBlocksOnceSpentAndCachedAnswersDoNotCount(): void
    {
        $fetch = $this->hopFetcher(array('/ok' => 200, '/gone' => 404, '/moved' => 200));
        $s = $this->settings(2, 60000);
        $first = $this->check(array('links' => array(self::HOST . '/ok', self::HOST . '/gone'), 'hopFetcher' => $fetch, 'rateSettings' => $s, 'nowMs' => 1000));
        $this->assertFalse($first['rateLimited']);
        $this->assertSame(0, $first['remaining']);
        $cachedOnly = $this->check(array('links' => array(self::HOST . '/ok'), 'hopFetcher' => $fetch, 'rateSettings' => $s, 'nowMs' => 2000));
        $this->assertFalse($cachedOnly['rateLimited']);
        $blocked = $this->check(array('links' => array(self::HOST . '/moved#part'), 'hopFetcher' => $fetch, 'rateSettings' => $s, 'nowMs' => 3000));
        $this->assertTrue($blocked['rateLimited']);
        $this->assertSame(58, $blocked['retryAfterSeconds']);
        $this->assertCount(2, $this->calls);
        $other = $this->check(array('links' => array(self::HOST . '/moved#part'), 'hopFetcher' => $fetch, 'rateSettings' => $s, 'rateKey' => 'bob::demo', 'nowMs' => 3000));
        $this->assertFalse($other['rateLimited']);
    }

    public function testLinksPastTheTimeBudgetAreDeferredUncachedAndUncharged(): void
    {
        $out = $this->check(array(
            'links' => array(self::HOST . '/ok', self::HOST . '/gone'),
            'hopFetcher' => $this->hopFetcher(array()),
            'rateSettings' => $this->settings(5),
            'budgetSeconds' => 0,
            'nowMs' => 1000,
        ));
        $this->assertFalse($out['rateLimited']);
        $this->assertSame(2, $out['deferred']);
        $this->assertSame(0, $out['checked']);
        $this->assertSame(5, $out['remaining']);
        $this->assertSame(array('skipped deferred', 'skipped deferred'), array_map(function ($r) {
            return $r['state'] . ' ' . $r['reason'];
        }, $out['results']));
        $this->assertCount(0, $this->calls);
    }

    public function testDuplicateAndNonStringHrefsAreFolded(): void
    {
        $out = $this->check(array(
            'links' => array(self::HOST . '/ok', ' ' . self::HOST . '/ok ', 42, null, ''),
            'hopFetcher' => $this->hopFetcher(array('/ok' => 200)),
        ));
        $this->assertCount(1, $out['results']);
        $this->assertCount(1, $this->calls);
    }
}
