<?php
/**
 * Tests for the Markdown / plain text and OpenDocument Text importers:
 * lib/MarkdownImport.php, lib/OdtToHtml.php and the importMd, importOdt and
 * convertOdtToHtml system routes.
 *
 * Parity: the twin of haxcms-nodejs test/unit/import-md-odt.test.cjs. The ODT
 * fixture and the expected HTML are the same strings, so a converter change
 * on either backend that breaks parity fails here or there.
 */

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once dirname(__DIR__, 2) . '/lib/OdtToHtml.php';
require_once dirname(__DIR__, 2) . '/lib/MarkdownImport.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class SystemRoutesImportMdOdtTest extends TestCase
{
    const NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        . 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
        . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
        . 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        . 'xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" '
        . 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
        . 'xmlns:xlink="http://www.w3.org/1999/xlink" '
        . 'xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"';

    // 1x1 transparent PNG
    const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    const STYLES_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles __NS__><office:styles>
<style:style style:name="Heading" style:family="paragraph"><style:text-properties fo:font-weight="bold"/></style:style>
<style:style style:name="Heading_20_1" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="1"/>
<style:style style:name="Heading_20_2" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="2"/>
<style:style style:name="Strong_20_Emphasis" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>
</office:styles></office:document-styles>
XML;

    const BODY_XML = <<<'XML'
<text:sequence-decls><text:sequence-decl text:name="Figure"/></text:sequence-decls>
<text:table-of-content><text:index-body><text:p>Unit One ..... 1</text:p></text:index-body></text:table-of-content>
<text:p text:style-name="P1">Before any heading.</text:p>
<text:h text:style-name="Heading_20_1" text:outline-level="1">Unit One</text:h>
<text:p>Plain <text:span text:style-name="Strong_20_Emphasis">bold</text:span>, <text:span text:style-name="T1">italic</text:span>, <text:span text:style-name="T2">under</text:span>, <text:span text:style-name="T3">gone</text:span>, x<text:span text:style-name="T4">2</text:span> &amp; &lt;tags&gt;<text:s text:c="2"/>end<text:line-break/>next<text:note text:note-class="footnote"><text:note-citation>1</text:note-citation><text:note-body><text:p>note</text:p></text:note-body></text:note>.</text:p>
<text:p><text:a xlink:href="https://hax.psu.edu">safe</text:a> and <text:a xlink:href="javascript:alert(1)">unsafe</text:a></text:p>
<text:p text:style-name="Heading_20_2">Styled as heading</text:p>
<text:list text:style-name="L1"><text:list-item><text:p>one</text:p></text:list-item><text:list-item><text:p>two</text:p></text:list-item></text:list>
<text:list text:style-name="L2"><text:list-item><text:list><text:list-item><text:p>nested</text:p></text:list-item></text:list></text:list-item></text:list>
<text:list text:style-name="L1" text:continue-numbering="true"><text:list-item><text:p>three</text:p></text:list-item></text:list>
<text:p/>
<table:table><table:table-column table:number-columns-repeated="2"/>
<table:table-header-rows><table:table-row><table:table-cell><text:p>Name</text:p></table:table-cell><table:table-cell><text:p>Value</text:p></table:table-cell></table:table-row></table:table-header-rows>
<table:table-row><table:table-cell table:number-columns-spanned="2"><text:p>Merged</text:p></table:table-cell><table:covered-table-cell/></table:table-row>
</table:table>
<text:p><draw:frame><draw:image xlink:href="Pictures/a.png"/><svg:title>A dot</svg:title></draw:frame></text:p>
<text:p><draw:frame><draw:image xlink:href="Pictures/a.svg"/></draw:frame></text:p>
<office:annotation><text:p>comment</text:p></office:annotation>
<text:section><text:h text:outline-level="1">Unit Two</text:h><text:p>Last.</text:p></text:section>
XML;

    const AUTO_STYLES = <<<'XML'
<office:automatic-styles>
<style:style style:name="T1" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>
<style:style style:name="T2" style:family="text"><style:text-properties style:text-underline-style="solid"/></style:style>
<style:style style:name="T3" style:family="text"><style:text-properties style:text-line-through-style="solid"/></style:style>
<style:style style:name="T4" style:family="text"><style:text-properties style:text-position="super 58%"/></style:style>
<style:style style:name="P1" style:family="paragraph" style:parent-style-name="Standard"/>
<text:list-style style:name="L1"><text:list-level-style-number text:level="1" style:num-format="1"/></text:list-style>
<text:list-style style:name="L2"><text:list-level-style-bullet text:level="1"/><text:list-level-style-bullet text:level="2"/></text:list-style>
</office:automatic-styles>
XML;

    private $savedFiles;
    private $tmpFiles = array();

    protected function setUp(): void
    {
        $this->savedFiles = $_FILES;
    }

    protected function tearDown(): void
    {
        $_FILES = $this->savedFiles;
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        http_response_code(200);
    }

    private function expectedHtml()
    {
        return "<p>Before any heading.</p>\n"
            . "<h1>Unit One</h1>\n"
            . "<p>Plain <strong>bold</strong>, <em>italic</em>, <em>under</em>, <del>gone</del>, x<sup>2</sup> &amp; &lt;tags&gt;  end<br>next.</p>\n"
            . "<p><a href=\"https://hax.psu.edu\">safe</a> and unsafe</p>\n"
            . "<h2>Styled as heading</h2>\n"
            . "<ol><li>one</li><li>two<ul><li>nested</li></ul></li><li>three</li></ol>\n"
            . "<table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td colspan=\"2\">Merged</td></tr></tbody></table>\n"
            . "<p><img src=\"data:image/png;base64," . self::PNG_BASE64 . "\" alt=\"A dot\"></p>\n"
            . "<h1>Unit Two</h1>\n"
            . "<p>Last.</p>";
    }

    private function tmpFile($contents)
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-import-');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function buildOdt($options = array())
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-odt-');
        $this->tmpFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', isset($options['mimetype']) ? $options['mimetype'] : 'application/vnd.oasis.opendocument.text');
        if (!isset($options['noContent'])) {
            $body = isset($options['body']) ? $options['body'] : self::BODY_XML;
            $zip->addFromString(
                'content.xml',
                '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NS . '>' . self::AUTO_STYLES
                    . '<office:body><office:text>' . $body . '</office:text></office:body></office:document-content>'
            );
            $zip->addFromString('styles.xml', str_replace('__NS__', self::NS, self::STYLES_XML));
            $zip->addFromString('Pictures/a.png', base64_decode(self::PNG_BASE64));
            $zip->addFromString('Pictures/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        }
        if (isset($options['raw'])) {
            $zip->addFromString('content.xml', $options['raw']);
        }
        $zip->close();
        return $path;
    }

    private function runRoute($file, $tmpName, $fileName, $body = array())
    {
        $_FILES = $tmpName === null ? array() : array(
            'upload' => array('name' => $fileName, 'tmp_name' => $tmpName, 'size' => 42),
        );
        $context = (new ReflectionClass('SystemApiRequestContext'))->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = 'v1/' . $file;
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/' . $file . '.php';
        $handler($context);
        $raw = ob_get_clean();
        return array('data' => json_decode($raw, true), 'code' => http_response_code());
    }

    public function testOdtHeadingsStylesListsTablesImagesSkips()
    {
        $this->assertSame($this->expectedHtml(), OdtToHtml::convertFile($this->buildOdt()));
    }

    public function testOdtAcceptsWriterVariants()
    {
        $this->assertSame(
            $this->expectedHtml(),
            OdtToHtml::convertFile($this->buildOdt(array('mimetype' => 'application/vnd.oasis.opendocument.text-web')))
        );
    }

    public function testOdtRejectsNonZip()
    {
        $this->expectExceptionMessageMatches('/ZIP signature/');
        OdtToHtml::convertFile($this->tmpFile('not a zip'));
    }

    public function testOdtRejectsMissingContent()
    {
        $this->expectExceptionMessageMatches('/content\.xml/');
        OdtToHtml::convertFile($this->buildOdt(array('noContent' => true)));
    }

    public function testOdtRejectsOtherOdfTypes()
    {
        $this->expectExceptionMessageMatches('/not an OpenDocument text file/');
        OdtToHtml::convertFile($this->buildOdt(array('mimetype' => 'application/vnd.oasis.opendocument.spreadsheet')));
    }

    public function testOdtRefusesEntityDeclarations()
    {
        $this->expectExceptionMessageMatches('/not valid OpenDocument XML/');
        OdtToHtml::convertFile($this->buildOdt(array(
            'noContent' => true,
            'raw' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><office:document-content ' . self::NS
                . '><office:body><office:text><text:p>&a;</text:p></office:text></office:body></office:document-content>',
        )));
    }

    public function testOdtNumberedHeadingsInsideAListStayHeadings()
    {
        $body = '<text:list text:style-name="L1"><text:list-item><text:h text:outline-level="1">First</text:h></text:list-item>'
            . '<text:list-item><text:h text:outline-level="1">Second</text:h><text:p>Body</text:p></text:list-item></text:list>';
        $this->assertSame(
            "<h1>First</h1>\n<h1>Second</h1>\n<p>Body</p>",
            OdtToHtml::convertFile($this->buildOdt(array('body' => $body)))
        );
    }

    public function testMarkdownFrontMatterBomCrlfAndWindows1252()
    {
        $decoded = MarkdownImport::decodeText("\xEF\xBB\xBF---\r\ntitle: \"My Notes\"\r\nauthor: me\r\n---\r\n# Hi\r\n");
        $this->assertFalse(strpos($decoded, "\r"));
        $split = MarkdownImport::splitFrontMatter($decoded);
        $this->assertSame('My Notes', $split['title']);
        $this->assertSame("# Hi\n", $split['body']);
        $this->assertSame('café', MarkdownImport::decodeText("caf\xE9"));
        $no = MarkdownImport::splitFrontMatter('# No front matter');
        $this->assertSame('', $no['title']);
        $this->assertMatchesRegularExpression('/class="language-js"/', MarkdownImport::render("```js\nx\n```"));
    }

    public function testImportMdBuildsPagesFromHeadingsAndStripsScripts()
    {
        $md = "---\ntitle: Notes\n---\n# Chapter One\n\nIntro.\n\n## Part A\n\nA text.\n\n# Chapter Two\n\n<script>alert(1)</script><p>ok</p>\n";
        $result = $this->runRoute('importMd', $this->tmpFile($md), 'notes.md');
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $shape = array();
        foreach ($items as $item) {
            $shape[] = array($item['title'], $item['slug'], $item['indent']);
        }
        $this->assertSame(
            array(array('Chapter One', 'chapter-one', 0), array('Part A', 'chapter-one/part-a', 1), array('Chapter Two', 'chapter-two', 0)),
            $shape
        );
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertFalse(strpos($items[2]['contents'], '<script'));
        $this->assertSame('notes.md', $result['data']['data']['filename']);
    }

    public function testImportMdTxtWithoutHeadingsIsOnePageNamedByTheFile()
    {
        $result = $this->runRoute('importMd', $this->tmpFile("Line one.\n\nLine two.\n"), 'My notes.txt');
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('My notes', $items[0]['title']);
        $this->assertSame('<p>Line one.</p><p>Line two.</p>', preg_replace('/>\s+</', '><', trim($items[0]['contents'])));
    }

    public function testImportMdFrontMatterTitleNamesASinglePage()
    {
        $result = $this->runRoute('importMd', $this->tmpFile("---\ntitle: Welcome\n---\nJust text.\n"), 'a.markdown');
        $this->assertSame('Welcome', $result['data']['data']['items'][0]['title']);
    }

    public function testImportMdRefusesWrongTypesEmptyAndBinary()
    {
        $this->assertSame(400, $this->runRoute('importMd', null, '')['code']);
        $wrong = $this->runRoute('importMd', $this->tmpFile('x'), 'a.odt');
        $this->assertSame(400, $wrong['code']);
        $this->assertMatchesRegularExpression('/Invalid file type/', $wrong['data']['data']['error']);
        $this->assertSame(400, $this->runRoute('importMd', $this->tmpFile(''), 'a.md')['code']);
        $binary = $this->runRoute('importMd', $this->tmpFile("PK\x00\x01"), 'a.txt');
        $this->assertSame(400, $binary['code']);
        $this->assertMatchesRegularExpression('/not a valid text file/', $binary['data']['data']['error']);
    }

    public function testImportOdtSiteBranchAndPageMethods()
    {
        $odt = $this->buildOdt();
        $site = $this->runRoute('importOdt', $odt, 'course.odt', array('method' => 'site'));
        $this->assertSame(200, $site['code']);
        $titles = array_map(function ($item) { return $item['title']; }, $site['data']['data']['items']);
        $this->assertSame(array('Unit One', 'Styled as heading', 'Unit Two'), $titles);
        $this->assertMatchesRegularExpression('/data:image\/png;base64/', $site['data']['data']['items'][1]['contents']);
        $branch = $this->runRoute('importOdt', $odt, 'course.odt', array('method' => 'branch', 'parentId' => 'p1'));
        $shape = array_map(function ($item) { return array($item['title'], $item['parent']); }, $branch['data']['data']['items']);
        $this->assertSame(array(array('Unit One', 'p1'), array('Unit Two', 'p1')), $shape);
        $page = $this->runRoute('importOdt', $odt, 'course.odt', array('method' => 'page'));
        $this->assertCount(1, $page['data']['data']['items']);
        $this->assertSame('course', $page['data']['data']['items'][0]['title']);
    }

    public function testImportOdtRefusesWrongTypesAndBrokenFiles()
    {
        $wrong = $this->runRoute('importOdt', $this->tmpFile('x'), 'a.docx');
        $this->assertSame(400, $wrong['code']);
        $this->assertMatchesRegularExpression('/Invalid file type/', $wrong['data']['data']['error']);
        $broken = $this->runRoute('importOdt', $this->tmpFile('not a zip'), 'a.odt');
        $this->assertSame(400, $broken['code']);
        $this->assertMatchesRegularExpression('/ZIP signature/', $broken['data']['data']['error']);
    }

    public function testConvertOdtToHtmlReturnsOneHtmlString()
    {
        $result = $this->runRoute('convertOdtToHtml', $this->buildOdt(), 'a.odt');
        $this->assertSame(200, $result['code']);
        $this->assertSame($this->expectedHtml(), $result['data']['data']['contents']);
        $this->assertSame('a.odt', $result['data']['data']['filename']);
    }

    // ---- OpenDocument Presentation (.odp) ----
    // Same fixture and expected HTML as the Node test.

    const ODP_SLIDES = <<<'XML'
<draw:page><draw:frame presentation:class="title"><draw:text-box><text:p>Course Intro</text:p></draw:text-box></draw:frame><draw:frame presentation:class="subtitle"><draw:text-box><text:p>Welcome</text:p></draw:text-box></draw:frame><presentation:notes><draw:frame presentation:class="notes"><draw:text-box><text:p>Speaker notes</text:p></draw:text-box></draw:frame></presentation:notes></draw:page><draw:page><draw:frame presentation:class="title"><draw:text-box><text:p>Unit One</text:p></draw:text-box></draw:frame><draw:frame presentation:class="outline"><draw:text-box><text:list text:style-name="L2"><text:list-item><text:p>First</text:p></text:list-item></text:list><text:list text:style-name="L2"><text:list-item><text:list><text:list-item><text:p>Nested</text:p></text:list-item></text:list></text:list-item></text:list></draw:text-box></draw:frame><draw:frame presentation:class="page-number"><draw:text-box><text:p>2</text:p></draw:text-box></draw:frame></draw:page><draw:page><draw:frame presentation:class="title"><draw:text-box><text:p>Table slide</text:p></draw:text-box></draw:frame><draw:frame><table:table><table:table-row><table:table-cell><text:p>A</text:p></table:table-cell><table:table-cell><text:p>1</text:p></table:table-cell></table:table-row></table:table><draw:image xlink:href="Pictures/preview.svm"/></draw:frame></draw:page><draw:page><draw:g><draw:custom-shape><text:p>Grouped heading</text:p><text:p><text:span text:style-name="T2"><text:a xlink:href="https://hax.psu.edu">a link</text:a></text:span></text:p></draw:custom-shape></draw:g></draw:page><draw:page><draw:frame><draw:image xlink:href="Pictures/a.png"/><svg:desc>A dot</svg:desc></draw:frame></draw:page><draw:page/>
XML;

    private function odpExpected()
    {
        return "<h1>Course Intro</h1>\n"
            . "<p>Welcome</p>\n"
            . "<h1>Unit One</h1>\n"
            . "<ul><li>First<ul><li>Nested</li></ul></li></ul>\n"
            . "<h1>Table slide</h1>\n"
            . "<table><tbody><tr><td>A</td><td>1</td></tr></tbody></table>\n"
            . "<h1>Grouped heading</h1>\n"
            . "<p><a href=\"https://hax.psu.edu\">a link</a></p>\n"
            . "<h1>Slide 5</h1>\n"
            . "<p><img src=\"data:image/png;base64," . self::PNG_BASE64 . "\" alt=\"A dot\"></p>";
    }

    private function buildOdp($mimetype = 'application/vnd.oasis.opendocument.presentation')
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-odp-');
        $this->tmpFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', $mimetype);
        $zip->addFromString(
            'content.xml',
            '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NS
                . ' xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0">' . self::AUTO_STYLES
                . '<office:body><office:presentation>' . trim(self::ODP_SLIDES) . '</office:presentation></office:body></office:document-content>'
        );
        $zip->addFromString('styles.xml', str_replace('__NS__', self::NS, self::STYLES_XML));
        $zip->addFromString('Pictures/a.png', base64_decode(self::PNG_BASE64));
        $zip->addFromString('Pictures/preview.svm', 'not an image');
        $zip->close();
        return $path;
    }

    public function testOdpOneH1PerSlideNotesAndEmptySlidesLeftOut()
    {
        $this->assertSame($this->odpExpected(), OdtToHtml::convertOdpFile($this->buildOdp()));
    }

    public function testOdpRefusesAnOdt()
    {
        $this->expectExceptionMessageMatches('/not an OpenDocument presentation file/');
        OdtToHtml::convertOdpFile($this->buildOdp('application/vnd.oasis.opendocument.text'));
    }

    public function testOdpRefusesNonZip()
    {
        $this->expectExceptionMessageMatches('/valid \\.odp file/');
        OdtToHtml::convertOdpFile($this->tmpFile('nope'));
    }

    public function testImportOdpEachSlideIsAPage()
    {
        $result = $this->runRoute('importOdp', $this->buildOdp(), 'deck.odp');
        $this->assertSame(200, $result['code']);
        $titles = array_map(function ($item) { return $item['title']; }, $result['data']['data']['items']);
        $this->assertSame(array('Course Intro', 'Unit One', 'Table slide', 'Grouped heading', 'Slide 5'), $titles);
        $wrong = $this->runRoute('importOdp', $this->tmpFile('x'), 'deck.pptx');
        $this->assertSame(400, $wrong['code']);
        $this->assertMatchesRegularExpression('/Expected \\.odp/', $wrong['data']['data']['error']);
    }

    public function testConvertOdpToHtmlReturnsTheDeckAsOneHtmlString()
    {
        $result = $this->runRoute('convertOdpToHtml', $this->buildOdp(), 'deck.odp');
        $this->assertSame(200, $result['code']);
        $this->assertSame($this->odpExpected(), $result['data']['data']['contents']);
    }

    // ---- OpenDocument Spreadsheet (.ods) through the spreadsheet importer ----

    private function buildOds()
    {
        $cell = function ($text) {
            return '<table:table-cell office:value-type="string"><text:p>' . $text . '</text:p></table:table-cell>';
        };
        $row = function ($cells) use ($cell) {
            return '<table:table-row>' . implode('', array_map($cell, $cells)) . '</table:table-row>';
        };
        $path = tempnam(sys_get_temp_dir(), 'hax-ods-');
        $this->tmpFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->addFromString(
            'content.xml',
            '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NS . '><office:body><office:spreadsheet><table:table table:name="Outline">'
                . $row(array('Title', 'Slug', 'Parent', 'Content'))
                . $row(array('Unit One', 'unit-one', '', '&lt;p&gt;One&lt;/p&gt;'))
                . $row(array('Lesson A', 'lesson-a', 'unit-one', '&lt;p&gt;A&lt;/p&gt;'))
                . '</table:table></office:spreadsheet></office:body></office:document-content>'
        );
        $zip->addFromString('styles.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . self::NS . '/>');
        $zip->addFromString('meta.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.2"><office:meta/></office:document-meta>');
        $zip->addFromString(
            'META-INF/manifest.xml',
            '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>'
        );
        $zip->close();
        return $path;
    }

    public function testImportXlsxReadsAnOdsOutlineLikeAnXlsxOne()
    {
        $result = $this->runRoute('importXlsx', $this->buildOds(), 'outline.ods');
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $shape = array_map(function ($item) {
            return array($item['title'], $item['slug'], $item['indent'], $item['contents']);
        }, $items);
        $this->assertSame(array(array('Unit One', 'unit-one', 0, '<p>One</p>'), array('Lesson A', 'lesson-a', 1, '<p>A</p>')), $shape);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
    }
}
