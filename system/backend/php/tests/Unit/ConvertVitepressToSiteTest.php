<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Unit tests for lib/systemRoutes/v1/imports/convertVitepressToSite.php
 * (haxtheweb/issues#2923). Mirrors haxcms-nodejs
 * test/unit/convertVitepressToSite.test.cjs.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load (it skips
 * systemRoutes/v1), so the converter is require_once'd directly here, as the
 * other imports/*.php converter tests do.
 *
 * Unlike the sibling converter tests, this one covers the whole route rather
 * than only the validation branches: haxcmsImportVitepressRun takes the HTTP
 * client, so the GitHub API and raw reads are answered by a Guzzle handler over
 * an in-memory fixture repository - the MockHandler pattern StageRemoteFileTest
 * introduced. The GitHub origins are overridden to an IP literal so SsrfGuard's
 * address check needs no DNS, and the request log doubles as the assertion that
 * every call carries the User-Agent the GitHub API demands.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertVitepressToSite.php';

class ConvertVitepressToSiteTest extends TestCase
{
    const OWNER = 'dmd-program';
    const REPO = 'dmd-100-book';
    const API = 'http://93.184.215.14/api';
    const RAW = 'http://93.184.215.14/raw';

    private $requests = array();

    protected function setUp(): void
    {
        $this->requests = array();
        // the delay is real time in the page loop; nothing here needs it
        $GLOBALS['HAXCMS_VITEPRESS_LIMITS'] = array('requestDelayMs' => 0);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS_VITEPRESS_LIMITS']);
    }

    private function limits(array $limits): void
    {
        $GLOBALS['HAXCMS_VITEPRESS_LIMITS'] = array_merge(array('requestDelayMs' => 0), $limits);
    }

    // ------------------------------------------------------------------
    // the fixture repository
    // ------------------------------------------------------------------

    private function config(): string
    {
        return <<<'CONFIG'
import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'DMD 100',
  description: 'A course book',
  base: '/dmd-100-book/',
  themeConfig: {
    license: 'cc-by-sa',
    defaultAuthor: 'Michael Collins',
    workTitle: 'DMD 100 Book',
    siteUrl: 'https://example.edu',
    sidebar: [
      { text: 'Home', link: '/' },
      {
        text: 'Chapter One',
        items: [
          { text: 'Lesson One', link: '/chapter-1/lesson-one' },
          { text: 'Lesson Two', link: '/chapter-1/lesson-two' },
        ],
      },
    ],
  },
})
CONFIG;
    }

    private function files(array $overrides = array()): array
    {
        $files = array(
            'docs/.vitepress/config.mjs' => $this->config(),
            'docs/index.md' => "# Home\n\nWelcome to the [first lesson](/chapter-1/lesson-one).\n\n![Diagram](/assets/diagram.png)\n",
            'docs/chapter-1/lesson-one.md' => "---\ntitle: Lesson One Revised\nlicense: cc-by\nauthor: Pat Lee\n---\n\n"
                . "Intro text with a footnote.[^1]\n\n"
                . "::: learning-objective skill=\"Design\" course=\"DMD 100\"\nDescribe the design process.\n:::\n\n"
                . "<VideoEmbed src=\"/assets/videos/demo.webm\" type=\"local\" title=\"Demo\" caption=\"A short demo\" />\n\n"
                . "![Local diagram](../assets/diagram.png)\n\n"
                . "[^1]: The footnote body.\n",
            'docs/chapter-1/lesson-two.md' => "# Lesson Two\n\n"
                . "<VideoEmbed src=\"https://youtu.be/abc123\" type=\"youtube\" title=\"Intro\" />\n\n"
                . "<LicenseFooter />\n\n"
                . "Back to [lesson one](./lesson-one#top).\n\n"
                . "![Logo](/assets/logo.svg)\n\n"
                . "The [notes](/assets/notes.pdf) and the [cover](/cover.jpg).\n\n"
                . "![Shared](/public/diagram.png)\n",
            'docs/assets/diagram.png' => 'PNGDATA',
            'docs/assets/logo.svg' => '<svg></svg>',
            'docs/assets/notes.pdf' => 'PDFDATA',
            'docs/assets/videos/demo.webm' => 'WEBMDATA',
            'docs/public/cover.jpg' => 'JPGDATA',
            'docs/public/diagram.png' => 'OTHERPNG',
        );
        foreach ($overrides as $path => $contents) {
            if ($contents === null) {
                unset($files[$path]);
                continue;
            }
            $files[$path] = $contents;
        }
        return $files;
    }

    /** A client that answers from the fixture repository, logging every call. */
    private function client(array $files, array $statusOverrides = array()): Client
    {
        $requests = &$this->requests;
        $owner = self::OWNER;
        $repo = self::REPO;
        $handler = function (RequestInterface $request, array $options) use ($files, $statusOverrides, &$requests, $owner, $repo) {
            $uri = $request->getUri();
            $path = $uri->getPath();
            $requests[] = array(
                'path'      => $path,
                'query'     => $uri->getQuery(),
                'userAgent' => $request->getHeaderLine('User-Agent'),
                'accept'    => $request->getHeaderLine('Accept'),
            );
            foreach ($statusOverrides as $needle => $status) {
                if (strpos($path, $needle) !== false) {
                    return new FulfilledPromise(new Response($status, array(), 'unavailable'));
                }
            }
            if (preg_match('#/api/repos/[^/]+/[^/]+$#', $path)) {
                return new FulfilledPromise(new Response(200, array(), json_encode(array('default_branch' => 'main'))));
            }
            if (strpos($path, '/git/trees/') !== false) {
                $tree = array();
                foreach (array_keys($files) as $file) {
                    $tree[] = array('path' => $file, 'type' => 'blob');
                }
                return new FulfilledPromise(new Response(200, array(), json_encode(array('tree' => $tree))));
            }
            $prefix = '/raw/' . $owner . '/' . $repo . '/';
            if (strpos($path, $prefix) === 0) {
                $rest = substr($path, strlen($prefix));
                $slash = strpos($rest, '/');
                $repoPath = $slash === false ? '' : rawurldecode(substr($rest, $slash + 1));
                if (isset($files[$repoPath])) {
                    return new FulfilledPromise(new Response(200, array(), $files[$repoPath]));
                }
            }
            return new FulfilledPromise(new Response(404, array(), 'not found'));
        };
        return new Client(array('handler' => HandlerStack::create($handler)));
    }

    private function import($body, $files = null, array $statusOverrides = array()): array
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = is_array($body) ? $body : array('repoUrl' => 'https://github.com/' . self::OWNER . '/' . self::REPO);
        $client = $this->client($files === null ? $this->files() : $files, $statusOverrides);
        ob_start();
        try {
            haxcmsImportVitepressRun($context, $client, array('api' => self::API, 'raw' => self::RAW));
        }
        finally {
            $printed = ob_get_clean();
        }
        return json_decode($printed, true);
    }

    private function itemBy(array $items, string $title): ?array
    {
        foreach ($items as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }
        return null;
    }

    private function allContents(array $items): string
    {
        $body = '';
        foreach ($items as $item) {
            $body .= isset($item['contents']) ? $item['contents'] : '';
        }
        return $body;
    }

    // ------------------------------------------------------------------
    // request validation
    // ------------------------------------------------------------------

    public function testMissingRepoUrlReturns400(): void
    {
        $response = $this->import(array());
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame(array(), $response['data']['items']);
        $this->assertNull($response['data']['filename']);
        $this->assertSame(array(), $response['data']['files']);
    }

    public function testBlankRepoUrlAfterTrimReturns400(): void
    {
        $response = $this->import(array('repoUrl' => '   '));
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
    }

    public function testNonGithubHostReturns400(): void
    {
        $response = $this->import(array('repoUrl' => 'https://gitlab.com/owner/repo'));
        $this->assertSame(400, $response['status']);
        $this->assertSame('repoUrl must be a github.com repository URL', $response['data']['error']);
    }

    public function testRepoUrlWithoutOwnerAndRepoReturns400(): void
    {
        $response = $this->import(array('repoUrl' => 'https://github.com/owner'));
        $this->assertSame(400, $response['status']);
        $this->assertSame('repoUrl is missing the owner/repo path: https://github.com/owner', $response['data']['error']);
    }

    public function testUnparseableRepoUrlReturns400(): void
    {
        $response = $this->import(array('repoUrl' => 'not-a-url'));
        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid repoUrl: not-a-url', $response['data']['error']);
    }

    public function testMissingRepositoryReturns422(): void
    {
        $response = $this->import(null, null, array('/api/repos/' => 404));
        $this->assertSame(422, $response['status']);
        $this->assertSame(
            'Unable to read the repository ' . self::OWNER . '/' . self::REPO . ' (HTTP 404)',
            $response['data']['error']
        );
    }

    public function testUnreadableFileTreeReturns400(): void
    {
        $response = $this->import(null, null, array('/git/trees/' => 500));
        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Unable to read the file tree of ' . self::OWNER . '/' . self::REPO . ' (HTTP 500)',
            $response['data']['error']
        );
    }

    public function testRepositoryWithoutAVitepressConfigReturns422(): void
    {
        $response = $this->import(null, $this->files(array('docs/.vitepress/config.mjs' => null)));
        $this->assertSame(422, $response['status']);
        $this->assertSame(
            'No .vitepress/config file found in ' . self::OWNER . '/' . self::REPO,
            $response['data']['error']
        );
    }

    public function testUnreadableConfigReturns422(): void
    {
        $response = $this->import(null, null, array('config.mjs' => 500));
        $this->assertSame(422, $response['status']);
        $this->assertSame('Unable to read docs/.vitepress/config.mjs', $response['data']['error']);
    }

    public function testASiteWithNoPagesReturns422(): void
    {
        $bare = array('docs/.vitepress/config.mjs' => "export default { title: 'Empty' }\n");
        $response = $this->import(null, $bare);
        $this->assertSame(422, $response['status']);
        $this->assertSame('The VitePress site has no pages to import', $response['data']['error']);
    }

    // ------------------------------------------------------------------
    // repository resolution
    // ------------------------------------------------------------------

    public function testTheDefaultBranchIsUsedWhenTheUrlNamesNone(): void
    {
        $response = $this->import(null);
        $this->assertSame(200, $response['status']);
        $rawCalls = array_filter($this->requests, function ($call) {
            return strpos($call['path'], '/raw/') === 0;
        });
        $this->assertNotEmpty($rawCalls);
        foreach ($rawCalls as $call) {
            $this->assertStringContainsString('/raw/' . self::OWNER . '/' . self::REPO . '/main/', $call['path']);
        }
    }

    public function testABranchInTheUrlIsHonoredAndAGitSuffixStripped(): void
    {
        $response = $this->import(array('repoUrl' => 'https://github.com/' . self::OWNER . '/' . self::REPO . '.git/tree/dev'));
        $this->assertSame(200, $response['status']);
        $treeCalls = array_filter($this->requests, function ($call) {
            return strpos($call['path'], '/git/trees/') !== false;
        });
        $this->assertNotEmpty($treeCalls);
        foreach ($treeCalls as $call) {
            $this->assertStringEndsWith('/git/trees/dev', $call['path']);
            $this->assertSame('recursive=1', $call['query']);
        }
    }

    public function testEveryGithubCallCarriesTheUserAgentTheApiDemands(): void
    {
        $this->import(null);
        $this->assertNotEmpty($this->requests);
        foreach ($this->requests as $call) {
            $this->assertSame('HAXcms-Import/1.0', $call['userAgent'], $call['path']);
        }
        $apiCalls = array_filter($this->requests, function ($call) {
            return strpos($call['path'], '/api/') === 0;
        });
        $this->assertNotEmpty($apiCalls);
        foreach ($apiCalls as $call) {
            $this->assertSame('application/vnd.github.v3+json', $call['accept']);
        }
    }

    public function testADocsConfigWinsOverOneAtTheRepositoryRoot(): void
    {
        $files = $this->files(array('.vitepress/config.mjs' => "export default { title: 'Root config' }\n"));
        $response = $this->import(null, $files);
        $this->assertSame(200, $response['status']);
        // the docs config carries the title, and its sidebar drives the outline
        $this->assertSame('DMD 100', $response['data']['filename']);
        $this->assertSame('Home', $response['data']['items'][0]['title']);
    }

    // ------------------------------------------------------------------
    // the outline
    // ------------------------------------------------------------------

    public function testTheSidebarBecomesANestedOutline(): void
    {
        $response = $this->import(null);
        $items = $response['data']['items'];
        $this->assertCount(4, $items);
        $this->assertSame(array('Home', 'Chapter One', 'Lesson One Revised', 'Lesson Two'), array_map(function ($item) {
            return $item['title'];
        }, $items));
        $this->assertSame(array(0, 0, 1, 1), array_map(function ($item) {
            return $item['indent'];
        }, $items));
        $this->assertSame(array(0, 1, 0, 1), array_map(function ($item) {
            return $item['order'];
        }, $items));
        $this->assertNull($items[0]['parent']);
        $this->assertNull($items[1]['parent']);
        $this->assertSame($items[1]['id'], $items[2]['parent']);
        $this->assertSame($items[1]['id'], $items[3]['parent']);
        $this->assertSame('chapter-one/lesson-one', $items[2]['slug']);
        $this->assertSame('chapter-one/lesson-two', $items[3]['slug']);
    }

    public function testAGroupWithoutALinkIsALandingPage(): void
    {
        $response = $this->import(null);
        $chapter = $this->itemBy($response['data']['items'], 'Chapter One');
        $this->assertNotNull($chapter);
        $this->assertSame('<p></p>', $chapter['contents']);
        $this->assertNull($chapter['metadata']['vitepress']['path']);
    }

    public function testASidebarMapOfPathPrefixesIsMerged(): void
    {
        $config = "export default {\n  themeConfig: {\n    sidebar: {\n      '/chapter-1/': [\n        { text: 'Lesson One', link: '/chapter-1/lesson-one' },\n      ],\n      '/': [\n        { text: 'Home', link: '/' },\n      ],\n    },\n  },\n}\n";
        $response = $this->import(null, $this->files(array('docs/.vitepress/config.mjs' => $config)));
        $this->assertSame(200, $response['status']);
        $titles = array_map(function ($item) {
            return $item['title'];
        }, $response['data']['items']);
        $this->assertContains('Lesson One Revised', $titles);
        $this->assertContains('Home', $titles);
        $this->assertCount(2, $titles);
    }

    public function testAConfigThatIsNotPlainDataFallsBackToTheFileTree(): void
    {
        $config = "export default {\n  themeConfig: {\n    sidebar: buildSidebar(process.cwd()),\n  },\n}\n";
        $response = $this->import(null, $this->files(array('docs/.vitepress/config.mjs' => $config)));
        $this->assertSame(200, $response['status']);
        $titles = array_map(function ($item) {
            return $item['title'];
        }, $response['data']['items']);
        // every markdown page in the docs root, index first, and nothing else
        $this->assertSame(array('Home', 'Lesson One Revised', 'Lesson two'), $titles);
        foreach ($response['data']['items'] as $item) {
            $this->assertSame(0, $item['indent']);
        }
    }

    public function testAnEmptySidebarMapFallsBackToTheFileTree(): void
    {
        // VitePress accepts an empty multi-sidebar map
        $config = "export default {\n  themeConfig: {\n    sidebar: {},\n  },\n}\n";
        $response = $this->import(null, $this->files(array('docs/.vitepress/config.mjs' => $config)));
        $this->assertSame(200, $response['status'], isset($response['data']['error']) ? $response['data']['error'] : '');
        $this->assertCount(3, $response['data']['items']);
    }

    public function testAnEmptySidebarArrayIsASiteWithNoPages(): void
    {
        // [] is a sidebar that names no pages, which is not the same as {}:
        // haxcms-nodejs 422s here too, so the PHP literal reader has to keep
        // JS arrays and objects apart
        $config = "export default {\n  themeConfig: {\n    sidebar: [],\n  },\n}\n";
        $response = $this->import(null, $this->files(array('docs/.vitepress/config.mjs' => $config)));
        $this->assertSame(422, $response['status']);
        $this->assertSame('The VitePress site has no pages to import', $response['data']['error']);
    }

    // ------------------------------------------------------------------
    // the page pipeline
    // ------------------------------------------------------------------

    public function testFrontmatterSuppliesTheTitle(): void
    {
        $response = $this->import(null);
        $this->assertNotNull($this->itemBy($response['data']['items'], 'Lesson One Revised'));
        $this->assertNull($this->itemBy($response['data']['items'], 'Lesson One'));
    }

    public function testEachPageCarriesItsSourceMetadata(): void
    {
        $response = $this->import(null);
        $lesson = $this->itemBy($response['data']['items'], 'Lesson One Revised');
        $this->assertSame('vitepress', $lesson['metadata']['sourceType']);
        $this->assertSame(self::OWNER . '/' . self::REPO, $lesson['metadata']['vitepress']['repo']);
        $this->assertSame('main', $lesson['metadata']['vitepress']['branch']);
        $this->assertSame('docs/chapter-1/lesson-one.md', $lesson['metadata']['vitepress']['path']);
        $this->assertSame('DMD 100 Book', $lesson['metadata']['vitepress']['workTitle']);
        $this->assertSame('https://example.edu/dmd-100-book/chapter-1/lesson-one', $lesson['metadata']['source']);
        $this->assertNotSame('', $lesson['metadata']['vitepress']['accessed']);
    }

    public function testFrontmatterLicenseAndAuthorWinOverTheSiteLevelOnes(): void
    {
        $response = $this->import(null);
        $lesson = $this->itemBy($response['data']['items'], 'Lesson One Revised');
        $this->assertSame('by', $lesson['metadata']['vitepress']['license']);
        $this->assertSame('Pat Lee', $lesson['metadata']['vitepress']['author']);
        $this->assertStringContainsString('<license-element license="by"', $lesson['contents']);
        $this->assertStringContainsString('creator="Pat Lee"', $lesson['contents']);
        $this->assertStringContainsString('source="https://example.edu/dmd-100-book/chapter-1/lesson-one"', $lesson['contents']);
    }

    public function testThePageWithoutAFrontmatterLicenseUsesTheSiteLicense(): void
    {
        $response = $this->import(null);
        $this->assertSame('by-sa', $response['data']['site']['license']);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('<license-element license="by-sa"', $lessonTwo['contents']);
        $this->assertStringContainsString('creator="Michael Collins"', $lessonTwo['contents']);
    }

    public function testAnUnsupportedLicenseCodeIsDropped(): void
    {
        $config = str_replace("license: 'cc-by-sa'", "license: 'all-rights-reserved'", $this->config());
        $response = $this->import(null, $this->files(array('docs/.vitepress/config.mjs' => $config)));
        $this->assertNull($response['data']['site']['license']);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringNotContainsString('<license-element', $lessonTwo['contents']);
    }

    public function testAnOerContainerBecomesOerSchema(): void
    {
        $response = $this->import(null);
        $lesson = $this->itemBy($response['data']['items'], 'Lesson One Revised');
        $this->assertStringContainsString('<oer-schema typeof="LearningObjective">', $lesson['contents']);
        $this->assertStringContainsString('<oer-schema oer-property="skill" text="Design"></oer-schema>', $lesson['contents']);
        $this->assertStringContainsString('<oer-schema oer-property="forCourse" text="DMD 100"></oer-schema>', $lesson['contents']);
        $this->assertStringContainsString('Describe the design process.', $lesson['contents']);
        $this->assertStringNotContainsString(':::', $lesson['contents']);
    }

    public function testFootnotesSurviveTheRender(): void
    {
        $response = $this->import(null);
        $lesson = $this->itemBy($response['data']['items'], 'Lesson One Revised');
        $this->assertStringContainsString('footnote', $lesson['contents']);
        $this->assertStringContainsString('The footnote body.', $lesson['contents']);
        $this->assertStringNotContainsString('[^1]', $lesson['contents']);
    }

    public function testALocalVideoEmbedBecomesAVideoPlayerOnAnImportedFile(): void
    {
        $response = $this->import(null);
        $lesson = $this->itemBy($response['data']['items'], 'Lesson One Revised');
        $this->assertStringContainsString('<video-player source="files/demo.webm" media-title="Demo">', $lesson['contents']);
        $this->assertStringContainsString('<div slot="caption">A short demo</div>', $lesson['contents']);
        $this->assertArrayHasKey('files/demo.webm', $response['data']['files']);
        $this->assertSame(
            self::RAW . '/' . self::OWNER . '/' . self::REPO . '/main/docs/assets/videos/demo.webm',
            $response['data']['files']['files/demo.webm']
        );
    }

    public function testARemoteVideoEmbedStaysRemote(): void
    {
        $response = $this->import(null);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('<video-player source="https://youtu.be/abc123" media-title="Intro">', $lessonTwo['contents']);
        $this->assertArrayNotHasKey('files/abc123', $response['data']['files']);
    }

    public function testAVideoEmbedWithoutASourceIsDropped(): void
    {
        $page = "# Only\n\n<VideoEmbed type=\"local\" title=\"No source\" />\n";
        $response = $this->import(null, $this->files(array('docs/chapter-1/lesson-two.md' => $page)));
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringNotContainsString('<video-player', $lessonTwo['contents']);
        $this->assertStringNotContainsString('VideoEmbed', $lessonTwo['contents']);
    }

    public function testAnUnmappedComponentIsUnwrappedAndReported(): void
    {
        $response = $this->import(null);
        $this->assertSame(array('LicenseFooter'), $response['data']['unmappedComponents']);
        // case insensitively: DOMDocument lowercases unknown tags, so a wrapper
        // left in the page would surface as <licensefooter>
        $this->assertStringNotContainsStringIgnoringCase(
            'licensefooter',
            $this->allContents($response['data']['items'])
        );
    }

    // ------------------------------------------------------------------
    // assets and links
    // ------------------------------------------------------------------

    public function testImagesBecomeMediaImageWithTheirAltText(): void
    {
        $response = $this->import(null);
        $home = $this->itemBy($response['data']['items'], 'Home');
        $this->assertStringContainsString('<media-image source="files/diagram.png" alt="Diagram">', $home['contents']);
        $this->assertStringNotContainsString('<img', $home['contents']);
        $lesson = $this->itemBy($response['data']['items'], 'Lesson One Revised');
        $this->assertStringContainsString('alt="Local diagram"', $lesson['contents']);
    }

    public function testAnImageTheRepositoryDoesNotCarryStaysAnImageOnTheSource(): void
    {
        $page = "# Only\n\n![Missing](/assets/nope.png)\n";
        $response = $this->import(null, $this->files(array('docs/chapter-1/lesson-two.md' => $page)));
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('<media-image source="' . self::RAW . '/' . self::OWNER . '/' . self::REPO . '/main/docs/assets/nope.png"', $lessonTwo['contents']);
        $this->assertArrayNotHasKey('files/nope.png', $response['data']['files']);
    }

    public function testAnExtensionCreateSiteWillNotImportStaysRemote(): void
    {
        $response = $this->import(null);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('src="' . self::RAW . '/' . self::OWNER . '/' . self::REPO . '/main/docs/assets/logo.svg"', $lessonTwo['contents']);
        $this->assertArrayNotHasKey('files/logo.svg', $response['data']['files']);
    }

    public function testAPublicFolderAssetIsFoundFromTheSiteRoot(): void
    {
        $response = $this->import(null);
        $this->assertArrayHasKey('files/cover.jpg', $response['data']['files']);
        $this->assertSame(
            self::RAW . '/' . self::OWNER . '/' . self::REPO . '/main/docs/public/cover.jpg',
            $response['data']['files']['files/cover.jpg']
        );
    }

    public function testDuplicateBasenamesAreMadeUnique(): void
    {
        $response = $this->import(null);
        $this->assertArrayHasKey('files/diagram.png', $response['data']['files']);
        $this->assertArrayHasKey('files/diagram-1.png', $response['data']['files']);
        $this->assertSame(
            self::RAW . '/' . self::OWNER . '/' . self::REPO . '/main/docs/public/diagram.png',
            $response['data']['files']['files/diagram-1.png']
        );
    }

    public function testALinkToAnImportableFileJoinsTheFileMap(): void
    {
        $response = $this->import(null);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('href="files/notes.pdf"', $lessonTwo['contents']);
        $this->assertArrayHasKey('files/notes.pdf', $response['data']['files']);
    }

    public function testInBookLinksPointAtTheImportedSlugsAndKeepTheirHash(): void
    {
        $response = $this->import(null);
        $home = $this->itemBy($response['data']['items'], 'Home');
        $this->assertStringContainsString('href="chapter-one/lesson-one"', $home['contents']);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('href="chapter-one/lesson-one#top"', $lessonTwo['contents']);
    }

    public function testTheConfiguredBasePrefixIsStrippedFromLinks(): void
    {
        $page = "# Only\n\nSee [the lesson](/dmd-100-book/chapter-1/lesson-one).\n";
        $response = $this->import(null, $this->files(array('docs/index.md' => $page)));
        $home = $this->itemBy($response['data']['items'], 'Home');
        $this->assertStringContainsString('href="chapter-one/lesson-one"', $home['contents']);
        $this->assertStringNotContainsString('href="/dmd-100-book', $home['contents']);
    }

    // ------------------------------------------------------------------
    // safety valves
    // ------------------------------------------------------------------

    public function testThePageCapTruncatesAndLinksTheRestToTheirSource(): void
    {
        $this->limits(array('maxPages' => 1));
        $response = $this->import(null);
        $this->assertTrue($response['data']['truncated']);
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertSame(
            '<p>Read this page on <a href="https://example.edu/dmd-100-book/chapter-1/lesson-two">the source site</a>.</p>',
            $lessonTwo['contents']
        );
    }

    public function testTheFileCapStopsRegisteringFiles(): void
    {
        $this->limits(array('maxFiles' => 1));
        $response = $this->import(null);
        $this->assertCount(1, $response['data']['files']);
        $this->assertTrue($response['data']['truncated']);
    }

    public function testAnExhaustedTimeBudgetTruncatesEveryPage(): void
    {
        $this->limits(array('fetchBudgetSeconds' => -1));
        $response = $this->import(null);
        $this->assertTrue($response['data']['truncated']);
        $home = $this->itemBy($response['data']['items'], 'Home');
        $this->assertStringContainsString('the source site', $home['contents']);
    }

    // ------------------------------------------------------------------
    // the dispatcher case
    // ------------------------------------------------------------------

    public function testTheSiteImportDispatcherRoutesVitepressToThisConverter(): void
    {
        $context = new VitepressDispatcherContext('vitepress', array());
        $dispatcher = include __DIR__ . '/../../lib/systemRoutes/v1/siteImport.php';
        ob_start();
        try {
            $dispatcher($context);
        }
        finally {
            $printed = ob_get_clean();
        }
        $response = json_decode($printed, true);
        // the converter's own validation message, so the case reaches it
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
    }

    public function testAnUnknownPlatformStillReportsItAsUnsupported(): void
    {
        $context = new VitepressDispatcherContext('vitepres', array());
        $dispatcher = include __DIR__ . '/../../lib/systemRoutes/v1/siteImport.php';
        ob_start();
        try {
            $dispatcher($context);
        }
        finally {
            $printed = ob_get_clean();
        }
        $response = json_decode($printed, true);
        $this->assertSame(400, $response['status']);
        $this->assertSame('Unsupported import platform "vitepres"', $response['data']['error']);
    }

    public function testAPageThatCannotBeFetchedLinksToItsSource(): void
    {
        $response = $this->import(null, $this->files(array('docs/chapter-1/lesson-two.md' => null)));
        $lessonTwo = $this->itemBy($response['data']['items'], 'Lesson Two');
        $this->assertStringContainsString('the source site', $lessonTwo['contents']);
        $this->assertFalse($response['data']['truncated']);
    }
}

/** The bit of request context the site import dispatcher reads. */
class VitepressDispatcherContext
{
    public $apiBasePath = '/system/api';
    public $routeSuffix = '';
    public $body;
    private $platform;

    public function __construct($platform, $body)
    {
        $this->platform = $platform;
        $this->body = $body;
    }

    public function getParam($name, $fallback = '')
    {
        return $name === 'platform' ? $this->platform : $fallback;
    }
}
