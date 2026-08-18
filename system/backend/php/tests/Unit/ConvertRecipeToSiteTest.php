<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertRecipeToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * The converter shells out to `which hax` and, if found, `hax recipe ...`
 * to try to process the recipe before falling back to its own items/pages
 * array logic. To make tests deterministic regardless of whether the `hax`
 * CLI binary happens to be on PATH in a given dev/CI environment, PATH is
 * forced empty in setUp/tearDown so `which hax` always returns nothing and
 * the fallback branch (recipe['items']/recipe['pages']) is exercised
 * reliably. This was verified directly: with PATH forced empty, `shell_exec`
 * returns '' for `which hax`, guaranteeing the code path under test.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertRecipeToSite.php';

class ConvertRecipeToSiteTest extends TestCase
{
    private $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = getenv('PATH');
        putenv('PATH=');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . ($this->originalPath === false ? '' : $this->originalPath));
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
        haxcmsImportConvertRecipeToSite($context);
        return json_decode(ob_get_clean(), true);
    }

    public function testMissingRecipeContentReturns400(): void
    {
        $response = $this->call($this->makeContext());

        $this->assertSame(400, $response['status']);
        $this->assertSame('missing recipe content, file upload, or `repoUrl` param', $response['data']['error']);
        $this->assertSame([], $response['data']['items']);
        $this->assertNull($response['data']['filename']);
    }

    public function testInvalidJsonRecipeStringReturns400(): void
    {
        $response = $this->call($this->makeContext(['recipe' => 'not json{']));

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid recipe format - expected JSON', $response['data']['error']);
        $this->assertSame('recipe.json', $response['data']['filename']);
    }

    public function testRecipeWithItemsArrayBuildsItemsDirectly(): void
    {
        $response = $this->call($this->makeContext([
            'recipe' => ['items' => [
                ['title' => 'Page One', 'slug' => 'p1'],
            ]],
        ]));

        $this->assertSame(200, $response['status']);
        $this->assertSame('recipe', $response['data']['filename']);
        $this->assertCount(1, $response['data']['items']);
        $this->assertSame('Page One', $response['data']['items'][0]['title']);
        $this->assertSame('p1', $response['data']['items'][0]['slug']);
        $this->assertSame(0, $response['data']['items'][0]['order']);
        $this->assertNull($response['data']['items'][0]['parent']);
        $this->assertNotSame('', $response['data']['items'][0]['id']);
    }

    public function testRecipeWithPagesArrayBuildsItemsFromContentField(): void
    {
        $response = $this->call($this->makeContext([
            'recipe' => ['pages' => [
                ['title' => 'Page Two', 'content' => '<p>c</p>'],
            ]],
        ]));

        $this->assertSame(200, $response['status']);
        $this->assertCount(1, $response['data']['items']);
        $this->assertSame('Page Two', $response['data']['items'][0]['title']);
        $this->assertSame('page-two', $response['data']['items'][0]['slug']);
        $this->assertSame('<p>c</p>', $response['data']['items'][0]['contents']);
    }

    public function testRecipeItemsUsesParentIdWhenItemHasNoParent(): void
    {
        $response = $this->call($this->makeContext([
            'parentId' => 'parent-xyz',
            'recipe' => ['items' => [
                ['title' => 'Child'],
            ]],
        ]));

        $this->assertSame('parent-xyz', $response['data']['items'][0]['parent']);
    }

    public function testRecipeAsRawJsonStringIsAccepted(): void
    {
        $response = $this->call($this->makeContext([
            'recipe' => json_encode(['items' => [['title' => 'From String']]]),
        ]));

        $this->assertSame(200, $response['status']);
        $this->assertSame('From String', $response['data']['items'][0]['title']);
    }

    public function testFileUploadIsParsedAsRecipeJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'recipe_upload_');
        file_put_contents($tmp, json_encode(['items' => [['title' => 'Uploaded Page']]]));
        $_FILES['upload'] = ['name' => 'my-recipe.json', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(200, $response['status']);
        $this->assertSame('my-recipe', $response['data']['filename']);
        $this->assertSame('Uploaded Page', $response['data']['items'][0]['title']);
    }

    public function testEmptyRecipeStringUploadReturns400(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'recipe_upload_');
        file_put_contents($tmp, '   ');
        $_FILES['upload'] = ['name' => 'blank.json', 'tmp_name' => $tmp];

        $response = $this->call($this->makeContext());
        unlink($tmp);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Empty recipe content', $response['data']['error']);
    }

    public function testRecipeWithNeitherItemsNorPagesReturnsEmptyItemsArray(): void
    {
        $response = $this->call($this->makeContext(['recipe' => ['title' => 'no pages here']]));

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['data']['items']);
    }
}
