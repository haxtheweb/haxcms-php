<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the ExportConverters pure-transform seam.
 *
 * Expected values are derived from each transform's spec, not the
 * implementation: filename sanitization, file/document title fallbacks,
 * HTML escaping, media-type resolution, HTML→Markdown, body-inner extraction,
 * and item export HTML structure. FS-heavy methods (PDF/DOCX/EPUB binary
 * generation) are out of scope for this slice.
 */
class ExportConvertersTest extends TestCase
{
    public function testGetItemExportFormatsReturnsConstantSet(): void
    {
        $formats = ExportConverters::getItemExportFormats();
        $this->assertSame(['pdf', 'docx', 'html', 'md', 'json', 'yaml', 'xml', 'epub'], $formats);
    }

    public static function downloadFileNameProvider(): array
    {
        return [
            'plain' => ['my-site', 'my-site'],
            'spaces and punctuation' => ['My Site!', 'My-Site'],
            'slashes become hyphens' => ['a/b\\c', 'a-b-c'],
            'dots preserved in filenames' => ['file.name.txt', 'file.name.txt'],
            'padded whitespace trimmed' => ['  spaced  ', 'spaced'],
            'all-disallowed becomes fallback' => ['!!!', 'export'],
            'empty becomes fallback' => ['', 'export'],
            'custom fallback used' => ['!!!', 'custom', 'custom'],
        ];
    }

    #[DataProvider('downloadFileNameProvider')]
    public function testSanitizeDownloadFileName(string $input, string $expected, string $fallback = 'export'): void
    {
        $this->assertSame($expected, ExportConverters::sanitizeDownloadFileName($input, $fallback));
    }

    public function testGetSiteExportFileBaseNamePrefersSiteName(): void
    {
        $site = (object)array(
            'manifest' => (object)array(
                'title' => 'Display Title',
                'metadata' => (object)array('site' => (object)array('name' => 'machine-name')),
            ),
        );
        $this->assertSame('machine-name', ExportConverters::getSiteExportFileBaseName($site));
    }

    public function testGetSiteExportFileBaseNameFallsBackToManifestTitle(): void
    {
        $site = (object)array(
            'manifest' => (object)array('title' => 'Display Title!'),
        );
        $this->assertSame('Display-Title', ExportConverters::getSiteExportFileBaseName($site));
    }

    public function testGetSiteExportFileBaseNameFallsBackToSiteName(): void
    {
        $site = (object)array('name' => 'raw name');
        $this->assertSame('raw-name', ExportConverters::getSiteExportFileBaseName($site));
    }

    public function testGetSiteExportFileBaseNameFinalFallback(): void
    {
        $site = (object)array();
        $this->assertSame('site', ExportConverters::getSiteExportFileBaseName($site));
    }

    public function testGetItemExportFileBaseNamePrefersSlug(): void
    {
        $item = (object)array('slug' => 'my-page', 'title' => 'Title', 'id' => 'x');
        $this->assertSame('my-page', ExportConverters::getItemExportFileBaseName($item));
    }

    public function testGetItemExportFileBaseNameFallsBackToTitleThenId(): void
    {
        $this->assertSame('Title', ExportConverters::getItemExportFileBaseName((object)array('title' => 'Title', 'id' => 'x')));
        $this->assertSame('x', ExportConverters::getItemExportFileBaseName((object)array('id' => 'x')));
        $this->assertSame('item', ExportConverters::getItemExportFileBaseName((object)array()));
    }

    public function testBuildSiteExportDocumentTitlePrefersManifestTitle(): void
    {
        $site = (object)array('manifest' => (object)array('title' => 'My Site'), 'name' => 'other');
        $this->assertSame('My Site', ExportConverters::buildSiteExportDocumentTitle($site));
    }

    public function testBuildSiteExportDocumentTitleFallsBackToNameThenDefault(): void
    {
        $this->assertSame('raw', ExportConverters::buildSiteExportDocumentTitle((object)array('name' => 'raw')));
        $this->assertSame('Site export', ExportConverters::buildSiteExportDocumentTitle((object)array()));
    }

