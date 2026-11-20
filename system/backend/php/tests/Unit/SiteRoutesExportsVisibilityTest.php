<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Whole-site and per-item export access rules for anonymous vs logged-in
 * callers (lib/siteRoutes/v1/exports.php):
 *  - anonymous html site export excludes unpublished and hidden pages
 *  - anonymous pdf / docx / epub site exports are refused with 401
 *  - anonymous pdf / docx / epub item exports are refused with 401
 *  - logged-in html site export still includes every page
 *  - the html export's magic CDN is ignored for anonymous callers and is
 *    otherwise only an absolute http(s) base URL (mirrors haxcms-nodejs)
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

    public function testAnonymousMagicHtmlSiteExportIgnoresCallerCdn(): void
    {
        $_GET['magic'] = 'https://evil.example.com/';
        $result = $this->siteExport('html', false);
        $this->assertStringNotContainsString('evil.example.com', $result['raw']);
        $this->assertStringNotContainsString('<haxcms-print-theme>', $result['raw']);
    }

    public function testAuthenticatedMagicHtmlSiteExportWrapsWithCdn(): void
    {
        $_GET['magic'] = 'https://cdn.example.com/';
        $result = $this->siteExport('html', true);
        $this->assertStringContainsString('<haxcms-print-theme>', $result['raw']);
        $this->assertStringContainsString('window.__appCDN="https://cdn.example.com/"', $result['raw']);
    }

    public static function magicCdnProvider(): array
    {
        return array(
            'cdn base' => array('https://cdn.hax.cloud/cdn/', 'https://cdn.hax.cloud/cdn/'),
            'adds the slash' => array('https://cdn.example.com/cdn', 'https://cdn.example.com/cdn/'),
            'http with port' => array('http://localhost:8080/', 'http://localhost:8080/'),
            'empty' => array('', ''),
            'relative' => array('./', ''),
            'scheme relative' => array('//evil.example.com/', ''),
            'javascript' => array('javascript:alert(1)//', ''),
            'data' => array('data:text/javascript,alert(1)//', ''),
            'credentials' => array('https://user:pass@cdn.example.com/', ''),
            'query' => array('https://cdn.example.com/?x=1', ''),
            'fragment' => array('https://cdn.example.com/#x', ''),
            'quote' => array('https://cdn.example.com/"><script>', ''),
            'space' => array('https://cdn.example.com/a b/', ''),
            'trailing newline' => array("https://cdn.example.com/\n<script>", ''),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('magicCdnProvider')]
    public function testExportMagicCdnOnlyAllowsPlainHttpBase($raw, $expected): void
    {
        $this->assertSame($expected, ExportConverters::exportMagicCdn($raw));
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
        return array(array('pdf'), array('docx'), array('epub'));
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
