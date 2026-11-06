<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once dirname(__DIR__, 2) . '/lib/LinkChecker.php';

/**
 * Route-level tests for siteCheckLinks.php (POST x/api/v1/site/check-links,
 * haxtheweb/issues#3167). Mirrors haxcms-nodejs
 * test/unit/link-check-route.test.cjs: request validation, links that are
 * not in the site are skipped without any network call, and the 429 answer
 * once the per user/site budget is spent. No test here reaches the network:
 * every requested link is either not in the site or refused by the budget.
 */
class SiteRoutesCheckLinksTest extends TestCase
{
    private $savedHaxcms = null;

    protected function setUp(): void
    {
        LinkChecker::resetForTesting();
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $GLOBALS['HAXCMS'] = new SiteRoutesCheckLinksHaxcmsStub(1000);
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        }
        else {
            unset($GLOBALS['HAXCMS']);
        }
    }

    private function makeSite()
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('item-1', 'one', 'One', '', 0),
            makeSiteRouteItem('item-2', 'two', 'Two', '', 1, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'item-1' => '<p><a href="http://93.184.215.14/ok">ok</a></p>',
            'item-2' => '<p><a href="http://93.184.215.14/draft">draft</a></p>',
        );
        return $site;
    }

    private function invoke($site, $body)
    {
        $context = new SiteRoutesCheckLinksContext($site);
        $context->apiBasePath = '/x/api';
        $context->routeSuffix = 'v1/site/check-links';
        $context->auth = array('authenticated' => true, 'userName' => 'test-user');
        $context->body = $body;
        return invokeSiteRouteHandler('siteCheckLinks.php', $context);
    }

    public function testMissingSiteReturns404(): void
    {
        $result = $this->invoke(null, array('links' => array('http://93.184.215.14/ok')));
        $this->assertSame(404, $result['data']['status']);
    }

    public function testEmptyOrOversizedLinkListsReturn400(): void
    {
        $none = $this->invoke($this->makeSite(), array());
        $this->assertSame(400, $none['data']['status']);
        $this->assertSame('links is required', $none['data']['data']['message']);
        $links = array();
        for ($i = 0; $i <= LinkChecker::MAX_LINKS; $i++) {
            $links[] = 'http://93.184.215.14/' . $i;
        }
        $tooMany = $this->invoke($this->makeSite(), array('links' => $links));
        $this->assertSame(400, $tooMany['data']['status']);
    }

    public function testLinksNotInTheSiteAreSkipped(): void
    {
        $result = $this->invoke($this->makeSite(), array('links' => array('https://elsewhere.test/', 'http://169.254.169.254/')));
        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame(2, $data['count']);
        $this->assertSame(0, $data['checked']);
        $this->assertSame(LinkChecker::MAX_LINKS, $data['limits']['maxLinks']);
        foreach ($data['results'] as $row) {
            $this->assertSame('skipped', $row['state']);
            $this->assertSame('not-in-site', $row['reason']);
        }
    }

    public function testSpentBudgetReturns429(): void
    {
        $GLOBALS['HAXCMS'] = new SiteRoutesCheckLinksHaxcmsStub(1);
        // spend the only check in this window for test-user::testsite
        LinkChecker::changeBudget(null, 'test-user::testsite', 1, intval(round(microtime(true) * 1000)), $GLOBALS['HAXCMS']->getLinkCheckRateLimitSettings());
        // a link from an unpublished page is still a site link, so it needs a check
        $result = $this->invoke($this->makeSite(), array('links' => array('http://93.184.215.14/draft')));
        $this->assertSame(429, $result['data']['status']);
        $this->assertStringStartsWith('Link check limit reached: 1 checks per 15 minutes', $result['data']['data']['message']);
        $this->assertGreaterThan(0, $result['data']['data']['retryAfterSeconds']);
    }
}

/**
 * Request context whose JSON body is set by the test instead of read from
 * php://input.
 */
class SiteRoutesCheckLinksContext extends SiteApiRequestContext
{
    public $body = array();

    public function getBody()
    {
        return is_array($this->body) ? $this->body : array();
    }
}

/** Minimal $GLOBALS['HAXCMS'] for the route: settings and no shared cache. */
class SiteRoutesCheckLinksHaxcmsStub
{
    public $cache = null;
    private $max;

    public function __construct($max)
    {
        $this->max = $max;
    }

    public function getLinkCheckRateLimitSettings()
    {
        $s = new stdClass();
        $s->enabled = true;
        $s->windowMs = 15 * 60 * 1000;
        $s->max = $this->max;
        return $s;
    }
}
