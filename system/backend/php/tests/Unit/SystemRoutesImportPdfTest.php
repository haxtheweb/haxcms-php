<?php
/**
 * Characterization tests for lib/systemRoutes/v1/importPdf.php.
 *
 * All 8 helper functions are function_exists-guarded, so the file can be
 * required fresh per test. Success paths parse a hand-built minimal PDF whose
 * content stream uses BT/Tf/Td/Tj operators so Smalot\PdfParser's getDataTm()
 * (with setDataTmFontInfoHasToBeIncluded) yields per-run font sizes — the
 * same shape the font-size heuristic expects from real PDFs.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS:
 *   - none significant; the corrupt-PDF test reaches the catch tail.
 */

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

class SystemRoutesImportPdfTest extends TestCase
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
        $context->routeSuffix = 'v1/importPdf';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importPdf.php';
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

    private function tempFile($content)
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-pdf-');
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Build a minimal single-page PDF: catalog, pages, one page, one font per
     * distinct size, and a content stream object. Byte offsets are computed
     * exactly so the xref table is valid.
     *
     * @param array $blocks Each block: text (no parens), size, x, y.
     */
    private function buildPdf(array $blocks)
    {
        $fontObjs = array();
        $nextObj = 4;
        foreach ($blocks as $b) {
            if (!isset($fontObjs[$b['size']])) {
                $fontObjs[$b['size']] = $nextObj++;
            }
        }
        $contentObj = $nextObj++;
        $fontRefs = array();
        foreach ($fontObjs as $num) {
            $fontRefs[] = '/F' . $num . ' ' . $num . ' 0 R';
        }
        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << '
                . implode(' ', $fontRefs) . ' >> >> /Contents ' . $contentObj . ' 0 R >>',
        );
        foreach ($fontObjs as $num) {
            $objects[$num] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        }
        $stream = '';
        foreach ($blocks as $b) {
            $stream .= 'BT /F' . $fontObjs[$b['size']] . ' ' . $b['size'] . ' Tf '
                . $b['x'] . ' ' . $b['y'] . ' Td (' . $b['text'] . ') Tj ET ';
        }
        $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = array();
        for ($i = 1; $i <= $contentObj; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($contentObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $contentObj; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($contentObj + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }

    private function sampleBlocks()
    {
        return array(
            array('text' => 'Big Title Line', 'size' => 24, 'x' => 72, 'y' => 700),
            array('text' => 'Body paragraph text.', 'size' => 12, 'x' => 72, 'y' => 650),
            array('text' => 'Second body line', 'size' => 12, 'x' => 72, 'y' => 630),
            array('text' => 'Sub Heading', 'size' => 18, 'x' => 72, 'y' => 600),
        );
    }

    /**
     * Generate real, minimal JPEG bytes (32x32) via GD so the DCTDecode
     * fixture is a valid image, not just arbitrary bytes.
     */
    private function minimalJpegBytes()
    {
        $image = imagecreatetruecolor(32, 32);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 60, 200));
        $path = tempnam(sys_get_temp_dir(), 'hax-pdf-img-');
        imagejpeg($image, $path, 80);
        imagedestroy($image);
        $bytes = file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    /**
     * Build a single-page PDF like buildPdf(), but also add an Image XObject
     * to the page's /Resources with the given /Filter name and stream bytes,
     * so Page::getXObjects() resolves a real Smalot\PdfParser\XObject\Image.
     */
    private function buildPdfWithImage(array $blocks, $filterName, $imageBytes, $width, $height)
    {
        $fontObjs = array();
        $nextObj = 4;
        foreach ($blocks as $b) {
            if (!isset($fontObjs[$b['size']])) {
                $fontObjs[$b['size']] = $nextObj++;
            }
        }
        $contentObj = $nextObj++;
        $imageObj = $nextObj++;
        $fontRefs = array();
        foreach ($fontObjs as $num) {
            $fontRefs[] = '/F' . $num . ' ' . $num . ' 0 R';
        }
        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << '
                . implode(' ', $fontRefs) . ' >> /XObject << /Im1 ' . $imageObj . ' 0 R >> >> /Contents ' . $contentObj . ' 0 R >>',
        );
        foreach ($fontObjs as $num) {
            $objects[$num] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        }
        $stream = '';
        foreach ($blocks as $b) {
            $stream .= 'BT /F' . $fontObjs[$b['size']] . ' ' . $b['size'] . ' Tf '
                . $b['x'] . ' ' . $b['y'] . ' Td (' . $b['text'] . ') Tj ET ';
        }
        $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $objects[$imageObj] = '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /' . $filterName
            . ' /Length ' . strlen($imageBytes) . " >>\nstream\n" . $imageBytes . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = array();
        for ($i = 1; $i <= $imageObj; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($imageObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $imageObj; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($imageObj + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
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
        $result = $this->runRoute($this->upload(__FILE__, 'notes.txt'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Invalid file type. Expected .pdf, got: notes.txt',
            $result['data']['data']['error']
        );
    }

    public function testMissingPdfSignatureReturns400(): void
    {
        $tmp = $this->tempFile('GIF8 not a pdf');
        $result = $this->runRoute($this->upload($tmp, 'doc.pdf'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file is not a valid PDF',
            $result['data']['data']['error']
        );
    }

    public function testSuccessBranchMethodProducesSingleRootItem(): void
    {
        $tmp = $this->tempFile($this->buildPdf($this->sampleBlocks()));
        $result = $this->runRoute(
            $this->upload($tmp, 'doc.pdf'),
            array('method' => 'branch')
        );
        $this->assertSame(200, $result['code']);
        $this->assertSame('doc.pdf', $result['data']['data']['filename']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Big Title Line', $items[0]['title']);
        $this->assertStringContainsString('<p>Body paragraph text.</p>', $items[0]['contents']);
        $this->assertStringContainsString('<p>Second body line</p>', $items[0]['contents']);
        $this->assertStringContainsString('<h2>Sub Heading</h2>', $items[0]['contents']);
    }

    public function testSuccessSiteMethodBuildsRootAndChild(): void
    {
        $tmp = $this->tempFile($this->buildPdf($this->sampleBlocks()));
        $result = $this->runRoute($this->upload($tmp, 'doc.pdf'));
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Big Title Line', $items[0]['title']);
        $this->assertSame('big-title-line', $items[0]['slug']);
        $this->assertSame('Sub Heading', $items[1]['title']);
        $this->assertSame('big-title-line/sub-heading', $items[1]['slug']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame(1, $items[1]['indent']);
    }

    public function testPdfWithoutTextProducesSingleBlankItem(): void
    {
        // A page with no text operators yields no lines, so the converter
        // falls back to '<p></p>' and the route still returns one item.
        $tmp = $this->tempFile($this->buildPdf(array()));
        $result = $this->runRoute($this->upload($tmp, 'blank.pdf'));
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('<p></p>', $items[0]['contents']);
    }

    public function testCorruptPdfReturns400ViaCatch(): void
    {
        $tmp = $this->tempFile("%PDF-1.4\ncorrupted payload with no objects");
        $result = $this->runRoute($this->upload($tmp, 'doc.pdf'));
        $this->assertSame(400, $result['code']);
        $this->assertStringStartsWith(
            'Error processing PDF import:',
            $result['data']['data']['error']
        );
        $this->assertSame(array(), $result['data']['data']['items']);
    }

    // ------------------------------------------------------------------
    // Direct helper characterization (all function_exists-guarded)
    // ------------------------------------------------------------------

    private function loadFile()
    {
        return require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importPdf.php';
    }

    public function testNormalizePdfFontSizeStringKeysPreservePrecision(): void
    {
        $this->loadFile();
        $this->assertSame('13.6', haxcmsSystemNormalizePdfFontSize(13.56));
        $this->assertSame('12.0', haxcmsSystemNormalizePdfFontSize(12));
    }

    public function testGetPdfHeadingLevelMatchesWithinTolerance(): void
    {
        $this->loadFile();
        $sizes = array('h1' => 24.0, 'h2' => 18.0, 'h3' => 15.0);
        $this->assertSame('h1', haxcmsSystemGetPdfHeadingLevel(24.2, $sizes, 0.75));
        $this->assertSame('h2', haxcmsSystemGetPdfHeadingLevel(17.5, $sizes, 0.75));
        $this->assertNull(haxcmsSystemGetPdfHeadingLevel(12.0, $sizes, 0.75));
    }

    public function testPdfLooksLikeHeadingRejectsLongSentencesAndPunctuation(): void
    {
        $this->loadFile();
        $this->assertTrue(haxcmsSystemPdfLooksLikeHeading('Good Heading', 16, 140));
        $this->assertFalse(haxcmsSystemPdfLooksLikeHeading('Ends with period.', 16, 140));
        // 17 words exceeds the maxWords cap.
        $this->assertFalse(
            haxcmsSystemPdfLooksLikeHeading(
                'One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen',
                16,
                140
            )
        );
        $this->assertFalse(haxcmsSystemPdfLooksLikeHeading(str_repeat('word ', 30), 16, 140));
    }

    public function testGetPdfListMatchDetectsBulletsAndOrderedMarkers(): void
    {
        $this->loadFile();
        $this->assertSame(
            array('listType' => 'ul', 'content' => 'item one'),
            haxcmsSystemGetPdfListMatch('- item one')
        );
        $this->assertSame(
            array('listType' => 'ul', 'content' => 'bullet text'),
            haxcmsSystemGetPdfListMatch("\u{2022} bullet text")
        );
        $this->assertSame(
            array('listType' => 'ol', 'content' => 'first'),
            haxcmsSystemGetPdfListMatch('1. first')
        );
        $this->assertSame(
            array('listType' => 'ol', 'content' => 'second'),
            haxcmsSystemGetPdfListMatch('(2) second')
        );
        $this->assertNull(haxcmsSystemGetPdfListMatch('plain text'));
    }

    public function testShouldMergePdfParagraphRules(): void
    {
        $this->loadFile();
        // Current line lacks terminal punctuation: always merge.
        $this->assertTrue(haxcmsSystemShouldMergePdfParagraph('Hello', 'World'));
        // Sentence ended but next line starts lowercase: continue it.
        $this->assertTrue(haxcmsSystemShouldMergePdfParagraph('Hello.', 'world'));
        // Sentence ended and next line starts a new sentence: do not merge.
        $this->assertFalse(haxcmsSystemShouldMergePdfParagraph('Hello.', 'World'));
    }

    public function testEscapePdfHtmlHealsSplitTokensAndEscapes(): void
    {
        $this->loadFile();
        $this->assertSame('Action Items', haxcmsSystemEscapePdfHtml('Action Item s'));
        $this->assertSame('a &lt; b &amp; c', haxcmsSystemEscapePdfHtml('a < b & c'));
        // A missing space (as opposed to an extra one) is left untouched.
        $this->assertSame('w ill', haxcmsSystemEscapePdfHtml('w ill'));
    }

    public function testConvertPdfMergesContinuationLinesIntoOneParagraph(): void
    {
        $this->loadFile();
        $path = $this->tempFile($this->buildPdf(array(
            array('text' => 'Body paragraph text.', 'size' => 12, 'x' => 72, 'y' => 650),
            array('text' => 'and more text', 'size' => 12, 'x' => 72, 'y' => 630),
        )));
        $this->assertSame(
            '<p>Body paragraph text. and more text</p>',
            haxcmsSystemConvertPdfToSemanticHtml($path)
        );
    }

    public function testConvertPdfEmitsListItemsFromMarkers(): void
    {
        $this->loadFile();
        $path = $this->tempFile($this->buildPdf(array(
            array('text' => '- first bullet', 'size' => 12, 'x' => 72, 'y' => 650),
            array('text' => '2. ordered item', 'size' => 12, 'x' => 72, 'y' => 630),
        )));
        // Lists are emitted as their list type from GetPdfListMatch via
        // GetPdfLineInfo: the html for list lines carries the marker text.
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertStringContainsString('first bullet', $html);
        $this->assertStringContainsString('ordered item', $html);
    }

    public function testConvertPdfEmitsImgTagForDctDecodeImage(): void
    {
        $this->loadFile();
        $jpeg = $this->minimalJpegBytes();
        $path = $this->tempFile($this->buildPdfWithImage(
            array(array('text' => 'Body paragraph text.', 'size' => 12, 'x' => 72, 'y' => 650)),
            'DCTDecode',
            $jpeg,
            32,
            32
        ));
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertStringContainsString(
            '<img src="data:image/jpeg;base64,' . base64_encode($jpeg) . '">',
            $html
        );
        // Image is appended after the page's text content.
        $this->assertTrue(
            strpos($html, '<p>Body paragraph text.</p>') < strpos($html, '<img src="data:image/jpeg;base64,')
        );
    }

    public function testConvertPdfSkipsNonDctDecodeImageGracefully(): void
    {
        $this->loadFile();
        $path = $this->tempFile($this->buildPdfWithImage(
            array(array('text' => 'Body paragraph text.', 'size' => 12, 'x' => 72, 'y' => 650)),
            'JPXDecode',
            'not-a-real-jpeg2000-stream',
            32,
            32
        ));
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<p>Body paragraph text.</p>', $html);
    }

    public function testImportPdfRouteSucceedsWithEmbeddedImage(): void
    {
        $jpeg = $this->minimalJpegBytes();
        $tmp = $this->tempFile($this->buildPdfWithImage(
            $this->sampleBlocks(),
            'DCTDecode',
            $jpeg,
            32,
            32
        ));
        $result = $this->runRoute(
            $this->upload($tmp, 'doc.pdf'),
            array('method' => 'branch')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertStringContainsString(
            '<img src="data:image/jpeg;base64,' . base64_encode($jpeg) . '">',
            $items[0]['contents']
        );
    }

    public function testExtractPdfPageImagesSkipsImagesBelowMinimumDimension(): void
    {
        $this->loadFile();
        $jpeg = $this->minimalJpegBytes();
        $path = $this->tempFile($this->buildPdfWithImage(
            array(array('text' => 'Body paragraph text.', 'size' => 12, 'x' => 72, 'y' => 650)),
            'DCTDecode',
            $jpeg,
            4,
            4
        ));
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * End-to-end: the inline <img src="data:..."> returned by the import-pdf
     * route (characterized above) round-trips through HAXCMSSite::addPage()
     * (the same code path createNode uses for an 'html'-template node with
     * 'contents') and ends up persisted as a <media-image source="...">,
     * with the data: URI gone and a FileEntity created on disk. This proves
     * the route's skipSanitize opt-out does not leave raw data: URIs
     * persisted anywhere — they are always materialized+sanitized before
     * being written to a page.
     */
    public function testImportedPdfImageMaterializesThroughAddPage(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/HAXCMSSite.php';
        require_once dirname(__DIR__, 2) . '/lib/JSONOutlineSchemaItem.php';
        require_once dirname(__DIR__, 2) . '/lib/MaterializeInlineImages.php';
        require_once dirname(__DIR__, 2) . '/lib/HAXCMSFile.php';
        require_once dirname(__DIR__, 2) . '/lib/EntityRegistry.php';
        require_once dirname(__DIR__, 2) . '/lib/FileStorage.php';
        require_once dirname(__DIR__, 2) . '/lib/FileEntity.php';
        require_once dirname(__DIR__, 2) . '/lib/FilesDataStore.php';
        require_once dirname(__DIR__, 2) . '/lib/SanitizeContent.php';

        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_pdf_mii_root_' . getmypid());
        }
        if (!is_dir(HAXCMS_ROOT)) {
            @mkdir(HAXCMS_ROOT, 0777, true);
        }

        $siteName = 'pdf-mii-site';
        $siteRoot = HAXCMS_ROOT . '/_sites/' . $siteName;
        @mkdir($siteRoot . '/files', 0777, true);
        @mkdir($siteRoot . '/pages', 0777, true);

        $configDir = sys_get_temp_dir() . '/haxcms_pdf_mii_config_' . uniqid();
        @mkdir($configDir . '/tmp/imports', 0777, true);

        $savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $haxcms = new OperationsTestHaxcms();
        $haxcms->sitesDirectory = '_sites';
        $haxcms->configDirectory = $configDir;
        $GLOBALS['HAXCMS'] = $haxcms;

        try {
            // Step 1: get the intact <img src="data:..."> tag from the route,
            // exactly as a client would receive it.
            $jpeg = $this->minimalJpegBytes();
            $tmp = $this->tempFile($this->buildPdfWithImage(
                $this->sampleBlocks(),
                'DCTDecode',
                $jpeg,
                32,
                32
            ));
            $result = $this->runRoute(
                $this->upload($tmp, 'doc.pdf'),
                array('method' => 'branch')
            );
            $this->assertSame(200, $result['code']);
            $contents = $result['data']['data']['items'][0]['contents'];
            $this->assertStringContainsString('<img src="data:image/jpeg;base64,', $contents);

            // Step 2: round-trip that content through addPage(), the same
            // path createNode() uses for an 'html'-template node.
            $site = new PdfImportMiiHaxcmsSite();
            $site->name = $siteName;
            $site->directory = HAXCMS_ROOT . '/_sites';
            $site->siteDirectory = $siteRoot;
            $site->manifest = new PdfImportMiiManifest();
            $site->manifest->items = array();
            $site->manifest->metadata = new stdClass();
            $site->manifest->metadata->site = new stdClass();
            $site->manifest->metadata->site->name = $siteName;
            $site->manifest->metadata->site->updated = time();

            $page = $site->addPage(
                null,
                'Imported PDF Page',
                'html',
                'imported-pdf-page',
                null,
                null,
                $contents
            );

            // The persisted page content is materialized+sanitized: the
            // data: URI is gone, replaced with a media-image tag.
            $location = $siteRoot . '/' . $page->location;
            $this->assertTrue(file_exists($location));
            $persisted = file_get_contents($location);
            $this->assertStringContainsString('media-image', $persisted);
            $this->assertStringNotContainsString('data:image', $persisted);

            // A FileEntity was created and associated on the page.
            $this->assertIsObject($page->metadata);
            $this->assertIsArray($page->metadata->files);
            $this->assertNotEmpty($page->metadata->files);

            $filesDir = $siteRoot . '/files';
            $savedJpgs = array();
            foreach (scandir($filesDir) as $name) {
                if (preg_match('/\.jpg$/i', $name)) {
                    $savedJpgs[] = $name;
                }
            }
            $this->assertNotEmpty($savedJpgs);
        } finally {
            if ($savedHaxcms !== null) {
                $GLOBALS['HAXCMS'] = $savedHaxcms;
            } else {
                unset($GLOBALS['HAXCMS']);
            }
            $this->rrmdirPdfMii($siteRoot);
            $this->rrmdirPdfMii($configDir);
        }
    }

    private function rrmdirPdfMii($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdirPdfMii($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * HAXCMSSite subclass that skips git/alt formats, used by
 * testImportedPdfImageMaterializesThroughAddPage. Mirrors
 * MaterializeInlineImagesTest's MiiHaxcmsSite but with a distinct class name
 * to avoid redeclaration when both test files load in the same process.
 */
class PdfImportMiiHaxcmsSite extends HAXCMSSite
{
    public $siteDirectory;

    public function gitCommit($msg = 'Committed changes')
    {
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }

    public function getUniqueSlugName($slug, $item = null, $pathauto = false)
    {
        return $slug !== '' ? $slug : 'page';
    }
}

class PdfImportMiiManifest
{
    public $items = array();
    public $metadata;
    public $saves = 0;

    public function addItem($item)
    {
        $this->items[] = $item;
    }

    public function save($reorder = true)
    {
        $this->saves++;
        return true;
    }
}
