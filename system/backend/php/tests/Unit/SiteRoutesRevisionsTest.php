<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/revisions.php.
 *
 * Scope is deliberately narrow: revisions.php's own dispatch logic —
 * param extraction (idOrSlug, revisionId, site_token header), slug→id
 * resolution via SiteRouteUtils::findItemByIdOrSlug, delegation to
 * Operations::getNodeRevisions() (list) vs getNodeRevision() (single),
 * and response-shape branching for the __failed envelope (envelope=false).
 *
 * The Operations internals (git log/show, metadata merging, etc.) are
 * Area D's coverage scope. Here we rely on early-exit error paths
 * (400 for missing params, 400 for invalid hash format, 403 for rejected
 * token, 404 for site-not-found) that are reachable without a real git
 * repository — a minimal fake $GLOBALS['HAXCMS'] is sufficient to reach
 * buildNodeRevisionContext's token-validation and loadSite branches.
 */
class SiteRoutesRevisionsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
        unset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS']);
        unset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }

    /**
     * Minimal fake HAXCMS for reaching buildNodeRevisionContext without a
     * real HAXcms installation. $acceptTokens controls whether
     * validateRequestToken returns true (→ proceeds to loadSite) or false
     * (→ 403 "Invalid site token"). loadSite always returns false (→ 404
     * "Site not found") since we never need a real site object for these
     * route-handler-level tests.
     */
    private function setFakeHaxcms($acceptTokens = false)
    {
        $GLOBALS['HAXCMS'] = new SiteRoutesRevisionsFakeHaxcms();
        $GLOBALS['HAXCMS']->acceptTokens = $acceptTokens;
    }

    private function buildSiteWithOneItem()
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('item-uuid-1', 'my-page', 'My Page', '', 1),
        );
        return $site;
    }

    // ── 400: missing params ──────────────────────────────────────────

    public function testNoSiteReturns400MissingBodyFields(): void
    {
        // No site context → siteName defaults to '', idOrSlug defaults to
        // '', slug resolution is skipped (no site), resolvedItemId stays ''.
        // getNodeRevisions() sees empty site.name and node.id → 400.
        $context = makeSiteRouteContext(null, array(), 'v1/items/abc/revisions');
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(400, $result['data']['status']);
        $this->assertStringContainsString('site.name', $result['data']['message']);
    }

    public function testSiteSetButNoIdOrSlugReturns400(): void
    {
        // Site is set (siteName='testsite') but idOrSlug is '' so
        // resolvedItemId is ''. getNodeRevisions() sees empty node.id → 400.
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/items//revisions');
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(400, $result['data']['status']);
        $this->assertStringContainsString('node.id', $result['data']['message']);
    }

    // ── 400: invalid hash format (getNodeRevision single-revision path) ──

    public function testInvalidHashFormatReturns400(): void
    {
        // revisionId present but doesn't match /^[a-fA-F0-9]{7,64}$/ →
        // getNodeRevision() returns 400 "Invalid revision hash" before
        // reaching buildNodeRevisionContext (no HAXCMS global needed).
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'item-uuid-1', 'revisionId' => 'not-a-valid-hash'),
            'v1/items/item-uuid-1/revisions/not-a-valid-hash'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Invalid revision hash', $result['data']['message']);
    }

    // ── 403: token rejected (requires fake HAXCMS) ───────────────────

    public function testValidHashWithRejectedTokenReturns403(): void
    {
        // Valid hash (7 hex chars) passes format validation, then
        // buildNodeRevisionContext rejects the token → 403. Proves the
        // single-revision path (getNodeRevision) is taken when revisionId
        // is present and valid.
        $this->setFakeHaxcms(false);
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'item-uuid-1', 'revisionId' => 'abcdef1234567890'),
            'v1/items/item-uuid-1/revisions/abcdef1234567890'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('Invalid site token', $result['data']['message']);
    }

    public function testNoRevisionIdCallsListPathAndReturns403(): void
    {
        // No revisionId → getNodeRevisions() (list path) is called instead
        // of getNodeRevision(). There's no hash to validate, so Operations
        // goes straight to buildNodeRevisionContext which rejects token → 403.
        $this->setFakeHaxcms(false);
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'item-uuid-1'),
            'v1/items/item-uuid-1/revisions'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('Invalid site token', $result['data']['message']);
    }

    // ── Slug → id resolution ─────────────────────────────────────────

    public function testSlugIsResolvedBeforeDelegatingToOperations(): void
    {
        // Pass a slug ('my-page') instead of the UUID. revisions.php
        // resolves it to 'item-uuid-1' via findItemByIdOrSlug, then
        // delegates to Operations with the UUID as node.id. Operations
        // validates the hash (passes), then rejects the token → 403.
        // The fact that we get 403 (not 400 "Missing body fields") proves
        // the slug was resolved to a non-empty id and passed through.
        $this->setFakeHaxcms(false);
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'my-page', 'revisionId' => 'abcdef1234567890'),
            'v1/items/my-page/revisions/abcdef1234567890'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(403, $result['data']['status']);
    }

    public function testUnresolvedSlugFallsBackToRawValueAndStillDelegates(): void
    {
        // Pass a slug that doesn't exist in the manifest. findItemByIdOrSlug
        // returns null, so resolvedItemId stays as the raw slug. The slug is
        // non-empty, so getNodeRevision() passes the node.id check, validates
        // the hash (passes), then rejects the token → 403. This proves the
        // fallback path: unresolved slugs are passed as-is to Operations.
        $this->setFakeHaxcms(false);
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'nonexistent-slug', 'revisionId' => 'abcdef1234567890'),
            'v1/items/nonexistent-slug/revisions/abcdef1234567890'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(403, $result['data']['status']);
    }

    // ── 404: token accepted but site not found ───────────────────────

    public function testAcceptedTokenButSiteNotFoundReturns404(): void
    {
        // Token is accepted (validateRequestToken returns true), so
        // buildNodeRevisionContext proceeds to loadSite, which returns
        // false → 404 "Site not found". Verifies the route handler formats
        // a 404 __failed response correctly.
        $this->setFakeHaxcms(true);
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'item-uuid-1', 'revisionId' => 'abcdef1234567890'),
            'v1/items/item-uuid-1/revisions/abcdef1234567890'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Site not found', $result['data']['message']);
    }

    // ── Response shape: envelope=false ───────────────────────────────

    public function testFailedResponseHasEnvelopeFalseShape(): void
    {
        // revisions.php always sends responses with envelope=false. For the
        // __failed branch, the payload is {status, message} directly — NOT
        // wrapped in {status, data: {status, message}}. This test verifies
        // that structure: top-level keys are 'status' and 'message' only,
        // with no nested 'data' key.
        $context = makeSiteRouteContext(null, array(), 'v1/items/abc/revisions');
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertArrayHasKey('status', $result['data']);
        $this->assertArrayHasKey('message', $result['data']);
        $this->assertArrayNotHasKey('data', $result['data']);
    }

    // ── Site token header wiring ─────────────────────────────────────

    public function testSiteTokenHeaderIsPassedThroughToOperations(): void
    {
        // Set the X-HAXCMS-Site-Token header via $_SERVER and use a fake
        // HAXCMS that only accepts 'my-secret-token'. If the header is
        // correctly read and passed to Operations, validateRequestToken
        // receives 'my-secret-token' and returns true → 404 (site not
        // found). If the header were NOT passed, site_token would be ''
        // and the token check would fail → 403.
        $fake = new SiteRoutesRevisionsFakeHaxcms();
        $fake->expectedToken = 'my-secret-token';
        $GLOBALS['HAXCMS'] = $fake;

        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'my-secret-token';
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'item-uuid-1', 'revisionId' => 'abcdef1234567890'),
            'v1/items/item-uuid-1/revisions/abcdef1234567890'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        // Token accepted → loadSite returns false → 404 (not 403).
        $this->assertSame(404, $result['data']['status']);
    }

    public function testMissingSiteTokenHeaderDefaultsToEmptyAndReturns403(): void
    {
        // No X-HAXCMS-Site-Token header set → getHeader returns null →
        // handler coerces to '' → validateRequestToken('', scope) returns
        // false → 403 "Invalid site token".
        $this->setFakeHaxcms(false);
        $site = $this->buildSiteWithOneItem();
        $context = makeSiteRouteContext(
            $site,
            array('idOrSlug' => 'item-uuid-1', 'revisionId' => 'abcdef1234567890'),
            'v1/items/item-uuid-1/revisions/abcdef1234567890'
        );
        $result = invokeSiteRouteHandler('revisions.php', $context);
        $this->assertSame(403, $result['data']['status']);
    }
}

/**
 * Minimal fake HAXCMS for revisions.php route tests. Exposes just the
 * surface that Operations::buildNodeRevisionContext() calls:
 * validateRequestToken, getActiveUserName, loadSite.
 *
 * - $acceptTokens: if true, validateRequestToken returns true for any
 *   non-empty token (or for the $expectedToken if set).
 * - $expectedToken: if non-null, validateRequestToken returns true only
 *   when the received token matches this value (used for header-wiring
 *   tests). If null, $acceptTokens controls the return value.
 */
class SiteRoutesRevisionsFakeHaxcms
{
    public $acceptTokens = false;
    public $expectedToken = null;

    public function validateRequestToken($token, $scope)
    {
        if ($this->expectedToken !== null) {
            return $token === $this->expectedToken;
        }
        return $this->acceptTokens;
    }

    public function getActiveUserName()
    {
        return 'test-user';
    }

    public function loadSite($name)
    {
        return false;
    }
}
