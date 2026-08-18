<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the 6 file-upload format converters in
 * lib/systemRoutes/v1/:
 *   convertDocxToPdf, convertHtmlToDocx, convertHtmlToPdf,
 *   convertPdfToHtml, convertPptxToHtml, convertXlsxToCsv
 *
 * Each converter reads $_FILES (keys: upload, file, file-upload), validates
 * the filename extension and (for PDF/PPTX) magic bytes, then delegates to
 * the appropriate library (Dompdf, PhpWord, PhpSpreadsheet, smalot/pdfparser,
 * ZipArchive). Error paths return JSON via sendFormattedResponse; binary
 * success paths either return base64 in JSON or raw bytes with headers.
 *
 * Covers:
 *  - No-file-uploaded validation (400)
 *  - Wrong-extension validation (400)
 *  - Empty-file validation (400) where applicable
 *  - Invalid magic-bytes validation (400) where applicable
 *  - Legacy .xls rejection (400) for xlsx-to-csv
 *  - Happy paths with crafted minimal binary fixtures (DOCX/HTML/PDF/PPTX/XLSX)
 *
 * Binary deps are in composer; if a class can't load the test marks skipped.
 * Expected values come from independent sources of truth (ZIP format spec,
 * PDF/DOCX magic bytes, CSV format spec) — not re-calls of the production code.
 */
class FormatConvertersFileTest extends TestCase
{
    private $converterDir;
    private $savedGet;
    private $savedFiles;
    private $tmpFiles = array();
    private static $pptxClosure = null;

