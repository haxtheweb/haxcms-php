<?php
use PHPUnit\Framework\TestCase;

/**
 * Accessibility invariants for exported documents (haxtheweb/issues#3376):
 * exported HTML declares the site language and direction, and an embedded
 * video frame always carries a title. Mirrors haxcms-nodejs
 * test/unit/exports-a11y.test.cjs.
 */
class ExportA11yTest extends TestCase
{
    private function siteWithLang($lang)
    {
        $site = new stdClass();
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->settings = new stdClass();
        $site->manifest->metadata->site->settings->lang = $lang;
        return $site;
    }

    public function testUsesTheSiteLanguage(): void
    {
        $this->assertSame('es-MX', ExportConverters::getSiteExportLang($this->siteWithLang('es-MX')));
    }

    public function testFallsBackToEnglish(): void
    {
        $this->assertSame('en', ExportConverters::getSiteExportLang(null));
        $this->assertSame('en', ExportConverters::getSiteExportLang(new stdClass()));
        $this->assertSame('en', ExportConverters::getSiteExportLang($this->siteWithLang('')));
    }

    public function testKeepsOnlyBcp47Characters(): void
    {
        $this->assertSame('frscript', ExportConverters::getSiteExportLang($this->siteWithLang('fr"><script>')));
    }

    public function testRightToLeftLanguagesGetRtl(): void
    {
        $this->assertSame('lang="ar" dir="rtl"', ExportConverters::getExportLangDirAttributes('ar'));
        $this->assertSame('lang="he-IL" dir="rtl"', ExportConverters::getExportLangDirAttributes('he-IL'));
        $this->assertSame('lang="en" dir="ltr"', ExportConverters::getExportLangDirAttributes('en'));
    }

    public function testItemExportHtmlDeclaresSiteLanguage(): void
    {
        $item = new stdClass();
        $item->id = 'item-1';
        $item->title = 'Page';
        $html = ExportConverters::buildItemExportHtml($item, '<p>Body</p>', $this->siteWithLang('ar'));
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $html = ExportConverters::buildItemExportHtml($item, '<p>Body</p>');
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
    }

    public function testVideoFrameUsesVideoTitle(): void
    {
        $html = '<video-player source="https://www.youtube.com/watch?v=abc123" media-title="Intro to HAX"></video-player>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('title="Intro to HAX"', $out);
    }

    public function testVideoFrameFallsBackToGenericTitle(): void
    {
        $html = '<video-player source="https://www.youtube.com/watch?v=abc123"></video-player>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('title="Video"', $out);
    }

    public function testVideoFrameTitleIsEscaped(): void
    {
        $html = '<video-player source="https://www.youtube.com/watch?v=abc123" media-title="a &quot;b&quot; &lt;c&gt;"></video-player>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringNotContainsString('<c>', $out);
    }
}
