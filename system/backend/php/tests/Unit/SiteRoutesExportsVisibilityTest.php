<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Whole-site and per-item export access rules for anonymous vs logged-in
 * callers (lib/siteRoutes/v1/exports.php):
 *  - anonymous html site export excludes unpublished and hidden pages
 *  - anonymous pdf / docx / epub site exports are refused with 401
 *  - anonymous pdf / docx item exports are refused with 401
 *  - logged-in html site export still includes every page
 */
class SiteRoutesExportsVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSite(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('b', 'page-b', 'Draft B', '', 2, 0, '', '', array('published' => false)),
            makeSiteRouteItem('c', 'page-c', 'Hidden C', '', 3, 0, '', '', array('hideInMenu' => true)),
        );
        $site->pageContentMap = array(
            'a' => '<p>visible-content-a</p>',
            'b' => '<p>secret-draft-b</p>',
            'c' => '<p>secret-hidden-c</p>',
        );
        return $site;
    }

    private function siteExport($format, $authenticated)
    {
        $context = makeSiteRouteContext(
            $this->buildSite(),
            array('format' => $format),
            'v1/site/export/' . $format,
            '/x/api',
            $authenticated
        );
        return invokeSiteRouteHandler('exports.php', $context);
    }

    public function testAnonymousHtmlSiteExportExcludesUnpublishedAndHidden(): void
    {
        $result = $this->siteExport('html', false);
        $this->assertStringContainsString('visible-content-a', $result['raw']);
        $this->assertStringNotContainsString('secret-draft-b', $result['raw']);
        $this->assertStringNotContainsString('secret-hidden-c', $result['raw']);
    }

    public function testAuthenticatedHtmlSiteExportIncludesEveryPage(): void
    {
        $result = $this->siteExport('html', true);
        $this->assertStringContainsString('visible-content-a', $result['raw']);
        $this->assertStringContainsString('secret-draft-b', $result['raw']);
        $this->assertStringContainsString('secret-hidden-c', $result['raw']);
    }

    public static function binarySiteFormats(): array
    {
        return array(array('pdf'), array('docx'), array('epub'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('binarySiteFormats')]
    public function testAnonymousBinarySiteExportRequiresAuth($format): void
    {
        $result = $this->siteExport($format, false);
        $this->assertSame(401, $result['data']['status']);
        $this->assertStringNotContainsString('visible-content-a', $result['raw']);
    }

    public static function binaryItemFormats(): array
    {
        return array(array('pdf'), array('docx'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('binaryItemFormats')]
    public function testAnonymousBinaryItemExportRequiresAuth($format): void
    {
        $context = makeSiteRouteContext(
            $this->buildSite(),
            array('idOrSlug' => 'page-a', 'format' => $format),
            'v1/items/page-a/export/' . $format,
            '/x/api',
            false
        );
        $result = invokeSiteRouteHandler('exports.php', $context);
        $this->assertSame(401, $result['data']['status']);
    }
}
