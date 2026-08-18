<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertPressbooksToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * Coverage: the file-upload fallback branch (which delegates directly to
 * haxcmsImportHtmlToItems, already characterized in ImportUtilsTest.php)
 * and the missing-repoUrl validation branch. The Pressbooks TOC API fetch
 * happy path requires live network access and is out of scope.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertPressbooksToSite.php';

class ConvertPressbooksToSiteTest extends TestCase
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
        haxcmsImportConvertPressbooksToSite($context);
        return json_decode(ob_get_clean(), true);
    }

    public function testMissingRepoUrlAndNoUploadReturns400(): void
    {
        $response = $this->call($this->makeContext());

        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame([], $response['data']['items']);
        $this->assertNull($response['data']['filename']);
        $this->assertSame([], $response['data']['files']);
    }

    public function testHtmlUploadFallbackReturns200WithParsedItems(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pb_upload_');
        file_put_contents($tmp, '<h1>Fallback Doc</h1><p>Body</p>');
        $_FILES['upload'] = ['name' => 'Fallback Doc.html', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(200, $response['status']);
        $this->assertSame('Fallback Doc.html', $response['data']['filename']);
        $this->assertCount(1, $response['data']['items']);
        $this->assertSame('Fallback Doc', $response['data']['items'][0]['title']);
        $this->assertSame('<p>Body</p>', $response['data']['items'][0]['contents']);
    }

    public function testUploadTakesPrecedenceOverRepoUrl(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pb_upload_');
        file_put_contents($tmp, '<p>Only upload should matter</p>');
        $_FILES['upload'] = ['name' => 'doc.html', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext(['repoUrl' => 'https://example.com/book']));
        unlink($tmp);

        $this->assertSame(200, $response['status']);
        $this->assertSame('doc.html', $response['data']['filename']);
    }
}
