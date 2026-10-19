<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Fake site whose loadNode() explodes, used to drive the route-level
 * exception handlers (500 / 502 catch blocks in exports.php) without
 * depending on real filesystem content.
 */
class SiteRoutesExportsThrowingSite extends SiteRoutesFakeSite
{
    public function loadNode($id)
    {
        throw new Exception('content source exploded');
    }
}

/**
 * Fake HAXCMS global exposing systemRequestBase so the site-level export
 * descriptor closure's system-api mapping branch runs.
 */
class SiteRoutesExportsFakeHaxcms
{
    public $systemRequestBase = 'system/api';
}

/**
 * Covers the export route branches not exercised by the markdown/visibility
 * tests: missing site/format errors, unsupported format errors, the site
 * export descriptor JSON (incl. system api base path mapping), authenticated
 * binary site/item exports (pdf, docx, epub), every record serialization
 * format for items, and the per-branch exception paths.
 */
class SiteRoutesExportsFormatsTest extends TestCase
{
    private $originalErrorLog = '';

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
        // The route handlers @error_log() on intentional failure paths;
        // keep the test runner output clean without muting PHPUnit itself.
        $this->originalErrorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS']);
        ini_set('error_log', $this->originalErrorLog);
    }

    private function buildSite(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->title = 'Demo Course';
        $site->manifest->description = 'A short course';
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('b', 'draft-b', 'Draft B', '', 2, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'a' => '<p>Visible <strong>content</strong> A</p>',
            'b' => '<p>secret-draft</p>',
        );
        return $site;
    }

    private function siteExport($format, $authenticated = true, $site = 'default', $suffix = null)
    {
        if ($suffix === null) {
            $suffix = 'v1/site/export/' . $format;
        }
        $context = makeSiteRouteContext(
            $site === 'default' ? $this->buildSite() : $site,
            array('format' => $format),
            $suffix,
            '/x/api',
            $authenticated
        );
        return invokeSiteRouteHandler('exports.php', $context);
    }

    private function itemExport($format, $authenticated = true, $site = 'default')
    {
        $context = makeSiteRouteContext(
            $site === 'default' ? $this->buildSite() : $site,
            array('idOrSlug' => 'page-a', 'format' => $format),
            'v1/items/page-a/export/' . $format,
            '/x/api',
            $authenticated
        );
        return invokeSiteRouteHandler('exports.php', $context);
    }

    /**
     * A site whose manifest/content map is fully populated but whose
     * loadNode() throws, so route-level exception handlers run.
     */
    private function buildThrowingSite(): SiteRoutesExportsThrowingSite
    {
        $throwing = new SiteRoutesExportsThrowingSite();
        $normal = $this->buildSite();
        $throwing->manifest = $normal->manifest;
        $throwing->pageContentMap = $normal->pageContentMap;
        return $throwing;
    }

    // ------------------------------------------------------------------
    // Site-level guard rails
    // ------------------------------------------------------------------

    public function testMissingSiteContextReturns404(): void
    {
        $result = $this->siteExport('md', true, null);
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame(
            'Unable to resolve site context for export endpoint',
            $result['data']['data']['message']
        );
    }

    public function testMissingFormatReturns400(): void
    {
        $context = makeSiteRouteContext($this->buildSite(), array(), 'v1/site/export', '/x/api', true);
        $result = invokeSiteRouteHandler('exports.php', $context);
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Export format is required', $result['data']['data']['message']);
    }

    public function testUnsupportedSiteFormatReturns400WithSupportedList(): void
    {
        $result = $this->siteExport('txt');
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Unsupported site export format "txt"', $result['data']['data']['message']);
        $this->assertContains('zip', $result['data']['data']['supportedFormats']);
        $this->assertContains('skeleton', $result['data']['data']['supportedFormats']);
        $this->assertNotContains('txt', $result['data']['data']['supportedFormats']);
    }

    // ------------------------------------------------------------------
    // Site export descriptor (JSON) branches
    // ------------------------------------------------------------------

    public function testZipSiteExportReturnsDescriptorJson(): void
    {
        $result = $this->siteExport('zip');
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('zip', $result['data']['data']['format']);
        $export = $result['data']['data']['export'];
        $this->assertSame('/system/api/v1/sites/testsite/download', $export['href']);
        $this->assertSame('/system/api/v1/sites/testsite/download', $export['authenticatedEndpoint']);
        $this->assertSame('application/zip', $export['mediaType']);
        $this->assertSame('POST', $export['method']);
        $links = $result['data']['data']['links'];
        $this->assertSame('/x/api/v1/site/export/zip', $links['self']);
        $this->assertSame('/x/api/v1/site', $links['site']);
    }

    public function testSiteExportDescriptorHonorsCustomSystemRequestBase(): void
    {
        $haxcms = new SiteRoutesExportsFakeHaxcms();
        $haxcms->systemRequestBase = 'sys/api';
        $GLOBALS['HAXCMS'] = $haxcms;
        $result = $this->siteExport('skeleton');
        $this->assertSame(200, $result['data']['status']);
        $export = $result['data']['data']['export'];
        $this->assertSame('/sys/api/v1/sites/testsite/download-skeleton', $export['href']);
        $this->assertSame('application/json', $export['mediaType']);
    }

    public function testSiteExportDescriptorHandlesMissingSiteName(): void
    {
        // Malformed manifest (no metadata.site.name): descriptor still builds,
        // with an empty site segment in the download href.
        $site = $this->buildSite();
        unset($site->manifest->metadata->site->name);
        $result = $this->siteExport('zip', true, $site);
        $this->assertSame(200, $result['data']['status']);
        $export = $result['data']['data']['export'];
        $this->assertSame('/system/api/v1/sites//download', $export['href']);
    }

    public function testMarkdownAliasSiteExportReturnsDescriptorNotBody(): void
    {
        // 'markdown' normalizes to an allowed site format but only 'md' has a
        // conversion branch, so it falls through to the descriptor response.
        $result = $this->siteExport('markdown');
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('markdown', $result['data']['data']['format']);
        $export = $result['data']['data']['export'];
        $this->assertSame('text/markdown', $export['mediaType']);
        $this->assertSame('/x/api/v1/site/export/md', $export['href']);
    }

    // ------------------------------------------------------------------
    // Authenticated binary site exports
    // ------------------------------------------------------------------

    public function testAuthenticatedSitePdfExportReturnsPdfBytes(): void
    {
        $result = $this->siteExport('pdf');
        $this->assertStringStartsWith('%PDF-', $result['raw']);
    }

    public function testAuthenticatedSiteDocxExportReturnsZipBytes(): void
    {
        $result = $this->siteExport('docx');
        $this->assertStringStartsWith('PK', $result['raw']);
    }

    public function testAuthenticatedSiteEpubExportReturnsZipBytes(): void
    {
        $result = $this->siteExport('epub');
        $this->assertStringStartsWith('PK', $result['raw']);
    }

    // ------------------------------------------------------------------
    // Site export failure paths
    // ------------------------------------------------------------------

    public function testSiteMarkdownExportFailureReturns500(): void
    {
        $result = $this->siteExport('md', true, $this->buildThrowingSite());
        $this->assertSame(500, $result['data']['status']);
        $this->assertSame('Unable to build site export markdown', $result['data']['data']['message']);
    }

    public function testSiteHtmlExportFailureReturns500(): void
    {
        $result = $this->siteExport('html', true, $this->buildThrowingSite());
        $this->assertSame(500, $result['data']['status']);
        $this->assertSame('Unable to build site export HTML', $result['data']['data']['message']);
    }

    public function testSitePdfExportFailureReturns502(): void
    {
        $result = $this->siteExport('pdf', true, $this->buildThrowingSite());
        $this->assertSame(502, $result['data']['status']);
        $this->assertSame('Export conversion failed', $result['data']['data']['message']);
    }

    public function testSiteEpubExportFailureReturns502(): void
    {
        $result = $this->siteExport('epub', true, $this->buildThrowingSite());
        $this->assertSame(502, $result['data']['status']);
        $this->assertSame('Export conversion failed', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // Item-level guard rails
    // ------------------------------------------------------------------

    public function testItemExportUnknownSlugReturns404(): void
    {
        $context = makeSiteRouteContext(
            $this->buildSite(),
            array('idOrSlug' => 'nope', 'format' => 'md'),
            'v1/items/nope/export/md',
            '/x/api',
            true
        );
        $result = invokeSiteRouteHandler('exports.php', $context);
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Item not found for idOrSlug "nope"', $result['data']['data']['message']);
    }

    public function testAnonymousInvisibleItemExportReturns404(): void
    {
        $context = makeSiteRouteContext(
            $this->buildSite(),
            array('idOrSlug' => 'draft-b', 'format' => 'md'),
            'v1/items/draft-b/export/md',
            '/x/api',
            false
        );
        $result = invokeSiteRouteHandler('exports.php', $context);
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Item not found for idOrSlug "draft-b"', $result['data']['data']['message']);
    }

    public function testUnsupportedItemFormatReturns400WithSupportedList(): void
    {
        $result = $this->itemExport('txt');
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Unsupported item export format "txt"', $result['data']['data']['message']);
        foreach (array('pdf', 'docx', 'html', 'md', 'json', 'yaml', 'xml', 'epub') as $expected) {
            $this->assertContains($expected, $result['data']['data']['supportedFormats']);
        }
    }

    // ------------------------------------------------------------------
    // Item export conversions
    // ------------------------------------------------------------------

    public function testItemHtmlExportWrapsContent(): void
    {
        $result = $this->itemExport('html');
        $this->assertStringContainsString('<article data-haxcms-export="item" data-item-id="a" data-item-slug="page-a">', $result['raw']);
        $this->assertStringContainsString('<h1>Page A</h1>', $result['raw']);
        $this->assertStringContainsString('Visible <strong>content</strong> A', $result['raw']);
    }

    public function testItemMarkdownExportConvertsHtml(): void
    {
        $result = $this->itemExport('md');
        $this->assertStringStartsWith('# Page A', $result['raw']);
        $this->assertStringContainsString('Visible **content** A', $result['raw']);
    }

    public function testItemJsonExportIncludesContentAndLinks(): void
    {
        $result = $this->itemExport('json');
        $record = json_decode($result['raw'], true);
        $this->assertSame('Page A', $record['title']);
        $this->assertSame('page-a', $record['slug']);
        $this->assertSame('<p>Visible <strong>content</strong> A</p>', $record['content']);
        $this->assertSame('/x/api/v1/items/page-a', $record['links']['self']);
    }

    public function testItemYamlExportSerializesRecord(): void
    {
        $result = $this->itemExport('yaml');
        // Symfony's YAML dumper quotes scalar strings; only assert the
        // structural keys plus values survive serialization.
        $this->assertStringContainsString('title:', $result['raw']);
        $this->assertStringContainsString('Page A', $result['raw']);
        $this->assertStringContainsString('slug:', $result['raw']);
        $this->assertStringContainsString('page-a', $result['raw']);
    }

    public function testItemXmlExportSerializesRecord(): void
    {
        $result = $this->itemExport('xml');
        $this->assertStringStartsWith('<?xml', $result['raw']);
        $this->assertStringContainsString('<title>Page A</title>', $result['raw']);
        $this->assertStringContainsString('Visible', $result['raw']);
    }

    public function testAuthenticatedItemPdfExportReturnsPdfBytes(): void
    {
        $result = $this->itemExport('pdf');
        $this->assertStringStartsWith('%PDF-', $result['raw']);
    }

    public function testAuthenticatedItemDocxExportReturnsZipBytes(): void
    {
        $result = $this->itemExport('docx');
        $this->assertStringStartsWith('PK', $result['raw']);
    }

    public function testItemEpubExportReturnsZipBytes(): void
    {
        $result = $this->itemExport('epub');
        $this->assertStringStartsWith('PK', $result['raw']);
    }

    // ------------------------------------------------------------------
    // Item export failure paths
    // ------------------------------------------------------------------

    public function testItemHtmlExportFailureReturns500WithReason(): void
    {
        $result = $this->itemExport('html', true, $this->buildThrowingSite());
        $this->assertSame(500, $result['data']['status']);
        $this->assertSame(
            'Unable to build item export HTML: content source exploded',
            $result['data']['data']['message']
        );
    }

    public function testItemPdfExportHtmlBuildFailureReturns500(): void
    {
        $result = $this->itemExport('pdf', true, $this->buildThrowingSite());
        $this->assertSame(500, $result['data']['status']);
        $this->assertSame('Unable to build item export HTML', $result['data']['data']['message']);
    }

    public function testItemEpubExportFailureReturns502(): void
    {
        $result = $this->itemExport('epub', true, $this->buildThrowingSite());
        $this->assertSame(502, $result['data']['status']);
        $this->assertSame('Export conversion failed', $result['data']['data']['message']);
    }

    public function testItemRecordExportFailureReturns500(): void
    {
        $result = $this->itemExport('json', true, $this->buildThrowingSite());
        $this->assertSame(500, $result['data']['status']);
        $this->assertSame('Unable to build item export record', $result['data']['data']['message']);
    }
}
