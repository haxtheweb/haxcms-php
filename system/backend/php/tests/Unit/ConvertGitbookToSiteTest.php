<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for lib/systemRoutes/v1/imports/convertGitbookToSite.php.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load (it skips the
 * procedural systemRoutes/v1 dir); require_once'd directly here (see
 * ConvertHtmlToSiteTest.php for the rationale, shared across all
 * imports/*.php converter test files).
 *
 * Coverage:
 *  - the missing/invalid repoUrl validation branches (reachable without
 *    network I/O) and haxcmsParseGithubRepoUrl, a pure helper defined
 *    alongside the converter.
 *  - the pure SUMMARY.md -> items / files-map / reference-rewrite helpers
 *    (haxcmsGitbookBuildFileMaps, haxcmsGitbookFindSummaryPath,
 *    haxcmsGitbookRewriteFileReferences, haxcmsGitbookSummaryHtmlToItems)
 *    which mirror the Node convert-gitbook-to-site.test.cjs cases without
 *    needing Guzzle mocking. The live GitHub API/raw-file happy path is
 *    out of scope (no established Guzzle-mocking pattern in this suite).
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

    // ------------------------------------------------------------------
    // haxcmsGitbookBuildFileMaps (pure; mirrors Node files-map test)
    // ------------------------------------------------------------------

    public function testBuildFileMapsStagesNonMdFilesAndSkipsMarkdownAndFolders(): void
    {
        // Mirrors the Node TREE fixture: markdown files and the extensionless
        // `assets` folder entry must NOT be staged; assets/image.png must.
        $tree = [
            ['path' => 'chapter1.md'],
            ['path' => 'chapter2.md'],
            ['path' => 'section21.md'],
            ['path' => 'assets/image.png'],
            ['path' => 'assets'],
        ];
        list($downloads, $fileMap) = haxcmsGitbookBuildFileMaps($tree, 'owner', 'repo', 'main');

        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/assets/image.png',
            $downloads['files/assets/image.png']
        );
        // markdown files and extensionless folder entries are not staged
        $this->assertArrayNotHasKey('files/chapter1.md', $downloads);
        $this->assertArrayNotHasKey('files/assets', $downloads);
        // fileMap value is the verbatim repo-relative path (no first-slash
        // corruption) so the content rewrite can match page references.
        $this->assertSame('assets/image.png', $fileMap['files/assets/image.png']);
    }

    public function testBuildFileMapsEncodesPathSegmentsInRawUrls(): void
    {
        $tree = [['path' => 'assets/my image.png']];
        list($downloads, $fileMap) = haxcmsGitbookBuildFileMaps($tree, 'owner', 'repo', 'main');

        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/assets/my%20image.png',
            $downloads['files/assets/my image.png']
        );
        // the fileMap value stays raw so it matches raw content references
        $this->assertSame('assets/my image.png', $fileMap['files/assets/my image.png']);
    }

    public function testBuildFileMapsHandlesNonArrayTree(): void
    {
        list($downloads, $fileMap) = haxcmsGitbookBuildFileMaps(null, 'owner', 'repo', 'main');
        $this->assertSame([], $downloads);
        $this->assertSame([], $fileMap);
    }

    // ------------------------------------------------------------------
    // haxcmsGitbookFindSummaryPath (pure)
    // ------------------------------------------------------------------

    public function testFindSummaryPathPrefersRootLevelCaseInsensitive(): void
    {
        $tree = [
            ['path' => 'docs/summary.md'],
            ['path' => 'SUMMARY.md'],
        ];
        $this->assertSame('SUMMARY.md', haxcmsGitbookFindSummaryPath($tree));
    }

    public function testFindSummaryPathFallsBackToDeepMatch(): void
    {
        $tree = [['path' => 'book/SUMMARY.md']];
        $this->assertSame('book/SUMMARY.md', haxcmsGitbookFindSummaryPath($tree));
    }

    public function testFindSummaryPathReturnsNullWhenAbsent(): void
    {
        $tree = [['path' => 'chapter1.md'], ['path' => 'assets/image.png']];
        $this->assertNull(haxcmsGitbookFindSummaryPath($tree));
    }

    // ------------------------------------------------------------------
    // haxcmsGitbookRewriteFileReferences (pure; mirrors Node rewrite tests)
    // ------------------------------------------------------------------

    public function testRewriteCatchesLeadingSlashFormAndPointsAtFilesPath(): void
    {
        // gitbook markdown renders ![](/assets/image.png) -> src="/assets/image.png"
        $fileMap = ['files/assets/image.png' => 'assets/image.png'];
        $content = '<p><img src="/assets/image.png" alt="image"></p>';
        $out = haxcmsGitbookRewriteFileReferences($content, $fileMap);

        $this->assertStringContainsString('src="files/assets/image.png"', $out);
        $this->assertStringNotContainsString('src="/assets/image.png"', $out);
        // the corrupted first-slash-stripped form must never appear
        $this->assertStringNotContainsString('assetsimage.png', $out);
    }

    public function testRewriteCatchesBareRelativeForm(): void
    {
        $fileMap = ['files/assets/image.png' => 'assets/image.png'];
        $content = '<p><img src="assets/image.png" alt="image"></p>';
        $out = haxcmsGitbookRewriteFileReferences($content, $fileMap);

        $this->assertStringContainsString('src="files/assets/image.png"', $out);
        $this->assertStringNotContainsString('src="assets/image.png"', $out);
    }

    // ------------------------------------------------------------------
    // haxcmsGitbookSummaryHtmlToItems (pure given a mock page fetcher;
    // mirrors the Node nested-SUMMARY structure test)
    // ------------------------------------------------------------------

    public function testSummaryHtmlToItemsBuildsNestedItemsWithCorrectShape(): void
    {
        // Mirrors the Node SUMMARY_MD fixture: Chapter 2 has a child Section 2.1.
        $summary = "# Summary\n\n* [Chapter 1](chapter1.md)\n* [Chapter 2](chapter2.md)\n    * [Section 2.1](section21.md)\n";

        // mock per-page fetcher: chapter1 references /assets/image.png (leading
        // slash) so the rewrite is exercised; others are plain markdown.
        $pageFetcher = function ($href) {
            if ($href === 'chapter1.md') {
                return "# Chapter 1\n\n![image](/assets/image.png)\n";
            }
            if ($href === 'chapter2.md') {
                return 'Chapter 2 content';
            }
            if ($href === 'section21.md') {
                return 'Section 2.1 content';
            }
            return '';
        };
        $fileMap = ['files/assets/image.png' => 'assets/image.png'];

        $items = haxcmsGitbookSummaryHtmlToItems($summary, $pageFetcher, $fileMap, null);

        $this->assertCount(3, $items);

        $chapter1 = $items[0];
        $chapter2 = $items[1];
        $section21 = $items[2];

        $this->assertSame('Chapter 1', $chapter1['title']);
        $this->assertSame('', $chapter1['parent']);
        $this->assertSame(0, $chapter1['indent']);
        $this->assertSame('chapter1.md', $chapter1['slug']);
        $this->assertSame('content/chapter1.md', $chapter1['location']);
        // leading-slash image reference rewritten to files/assets/image.png
        $this->assertStringContainsString('src="files/assets/image.png"', $chapter1['contents']);
        $this->assertStringNotContainsString('src="/assets/image.png"', $chapter1['contents']);

        $this->assertSame('Chapter 2', $chapter2['title']);
        $this->assertSame('', $chapter2['parent']);
        $this->assertSame(0, $chapter2['indent']);
        $this->assertSame('chapter2.md', $chapter2['slug']);
        $this->assertSame('content/chapter2.md', $chapter2['location']);

        // nested child maps its `parent` to the chapter2 item's generated id
        $this->assertSame('Section 2.1', $section21['title']);
        $this->assertSame($chapter2['id'], $section21['parent']);
        $this->assertSame(1, $section21['indent']);
        $this->assertSame('section21.md', $section21['slug']);
        $this->assertSame('content/section21.md', $section21['location']);
        $this->assertStringContainsString('Section 2.1 content', $section21['contents']);
    }

    public function testSummaryHtmlToItemsAppliesParentIdOverrideToTopLevel(): void
    {
        $summary = "* [One](one.md)\n";
        $items = haxcmsGitbookSummaryHtmlToItems($summary, function ($h) { return ''; }, [], 'parent-uuid');

        $this->assertCount(1, $items);
        $this->assertSame('parent-uuid', $items[0]['parent']);
        $this->assertSame(0, $items[0]['indent']);
    }

    public function testSummaryHtmlToItemsReturnsEmptyForEmptyMarkdown(): void
    {
        $items = haxcmsGitbookSummaryHtmlToItems('', function ($h) { return ''; }, [], null);
        $this->assertSame([], $items);
    }
}