    protected function setUp(): void
    {
        $this->converterDir = dirname(__DIR__, 2) . '/lib/systemRoutes/v1';
        $this->savedGet = $_GET;
        $this->savedFiles = $_FILES;
        $_GET = array();
        $_FILES = array();
        $this->tmpFiles = array();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_FILES = $this->savedFiles;
        foreach ($this->tmpFiles as $f) {
            if (is_string($f) && file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = array();
        http_response_code(200);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Load a converter closure. For convertPptxToHtml we must use require_once
     * (it declares a top-level function haxcmsSystemExtractPptxFiles without a
     * function_exists guard, so re-including would fatal). The closure is
     * cached statically. All other converters only return a closure with no
     * top-level declarations, so include is safe and returns a fresh closure
     * each time.
     */
    private function loadConverter(string $name)
    {
        $path = $this->converterDir . '/' . $name . '.php';
        $this->assertFileExists($path);
        if ($name === 'convertPptxToHtml') {
            if (self::$pptxClosure === null) {
                self::$pptxClosure = require_once $path;
            }
            return self::$pptxClosure;
        }
        return include $path;
    }

    /**
     * Invoke a converter closure. Returns [statusCode, decodedJson|null, rawOutput].
     */
    private function invokeConverter(string $name, string $routeSuffix = 'v1/actions/docx-to-pdf')
    {
        $closure = $this->loadConverter($name);
        $this->assertIsCallable($closure);
        $context = new stdClass();
        $context->body = array();
        $context->routeSuffix = $routeSuffix;
        $context->apiBasePath = '/system/api';
        ob_start();
        call_user_func($closure, $context);
        $raw = ob_get_clean();
        $status = http_response_code();
        $decoded = json_decode($raw, true);
        return array($status, $decoded, $raw);
    }

    /**
     * Create a temp file with the given content and extension, register it
     * for tearDown cleanup, and return its path.
     */
    private function makeTempFile(string $content, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fcft_') . $suffix;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Set $_FILES[$key] to point at a temp file.
     */
    private function setUploadFile(string $key, string $tmpPath, string $filename): void
    {
        $_FILES[$key] = array(
            'name' => $filename,
            'tmp_name' => $tmpPath,
            'size' => filesize($tmpPath),
            'type' => 'application/octet-stream',
            'error' => UPLOAD_ERR_OK,
        );
    }

    /**
     * Set $_FILES for the first matching key among upload/file/file-upload.
     */
    private function setUpload(string $tmpPath, string $filename): void
    {
        $this->setUploadFile('upload', $tmpPath, $filename);
    }

    /**
     * Create a ZIP file from an array of [entryName => content] pairs.
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fcft_zip_') . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Minimal DOCX (ZIP with word/document.xml) containing one paragraph.
     */
    private function makeMinimalDocx(string $paragraphText = 'Hello World'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>'
            . '<w:p><w:r><w:t>' . htmlspecialchars($paragraphText, ENT_XML1) . '</w:t></w:r></w:p>'
            . '</w:body>'
            . '</w:document>';
        return $this->makeZip(array('word/document.xml' => $xml));
    }

    /**
     * Minimal PPTX (ZIP with ppt/slides/slide1.xml) containing a title
     * placeholder and a content shape.
     */
    private function makeMinimalPptx(): string
    {
        $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<p:cSld><p:spTree>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>Slide Title</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>Slide content text</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '</p:spTree></p:cSld>'
            . '</p:sld>';
        return $this->makeZip(array('ppt/slides/slide1.xml' => $slideXml));
    }

    /**
     * PPTX with a p:pic element referencing an image in ppt/media/.
     */
    private function makePptxWithImage(): string
    {
        $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<p:cSld><p:spTree>'
            . '<p:pic>'
            . '<p:nvPicPr><p:cNvPr id="1" name="img1"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rId1"/></p:blipFill>'
            . '<p:spPr/>'
            . '</p:pic>'
            . '</p:spTree></p:cSld>'
            . '</p:sld>';
        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/>'
            . '</Relationships>';
        // Minimal 1x1 PNG (67 bytes)
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        return $this->makeZip(array(
            'ppt/slides/slide1.xml' => $slideXml,
            'ppt/slides/_rels/slide1.xml.rels' => $relsXml,
            'ppt/media/image1.png' => $png,
        ));
    }

    /**
     * Generate a real PDF using Dompdf (if available).
     */
    private function makePdf(string $html = '<p>Test PDF content</p>'): ?string
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            return null;
        }
        $dompdf = new \Dompdf\Dompdf(array('isRemoteEnabled' => false));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
        return $this->makeTempFile($pdf, '.pdf');
    }

    /**
     * Generate a real XLSX using PhpSpreadsheet (if available).
     */
    private function makeXlsx(): ?string
    {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return null;
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Name');
        $sheet->setCellValue('B1', 'Age');
        $sheet->setCellValue('A2', 'Alice');
        $sheet->setCellValue('B2', '30');
        $sheet->setCellValue('A3', 'Bob');
        $sheet->setCellValue('B3', '25');
        $path = tempnam(sys_get_temp_dir(), 'fcft_xlsx_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // ------------------------------------------------------------------
    // convertDocxToPdf
    // ------------------------------------------------------------------

    public function testDocxToPdfNoFileReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertDocxToPdf');
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $json['data']['error']);
    }

    public function testDocxToPdfWrongExtensionReturns400(): void
    {
        $tmpPath = $this->makeTempFile('not a docx', '.txt');
        $this->setUpload($tmpPath, 'document.txt');
        list($status, $json) = $this->invokeConverter('convertDocxToPdf');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Expected .docx', $json['data']['error']);
        $this->assertStringContainsString('document.txt', $json['data']['error']);
    }

    public function testDocxToPdfAcceptsAnyUploadKey(): void
    {
        // The converter checks upload, file, file-upload in order.
        $tmpPath = $this->makeTempFile('x', '.txt');
        $this->setUploadFile('file', $tmpPath, 'document.txt');
        list($status, $json) = $this->invokeConverter('convertDocxToPdf');
        $this->assertSame(400, $status);
        // Should not be "No file uploaded" — it found the file key
        $this->assertStringNotContainsString('No file uploaded', $json['data']['error']);
    }

    public function testDocxToPdfHappyPathProducesPdf(): void
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            $this->markTestSkipped('dompdf/dompdf not available');
        }
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $tmpPath = $this->makeMinimalDocx('Hello DOCX to PDF');
        $this->setUpload($tmpPath, 'test.docx');
        list($status, $json, $raw) = $this->invokeConverter('convertDocxToPdf');
        // Happy path outputs raw PDF binary (not JSON) with http_response_code 200
        $this->assertSame(200, $status);
        $this->assertSame(0, strncmp($raw, '%PDF', 4), 'Output must start with PDF magic bytes');
    }

    // ------------------------------------------------------------------
    // convertHtmlToDocx
    // ------------------------------------------------------------------

    public function testHtmlToDocxNoFileReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToDocx', 'v1/actions/html-to-docx');
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $json['data']['error']);
        $this->assertNull($json['data']['contents']);
        $this->assertNull($json['data']['filename']);
    }

    public function testHtmlToDocxWrongExtensionReturns400(): void
    {
        $tmpPath = $this->makeTempFile('plain text', '.txt');
        $this->setUpload($tmpPath, 'page.txt');
        list($status, $json) = $this->invokeConverter('convertHtmlToDocx', 'v1/actions/html-to-docx');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Expected .html or .htm', $json['data']['error']);
        $this->assertSame('page.txt', $json['data']['filename']);
    }

    public function testHtmlToDocxEmptyFileReturns400(): void
    {
        $tmpPath = $this->makeTempFile('', '.html');
        $this->setUpload($tmpPath, 'empty.html');
        list($status, $json) = $this->invokeConverter('convertHtmlToDocx', 'v1/actions/html-to-docx');
        $this->assertSame(400, $status);
        $this->assertSame('Uploaded file is empty or unreadable', $json['data']['error']);
    }

    public function testHtmlToDocxWhitespaceOnlyFileReturns400(): void
    {
        $tmpPath = $this->makeTempFile("   \n  \n", '.html');
        $this->setUpload($tmpPath, 'blank.html');
        list($status, $json) = $this->invokeConverter('convertHtmlToDocx', 'v1/actions/html-to-docx');
        $this->assertSame(400, $status);
        $this->assertSame('Uploaded file is empty or unreadable', $json['data']['error']);
    }

    public function testHtmlToDocxHappyPathProducesDocxBase64(): void
    {
        if (!class_exists('\\PhpOffice\\PhpWord\\PhpWord')) {
            $this->markTestSkipped('phpoffice/phpword not available');
        }
        $html = '<html><body><h1>Title</h1><p>Body paragraph</p></body></html>';
        $tmpPath = $this->makeTempFile($html, '.html');
        $this->setUpload($tmpPath, 'page.html');
        list($status, $json, $raw) = $this->invokeConverter('convertHtmlToDocx', 'v1/actions/html-to-docx');
        $this->assertSame(200, $status);
        $this->assertSame(200, $json['status']);
        $this->assertSame('page.docx', $json['data']['filename']);
        $base64 = $json['data']['contents'];
        $this->assertNotEmpty($base64);
        $binary = base64_decode($base64, true);
        $this->assertNotFalse($binary);
        // DOCX is a ZIP → PK magic bytes
        $this->assertSame(0, strncmp($binary, "PK\x03\x04", 4));
    }

    public function testHtmlToDocxFilenameReplacedWithDocxExtension(): void
    {
        if (!class_exists('\\PhpOffice\\PhpWord\\PhpWord')) {
            $this->markTestSkipped('phpoffice/phpword not available');
        }
        $html = '<p>content</p>';
        $tmpPath = $this->makeTempFile($html, '.htm');
        $this->setUpload($tmpPath, 'report.htm');
        list($status, $json) = $this->invokeConverter('convertHtmlToDocx', 'v1/actions/html-to-docx');
        $this->assertSame(200, $status);
        $this->assertSame('report.docx', $json['data']['filename']);
    }

    // ------------------------------------------------------------------
    // convertHtmlToPdf
    // ------------------------------------------------------------------

    public function testHtmlToPdfNoFileReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToPdf', 'v1/actions/html-to-pdf');
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $json['data']['error']);
    }

    public function testHtmlToPdfWrongExtensionReturns400(): void
    {
        $tmpPath = $this->makeTempFile('plain', '.txt');
        $this->setUpload($tmpPath, 'file.txt');
        list($status, $json) = $this->invokeConverter('convertHtmlToPdf', 'v1/actions/html-to-pdf');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Expected .html or .htm', $json['data']['error']);
    }

    public function testHtmlToPdfEmptyFileReturns400(): void
    {
        $tmpPath = $this->makeTempFile('', '.html');
        $this->setUpload($tmpPath, 'empty.html');
        list($status, $json) = $this->invokeConverter('convertHtmlToPdf', 'v1/actions/html-to-pdf');
        $this->assertSame(400, $status);
        $this->assertSame('Uploaded file is empty or unreadable', $json['data']['error']);
    }

    public function testHtmlToPdfHappyPathProducesPdfBase64(): void
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            $this->markTestSkipped('dompdf/dompdf not available');
        }
        $html = '<html><body><h1>Hello PDF</h1><p>Test content</p></body></html>';
        $tmpPath = $this->makeTempFile($html, '.html');
        $this->setUpload($tmpPath, 'page.html');
        list($status, $json) = $this->invokeConverter('convertHtmlToPdf', 'v1/actions/html-to-pdf');
        $this->assertSame(200, $status);
        $this->assertSame('page.pdf', $json['data']['filename']);
        $base64 = $json['data']['contents'];
        $this->assertNotEmpty($base64);
        $binary = base64_decode($base64, true);
        $this->assertNotFalse($binary);
        $this->assertSame(0, strncmp($binary, '%PDF', 4));
    }

    // ------------------------------------------------------------------
    // convertPdfToHtml
    // ------------------------------------------------------------------

    public function testPdfToHtmlNoFileReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertPdfToHtml', 'v1/actions/pdf-to-html');
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $json['data']['error']);
    }

    public function testPdfToHtmlWrongExtensionReturns400(): void
    {
        $tmpPath = $this->makeTempFile('plain', '.txt');
        $this->setUpload($tmpPath, 'file.txt');
        list($status, $json) = $this->invokeConverter('convertPdfToHtml', 'v1/actions/pdf-to-html');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Expected .pdf', $json['data']['error']);
    }

    public function testPdfToHtmlInvalidMagicBytesReturns400(): void
    {
        // File has .pdf extension but content doesn't start with %PDF
        $tmpPath = $this->makeTempFile('NOT A PDF FILE', '.pdf');
        $this->setUpload($tmpPath, 'fake.pdf');
        list($status, $json) = $this->invokeConverter('convertPdfToHtml', 'v1/actions/pdf-to-html');
        $this->assertSame(400, $status);
        $this->assertSame('Uploaded file is not a valid PDF', $json['data']['error']);
    }

    public function testPdfToHtmlHappyPathProducesHtml(): void
    {
        if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
            $this->markTestSkipped('smalot/pdfparser not available');
        }
        $pdfPath = $this->makePdf('<p>Extractable text content</p>');
        if ($pdfPath === null) {
            $this->markTestSkipped('Could not generate test PDF (dompdf unavailable)');
        }
        $this->setUpload($pdfPath, 'test.pdf');
        list($status, $json) = $this->invokeConverter('convertPdfToHtml', 'v1/actions/pdf-to-html');
        $this->assertSame(200, $status);
        $html = $json['data']['contents'];
        // The converter wraps extracted text in <p> tags
        $this->assertStringContainsString('<p>', $html);
        $this->assertStringContainsString('</p>', $html);
    }

    public function testPdfToHtmlEmptyPdfProducesEmptyParagraph(): void
    {
        if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
            $this->markTestSkipped('smalot/pdfparser not available');
        }
        $pdfPath = $this->makePdf('');
        if ($pdfPath === null) {
            $this->markTestSkipped('Could not generate test PDF (dompdf unavailable)');
        }
        $this->setUpload($pdfPath, 'empty.pdf');
        list($status, $json) = $this->invokeConverter('convertPdfToHtml', 'v1/actions/pdf-to-html');
        $this->assertSame(200, $status);
        // No extractable text → converter falls back to '<p></p>'
        $this->assertSame('<p></p>', $json['data']['contents']);
    }

    // ------------------------------------------------------------------
    // convertPptxToHtml
    // ------------------------------------------------------------------

    public function testPptxToHtmlNoFileReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertPptxToHtml', 'v1/actions/pptx-to-html');
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $json['data']['error']);
        $this->assertSame(array(), $json['data']['files']);
    }

    public function testPptxToHtmlWrongExtensionReturns400(): void
    {
        $tmpPath = $this->makeTempFile('plain', '.txt');
        $this->setUpload($tmpPath, 'file.txt');
        list($status, $json) = $this->invokeConverter('convertPptxToHtml', 'v1/actions/pptx-to-html');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Expected .pptx', $json['data']['error']);
    }

    public function testPptxToHtmlInvalidZipSignatureReturns400(): void
    {
        // .pptx extension but content doesn't start with PK (ZIP magic)
        $tmpPath = $this->makeTempFile('NOT A PPTX FILE', '.pptx');
        $this->setUpload($tmpPath, 'fake.pptx');
        list($status, $json) = $this->invokeConverter('convertPptxToHtml', 'v1/actions/pptx-to-html');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('not a valid .pptx', $json['data']['error']);
        $this->assertStringContainsString('ZIP signature', $json['data']['error']);
    }

    public function testPptxToHtmlExtractsTextFromSlides(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $tmpPath = $this->makeMinimalPptx();
        $this->setUpload($tmpPath, 'slides.pptx');
        list($status, $json) = $this->invokeConverter('convertPptxToHtml', 'v1/actions/pptx-to-html');
        $this->assertSame(200, $status);
        $html = $json['data']['contents'];
        // Title placeholder → <h2>, content → <p>
        $this->assertStringContainsString('<h2>Slide Title</h2>', $html);
        $this->assertStringContainsString('<p>Slide content text</p>', $html);
    }

    public function testPptxToHtmlExtractsImageFiles(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $tmpPath = $this->makePptxWithImage();
        $this->setUpload($tmpPath, 'with-image.pptx');
        list($status, $json) = $this->invokeConverter('convertPptxToHtml', 'v1/actions/pptx-to-html');
        $this->assertSame(200, $status);
        $files = $json['data']['files'];
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
        // The extracted file key follows files/pptx-media/slide-{N}-image-{M}{ext}
        $keys = array_keys($files);
        $this->assertTrue(
            strpos($keys[0], 'files/pptx-media/') === 0,
            'Extracted file key must start with files/pptx-media/'
        );
        $entry = $files[$keys[0]];
        $this->assertSame('image/png', $entry['mimeType']);
        $this->assertStringContainsString('ppt/media/', $entry['originalPath']);
        // buffer is base64-encoded image data
        $decoded = base64_decode($entry['buffer'], true);
        $this->assertNotFalse($decoded);
        // PNG magic bytes
        $this->assertSame(0, strncmp($decoded, "\x89PNG", 4));
    }

    // ------------------------------------------------------------------
    // convertXlsxToCsv
    // ------------------------------------------------------------------

    public function testXlsxToCsvNoFileReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertXlsxToCsv', 'v1/actions/xlsx-to-csv');
        $this->assertSame(400, $status);
        $this->assertSame('No file uploaded', $json['data']['error']);
    }

    public function testXlsxToCsvWrongExtensionReturns400(): void
    {
        $tmpPath = $this->makeTempFile('plain', '.txt');
        $this->setUpload($tmpPath, 'file.txt');
        list($status, $json) = $this->invokeConverter('convertXlsxToCsv', 'v1/actions/xlsx-to-csv');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Expected .xlsx or .xls', $json['data']['error']);
    }

    public function testXlsxToCsvLegacyXlsRejected(): void
    {
        $tmpPath = $this->makeTempFile('legacy', '.xls');
        $this->setUpload($tmpPath, 'legacy.xls');
        list($status, $json) = $this->invokeConverter('convertXlsxToCsv', 'v1/actions/xlsx-to-csv');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Legacy .xls files are not supported', $json['data']['error']);
    }

    public function testXlsxToCsvHappyPathProducesCsv(): void
    {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $this->markTestSkipped('phpoffice/phpspreadsheet not available');
        }
        $tmpPath = $this->makeXlsx();
        $this->assertNotNull($tmpPath);
        $this->setUpload($tmpPath, 'data.xlsx');
        list($status, $json) = $this->invokeConverter('convertXlsxToCsv', 'v1/actions/xlsx-to-csv');
        $this->assertSame(200, $status);
        $csv = $json['data']['contents'];
        // CSV should contain the header row and data rows
        $this->assertStringContainsString('Name', $csv);
        $this->assertStringContainsString('Age', $csv);
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('30', $csv);
        $this->assertStringContainsString('Bob', $csv);
        $this->assertStringContainsString('25', $csv);
        // Metadata
        $this->assertSame('data.xlsx', $json['data']['filename']);
        $this->assertSame('csv', $json['data']['format']);
        $this->assertIsArray($json['data']['sheetNames']);
    }

    public function testXlsxToCsvHeadersFalseDropsFirstRow(): void
    {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $this->markTestSkipped('phpoffice/phpspreadsheet not available');
        }
        $tmpPath = $this->makeXlsx();
        $this->assertNotNull($tmpPath);
        $this->setUpload($tmpPath, 'data.xlsx');
        $_GET['headers'] = 'false';
        list($status, $json) = $this->invokeConverter('convertXlsxToCsv', 'v1/actions/xlsx-to-csv');
        $this->assertSame(200, $status);
        $csv = $json['data']['contents'];
        // With headers=false, the "Name"/"Age" header row is dropped
        // but data rows Alice/Bob remain
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('Bob', $csv);
        // The header values should NOT appear as a CSV row (they may appear
        // as substrings of other words, but "Name" as a standalone CSV field
        // should be gone). We check that the first CSV line is Alice,30 not
        // Name,Age.
        $lines = explode("\n", trim($csv));
        $this->assertNotSame('Name,Age', $lines[0]);
        $this->assertStringContainsString('Alice', $lines[0]);
    }
}
