<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertHtmlToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load (systemRoutes/v1/*
 * is explicitly skipped there to avoid sibling-file redeclaration fatals
 * across the imports/*.php converters), so this file is require_once'd
 * directly. Only this converter file is required here; other converters
 * under imports/ each get their own dedicated test file.
 *
 * Coverage focuses on the validation and file-upload branches, which are
 * reachable without live network I/O. The `repoUrl` Guzzle-fetch happy path
 * is not covered — there is no established Guzzle-mocking pattern in this
 * test suite (confirmed via grep of tests/), so genuinely network-dependent
 * behavior is out of scope here (matching ServicesTest.php's convention of
 * using markTestSkipped for such cases elsewhere in the suite).
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertHtmlToSite.php';

class ConvertHtmlToSiteTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_FILES['upload'], $_FILES['file'], $_FILES['file-upload']);
    }

    private function makeContext(array $body = []): stdClass
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = $body;
        return $context;
    }

    private function call(stdClass $context): array
    {
        ob_start();
        haxcmsImportConvertHtmlToSite($context);
        return json_decode(ob_get_clean(), true);
    }

    public function testMissingRepoUrlAndNoUploadReturns400(): void
    {
        $response = $this->call($this->makeContext());

        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame([], $response['data']['items']);
        $this->assertNull($response['data']['filename']);
    }

    public function testUploadWithInvalidExtensionReturns400(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'html_upload_');
        file_put_contents($tmp, 'hi');
        $_FILES['upload'] = ['name' => 'file.txt', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid file type. Expected .html or .htm, got: file.txt', $response['data']['error']);
        $this->assertSame('file.txt', $response['data']['filename']);
    }

    public function testValidHtmlUploadReturns200WithParsedItems(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'html_upload_');
        file_put_contents($tmp, '<h1>My Doc</h1><p>Content</p>');
        $_FILES['upload'] = ['name' => 'MyDoc.html', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(200, $response['status']);
        $this->assertSame('MyDoc.html', $response['data']['filename']);
        $this->assertCount(1, $response['data']['items']);
        $this->assertSame('My Doc', $response['data']['items'][0]['title']);
        $this->assertSame('my-doc', $response['data']['items'][0]['slug']);
        $this->assertSame('<p>Content</p>', $response['data']['items'][0]['contents']);
        $this->assertNull($response['data']['items'][0]['parent']);
    }

    public function testEmptyHtmlUploadReturns400(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'html_upload_');
        file_put_contents($tmp, '   ');
        $_FILES['upload'] = ['name' => 'empty.html', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Empty HTML content', $response['data']['error']);
        $this->assertSame('empty.html', $response['data']['filename']);
    }

    public function testUploadAcceptsFileFieldKeyAsAlternative(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'html_upload_');
        file_put_contents($tmp, '<p>Body</p>');
        $_FILES['file'] = ['name' => 'doc.htm', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(200, $response['status']);
        $this->assertSame('doc.htm', $response['data']['filename']);
    }

    public function testUploadHonorsMethodAndParentIdOptions(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'html_upload_');
        file_put_contents($tmp, '<h1>Root</h1><p>Body</p>');
        $_FILES['upload'] = ['name' => 'doc.html', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext(['method' => 'branch', 'parentId' => 'parent-123']));
        unlink($tmp);

        $this->assertSame(200, $response['status']);
        $this->assertSame('parent-123', $response['data']['items'][0]['parent']);
    }
}
