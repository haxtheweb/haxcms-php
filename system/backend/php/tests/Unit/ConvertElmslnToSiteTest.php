<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertElmslnToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * This converter has no validation logic beyond the `repoUrl` presence
 * check — every other branch immediately fetches site.json over Guzzle,
 * which is out of scope without an established HTTP-mocking pattern.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertElmslnToSite.php';

class ConvertElmslnToSiteTest extends TestCase
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
        haxcmsImportConvertElmslnToSite($context);
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
}