    public function testBuildItemExportDocumentTitleFallbacks(): void
    {
        $this->assertSame('Title', ExportConverters::buildItemExportDocumentTitle((object)array('title' => 'Title', 'slug' => 's', 'id' => 'i')));
        $this->assertSame('s', ExportConverters::buildItemExportDocumentTitle((object)array('slug' => 's', 'id' => 'i')));
        $this->assertSame('i', ExportConverters::buildItemExportDocumentTitle((object)array('id' => 'i')));
        $this->assertSame('Item export', ExportConverters::buildItemExportDocumentTitle((object)array()));
    }

    public function testEscapeHtmlValueEncodesSpecialChars(): void
    {
        $this->assertSame('a &amp; b', ExportConverters::escapeHtmlValue('a & b'));
        $this->assertSame('&quot;q&quot;', ExportConverters::escapeHtmlValue('"q"'));
        $this->assertSame("it&#039;s", ExportConverters::escapeHtmlValue("it's"));
        $this->assertSame('&lt;tag&gt;', ExportConverters::escapeHtmlValue('<tag>'));
        $this->assertSame('', ExportConverters::escapeHtmlValue(''));
    }

    public static function mediaTypeProvider(): array
    {
        return [
            'pdf' => ['pdf', 'application/pdf'],
            'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'html' => ['html', 'text/html; charset=utf-8'],
            'md' => ['md', 'text/markdown; charset=utf-8'],
            'json' => ['json', 'application/json; charset=utf-8'],
            'yaml' => ['yaml', 'application/yaml; charset=utf-8'],
            'xml' => ['xml', 'application/xml; charset=utf-8'],
            'epub' => ['epub', 'application/epub+zip'],
            'uppercase PDF' => ['PDF', 'application/pdf'],
            'unknown falls back' => ['totally-fake', 'application/octet-stream'],
            'empty falls back' => ['', 'application/octet-stream'],
        ];
    }

    #[DataProvider('mediaTypeProvider')]
    public function testResolveExportMediaType(string $format, string $expected): void
    {
        $this->assertSame($expected, ExportConverters::resolveExportMediaType($format));
    }

    public function testHtmlToMarkdownConvertsParagraph(): void
    {
        $md = ExportConverters::htmlToMarkdown('<p>Hi</p>');
        $this->assertSame('Hi', trim($md));
    }

    public function testHtmlToMarkdownReturnsRawOnFailure(): void
    {
        // empty string is not valid HTML; converter may return it unchanged
        $this->assertSame('', ExportConverters::htmlToMarkdown(''));
    }

    public function testExtractBodyInnerHtmlPullsBodyContents(): void
    {
        $inner = ExportConverters::extractBodyInnerHtml('<html><body><p>Hi</p></body></html>');
        $this->assertStringContainsString('<p>Hi</p>', $inner);
        $this->assertStringNotContainsString('<html', $inner);
        $this->assertStringNotContainsString('<body', $inner);
    }

    public function testExtractBodyInnerHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame('', ExportConverters::extractBodyInnerHtml(''));
    }

    public function testBuildItemExportHtmlProducesDocumentStructure(): void
    {
        $item = (object)array('id' => 'item-1', 'slug' => 'page-1', 'title' => 'Page One');
        $html = ExportConverters::buildItemExportHtml($item, '<p>Body</p>');
        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<title>Page One</title>', $html);
        $this->assertStringContainsString('data-haxcms-export="item"', $html);
        $this->assertStringContainsString('data-item-id="item-1"', $html);
        $this->assertStringContainsString('data-item-slug="page-1"', $html);
        $this->assertStringContainsString('<h1>Page One</h1>', $html);
        $this->assertStringContainsString('<p>Body</p>', $html);
    }

    public function testBuildItemExportHtmlEscapesTitleAndAttributes(): void
    {
        $item = (object)array('id' => 'a"&b', 'slug' => 's', 'title' => '<script>');
        $html = ExportConverters::buildItemExportHtml($item, '');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
