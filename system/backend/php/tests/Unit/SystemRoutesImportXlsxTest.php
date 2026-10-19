<?php
/**
 * Characterization tests for lib/systemRoutes/v1/importXlsx.php.
 *
 * The route has no function definitions, so it is safe to `require` fresh per
 * test. Fixtures are real .xlsx files written with PhpSpreadsheet (the same
 * library the route uses to read them); garbage files exercise the catch
 * (\Exception) 400 rewrap.
 */

use PHPUnit\Framework\TestCase;

class SystemRoutesImportXlsxTest extends TestCase
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
        $context->routeSuffix = 'v1/importXlsx';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importXlsx.php';
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

    private function writeXlsx(array $rows)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'hax-xlsx-') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        return $path;
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
        $result = $this->runRoute($this->upload(
            __FILE__,
            'notes.csv'
        ));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Invalid file type. Expected .xlsx or .xls, got: notes.csv',
            $result['data']['data']['error']
        );
    }

    public function testSuccessBuildsItemsAndResolvesParents(): void
    {
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('Home', 'home', '', '<p>Welcome</p>'),
            array('About', 'about', 'home', '<p>About us</p>'),
        ));
        $result = $this->runRoute($this->upload($xlsx, 'book.xlsx'));
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('book.xlsx', $result['data']['data']['filename']);
        $this->assertSame('Worksheet', $result['data']['data']['selectedSheet']);
        $items = $result['data']['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Home', $items[0]['title']);
        $this->assertSame('home', $items[0]['slug']);
        $this->assertSame(0, $items[0]['order']);
        $this->assertNull($items[0]['parent']);
        $this->assertSame('<p>Welcome</p>', $items[0]['contents']);
        $this->assertSame('About', $items[1]['title']);
        $this->assertSame('about', $items[1]['slug']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame(1, $items[1]['indent']);
        $this->assertSame('<p>About us</p>', $items[1]['contents']);
    }

    public function testSlugsAndParentsAreNormalized(): void
    {
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('Home Page', 'My Home!!', '', '<p>a</p>'),
            // Parent raw value normalizes to 'my-home', matching row 1's slug.
            array('Child', 'Kid_2', 'My Home!!', '<p>b</p>'),
        ));
        $result = $this->runRoute($this->upload($xlsx, 'book.xlsx'));
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertSame('my-home', $items[0]['slug']);
        // Underscores are preserved by the slug regex (only non [a-z0-9-_]
        // runs collapse to dashes); case and spaces are normalized.
        $this->assertSame('kid_2', $items[1]['slug']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
    }

    public function testMissingTitleRowReturns400(): void
    {
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('', 'noTitle', '', 'x'),
        ));
        $result = $this->runRoute($this->upload($xlsx, 'book.xlsx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Error processing Excel import: Row 2: title is required',
            $result['data']['data']['error']
        );
    }

    public function testMissingSlugRowReturns400(): void
    {
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('No Slug', '', '', 'x'),
        ));
        $result = $this->runRoute($this->upload($xlsx, 'book.xlsx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Error processing Excel import: Row 2: slug is required',
            $result['data']['data']['error']
        );
    }

    public function testDuplicateSlugReturns400(): void
    {
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('Home', 'home', '', '<p>a</p>'),
            array('Home Again', 'home', '', '<p>b</p>'),
        ));
        $result = $this->runRoute($this->upload($xlsx, 'book.xlsx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Error processing Excel import: Row 3: duplicate slug "home" (already used on row 2)',
            $result['data']['data']['error']
        );
    }

    public function testEmptyRowsAreSkippedForNumbering(): void
    {
        // An empty row between data rows is skipped, so the row counter only
        // advances for non-empty rows.
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('First', 'first', '', '<p>a</p>'),
            array('', '', '', ''),
            array('Second', 'second', '', '<p>b</p>'),
            array('', 'noTitle', '', 'x'),
        ));
        $result = $this->runRoute($this->upload($xlsx, 'book.xlsx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Error processing Excel import: Row 4: title is required',
            $result['data']['data']['error']
        );
    }

    public function testSheetWithoutHeaderRowReturns400(): void
    {
        $xlsx = $this->writeXlsx(array());
        $result = $this->runRoute($this->upload($xlsx, 'empty.xlsx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Error processing Excel import: Spreadsheet has no header row',
            $result['data']['data']['error']
        );
    }

    public function testUnreadableWorkbookReturns400ViaCatch(): void
    {
        // Plain text would fall through IOFactory's CSV reader, so the file
        // must carry a ZIP signature with a broken archive body to make the
        // Xlsx reader throw into the route's catch.
        $tmp = tempnam(sys_get_temp_dir(), 'hax-fake-');
        file_put_contents($tmp, "PK\x03\x04 definitely not a real zip archive");
        $result = $this->runRoute($this->upload($tmp, 'broken.xlsx'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(400, $result['data']['status']);
        $this->assertStringStartsWith(
            'Error processing Excel import:',
            $result['data']['data']['error']
        );
        $this->assertSame(array(), $result['data']['data']['items']);
    }

    public function testFileUploadKeyVariantIsAccepted(): void
    {
        $xlsx = $this->writeXlsx(array(
            array('title', 'slug', 'parent', 'content'),
            array('Solo', 'solo', '', '<p>one</p>'),
        ));
        $result = $this->runRoute(array(
            'file' => array('name' => 'book.xlsx', 'tmp_name' => $xlsx, 'size' => 42),
        ));
        $this->assertSame(200, $result['code']);
        $this->assertSame('Solo', $result['data']['data']['items'][0]['title']);
    }
}
