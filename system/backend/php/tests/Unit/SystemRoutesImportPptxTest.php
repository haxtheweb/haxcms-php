<?php
/**
 * Characterization tests for lib/systemRoutes/v1/importPptx.php.
 *
 * The route file is loaded with require (never require_once) so handler()
 * always receives the returned route closure: require_once returns true when
 * another test file (FormatConvertersFileTest, via convertPptxToHtml.php)
 * has already loaded importPptx.php in the same process. Re-execution is
 * safe because haxcmsSystemExtractPptxToHtml() is function_exists-guarded
 * and the include_once dependencies at the top of the file are idempotent.
 *
 * Fixtures are real zip archives built in-process with ZipArchive — the same
 * extension the extractor relies on.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS:
 *   - 'ZipArchive extension is not available' (extractor line 16-18): the
 *     extension cannot be unloaded mid-process.
 *   - the getFromName-false `continue` inside the slide loop: a listed-but
 *     unreadable slide entry cannot be crafted through ZipArchive writes.
 */

use PHPUnit\Framework\TestCase;

class SystemRoutesImportPptxTest extends TestCase
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

    private static $handler = null;

    private function handler()
    {
        // require (not require_once) always executes the route file and
        // returns its closure; require_once would return true when another
        // test file (FormatConvertersFileTest, via convertPptxToHtml.php)
        // already loaded importPptx.php in this process. Re-execution is
        // safe: the extractor function is function_exists-guarded and the
        // file's include_once dependencies are idempotent.
        if (self::$handler === null) {
            self::$handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importPptx.php';
        }
        return self::$handler;
    }

    private function runRoute($files, $body = array())
    {
        $_FILES = $files;
        $context = (new ReflectionClass('SystemApiRequestContext'))
            ->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = 'v1/importPptx';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $this->handler()($context);
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

    private function tempFile($content, $suffix = '')
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-pptx-') . $suffix;
        file_put_contents($path, $content);
        return $path;
    }

    private static $slideNs = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';

    private function buildPptx($path, array $slides, array $extra = array())
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        foreach ($slides as $name => $xml) {
            $zip->addFromString('ppt/slides/' . $name, $xml);
        }
        foreach ($extra as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        return $path;
    }

    private function slideXml(array $shapes)
    {
        $xml = '<?xml version="1.0"?><p:sld ' . self::$slideNs . '>';
        foreach ($shapes as $shape) {
            $xml .= '<p:sp>';
            if (isset($shape['phType'])) {
                $attr = ($shape['nsAttr'] ?? false) ? 'p:type' : 'type';
                $xml .= '<p:nvSpPr><p:nvPr><p:ph ' . $attr . '="' . $shape['phType'] . '"/></p:nvPr></p:nvSpPr>';
            } else {
                $xml .= '<p:nvSpPr><p:nvPr></p:nvPr></p:nvSpPr>';
            }
            $xml .= '<p:txBody>';
            foreach ($shape['runs'] ?? array() as $run) {
                $xml .= '<a:p><a:r><a:t>' . $run . '</a:t></a:r></a:p>';
            }
            $xml .= '</p:txBody></p:sp>';
        }
        return $xml . '</p:sld>';
    }

    public function testNoFileUploadReturns400(): void
    {
        $result = $this->runRoute(array());
        $this->assertSame(400, $result['code']);
        $this->assertSame('No file uploaded', $result['data']['data']['error']);
        $this->assertNull($result['data']['data']['filename']);
    }

    public function testInvalidExtensionReturns400(): void
    {
        $result = $this->runRoute($this->upload(__FILE__, 'deck.ppt'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Invalid file type. Expected .pptx, got: deck.ppt',
            $result['data']['data']['error']
        );
    }

    public function testMissingZipSignatureReturns400(): void
    {
        $tmp = $this->tempFile('GIF8 not a pptx');
        $result = $this->runRoute($this->upload($tmp, 'deck.pptx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file is not a valid .pptx file (missing ZIP signature)',
            $result['data']['data']['error']
        );
    }

    public function testTooShortSignatureReturns400(): void
    {
        // Only 'PK' present: the strlen < 4 guard rejects before the PK check.
        $tmp = $this->tempFile('PK');
        $result = $this->runRoute($this->upload($tmp, 'deck.pptx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file is not a valid .pptx file (missing ZIP signature)',
            $result['data']['data']['error']
        );
    }

    public function testUnopenableZipReturns400ViaCatch(): void
    {
        // PK signature but a garbage body: passes the magic check, fails
        // ZipArchive::open, and the route catch rewraps the RuntimeException.
        $tmp = $this->tempFile("PK\x03\x04 garbage not an archive");
        $result = $this->runRoute($this->upload($tmp, 'deck.pptx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Error processing PPTX import: Unable to open PPTX as ZIP archive',
            $result['data']['data']['error']
        );
    }

    public function testSuccessBuildsItemsFromSlidesInNumericOrder(): void
    {
        $slides = array(
            // Deliberately out of numeric order in the archive.
            'slide2.xml' => $this->slideXml(array(
                array('phType' => 'ctrTitle', 'nsAttr' => true, 'runs' => array('Namespaced Title')),
                array('runs' => array('Plain paragraph')),
                array('runs' => array()), // empty shape is skipped
            )),
            'slide1.xml' => $this->slideXml(array(
                array('phType' => 'title', 'runs' => array('Deck &amp; Title')),
                array('phType' => 'body', 'runs' => array('Body text')),
            )),
        );
        $tmp = $this->buildPptx($this->tempFile(''), $slides);
        $result = $this->runRoute($this->upload($tmp, 'deck.pptx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('deck.pptx', $result['data']['data']['filename']);
        $items = $result['data']['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Deck & Title', $items[0]['title']);
        $this->assertSame('<p>Body text</p>', $items[0]['contents']);
        $this->assertSame('Namespaced Title', $items[1]['title']);
        $this->assertSame('<p>Plain paragraph</p>', $items[1]['contents']);
    }

    public function testSuccessPassesMethodTypeAndParentIdThrough(): void
    {
        $slides = array(
            'slide1.xml' => $this->slideXml(array(
                array('phType' => 'title', 'runs' => array('Solo Slide')),
            )),
        );
        $tmp = $this->buildPptx($this->tempFile(''), $slides);
        $result = $this->runRoute(
            $this->upload($tmp, 'deck.pptx'),
            array('method' => 'page', 'parentId' => 'site-x', 'type' => 'course')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('deck', $items[0]['title']);
        $this->assertSame('site-x', $items[0]['parent']);
    }

    public function testZipWithoutSlidesProducesSingleBlankItem(): void
    {
        $tmp = $this->buildPptx($this->tempFile(''), array());
        $result = $this->runRoute($this->upload($tmp, 'empty.pptx'));
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('empty', $items[0]['title']);
        $this->assertSame('<p></p>', $items[0]['contents']);
    }

    public function testExtractorProducesHeadingsParagraphsAndEscaping(): void
    {
        // Direct extractor characterization: h2 for title/ctrTitle (both
        // attribute serializations), p for plain shapes, htmlspecialchars
        // escaping, numeric slide ordering, non-slide entries ignored.
        $slides = array(
            'slide10.xml' => $this->slideXml(array(
                // Runs are inserted verbatim into the slide XML, so the fixture
                // pre-escapes; the extractor re-escapes when emitting HTML.
                array('phType' => 'title', 'runs' => array('Ten &amp; &lt;More&gt;')),
            )),
            'slide2.xml' => $this->slideXml(array(
                array('runs' => array('two')),
            )),
            'slide1.xml' => $this->slideXml(array(
                array('phType' => 'ctrTitle', 'nsAttr' => true, 'runs' => array('One')),
            )),
        );
        $tmp = $this->buildPptx($this->tempFile(''), $slides, array(
            'docProps/app.xml' => '<props/>',
        ));
        $this->handler(); // ensure the extractor function is defined
        $html = haxcmsSystemExtractPptxToHtml($tmp);
        $this->assertSame(
            "<h2>One</h2>\n<p>two</p>\n<h2>Ten &amp; &lt;More&gt;</h2>",
            $html
        );
    }

    public function testExtractorConcatenatesMultipleRunsPerShape(): void
    {
        $slides = array(
            'slide1.xml' => $this->slideXml(array(
                array('runs' => array('Hello ', 'World')),
            )),
        );
        $tmp = $this->buildPptx($this->tempFile(''), $slides);
        $this->handler(); // ensure the extractor function is defined
        $this->assertSame('<p>Hello World</p>', haxcmsSystemExtractPptxToHtml($tmp));
    }
}
