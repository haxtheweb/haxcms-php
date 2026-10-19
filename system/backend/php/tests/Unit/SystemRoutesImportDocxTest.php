<?php
/**
 * Characterization tests for lib/systemRoutes/v1/importDocx.php.
 *
 * importDocx.php defines UNGUARDED functions that are byte-identical to
 * convertDocxToHtml.php's (haxcmsSystemConvertDocxXmlToHtml, ValidUrl,
 * UrlMediaComponent, HtmlFromEl), so the two files can never load in the same
 * PHP process. Every test here therefore runs as a separate process with
 * global state not preserved (coverage merges back into the parent clover
 * report), and each test requires the route file fresh.
 *
 * Because the route include_once's imports/importUtils.php, the guarded
 * haxcmsImportConvertDocxXmlToHtml() exists whenever the closure runs — the
 * local fallback body of haxcmsSystemConvertDocxXmlToHtml is unreachable.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS:
 *   - the getFromIndex-false branch ('Unable to read word/document.xml...'):
 *     a listed-but-unreadable archive entry cannot be crafted via ZipArchive.
 *   - the ZipArchive-unavailable else branch: the extension cannot be
 *     unloaded mid-process.
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class SystemRoutesImportDocxTest extends TestCase
{
    private $savedFiles;

    protected function setUp(): void
    {
        $this->savedFiles = $_FILES;
    }

    protected function tearDown(): void
    {
        $_FILES = $this->savedFiles;
        http_response_code(200);
    }

    private function runRoute($files, $body = array())
    {
        $_FILES = $files;
        $context = (new ReflectionClass('SystemApiRequestContext'))
            ->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = 'v1/importDocx';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importDocx.php';
        $handler($context);
        $raw = ob_get_clean();
        return array(
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'code' => http_response_code(),
        );
    }

    private function upload($tmpName, $fileName)
    {
        return array(
            'upload' => array('name' => $fileName, 'tmp_name' => $tmpName, 'size' => 42),
        );
    }

    private function docXml($bodyXml)
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $bodyXml . '</w:body></w:document>';
    }

    private function buildDocx($path, $documentXml, array $extra = array())
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($documentXml !== null) {
            $zip->addFromString('word/document.xml', $documentXml);
        }
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    private function tempFile($content = '')
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-docx-');
        if ($content !== '') {
            file_put_contents($path, $content);
        }
        return $path;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoFileUploadReturns400MessagePayload(): void
    {
        $result = $this->runRoute(array());
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            array('status' => 400, 'message' => 'No file uploaded', 'code' => 'UPLOAD_MISSING'),
            $result['data']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInvalidExtensionReturns400(): void
    {
        $result = $this->runRoute($this->upload(__FILE__, 'doc.doc'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            array(
                'status' => 400,
                'message' => 'Invalid file type. Expected .docx, got: doc.doc',
                'code' => 'UPLOAD_WRONG_TYPE',
                'params' => array('expected' => '.docx', 'filename' => 'doc.doc'),
            ),
            $result['data']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMissingZipSignatureReturns400(): void
    {
        // Unlike the no-file/bad-ext gates, the PK guard responds with a
        // data.error shape rather than a top-level message.
        $tmp = $this->tempFile('GIF8 not a docx');
        $result = $this->runRoute($this->upload($tmp, 'doc.docx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file is not a valid .docx file (missing ZIP signature). If this is a .doc file, convert it to .docx first.',
            $result['data']['data']['error']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnopenableZipReturns400(): void
    {
        $tmp = $this->tempFile("PK\x03\x04 garbage not an archive");
        $result = $this->runRoute($this->upload($tmp, 'doc.docx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Unable to open uploaded file as a ZIP archive (invalid DOCX)',
            $result['data']['message']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testZipWithoutDocumentXmlReturns400(): void
    {
        $tmp = $this->buildDocx($this->tempFile(), null, array(
            'word/other.xml' => '<x/>',
        ));
        $result = $this->runRoute($this->upload($tmp, 'doc.docx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file does not contain a valid Word document structure',
            $result['data']['message']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPageMethodReturnsSingleItemWithConvertedContents(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Chapter One</w:t></w:r></w:p>'
                . '<w:p><w:r><w:t>Intro text.</w:t></w:r></w:p>'
            )
        );
        $result = $this->runRoute(
            $this->upload($tmp, 'document.docx'),
            array('method' => 'page')
        );
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('document.docx', $result['data']['data']['filename']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('document', $items[0]['title']);
        $this->assertSame('document', $items[0]['slug']);
        $this->assertSame('<h1>Chapter One</h1><p>Intro text.</p>', $items[0]['contents']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteMethodBuildsRootAndChildWithNestedSlug(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Root</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Child</w:t></w:r></w:p>'
                . '<w:p><w:r><w:t>Text under child.</w:t></w:r></w:p>'
            )
        );
        $result = $this->runRoute(
            $this->upload($tmp, 'doc.docx'),
            array('method' => 'site')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Root', $items[0]['title']);
        $this->assertSame('root', $items[0]['slug']);
        $this->assertSame('<p></p>', $items[0]['contents']);
        $this->assertSame('Child', $items[1]['title']);
        $this->assertSame('root/child', $items[1]['slug']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame(1, $items[1]['indent']);
        $this->assertSame('<p>Text under child.</p>', $items[1]['contents']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteMethodNoHeadingsJoinsEverythingIntoOneItem(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:r><w:t>Plain one.</w:t></w:r></w:p><w:p><w:r><w:t>Plain two.</w:t></w:r></w:p>'
            )
        );
        $result = $this->runRoute(
            $this->upload($tmp, 'doc.docx'),
            array('method' => 'site')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame(
            '<p>Plain one.</p><p>Plain two.</p>',
            $items[0]['contents']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteMethodEmptyRootUsesFallbackContentByType(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Solo</w:t></w:r></w:p>'
            )
        );
        $result = $this->runRoute(
            $this->upload($tmp, 'doc.docx'),
            array('method' => 'site', 'type' => 'portfolio')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame(haxcmsSystemGetFallbackContent('portfolio'), $items[0]['contents']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMediaUrlParagraphBecomesVideoPlayerToken(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:r><w:t>https://www.youtube.com/watch?v=abc123</w:t></w:r></w:p>'
            )
        );
        $result = $this->runRoute($this->upload($tmp, 'doc.docx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame(
            '<video-player source="https://www.youtube.com/watch?v=abc123"></video-player>',
            $result['data']['data']['items'][0]['contents']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMakeSlugPrefersHaxcmsCleanTitle(): void
    {
        $GLOBALS['HAXCMS'] = new class {
            public function cleanTitle($title)
            {
                return 'fixed-slug';
            }
        };
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml('<w:p><w:r><w:t>Whatever</w:t></w:r></w:p>')
        );
        $result = $this->runRoute($this->upload($tmp, 'document.docx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame('fixed-slug', $result['data']['data']['items'][0]['slug']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMakeSlugFallsBackToInlineRegex(): void
    {
        // No HAXCMS global: the inline normalization path runs.
        unset($GLOBALS['HAXCMS']);
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml('<w:p><w:r><w:t>Whatever</w:t></w:r></w:p>')
        );
        $result = $this->runRoute($this->upload($tmp, 'My Doc.docx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame('my-doc', $result['data']['data']['items'][0]['slug']);
        $this->assertSame('My Doc', $result['data']['data']['items'][0]['title']);
    }

    // ------------------------------------------------------------------
    // Direct helper characterization (own unguarded copies)
    // ------------------------------------------------------------------

    private function loadFile()
    {
        return require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importDocx.php';
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSimpleHtmlToElementsParsesTagsAndText(): void
    {
        $this->loadFile();
        $elements = haxcmsSystemSimpleHtmlToElements('<p>a</p><h1>B</h1>');
        $this->assertCount(2, $elements);
        $this->assertSame('P', $elements[0]['tagName']);
        $this->assertSame('<p>a</p>', $elements[0]['html']);
        $this->assertSame('a', $elements[0]['text']);
        $this->assertSame('H1', $elements[1]['tagName']);
        $this->assertSame('B', $elements[1]['text']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCollectSiblingsUntilStopsAtStopTag(): void
    {
        $this->loadFile();
        $elements = haxcmsSystemSimpleHtmlToElements('<p>one</p><h2>stop</h2><p>two</p>');
        $siblings = haxcmsSystemCollectSiblingsUntil($elements, 0, array('H2'));
        $this->assertCount(0, $siblings);
        // A stop tag that never appears collects to the end.
        $all = haxcmsSystemCollectSiblingsUntil($elements, 0, array('H3'));
        $this->assertCount(2, $all);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetHighestHeadingLevelScansOnlyLevelsOneToFour(): void
    {
        $this->loadFile();
        $this->assertSame(1, haxcmsSystemGetHighestHeadingLevel(
            haxcmsSystemSimpleHtmlToElements('<h1>x</h1><h3>y</h3>')
        ));
        $this->assertSame(3, haxcmsSystemGetHighestHeadingLevel(
            haxcmsSystemSimpleHtmlToElements('<h3>y</h3>')
        ));
        $this->assertNull(haxcmsSystemGetHighestHeadingLevel(
            haxcmsSystemSimpleHtmlToElements('<h5>too deep</h5><p>p</p>')
        ));
        $this->assertNull(haxcmsSystemGetHighestHeadingLevel(
            haxcmsSystemSimpleHtmlToElements('<p>no headings</p>')
        ));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetFallbackContentReturnsTypeSpecificMarkup(): void
    {
        $this->loadFile();
        $this->assertStringStartsWith(
            '<p>Enjoy my portfolio',
            haxcmsSystemGetFallbackContent('portfolio')
        );
        $this->assertStringStartsWith(
            '<p>Welcome to the lesson.</p>',
            haxcmsSystemGetFallbackContent('course')
        );
        $this->assertSame('<p></p>', haxcmsSystemGetFallbackContent('other'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValidUrlRequiresHttpSchemeAndValidUrl(): void
    {
        $this->loadFile();
        $this->assertTrue(haxcmsSystemValidUrl('https://example.com'));
        $this->assertTrue(haxcmsSystemValidUrl('  http://example.com  '));
        $this->assertFalse(haxcmsSystemValidUrl(''));
        $this->assertFalse(haxcmsSystemValidUrl('ftp://example.com'));
        $this->assertFalse(haxcmsSystemValidUrl('not a url'));
        $this->assertFalse(haxcmsSystemValidUrl('http://'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUrlMediaComponentMapsEachMediaType(): void
    {
        $this->loadFile();
        $this->assertSame(
            '<video-player source="https://youtube.com/watch?v=1"></video-player>',
            haxcmsSystemUrlMediaComponent('https://youtube.com/watch?v=1')
        );
        $this->assertSame(
            '<video-player source="https://x.com/a.mp4"></video-player>',
            haxcmsSystemUrlMediaComponent('https://x.com/a.mp4')
        );
        $this->assertSame(
            '<audio-player source="https://x.com/a.mp3"></audio-player>',
            haxcmsSystemUrlMediaComponent('https://x.com/a.mp3')
        );
        $this->assertSame(
            '<img src="https://x.com/a.png" loading="lazy" decoding="async" fetchpriority="high" alt="" />',
            haxcmsSystemUrlMediaComponent('https://x.com/a.png')
        );
        $this->assertStringContainsString(
            '<a11y-gif-player src="https://x.com/a.gif"',
            haxcmsSystemUrlMediaComponent('https://x.com/a.gif')
        );
        $this->assertSame(
            '<pdf-browser-viewer file="https://x.com/a.pdf" width="100%"></pdf-browser-viewer>',
            haxcmsSystemUrlMediaComponent('https://x.com/a.pdf')
        );
        // Ampersands in URLs are escaped for attribute safety.
        $this->assertSame(
            '<video-player source="https://youtube.com/watch?v=a&amp;b=c"></video-player>',
            haxcmsSystemUrlMediaComponent('https://youtube.com/watch?v=a&b=c')
        );
        $this->assertNull(haxcmsSystemUrlMediaComponent('https://example.com/page'));
        $this->assertNull(haxcmsSystemUrlMediaComponent('not a url'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHtmlFromElTokenConventions(): void
    {
        $this->loadFile();
        $this->assertSame('<lrn-math>x=y</lrn-math>', haxcmsSystemHtmlFromEl('[math:x=y]', ''));
        $this->assertSame('<lrn-math>x=y</lrn-math>', haxcmsSystemHtmlFromEl('[mathjax:x=y]', ''));
        $this->assertSame(
            '<place-holder type="video" text="my vid"></place-holder>',
            haxcmsSystemHtmlFromEl('[video:my vid]', '')
        );
        $this->assertSame(
            '<place-holder type="text" text="hello"></place-holder>',
            haxcmsSystemHtmlFromEl('[text:hello]', '')
        );
        // Bracket-wrapped URL falls back to the media component lookup.
        $this->assertSame(
            '<video-player source="https://youtu.be/x"></video-player>',
            haxcmsSystemHtmlFromEl('[https://youtu.be/x]', '')
        );
        // Unknown bracket token: text place-holder of the stripped content.
        $this->assertSame(
            '<place-holder type="text" text="unknown:z"></place-holder>',
            haxcmsSystemHtmlFromEl('[unknown:z]', '')
        );
        // !tag-name shortcut only for custom-element style names.
        $this->assertSame('<my-el></my-el>', haxcmsSystemHtmlFromEl('!my-el', ''));
        $this->assertSame('<p>kept</p>', haxcmsSystemHtmlFromEl('!Bad Tag', '<p>kept</p>'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHtmlFromElDefaultStripsTabsAndInlinesMath(): void
    {
        $this->loadFile();
        $this->assertSame(
            '<p>a<lrn-math>a+b</lrn-math>c</p>',
            haxcmsSystemHtmlFromEl('ignored', "<p>\ta[math:a+b]c</p>")
        );
    }
}
