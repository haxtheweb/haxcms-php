<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertWordpressToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * Coverage: missing-repoUrl and unknown-adapter validation branches, both
 * reachable without network I/O (the adapter check runs before any WP REST
 * API call is attempted). The WordPress pages-fetch happy path is out of
 * scope without an established HTTP-mocking pattern.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertWordpressToSite.php';

class ConvertWordpressToSiteTest extends TestCase
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
        haxcmsImportConvertWordpressToSite($context);
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

    public function testUnknownAdapterReturns400BeforeAnyNetworkCall(): void
    {
        $response = $this->call($this->makeContext([
            'repoUrl' => 'http://example.com',
            'adapter' => 'bogus',
        ]));

        $this->assertSame(400, $response['status']);
        $this->assertSame('unknown adapter `bogus`; valid adapters: pages', $response['data']['error']);
    }
}
