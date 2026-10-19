<?php
/**
 * Characterization tests for lib/systemRoutes/v1/importHtml.php.
 *
 * The route has no function definitions, so it is safe to `require` fresh per
 * test — each run re-executes the file and returns a new closure. Success
 * paths run the real SanitizeContent + haxcmsImportHtmlToItems pipeline
 * against temp-file fixtures shaped through $_FILES.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS:
 *   - the catch (\Exception) tail: SanitizeContent::sanitizeHTMLForStorage
 *     and haxcmsImportHtmlToItems do not throw on any reachable input, so the
 *     'Error processing HTML import' rewrap is defensive-only.
 */

use PHPUnit\Framework\TestCase;

class SystemRoutesImportHtmlTest extends TestCase
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
        $context->routeSuffix = 'v1/importHtml';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        $handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importHtml.php';
        $handler($context);
        $raw = ob_get_clean();
        return array(
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'code' => http_response_code(),
        );
    }

    private function upload($tmpName, $fileName, $key = 'upload')
    {
        return array(
            $key => array('name' => $fileName, 'tmp_name' => $tmpName, 'size' => 42),
        );
    }

    private function tempHtml($content)
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-html-');
        file_put_contents($path, $content);
        return $path;
    }

    public function testNoFileUploadReturns400ErrorPayload(): void
    {
        $result = $this->runRoute(array());
        $this->assertSame(400, $result['code']);
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('No file uploaded', $result['data']['data']['error']);
        $this->assertSame(array(), $result['data']['data']['items']);
        $this->assertNull($result['data']['data']['filename']);
    }

    public function testInvalidExtensionReturns400(): void
    {
        $tmp = $this->tempHtml('<h1>x</h1>');
        $result = $this->runRoute($this->upload($tmp, 'notes.txt'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Invalid file type. Expected .html or .htm, got: notes.txt',
            $result['data']['data']['error']
        );
        $this->assertSame('notes.txt', $result['data']['data']['filename']);
    }

    public function testEmptyFileReturns400(): void
    {
        $tmp = $this->tempHtml('   ');
        $result = $this->runRoute($this->upload($tmp, 'page.html'));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file is empty or unreadable',
            $result['data']['data']['error']
        );
    }

    public function testUnreadableFileReturns400(): void
    {
        // tmp_name points at a path that cannot be opened: file_get_contents
        // returns false and the same empty/unreadable 400 is emitted.
        $result = $this->runRoute($this->upload(
            sys_get_temp_dir() . '/hax-html-missing-' . uniqid(),
            'page.html'
        ));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Uploaded file is empty or unreadable',
            $result['data']['data']['error']
        );
    }

    public function testSiteMethodBuildsRootAndChildItems(): void
    {
        $tmp = $this->tempHtml(
            '<h1>Chapter One</h1><p>Intro text.</p><h2>Section</h2><p>More.</p>'
        );
        $result = $this->runRoute(
            $this->upload($tmp, 'book.html'),
            array('method' => 'site')
        );
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('book.html', $result['data']['data']['filename']);
        $items = $result['data']['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Chapter One', $items[0]['title']);
        $this->assertSame('chapter-one', $items[0]['slug']);
        $this->assertSame(0, $items[0]['indent']);
        $this->assertSame('<p>Intro text.</p>', $items[0]['contents']);
        $this->assertSame('Section', $items[1]['title']);
        $this->assertSame('chapter-one/section', $items[1]['slug']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame(1, $items[1]['indent']);
        $this->assertSame('<p>More.</p>', $items[1]['contents']);
    }

    public function testBranchMethodProducesSingleRootItem(): void
    {
        $tmp = $this->tempHtml(
            '<h1>Chapter One</h1><p>Intro text.</p><h2>Section</h2><p>More.</p>'
        );
        $result = $this->runRoute(
            $this->upload($tmp, 'page.html'),
            array('method' => 'branch')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Chapter One', $items[0]['title']);
        $this->assertSame(
            '<p>Intro text.</p><h2>Section</h2><p>More.</p>',
            $items[0]['contents']
        );
    }

    public function testPageMethodKeepsHeadingsInContentsAndPassesParentAndType(): void
    {
        $tmp = $this->tempHtml('<h1>Top</h1><p>Body copy.</p>');
        $result = $this->runRoute(
            $this->upload($tmp, 'page.html'),
            array('method' => 'page', 'parentId' => 'site-abc', 'type' => 'portfolio')
        );
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('page', $items[0]['title']);
        $this->assertSame('site-abc', $items[0]['parent']);
        // Page method keeps every element (including headings) in contents.
        $this->assertSame('<h1>Top</h1><p>Body copy.</p>', $items[0]['contents']);
    }

    public function testStringNullParentIdBecomesNullParent(): void
    {
        $tmp = $this->tempHtml('<p>Just a paragraph.</p>');
        $result = $this->runRoute(
            $this->upload($tmp, 'page.html'),
            array('parentId' => 'null')
        );
        $this->assertSame(200, $result['code']);
        $this->assertNull($result['data']['data']['items'][0]['parent']);
    }

    public function testNoHeadingContentStillProducesSingleBlankItem(): void
    {
        $tmp = $this->tempHtml('<p>Plain body only.</p>');
        $result = $this->runRoute($this->upload($tmp, 'page.html'));
        $this->assertSame(200, $result['code']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('<p>Plain body only.</p>', $items[0]['contents']);
    }

    public function testFileAndFileUploadKeysAreAccepted(): void
    {
        $tmp = $this->tempHtml('<p>Via file key.</p>');
        $result = $this->runRoute($this->upload($tmp, 'page.html', 'file'));
        $this->assertSame(200, $result['code']);
        $this->assertSame(
            '<p>Via file key.</p>',
            $result['data']['data']['items'][0]['contents']
        );

        $tmp2 = $this->tempHtml('<p>Via file-upload key.</p>');
        $result2 = $this->runRoute($this->upload($tmp2, 'page.html', 'file-upload'));
        $this->assertSame(200, $result2['code']);
        $this->assertSame(
            '<p>Via file-upload key.</p>',
            $result2['data']['data']['items'][0]['contents']
        );
    }

    public function testHtmExtensionIsAccepted(): void
    {
        $tmp = $this->tempHtml('<p>htm works.</p>');
        $result = $this->runRoute($this->upload($tmp, 'page.htm'));
        $this->assertSame(200, $result['code']);
        $this->assertSame('page', $result['data']['data']['items'][0]['title']);
    }
}
