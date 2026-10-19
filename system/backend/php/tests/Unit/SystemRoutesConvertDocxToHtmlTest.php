<?php
/**
 * Characterization tests for lib/systemRoutes/v1/convertDocxToHtml.php.
 *
 * convertDocxToHtml.php defines UNGUARDED functions (haxcmsSystemConvertDocxXmlToHtml,
 * haxcmsSystemValidUrl, haxcmsSystemUrlMediaComponent, haxcmsSystemHtmlFromEl,
 * haxcmsSystemProcessDocxHtml) that are byte-identical to importDocx.php's, so the
 * two route files can never load in the same PHP process. Every test here therefore
 * runs as a separate process with global state not preserved (coverage merges back
 * into the parent clover report), and each test requires the route file fresh.
 *
 * Because the route include_once's imports/importUtils.php, the guarded
 * haxcmsImportConvertDocxXmlToHtml() exists whenever the closure runs — the local
 * fallback body of haxcmsSystemConvertDocxXmlToHtml is unreachable dead code.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS:
 *   - the getFromIndex-false branch ('Unable to read word/document.xml...'):
 *     a listed-but-unreadable archive entry cannot be crafted via ZipArchive.
 *   - the ZipArchive-unavailable else branch: the extension cannot be
 *     unloaded mid-process.
 *
 * Unlike importDocx.php, ALL error responses from this route use the
 * status/data.error envelope shape (no top-level message payloads).
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class SystemRoutesConvertDocxToHtmlTest extends TestCase
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
        $context->routeSuffix = 'v1/convertDocxToHtml';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/convertDocxToHtml.php';
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
        $path = tempnam(sys_get_temp_dir(), 'hax-cdx-');
        if ($content !== '') {
            file_put_contents($path, $content);
        }
        return $path;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoFileUploadReturns400ErrorPayload(): void
    {
        $result = $this->runRoute(array());
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            array('status' => 400, 'data' => array('error' => 'No file uploaded')),
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
            'Invalid file type. Expected .docx, got: doc.doc',
            $result['data']['data']['error']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMissingZipSignatureReturns400(): void
    {
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
            $result['data']['data']['error']
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
            $result['data']['data']['error']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSuccessReturnsProcessedContentsAndFilename(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Chapter One</w:t></w:r></w:p>'
                . '<w:p><w:r><w:t>Intro text.</w:t></w:r></w:p>'
            )
        );
        $result = $this->runRoute($this->upload($tmp, 'doc.docx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame(
            array(
                'status' => 200,
                'data' => array(
                    // processDocxHtml re-emits each wrapper child, so the
                    // converter's newlines do not survive.
                    'contents' => '<h1>Chapter One</h1><p>Intro text.</p>',
                    'filename' => 'doc.docx',
                ),
            ),
            $result['data']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEmptyDocumentYieldsEmptyContents(): void
    {
        $tmp = $this->buildDocx($this->tempFile(), $this->docXml(''));
        $result = $this->runRoute($this->upload($tmp, 'empty.docx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('', $result['data']['data']['contents']);
        $this->assertSame('empty.docx', $result['data']['data']['filename']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMediaMapDocxEmbedsDataUriImage(): void
    {
        $tmp = $this->buildDocx(
            $this->tempFile(),
            $this->docXml(
                '<w:p><w:r><w:drawing><a:blip r:embed="rId7"/></w:drawing></w:r></w:p>'
            ),
            array(
                'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
                    . '</Relationships>',
                'word/media/image1.png' => 'PNGDATA',
            )
        );
        $result = $this->runRoute($this->upload($tmp, 'pictures.docx'));
        $this->assertSame(200, $result['code']);
        // processDocxHtml's loadHTML/saveHTML round-trip normalizes the
        // converter's XHTML-style ' />' to '>'
        $this->assertSame(
            '<p><img src="data:image/png;base64,' . base64_encode('PNGDATA') . '" alt=""></p>',
            $result['data']['data']['contents']
        );
    }

    // ------------------------------------------------------------------
    // Direct helper characterization (own unguarded copies)
    // ------------------------------------------------------------------

    private function loadFile()
    {
        return require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/convertDocxToHtml.php';
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConvertDocxXmlToHtmlHeadingLevelsOneThroughSix(): void
    {
        $this->loadFile();
        $body = '';
        foreach (array(1, 2, 3, 4, 5, 6) as $level) {
            $body .= '<w:p><w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>'
                . '<w:r><w:t>L' . $level . '</w:t></w:r></w:p>';
        }
        $expected = "<h1>L1</h1>\n<h2>L2</h2>\n<h3>L3</h3>\n<h4>L4</h4>\n<h5>L5</h5>\n<h6>L6</h6>";
        $this->assertSame($expected, haxcmsSystemConvertDocxXmlToHtml($this->docXml($body)));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConvertDocxXmlToHtmlRunFormatting(): void
    {
        $this->loadFile();
        $body = '<w:p>'
            . '<w:r><w:rPr><w:b/></w:rPr><w:t>bo</w:t></w:r>'
            . '<w:r><w:rPr><w:i/></w:rPr><w:t>it</w:t></w:r>'
            . '<w:r><w:rPr><w:u/></w:rPr><w:t>un</w:t></w:r>'
            . '<w:r><w:rPr><w:strike/></w:rPr><w:t>st</w:t></w:r>'
            . '</w:p>';
        // Characterization quirk: underline is emitted as <em> (same as italic).
        $this->assertSame(
            '<p><strong>bo</strong><em>it</em><em>un</em><del>st</del></p>',
            haxcmsSystemConvertDocxXmlToHtml($this->docXml($body))
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConvertDocxXmlToHtmlEscapesRunText(): void
    {
        $this->loadFile();
        $body = '<w:p><w:r><w:t>a &lt; b &amp; c</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>say "hi"</w:t></w:r></w:p>';
        $this->assertSame(
            "<p>a &lt; b &amp; c</p>\n<p>say &quot;hi&quot;</p>",
            haxcmsSystemConvertDocxXmlToHtml($this->docXml($body))
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConvertDocxXmlToHtmlEmptyParagraphsAndOmittedEmptyHeadings(): void
    {
        $this->loadFile();
        // Empty paragraphs become <p></p>; heading-styled paragraphs with no
        // text are dropped entirely.
        $body = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr></w:p><w:p></w:p>';
        $this->assertSame(
            '<p></p>',
            haxcmsSystemConvertDocxXmlToHtml($this->docXml($body))
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConvertDocxXmlToHtmlEmbedsMediaMapImages(): void
    {
        $this->loadFile();
        $body = '<w:p><w:r><w:t>Before </w:t></w:r>'
            . '<w:r><w:drawing><a:blip r:embed="rId7"/></w:drawing></w:r></w:p>';
        $html = haxcmsSystemConvertDocxXmlToHtml(
            $this->docXml($body),
            array('rId7' => 'data:image/png;base64,QUJD')
        );
        $this->assertSame(
            '<p>Before <img src="data:image/png;base64,QUJD" alt="" /></p>',
            $html
        );
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProcessDocxHtmlHandlesEmptyAndTokens(): void
    {
        $this->loadFile();
        $this->assertSame('', haxcmsSystemProcessDocxHtml(''));
        $this->assertSame(
            '<video-player source="https://www.youtube.com/watch?v=abc123"></video-player>',
            haxcmsSystemProcessDocxHtml('<p>https://www.youtube.com/watch?v=abc123</p>')
        );
        $this->assertSame(
            '<p>a<lrn-math>x=y</lrn-math>c</p>',
            haxcmsSystemProcessDocxHtml('<p>a[math:x=y]c</p>')
        );
        $this->assertSame(
            '<p>plain text</p>',
            haxcmsSystemProcessDocxHtml('<p>plain text</p>')
        );
    }
}
