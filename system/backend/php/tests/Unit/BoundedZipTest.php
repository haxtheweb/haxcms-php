<?php
/**
 * Tests for lib/BoundedZip.php and the importers that check uploaded zips
 * through it (docx, xlsx/ods, odt/odp, pptx).
 *
 * Invariant (SEC-DOS, SEC-UPLOAD): an uploaded zip is measured under real byte
 * limits before any reader inflates it; the sizes its directory declares only
 * ever lower a limit, so neither a lying header nor an honest huge entry can
 * make the server inflate past the budget (ZipArchive, PhpSpreadsheet's zip://
 * reads, PhpWord).
 *
 * Parity: the twin of haxcms-nodejs test/unit/bounded-zip.test.cjs; same
 * limits, same messages.
 */

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once dirname(__DIR__, 2) . '/lib/BoundedZip.php';
require_once dirname(__DIR__, 2) . '/lib/OdtToHtml.php';
require_once dirname(__DIR__, 2) . '/lib/PptxToHtml.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class BoundedZipTest extends TestCase
{
    const BOMB_BYTES = 67108864; // 64 MiB of spaces, deflates ~1000:1
    const BUDGET_MESSAGE = '/more content than can be imported/';

    /** @var array */
    private $tmpFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }
        $this->tmpFiles = array();
        $_FILES = array();
    }

    private function tmpPath()
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-bounded-zip-');
        @unlink($path);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Build a zip: $entries are stored as given, $bombPath holds $bytes of
     * spaces, deflated; $declare (if set) overwrites its declared size.
     */
    private function buildZip($entries, $bombPath = null, $bytes = self::BOMB_BYTES, $declare = null)
    {
        $path = $this->tmpPath();
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        if ($bombPath !== null) {
            $zip->addFromString($bombPath, str_repeat(' ', $bytes));
            $zip->setCompressionName($bombPath, ZipArchive::CM_DEFLATE);
        }
        $zip->close();
        if ($bombPath !== null && $declare !== null) {
            $this->patchDeclaredSize($path, $bombPath, $declare);
        }
        return $path;
    }

    /** Overwrite the declared size of $name in the local and central headers. */
    private function patchDeclaredSize($path, $name, $size)
    {
        $data = file_get_contents($path);
        $packed = pack('V', $size);
        $len = strlen($name);
        for ($i = 0; $i < strlen($data) - 4; $i++) {
            $sig = substr($data, $i, 4);
            if ($sig === "PK\x03\x04" && substr($data, $i + 30, $len) === $name) {
                $data = substr_replace($data, $packed, $i + 22, 4);
            } elseif ($sig === "PK\x01\x02" && substr($data, $i + 46, $len) === $name) {
                $data = substr_replace($data, $packed, $i + 24, 4);
            }
        }
        file_put_contents($path, $data);
    }

    private function assertRefused($path, $ext, $reason)
    {
        try {
            BoundedZip::assertWithinLimits($path, $ext);
            $this->fail('expected the zip to be refused');
        } catch (BoundedZipException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(400, $e->status);
            if ($reason === 'ZIP_BUDGET') {
                $this->assertSame('The .' . $ext . ' file expands to more content than can be imported.', $e->getMessage());
            } else {
                $this->assertSame('Uploaded file is not a valid .' . $ext . ' file.', $e->getMessage());
            }
        }
    }

    private function runRoute($file, $tmpName, $fileName)
    {
        $_FILES = array('upload' => array('name' => $fileName, 'tmp_name' => $tmpName, 'size' => filesize($tmpName)));
        $context = (new ReflectionClass('SystemApiRequestContext'))->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = 'v1/' . $file;
        $context->method = 'POST';
        $context->body = array();
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/' . $file . '.php';
        $handler($context);
        $raw = ob_get_clean();
        return array('data' => json_decode($raw, true), 'code' => http_response_code());
    }

    public function testANormalZipPasses()
    {
        $path = $this->buildZip(array(
            'mimetype' => 'application/vnd.oasis.opendocument.text',
            'content.xml' => '<a>' . str_repeat('hello ', 5000) . '</a>',
            'Pictures/a.bin' => "\x00\x01\x02\xfe\xff",
        ));
        $this->assertTrue(BoundedZip::hasZipSignature($path));
        BoundedZip::assertWithinLimits($path, 'odt');
        $this->addToAssertionCount(1);
    }

    /**
     * haxtheweb/issues#3426: an empty file (.nojekyll, a placeholder) is an
     * ordinary entry, stored or deflated. Parity: haxcms-nodejs boundedZip
     * "empty files (STORE and DEFLATE) come back as empty files".
     */
    public function testEmptyFilesPass()
    {
        foreach (array(ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE) as $method) {
            $path = $this->tmpPath();
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString('site.json', '{}');
            $zip->addFromString('empty.txt', '');
            $zip->addFromString('.nojekyll', '');
            $zip->setCompressionName('empty.txt', $method);
            $zip->setCompressionName('.nojekyll', $method);
            $zip->close();
            BoundedZip::assertWithinLimits($path, 'zip');
            $this->addToAssertionCount(1);
        }
    }

    public function testALyingHeaderStopsAtItsClaim()
    {
        $path = $this->buildZip(array(), 'content.xml', self::BOMB_BYTES, 100);
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = microtime(true);
        $this->assertRefused($path, 'odt', 'ZIP_BUDGET');
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function testAnHonestEntryOverTheBudgetIsRefusedBeforeInflating()
    {
        $path = $this->buildZip(array(), 'word/document.xml', 1024, 300 * 1024 * 1024);
        $this->assertRefused($path, 'docx', 'ZIP_BUDGET');
    }

    public function testCompressionRatioPastTheLimitIsRefused()
    {
        // 64 MiB from ~64 KiB: over the 32 MiB floor and over 100x the upload
        $path = $this->buildZip(array(), 'xl/worksheets/sheet1.xml');
        $this->assertLessThan(1024 * 1024, filesize($path));
        $this->assertRefused($path, 'xlsx', 'ZIP_BUDGET');
    }

    public function testTooManyEntriesAreRefused()
    {
        $entries = array();
        for ($i = 0; $i <= BoundedZip::MAX_ENTRIES; $i++) {
            $entries['f' . $i] = '';
        }
        $this->assertRefused($this->buildZip($entries), 'docx', 'ZIP_BUDGET');
    }

    public function testGarbageAndShortEntriesAreInvalidNotBudgetErrors()
    {
        $garbage = $this->tmpPath();
        file_put_contents($garbage, 'not a zip');
        $this->assertRefused($garbage, 'docx', 'ZIP_INVALID');
        // claims more than it holds (within budget): not a true size
        $short = $this->buildZip(array(), 'word/document.xml', 1024, 4096);
        $this->assertRefused($short, 'docx', 'ZIP_INVALID');
    }

    public function testOdtAndPptxConvertersRefuseZipBombs()
    {
        $odt = $this->buildZip(array('mimetype' => 'application/vnd.oasis.opendocument.text'), 'content.xml', self::BOMB_BYTES, 100);
        try {
            OdtToHtml::convertFile($odt);
            $this->fail('expected the .odt to be refused');
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression(self::BUDGET_MESSAGE, $e->getMessage());
        }
        $pptx = $this->buildZip(array(), 'ppt/slides/slide1.xml', self::BOMB_BYTES, 100);
        try {
            PptxToHtml::convertFile($pptx);
            $this->fail('expected the .pptx to be refused');
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression(self::BUDGET_MESSAGE, $e->getMessage());
        }
        // an unreadable zip keeps its existing message
        $broken = $this->tmpPath();
        file_put_contents($broken, "PK\x03\x04 not really a zip");
        try {
            PptxToHtml::convertFile($broken);
            $this->fail('expected the broken .pptx to be refused');
        } catch (\Exception $e) {
            $this->assertSame('Uploaded file is not a valid .pptx file (unreadable ZIP).', $e->getMessage());
        }
    }

    public function testDocxAndSpreadsheetRoutesAnswer400ForZipBombs()
    {
        $result = $this->runRoute('importDocx', $this->buildZip(array(), 'word/document.xml'), 'bomb.docx');
        $this->assertSame(400, $result['code']);
        $this->assertMatchesRegularExpression(self::BUDGET_MESSAGE, (string) json_encode($result['data']));

        // PhpSpreadsheet's Ods reader uses zip://, which ignores declared sizes
        $ods = $this->buildZip(array('mimetype' => 'application/vnd.oasis.opendocument.spreadsheet'), 'content.xml', self::BOMB_BYTES, 100);
        $result = $this->runRoute('importXlsx', $ods, 'bomb.ods');
        $this->assertSame(400, $result['code']);
        $this->assertMatchesRegularExpression(self::BUDGET_MESSAGE, (string) json_encode($result['data']));

        $result = $this->runRoute('convertXlsxToCsv', $this->buildZip(array(), 'xl/workbook.xml'), 'bomb.xlsx');
        $this->assertSame(400, $result['code']);
        $this->assertMatchesRegularExpression(self::BUDGET_MESSAGE, (string) json_encode($result['data']));
    }

    /**
     * Separate process: importDocx.php and convertDocxToHtml.php both declare
     * haxcmsSystemConvertDocxXmlToHtml(), so they can't load in one process.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testConvertDocxToHtmlAnswers400ForAZipBomb()
    {
        $result = $this->runRoute('convertDocxToHtml', $this->buildZip(array(), 'word/document.xml'), 'bomb.docx');
        $this->assertSame(400, $result['code']);
        $this->assertMatchesRegularExpression(self::BUDGET_MESSAGE, (string) json_encode($result['data']));
    }
}
