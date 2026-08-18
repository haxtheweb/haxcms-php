<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/search.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesSearchTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSearchableSite(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'about-us', 'About Us', '', 1, 0, '', 'A page about the team'),
            makeSiteRouteItem('b', 'contact', 'Contact', '', 2, 0, '', 'Get in touch'),
            makeSiteRouteItem('c', 'hidden', 'Hidden Page', '', 3, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'a' => '<p>Meet our fantastic team members</p>',
            'b' => '<p>Reach out via email</p>',
            'c' => '<p>Secret team content</p>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testMissingQueryReturns400(): void
    {
        $site = $this->buildSearchableSite();
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $this->assertSame(400, $result['data']['status']);
    }

    public function testQueryTooLongReturns400(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = str_repeat('a', 257);
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $this->assertSame(400, $result['data']['status']);
    }

    public function testMatchesTitleField(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'About';
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('a', $data['results'][0]['id']);
    }

    public function testMatchesContentFieldWhenRequested(): void
    {
        // 'content' is part of the default search-field set (no explicit
        // fields= override), which also avoids the fields= query param's
        // dual purpose in search.php: it both narrows search fields AND
        // projects the output result records, so passing fields=content
        // would project results down to a nonexistent 'content' key.
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'fantastic';
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('a', $data['results'][0]['id']);
        $this->assertContains('content', $data['fields']);
    }

    public function testNoMatchesReturnsEmptyResults(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'zzz-nonexistent';
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $this->assertSame(0, $result['data']['data']['count']);
        $this->assertSame([], $result['data']['data']['results']);
    }

    public function testResultIncludesSnippetAndMatches(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'touch';
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $result0 = $result['data']['data']['results'][0];
        $this->assertSame('b', $result0['id']);
        $this->assertNotEmpty($result0['snippet']);
        $this->assertNotEmpty($result0['matches']);
        $this->assertSame('description', $result0['matches'][0]['field']);
    }

    public function testAnonymousRequestExcludesUnpublishedItemFromSearch(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'team';
        $context = makeSiteRouteContext($site, array(), 'v1/search', '/x/api', false);
        $result = invokeSiteRouteHandler('search.php', $context);
        $ids = array_column($result['data']['data']['results'], 'id');
        $this->assertNotContains('c', $ids);
        $this->assertContains('a', $ids);
    }

    public function testAuthenticatedRequestIncludesUnpublishedItemInSearch(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'secret';
        $context = makeSiteRouteContext($site, array(), 'v1/search', '/x/api', true);
        $result = invokeSiteRouteHandler('search.php', $context);
        $ids = array_column($result['data']['data']['results'], 'id');
        $this->assertContains('c', $ids);
    }

    public function testResultLinksPointToItemAndContent(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'about';
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $links = $result['data']['data']['results'][0]['links'];
        $this->assertSame('/x/api/v1/items/about-us', $links['item']);
        $this->assertSame('/x/api/v1/content/about-us', $links['content']);
    }

    public function testDefaultFieldsUsedWhenFieldsNotSpecified(): void
    {
        $site = $this->buildSearchableSite();
        $_GET['q'] = 'contact';
        $context = makeSiteRouteContext($site, array(), 'v1/search');
        $result = invokeSiteRouteHandler('search.php', $context);
        $this->assertSame(['title', 'slug', 'description', 'tags', 'content'], $result['data']['data']['fields']);
    }
}
