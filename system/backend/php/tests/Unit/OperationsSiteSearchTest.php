<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteSiteSearch trait
 * (lib/operations/siteSearch.php) and its supporting private helpers on
 * Operations.php (getSiteSearchSiteName, parseSiteSearchBoolean,
 * parseSiteSearchLimit, normalizeSiteSearchFields,
 * normalizeSiteSearchOperation, siteSearchTextReplace,
 * parseSimpleSiteSearchSelector, siteSearchXPathLiteral, siteSearchTagsValue,
 * getSiteSearchFieldValue, siteSearchTextMatch, siteSearchSelectorMatch).
 *
 * Gate tests cover: missing site name, missing/invalid site_token, missing
 * search term, search term too long. Happy-path slices run text search,
 * selector-mode search, and the replace operation against a temp site
 * fixture with real on-disk page content, verifying matches/snippets and
 * (for replace) persisted file + manifest mutations.
 */
class OperationsSiteSearchTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'search-site';
    private $siteRoot;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_sitesearch_root');
        }

        // siteSearch()'s replace branch writes via an explicit
        // HAXCMS_ROOT . '/' . sitesDirectory . '/' . siteName path (not
        // $site->directory). HAXCMS_ROOT is a process-global constant that
        // may already have been defined by an earlier-run test class, so
        // nest this test's fixture under whatever value it ends up holding
        // and point sitesDirectory at that same nested folder name, keeping
        // the write path and the on-disk fixture path identical.
        $fixtureDirName = 'fixtures_sitesearch_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $fixtureDirName;
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixture();

        $this->haxcms = new SiteSearchTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->sitesDirectory = $fixtureDirName;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new SiteSearchTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
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
        $this->rrmdir($this->tmpRoot);
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

    /**
     * Build a temp site fixture with two pages: one with "apple" in its
     * content and an <h2 class="banner"> element for selector-mode tests,
     * the other with unrelated content.
     */
    private function buildSiteFixture(): void
    {
        mkdir($this->siteRoot . '/pages/page-one', 0777, true);
        mkdir($this->siteRoot . '/pages/page-two', 0777, true);
        file_put_contents(
            $this->siteRoot . '/pages/page-one/index.html',
            '<p>I really like apple pie.</p><h2 class="banner">Big Banner</h2>'
        );
        file_put_contents(
            $this->siteRoot . '/pages/page-two/index.html',
            '<p>Nothing interesting here.</p>'
        );

        $manifest = (object)array(
            'id' => 'site-sitesearch-uuid',
            'title' => 'Site Search Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => false),
                    'created' => time(),
                    'updated' => time(),
                ),
                'platform' => (object)array(
                    'features' => (object)array(),
                ),
            ),
            'items' => array(
                (object)array(
                    'id' => 'page-one',
                    'indent' => 0,
                    'location' => 'pages/page-one/index.html',
                    'slug' => 'page-one',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Page One',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000, 'tags' => array('fruit', 'pie')),
                ),
                (object)array(
                    'id' => 'page-two',
                    'indent' => 0,
                    'location' => 'pages/page-two/index.html',
                    'slug' => 'page-two',
                    'order' => 1,
                    'parent' => null,
                    'title' => 'Page Two',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
        );
        file_put_contents(
            $this->siteRoot . '/site.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    private function baseParams(array $overrides = array()): array
    {
        return array_merge(
            array(
                'site_token' => 'good',
                'site' => array('name' => $this->siteName),
                'search' => 'apple',
            ),
            $overrides
        );
    }

    // =========================================================================
    // Gate tests
    // =========================================================================

    public function testSiteSearchMissingSiteNameReturns400(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'search' => 'apple',
        );
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('siteName is required', $result['__failed']['message']);
    }

    public function testSiteSearchInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = $this->baseParams(array('site_token' => 'bad'));
        $result = $this->ops->siteSearch();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Authentication required', $result['__failed']['message']);
    }

    public function testSiteSearchMissingTokenReturns403(): void
    {
        $this->ops->params = array(
            'site' => array('name' => $this->siteName),
            'search' => 'apple',
        );
        $result = $this->ops->siteSearch();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Authentication required', $result['__failed']['message']);
    }

    public function testSiteSearchMissingSearchTermReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'search' => '   ',
        );
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Search query is required', $result['__failed']['message']);
    }

    public function testSiteSearchTermTooLongReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array('search' => str_repeat('a', 257)));
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Search query is too long (max 256 characters)', $result['__failed']['message']);
    }

    // =========================================================================
    // text search — happy path
    // =========================================================================

    public function testSiteSearchTextModeFindsMatchInContent(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams();
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $this->assertSame('text', $result['data']['mode']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('page-one', $result['data']['matches'][0]['id']);
        $this->assertSame('fruit, pie', $result['data']['matches'][0]['tags']);

        $fieldNames = array();
        foreach ($result['data']['matches'][0]['matches'] as $fieldMatch) {
            $fieldNames[] = $fieldMatch['field'];
        }
        $this->assertContains('content', $fieldNames);
    }

    public function testSiteSearchTextModeFindsNoMatchesForUnknownTerm(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array('search' => 'zzzznotfound'));
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame(array(), $result['data']['matches']);
    }

    public function testSiteSearchRespectsSearchLimit(): void
    {
        $this->haxcms->validRequestToken = true;
        // both pages contain "e" (case-insensitive), but limit caps to 1
        $this->ops->params = $this->baseParams(array('search' => 'e', 'searchLimit' => 1));
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['limit']);
        $this->assertLessThanOrEqual(1, count($result['data']['matches']));
    }

    // =========================================================================
    // selector-mode search — happy path
    // =========================================================================

    public function testSiteSearchSelectorModeFindsMatchingElement(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'h2[class="banner"]',
            'searchSelector' => true,
        ));
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $this->assertSame('selector', $result['data']['mode']);
        $this->assertSame(array('content'), $result['data']['fields']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('page-one', $result['data']['matches'][0]['id']);
        $selectorMatch = $result['data']['matches'][0]['matches'][0];
        $this->assertSame('selector', $selectorMatch['type']);
        $this->assertGreaterThan(0, $selectorMatch['count']);
    }

    public function testSiteSearchSelectorModeViaSearchModeParamActivatesSelectorMode(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'h2',
            'searchMode' => 'selector',
        ));
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $this->assertSame('selector', $result['data']['mode']);
    }

    public function testSiteSearchSelectorModeInvalidSelectorReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'div > p',
            'searchSelector' => true,
        ));
        $result = $this->ops->siteSearch();

        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('simple selectors', $result['__failed']['message']);
    }

    // =========================================================================
    // replace operation — gates
    // =========================================================================

    public function testSiteSearchReplaceTooShortSearchTermReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'a',
            'operation' => 'replace',
            'replace' => 'xyz',
            'replaceConfirm' => true,
        ));
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Search text must be more than 1 character for replacement operations',
            $result['__failed']['message']
        );
    }

    public function testSiteSearchReplaceWithoutConfirmationReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'operation' => 'replace',
            'replace' => 'orange',
        ));
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Replacement requires confirmation', $result['__failed']['message']);
    }

    public function testSiteSearchReplaceRemovalWithoutDestroyConfirmReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'operation' => 'replace',
            'replace' => '',
            'replaceConfirm' => true,
        ));
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Removing matched text requires a second confirmation',
            $result['__failed']['message']
        );
    }

    public function testSiteSearchReplaceNotFoundReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'zzzznotfound',
            'operation' => 'replace',
            'replace' => 'orange',
            'replaceConfirm' => true,
        ));
        $result = $this->ops->siteSearch();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Search term not found in site content', $result['__failed']['message']);
    }

    // =========================================================================
    // replace operation — happy path
    // =========================================================================

    public function testSiteSearchReplaceUpdatesPageContentAndManifest(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'apple',
            'operation' => 'replace',
            'replace' => 'orange',
            'replaceConfirm' => true,
        ));
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $this->assertSame('replace', $result['data']['operation']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(1, $result['data']['updatedItems']);
        $this->assertSame('page-one', $result['data']['items'][0]['id']);

        // Independent source of truth: persisted page content on disk
        $pageContent = file_get_contents($this->siteRoot . '/pages/page-one/index.html');
        $this->assertStringContainsString('orange', $pageContent);
        $this->assertStringNotContainsString('apple', $pageContent);

        // Manifest updated timestamp was bumped and gitCommit was called
        $site = $this->haxcms->loadedSite;
        $this->assertNotEmpty($site->gitCommits);
        $this->assertStringContainsString('Bulk replace', $site->gitCommits[0]);
    }

    public function testSiteSearchReplaceWithEmptyReplacementRemovesText(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->baseParams(array(
            'search' => 'apple',
            'operation' => 'replace',
            'replace' => '',
            'replaceConfirm' => true,
            'replaceDestroyConfirm' => true,
        ));
        $result = $this->ops->siteSearch();

        $this->assertSame(200, $result['status']);
        $pageContent = file_get_contents($this->siteRoot . '/pages/page-one/index.html');
        $this->assertStringNotContainsString('apple', $pageContent);
    }

    // =========================================================================
    // replace operation — limited by nodeIds
    // =========================================================================

    /**
     * Put "apple" on page two as well so an unscoped replace touches both
     * pages and a nodeIds-scoped replace can be told apart from it.
     */
    private function addAppleToPageTwo(): void
    {
        file_put_contents(
            $this->siteRoot . '/pages/page-two/index.html',
            '<p>Another apple here.</p>'
        );
    }

    /**
     * Rebuild the on-disk fixture and reload the site so a second replace
     * in the same test starts from identical state.
     */
    private function resetFixture(): void
    {
        $this->rrmdir($this->siteRoot);
        $this->buildSiteFixture();
        $site = new SiteSearchTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->haxcms->validRequestToken = true;
    }

    private function runScopedReplace($nodeIds, bool $includeNodeIds = true): array
    {
        $this->resetFixture();
        $this->addAppleToPageTwo();
        $params = array(
            'search' => 'apple',
            'operation' => 'replace',
            'replace' => 'orange',
            'replaceConfirm' => true,
        );
        if ($includeNodeIds) {
            $params['nodeIds'] = $nodeIds;
        }
        $this->ops->params = $this->baseParams($params);
        $result = $this->ops->siteSearch();
        return array(
            'result' => $result,
            'pageOne' => file_get_contents($this->siteRoot . '/pages/page-one/index.html'),
            'pageTwo' => file_get_contents($this->siteRoot . '/pages/page-two/index.html'),
            'commits' => $this->haxcms->loadedSite->gitCommits,
        );
    }

    public function testSiteSearchReplaceWithNodeIdsOnlyTouchesListedPages(): void
    {
        $run = $this->runScopedReplace(array('page-two', 'not-a-page'));
        $result = $run['result'];

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(1, $result['data']['updatedItems']);
        $this->assertSame(1, $result['data']['totalReplacements']);
        $this->assertCount(1, $result['data']['items']);
        $this->assertSame('page-two', $result['data']['items'][0]['id']);
        // unlisted page keeps its text, listed page is rewritten
        $this->assertStringContainsString('apple', $run['pageOne']);
        $this->assertStringContainsString('orange', $run['pageTwo']);
        $this->assertStringNotContainsString('apple', $run['pageTwo']);
        $this->assertSame(
            array('Bulk replace "apple" -> "orange" across 1 page'),
            $run['commits']
        );
    }

    public function testSiteSearchReplaceWithoutNodeIdsTouchesEveryMatchingPage(): void
    {
        $run = $this->runScopedReplace(null, false);
        $result = $run['result'];

        $this->assertSame(200, $result['status']);
        $this->assertSame(array(
            'operation' => 'replace',
            'query' => 'apple',
            'replace' => 'orange',
            'caseSensitive' => false,
            'total' => 2,
            'updatedItems' => 2,
            'totalReplacements' => 2,
            'items' => array(
                array('id' => 'page-one', 'title' => 'Page One', 'slug' => 'page-one', 'replacements' => 1),
                array('id' => 'page-two', 'title' => 'Page Two', 'slug' => 'page-two', 'replacements' => 1),
            ),
        ), $result['data']);
        $this->assertStringNotContainsString('apple', $run['pageOne']);
        $this->assertStringNotContainsString('apple', $run['pageTwo']);
        $this->assertSame(
            array('Bulk replace "apple" -> "orange" across 2 pages'),
            $run['commits']
        );
    }

    public function testSiteSearchReplaceEmptyOrInvalidNodeIdsMatchesOmitted(): void
    {
        $omitted = $this->runScopedReplace(null, false);
        $this->assertSame($omitted, $this->runScopedReplace(array()));
        $this->assertSame($omitted, $this->runScopedReplace(array('', '  ', 7)));
        $this->assertSame($omitted, $this->runScopedReplace('page-two'));
    }

    public function testSiteSearchReplaceWithNoListedMatchesReturns400(): void
    {
        $run = $this->runScopedReplace(array('not-a-page'));
        $this->assertSame(400, $run['result']['__failed']['status']);
        $this->assertSame('Search term not found in site content', $run['result']['__failed']['message']);
        $this->assertStringContainsString('apple', $run['pageOne']);
        $this->assertStringContainsString('apple', $run['pageTwo']);
        $this->assertSame(array(), $run['commits']);
    }

    public function testSiteSearchNodeIdsDoesNotChangeSearchResults(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->addAppleToPageTwo();
        $this->ops->params = $this->baseParams();
        $plain = $this->ops->siteSearch();
        $this->ops->params = $this->baseParams(array('nodeIds' => array('page-two')));
        $scoped = $this->ops->siteSearch();
        $this->assertSame(2, $plain['data']['total']);
        $this->assertSame($plain, $scoped);
    }
}

/**
 * HAXCMS mock for site search tests.
 */
class SiteSearchTestHaxcms extends OperationsTestHaxcms
{
    // Inherits all behavior from OperationsTestHaxcms.
}

/**
 * HAXCMSSite test subclass that no-ops gitCommit/updateAlternateFormats
 * while keeping real manifest load/save, loadNode, and getPageContent so
 * the real siteSearch() mutation logic runs against a temp fixture.
 */
class SiteSearchTestSite extends HAXCMSSite
{
    public $gitCommits = array();

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
        return true;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }
}
