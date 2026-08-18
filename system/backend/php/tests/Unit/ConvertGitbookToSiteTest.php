<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/convertGitbookToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load; require_once'd
 * directly here (see ConvertHtmlToSiteTest.php for the rationale, shared
 * across all imports/*.php converter test files).
 *
 * Coverage: the missing/invalid repoUrl validation branches (reachable
 * without network I/O) and haxcmsParseGithubRepoUrl, a pure helper function
 * defined alongside the converter in the same file. The GitHub API/raw-file
 * fetch happy path requires live network access and is out of scope (no
 * established Guzzle-mocking pattern in this suite).
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertGitbookToSite.php';

class ConvertGitbookToSiteTest extends TestCase
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
        haxcmsImportConvertGitbookToSite($context);
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

    // ------------------------------------------------------------------
    // haxcmsParseGithubRepoUrl (pure helper, no network I/O)
    // ------------------------------------------------------------------

    public static function parseGithubRepoUrlProvider(): array
    {
        return [
            'plain repo url' => ['https://github.com/owner/repo', ['owner', 'repo', null]],
            'repo url with tree branch' => ['https://github.com/owner/repo/tree/dev', ['owner', 'repo', 'dev']],
            'repo url with trailing slash on repo' => ['https://github.com/owner/repo/', ['owner', 'repo', null]],
            'non-github host returns null' => ['https://gitlab.com/owner/repo', null],
        ];
    }

    #[DataProvider('parseGithubRepoUrlProvider')]
    public function testHaxcmsParseGithubRepoUrl(string $url, $expected): void
    {
        $this->assertSame($expected, haxcmsParseGithubRepoUrl($url));
    }
}
