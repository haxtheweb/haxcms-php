<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertNotionToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * Coverage: the missing/invalid repoUrl validation branches, reachable
 * without network I/O. The GitHub contents fetch happy path is out of scope.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertNotionToSite.php';

class ConvertNotionToSiteTest extends TestCase
{
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
        haxcmsImportConvertNotionToSite($context);
        return json_decode(ob_get_clean(), true);
    }

    public function testMissingRepoUrlReturns400(): void
    {
        $response = $this->call($this->makeContext());

        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame([], $response['data']['items']);
        $this->assertNull($response['data']['filename']);
        $this->assertSame([], $response['data']['files']);
    }

    public function testBlankRepoUrlAfterTrimReturns400(): void
    {
        $response = $this->call($this->makeContext(['repoUrl' => '   ']));
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
    }

    public function testNonGithubRepoUrlReturns400(): void
    {
        $response = $this->call($this->makeContext(['repoUrl' => 'https://example.com/foo']));

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid GitHub repository URL: https://example.com/foo', $response['data']['error']);
    }
}
