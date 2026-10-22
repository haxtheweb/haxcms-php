<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Unit tests for lib/systemRoutes/v1/imports/convertQuartoToSite.php
 * (haxtheweb/issues#2189). Mirrors haxcms-nodejs
 * test/unit/convertQuartoToSite.test.cjs, test for test.
 *
 * Quarto declares its outline in _quarto.yml (book.chapters with parts and
 * book.appendices for a book, website.sidebar or website.navbar for a site),
 * so the converter reads that YAML as data and never renders or executes
 * anything. These tests cover the outline, the page pipeline (front matter,
 * headings, callouts, executable chunks, shortcodes, includes, attribute
 * blocks, cross-references, notebooks, images, links, assets) and the safety
 * valves.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load (it skips
 * systemRoutes/v1), so the converter is require_once'd directly here, as the
 * other imports/*.php converter tests do.
 *
 * Like the VitePress test, this one covers the whole route: the run function
 * takes the HTTP client, so the GitHub API and raw reads are answered by a
 * Guzzle handler over an in-memory fixture repository. The GitHub origins are
 * overridden to an IP literal so SsrfGuard's address check needs no DNS, and
 * the request log doubles as the assertion that every call carries the
 * User-Agent the GitHub API demands. The fixture is synthetic; no third-party
 * content is copied into the tests.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertQuartoToSite.php';

class ConvertQuartoToSiteTest extends TestCase
{
    const OWNER = 'example-org';
    const REPO = 'widgets-book';
    const API = 'http://93.184.215.14/api';
    const RAW = 'http://93.184.215.14/raw';

    private $requests = array();

    protected function setUp(): void
    {
        $this->requests = array();
        // the delay is real time in the page loop; nothing here needs it
        $GLOBALS['HAXCMS_QUARTO_LIMITS'] = array('requestDelayMs' => 0);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS_QUARTO_LIMITS']);
    }

    private function limits(array $limits): void
    {
        $GLOBALS['HAXCMS_QUARTO_LIMITS'] = array_merge(array('requestDelayMs' => 0), $limits);
    }

    private function repoUrl(): string
    {
        return 'https://github.com/' . self::OWNER . '/' . self::REPO;
    }

    private function rawBase(): string
    {
        return self::RAW . '/' . self::OWNER . '/' . self::REPO . '/main';
    }

    // ------------------------------------------------------------------
    // the fixture repositories
    // ------------------------------------------------------------------

    private function bookConfig(): string
    {
        return <<<'CONFIG'
project:
  type: book
  output-dir: docs

book:
  title: "Hands-On Widgets"
  author:
    - name: Ada Example
  site-url: https://example.org/widgets/
  chapters:
    - index.qmd
    - preface.qmd
    - part: basics.qmd
      chapters:
        - setup.qmd
        - first-steps.qmd
    - part: "Going Further"
      chapters:
        - chapters/*.qmd
    - href: about.qmd
      text: About the author
  appendices:
    - a1-install.qmd
    - notebook.ipynb

license: CC BY-SA 4.0

CONFIG;
    }

    private function notebook(): string
    {
        return json_encode(array(
            'cells' => array(
                array('cell_type' => 'raw', 'source' => array("---\n", "title: Notebook chapter\n", '---')),
                array('cell_type' => 'markdown', 'source' => array("## Intro\n", "\n", 'Notebook text.')),
                array(
                    'cell_type' => 'code',
                    'source'    => array("#| label: calc\n", "total = 1 + 1\n", 'total'),
                    'outputs'   => array(array('output_type' => 'execute_result')),
                ),
            ),
            'metadata' => array('kernelspec' => array('language' => 'python')),
        ));
    }

    private function bookFiles(): array
    {
        return array(
            '_quarto.yml' => $this->bookConfig(),
            'README.md' => "# Widgets book source\n",
            'index.qmd' => "---\ntitle: Welcome\n---\n\nWelcome to the book.\n",
            'preface.qmd' => "# Preface {#sec-preface}\n\nThis book teaches widgets. See @fig-widget and @sec-setup.\n\n"
                . "![A widget](images/widget.png){#fig-widget fig-alt=\"A blue widget\"}\n",
            'basics.qmd' => "# Basics\n\nPart introduction.\n",
            'setup.qmd' => "# Setup\n\n"
                . "::: {.callout-note}\n## Heads up\nInstall the thing.\n:::\n\n"
                . "```{r}\n#| label: setup\n#| echo: true\nx <- 1\n```\n\n"
                . "```{r}\n#| echo: false\nhidden <- 2\n```\n\n"
                . "See [first steps](first-steps.qmd#sec-go) and [the appendix](a1-install.html).\n",
            'first-steps.qmd' => "---\ntitle: First steps (front matter)\n---\n\n# Kept heading\n\nGo.\n\n"
                . "{{< video https://www.youtube.com/watch?v=abc title=\"Demo\" >}}\n",
            'chapters/two.qmd' => "# Two\n\nSecond chapter.\n",
            'chapters/one.qmd' => "# One\n\nFirst chapter.\n",
            'about.qmd' => "# About\n\nSome [marked text]{.mark} and a [link](https://example.com){target=\"_blank\"}.\n\n"
                . "::: {.content-visible when-format=\"pdf\"}\nPDF only\n:::\n\n"
                . "::: {.content-hidden when-format=\"pdf\"}\nHTML only\n:::\n",
            'a1-install.qmd' => "# Installing\n\n{{< include _shared.qmd >}}\n\n{{< pagebreak >}}\n\n{{< lipsum 1 >}}\n\n{{< brand logo >}}\n",
            '_shared.qmd' => "---\ntitle: ignored\n---\n\nShared text.\n",
            'notebook.ipynb' => $this->notebook(),
            'images/widget.png' => 'PNG',
            'images/diagram.svg' => 'SVG',
            'docs/old.qmd' => "# Rendered output, not source\n",
        );
    }

    private function websiteConfig(): string
    {
        return <<<'CONFIG'
project:
  type: website

website:
  title: "Widget Docs"
  site-url: https://docs.example.org
  sidebar:
    contents:
      - index.qmd
      - section: "Guides"
        href: guides/overview.qmd
        contents:
          - guides/install.qmd
          - text: "Using widgets"
            href: guides/use.qmd
      - section: "Reference"
        contents:
          - auto: "reference/*.qmd"
      - text: "Project site"
        href: https://example.com/project
      - missing.qmd

CONFIG;
    }

    private function websiteFiles(): array
    {
        return array(
            '_quarto.yml' => $this->websiteConfig(),
            'index.qmd' => "# Home\n\nWelcome.\n",
            'guides/overview.qmd' => "# Overview\n\nGuides start here.\n",
            'guides/install.qmd' => "# Install\n\nRun the installer.\n",
            'guides/use.qmd' => "# Use\n\nUse it.\n",
            'reference/b-api.qmd' => "# B API\n\nB.\n",
            'reference/a-api.qmd' => "# A API\n\nA.\n",
        );
    }

    /**
     * The fixture repository for one test, like stubRepository in the Node
     * suite: only/files/removed shape the file set, branch names the default
     * branch, repoStatus fails the repository call, unreadable lists paths
     * whose raw read answers 500.
     */
    private function repository(array $settings = array()): array
    {
        $files = !empty($settings['only']) ? array() : $this->bookFiles();
        $files = array_merge($files, isset($settings['files']) ? $settings['files'] : array());
        foreach (array_keys(isset($settings['removed']) ? $settings['removed'] : array()) as $path) {
            unset($files[$path]);
        }
        return array(
            'files'      => $files,
            'branch'     => isset($settings['branch']) ? $settings['branch'] : 'main',
            'repoStatus' => isset($settings['repoStatus']) ? $settings['repoStatus'] : null,
            'unreadable' => isset($settings['unreadable']) ? $settings['unreadable'] : array(),
        );
    }

    /** A client that answers from the fixture repository, logging every call. */
    private function client(array $repository): Client
    {
        $requests = &$this->requests;
        $owner = self::OWNER;
        $repo = self::REPO;
        $handler = function (RequestInterface $request, array $options) use ($repository, &$requests, $owner, $repo) {
            $uri = $request->getUri();
            $path = $uri->getPath();
            $requests[] = array(
                'path'      => $path,
                'query'     => $uri->getQuery(),
                'userAgent' => $request->getHeaderLine('User-Agent'),
                'accept'    => $request->getHeaderLine('Accept'),
            );
            if (preg_match('#/api/repos/[^/]+/[^/]+$#', $path)) {
                if ($repository['repoStatus'] !== null) {
                    return new FulfilledPromise(new Response($repository['repoStatus'], array(), '{}'));
                }
                return new FulfilledPromise(new Response(200, array(), json_encode(array('default_branch' => $repository['branch']))));
            }
            if (strpos($path, '/git/trees/') !== false) {
                $tree = array();
                foreach (array_keys($repository['files']) as $file) {
                    $tree[] = array('path' => (string) $file, 'type' => 'blob');
                }
                return new FulfilledPromise(new Response(200, array(), json_encode(array('tree' => $tree))));
            }
            $prefix = '/raw/' . $owner . '/' . $repo . '/' . $repository['branch'] . '/';
            if (strpos($path, $prefix) === 0) {
                $repoPath = rawurldecode(substr($path, strlen($prefix)));
                if (in_array($repoPath, $repository['unreadable'], true)) {
                    return new FulfilledPromise(new Response(500, array(), ''));
                }
                if (isset($repository['files'][$repoPath])) {
                    return new FulfilledPromise(new Response(200, array(), $repository['files'][$repoPath]));
                }
            }
            return new FulfilledPromise(new Response(404, array(), 'not found'));
        };
        return new Client(array('handler' => HandlerStack::create($handler)));
    }

    /** The whole route over a repository; the response as the client sees it. */
    private function importRepo($repoUrl = null, array $settings = array()): array
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = array('repoUrl' => $repoUrl === null ? $this->repoUrl() : $repoUrl);
        $client = $this->client($this->repository($settings));
        ob_start();
        try {
            haxcmsImportQuartoRun($context, $client, array('api' => self::API, 'raw' => self::RAW));
        }
        finally {
            $printed = ob_get_clean();
        }
        return json_decode($printed, true);
    }

    private function itemBySlug(array $items, string $slug): ?array
    {
        foreach ($items as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }
        return null;
    }

    private function itemByTitle(array $items, string $title): ?array
    {
        foreach ($items as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }
        return null;
    }

    private function contentsOf(array $response, string $slug): string
    {
        $item = $this->itemBySlug($response['data']['items'], $slug);
        $this->assertNotNull($item, 'no item with slug ' . $slug);
        return $item['contents'];
    }

    private function fetchedUrls(): array
    {
        return array_map(function ($call) {
            return $call['path'] . ($call['query'] !== '' ? '?' . $call['query'] : '');
        }, $this->requests);
    }

    // ------------------------------------------------------------------
    // request validation
    // ------------------------------------------------------------------

    public function testAMissingRepoUrlIsRejectedBeforeAnyFetch(): void
    {
        $response = $this->importRepo('');
        $this->assertSame(400, $response['status']);
        $this->assertMatchesRegularExpression('~missing `repoUrl`~', $response['data']['error']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame(array(), $response['data']['items']);
        $this->assertNull($response['data']['filename']);
        $this->assertSame(array(), $response['data']['files']);
        $this->assertCount(0, $this->requests);
    }

    public function testANonGithubUrlIsRejected(): void
    {
        $response = $this->importRepo('https://gitlab.com/owner/repo');
        $this->assertSame(400, $response['status']);
        $this->assertSame('repoUrl must be a github.com repository URL', $response['data']['error']);
        $this->assertCount(0, $this->requests);
    }

    public function testAUrlWithoutOwnerRepoIsRejected(): void
    {
        $response = $this->importRepo('https://github.com/example-org');
        $this->assertSame(400, $response['status']);
        $this->assertSame('repoUrl is missing the owner/repo path: https://github.com/example-org', $response['data']['error']);
    }

    public function testARepositoryWithNoQuartoYmlReports422(): void
    {
        $response = $this->importRepo(null, array('removed' => array('_quarto.yml' => true)));
        $this->assertSame(422, $response['status']);
        $this->assertSame('No _quarto.yml file found in ' . self::OWNER . '/' . self::REPO, $response['data']['error']);
    }

    public function testARepositoryGithubCannotFindReports422(): void
    {
        $response = $this->importRepo(null, array('repoStatus' => 404));
        $this->assertSame(422, $response['status']);
        $this->assertSame(
            'Unable to read the repository ' . self::OWNER . '/' . self::REPO . ' (HTTP 404)',
            $response['data']['error']
        );
    }

    public function testAnUnreadableQuartoYmlReports422(): void
    {
        $response = $this->importRepo(null, array('unreadable' => array('_quarto.yml')));
        $this->assertSame(422, $response['status']);
        $this->assertSame('Unable to read _quarto.yml', $response['data']['error']);
    }

    public function testGithubRequestsCarryTheImportUserAgent(): void
    {
        $this->importRepo();
        $api = array_filter($this->requests, function ($call) {
            return strpos($call['path'], '/api/') === 0;
        });
        $this->assertGreaterThanOrEqual(2, count($api));
        foreach ($api as $call) {
            $this->assertSame('HAXcms-Import/1.0', $call['userAgent']);
        }
    }

    public function testATreeBranchUrlNamesTheBranchToRead(): void
    {
        $response = $this->importRepo($this->repoUrl() . '/tree/develop', array('branch' => 'develop'));
        $this->assertSame(200, $response['status']);
        $this->assertContains('/api/repos/' . self::OWNER . '/' . self::REPO . '/git/trees/develop?recursive=1', $this->fetchedUrls());
        $this->assertSame('develop', $response['data']['items'][0]['metadata']['quarto']['branch']);
    }

    // ------------------------------------------------------------------
    // book outline
    // ------------------------------------------------------------------

    public function testBookChaptersPartsAndAppendicesBecomeTheOutline(): void
    {
        $response = $this->importRepo();
        $this->assertSame(200, $response['status']);
        $items = $response['data']['items'];
        $this->assertSame(
            array(
                array(0, 0, 'home'),
                array(0, 1, 'preface'),
                array(0, 2, 'basics'),
                array(1, 0, 'basics/setup'),
                array(1, 1, 'basics/first-steps'),
                array(0, 3, 'going-further'),
                array(1, 0, 'going-further/one'),
                array(1, 1, 'going-further/two'),
                array(0, 4, 'about-the-author'),
                array(0, 5, 'a1-install'),
                array(0, 6, 'notebook'),
            ),
            array_map(function ($item) {
                return array($item['indent'], $item['order'], $item['slug']);
            }, $items)
        );
        $basics = $this->itemBySlug($items, 'basics');
        $this->assertSame($basics['id'], $this->itemBySlug($items, 'basics/setup')['parent']);
        $this->assertNull($basics['parent']);
        $this->assertSame('Hands-On Widgets', $response['data']['filename']);
    }

    public function testAPartThatIsAPageKeepsItsContentAPartThatIsATitleIsALandingPage(): void
    {
        $items = $this->importRepo()['data']['items'];
        $this->assertStringContainsString('Part introduction', $this->itemBySlug($items, 'basics')['contents']);
        $this->assertSame('<p></p>', $this->itemBySlug($items, 'going-further')['contents']);
    }

    public function testAChaptersGlobExpandsInPathOrderBeneathItsPart(): void
    {
        $items = $this->importRepo()['data']['items'];
        $part = $this->itemBySlug($items, 'going-further');
        $children = array_values(array_filter($items, function ($item) use ($part) {
            return $item['parent'] === $part['id'];
        }));
        $this->assertSame(array('One', 'Two'), array_map(function ($item) {
            return $item['title'];
        }, $children));
    }

    public function testAPageNamedTwiceInTheOutlineIsPlacedOnce(): void
    {
        $config = str_replace("    - index.qmd\n", "    - index.qmd\n    - index.qmd\n", $this->bookConfig());
        $items = $this->importRepo(null, array('files' => array('_quarto.yml' => $config)))['data']['items'];
        $placed = array_filter($items, function ($item) {
            return $item['metadata']['quarto']['path'] === 'index.qmd';
        });
        $this->assertCount(1, $placed);
    }

    public function testTwoPagesWithTheSameTitleKeepDistinctSlugs(): void
    {
        $config = "project:\n  type: book\nbook:\n  chapters:\n    - href: a.qmd\n      text: Same\n    - href: b.qmd\n      text: Same\n";
        $items = $this->importRepo(null, array('only' => true, 'files' => array(
            '_quarto.yml' => $config,
            'a.qmd' => "# A\n",
            'b.qmd' => "# B\n",
        )))['data']['items'];
        $this->assertSame(array('same', 'same-2'), array_map(function ($item) {
            return $item['slug'];
        }, $items));
    }

    public function testAProjectFileBelowTheRootSetsTheProjectRoot(): void
    {
        $response = $this->importRepo(null, array('only' => true, 'files' => array(
            'book/_quarto.yml' => "project:\n  type: book\nbook:\n  chapters:\n    - index.qmd\n    - one.qmd\n",
            'book/index.qmd' => "# Start\n\n![pic](img/pic.png)\n",
            'book/one.qmd' => "# One\n",
            'book/img/pic.png' => 'PNG',
            'other/_quarto.yml' => "project:\n  type: default\n",
        )));
        $this->assertSame(200, $response['status']);
        $this->assertCount(2, $response['data']['items']);
        $this->assertSame(array('files/pic.png'), array_keys($response['data']['files']));
        $this->assertSame($this->rawBase() . '/book/img/pic.png', $response['data']['files']['files/pic.png']);
    }

    // ------------------------------------------------------------------
    // title resolution
    // ------------------------------------------------------------------

    public function testFrontMatterTitleWinsThenTheFirstHeadingThenTheFileName(): void
    {
        $items = $this->importRepo()['data']['items'];
        $this->assertSame('Welcome', $this->itemBySlug($items, 'home')['title']);
        $this->assertSame('Preface', $this->itemBySlug($items, 'preface')['title']);
        $this->assertSame('First steps (front matter)', $this->itemBySlug($items, 'basics/first-steps')['title']);
    }

    public function testAHeadingThatTitledThePageIsNotRepeatedInItsBodyOneBesideAFrontMatterTitleIsKept(): void
    {
        $response = $this->importRepo();
        $this->assertDoesNotMatchRegularExpression('~<h1~', $this->contentsOf($response, 'preface'));
        $this->assertMatchesRegularExpression('~<h1>Kept heading</h1>~', $this->contentsOf($response, 'basics/first-steps'));
    }

    public function testAnOutlineLabelIsTheTitleWhateverThePageSays(): void
    {
        $items = $this->importRepo()['data']['items'];
        $this->assertSame('About the author', $this->itemBySlug($items, 'about-the-author')['title']);
    }

    // ------------------------------------------------------------------
    // metadata
    // ------------------------------------------------------------------

    public function testItemsRecordTheirQuartoSourceAndWhereItIsPublished(): void
    {
        $items = $this->importRepo()['data']['items'];
        $setup = $this->itemBySlug($items, 'basics/setup');
        $this->assertSame('quarto', $setup['metadata']['sourceType']);
        $this->assertSame(self::OWNER . '/' . self::REPO, $setup['metadata']['quarto']['repo']);
        $this->assertSame('setup.qmd', $setup['metadata']['quarto']['path']);
        $this->assertSame('Ada Example', $setup['metadata']['quarto']['author']);
        $this->assertSame('Hands-On Widgets', $setup['metadata']['quarto']['workTitle']);
        $this->assertSame('https://example.org/widgets/setup.html', $setup['metadata']['source']);
        $this->assertArrayNotHasKey('source', $this->itemBySlug($items, 'going-further')['metadata']);
    }

    public function testADeclaredLicenseIsNormalizedReturnedForTheSiteAndCreditedOnEachPage(): void
    {
        $response = $this->importRepo();
        $this->assertSame('by-sa', $response['data']['site']['license']);
        $this->assertMatchesRegularExpression(
            '~<license-element license="by-sa" title="Setup" creator="Ada Example" source="https://example\.org/widgets/setup\.html"></license-element>~',
            $this->contentsOf($response, 'basics/setup')
        );
    }

    public function testPageFrontMatterCanSetItsOwnLicense(): void
    {
        $items = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "---\nlicense: cc-by\nauthor: Someone Else\n---\n\nText.\n",
        )))['data']['items'];
        $preface = $this->itemBySlug($items, 'preface');
        $this->assertSame('by', $preface['metadata']['quarto']['license']);
        $this->assertMatchesRegularExpression('~license="by"[^>]*creator="Someone Else"~', $preface['contents']);
    }

    public function testAProjectWithNoLicenseCreditsNothing(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => str_replace("license: CC BY-SA 4.0\n", '', $this->bookConfig()),
        )));
        $this->assertNull($response['data']['site']['license']);
        $this->assertSame(array('license:not-found'), $this->warningCodes($response));
        $this->assertDoesNotMatchRegularExpression('~license-element~', $this->contentsOf($response, 'basics/setup'));
    }

    // ------------------------------------------------------------------
    // license detection and notices
    // ------------------------------------------------------------------

    private function noLicenseConfig(): string
    {
        return str_replace("license: CC BY-SA 4.0\n", '', $this->bookConfig());
    }

    private function warningCodes(array $response): array
    {
        return array_map(function ($warning) {
            return $warning['code'];
        }, $response['data']['warnings']);
    }

    public function testAShareAlikeLicenseInTheProjectFileWarnsAboutShareAlikeOnly(): void
    {
        $response = $this->importRepo();
        $this->assertSame(array('license:share-alike'), $this->warningCodes($response));
        $this->assertSame(
            'The source is licensed Share Alike. Keep the same license on the imported site.',
            $response['data']['warnings'][0]['message']
        );
    }

    public function testANoDerivativesLicenseWarnsAndSoDoesNonCommercial(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => str_replace('license: CC BY-SA 4.0', 'license: CC BY-NC-ND 4.0', $this->bookConfig()),
        )));
        $this->assertSame('by-nc-nd', $response['data']['site']['license']);
        $this->assertSame(array('license:no-derivatives', 'license:non-commercial'), $this->warningCodes($response));
        $this->assertSame(
            'The source is licensed No Derivatives. Converting it into a new site may count as an adaptation; check the license or ask the author before publishing.',
            $response['data']['warnings'][0]['message']
        );
        $this->assertSame(
            'The source is licensed Non-commercial. The imported site must not be used commercially.',
            $response['data']['warnings'][1]['message']
        );
    }

    public function testALicenseFileIsReadWhenTheProjectFileDeclaresNone(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => $this->noLicenseConfig(),
            'LICENSE' => "This work is licensed under the Creative Commons Attribution-NonCommercial-NoDerivs 4.0 United States License. To view a copy of this license, visit https://creativecommons.org/licenses/by-nc-nd/4.0/\n",
        )));
        $this->assertSame('by-nc-nd', $response['data']['site']['license']);
        $this->assertSame(
            array('license:no-derivatives', 'license:non-commercial', 'license:from-file'),
            $this->warningCodes($response)
        );
        $this->assertSame(
            "The license (by-nc-nd) was read from the repository's license file because the project file declares none. Confirm it covers the written content and not only the code.",
            $response['data']['warnings'][2]['message']
        );
        $this->assertMatchesRegularExpression('~<license-element license="by-nc-nd"~', $this->contentsOf($response, 'basics/setup'));
    }

    public function testTheProjectFileWinsAndTheLicenseFileIsNeverFetched(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            'LICENSE' => 'Permission is hereby granted, free of charge, to any person obtaining a copy',
        )));
        $this->assertSame('by-sa', $response['data']['site']['license']);
        foreach ($this->fetchedUrls() as $url) {
            $this->assertDoesNotMatchRegularExpression('~/LICENSE$~', $url);
        }
    }

    public function testAProjectThatReservesAllRightsIsRefusedBeforeAnyPageIsRead(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => str_replace('license: CC BY-SA 4.0', 'license: All rights reserved', $this->bookConfig()),
        )));
        $this->assertSame(403, $response['status']);
        $this->assertMatchesRegularExpression('~all rights reserved~i', $response['data']['error']);
        foreach ($this->fetchedUrls() as $url) {
            $this->assertDoesNotMatchRegularExpression('~\.qmd$~', $url);
        }
    }

    public function testALicenseFileThatReservesAllRightsIsRefused(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => $this->noLicenseConfig(),
            'LICENSE' => "Copyright (c) 2024 Someone. All rights reserved.\n",
        )));
        $this->assertSame(403, $response['status']);
    }

    public function testAPageThatReservesAllRightsRefusesTheImport(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "---\nlicense: all-rights-reserved\n---\n\nText.\n",
        )));
        $this->assertSame(403, $response['status']);
    }

    public function testASoftwareLicenseInTheLicenseFileIsAppliedAndFlaggedForReview(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => $this->noLicenseConfig(),
            'LICENSE.md' => "MIT License\n\nCopyright (c) 2024 Someone\n\nPermission is hereby granted, free of charge, to any person obtaining a copy of this software\n",
        )));
        $this->assertSame('mit', $response['data']['site']['license']);
        $this->assertSame(array('license:from-file'), $this->warningCodes($response));
        $this->assertMatchesRegularExpression('~license="mit"~', $this->contentsOf($response, 'basics/setup'));
    }

    public function testAProjectFileCanStateASoftwareLicenseBySpdxNameOrAsAMap(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => str_replace('license: CC BY-SA 4.0', 'license: Apache-2.0', $this->bookConfig()),
        )));
        $this->assertSame('apache-2.0', $response['data']['site']['license']);
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => str_replace(
                'license: CC BY-SA 4.0',
                "license:\n  text: Some text\n  url: https://creativecommons.org/publicdomain/zero/1.0/",
                $this->bookConfig()
            ),
        )));
        $this->assertSame('cc0', $response['data']['site']['license']);
        $this->assertSame(array(), $this->warningCodes($response));
        $this->assertMatchesRegularExpression('~"warnings":\s*\[\s*\]~', json_encode($response));
    }

    public function testACreativeCommonsLicenseAtAnotherVersionIsReportedNotRelabeled(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => str_replace('license: CC BY-SA 4.0', 'license: CC BY-SA 3.0', $this->bookConfig()),
        )));
        $this->assertNull($response['data']['site']['license']);
        $this->assertSame(array('license:unrecognized'), $this->warningCodes($response));
        $this->assertMatchesRegularExpression('~CC BY-SA 3\.0~', $response['data']['warnings'][0]['message']);
        $this->assertSame(
            'The source states a license HAX cannot apply (CC BY-SA 3.0). No license was set on the imported site.',
            $response['data']['warnings'][0]['message']
        );
        $this->assertDoesNotMatchRegularExpression('~license-element~', $this->contentsOf($response, 'basics/setup'));
    }

    public function testALicenseFileThatCannotBeIdentifiedIsReportedAsUnrecognized(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => $this->noLicenseConfig(),
            'LICENSE' => "You may look at this but not touch it.\n",
        )));
        $this->assertNull($response['data']['site']['license']);
        $this->assertSame(array('license:unrecognized'), $this->warningCodes($response));
    }

    public function testARepositoryWithNoLicenseSaysSo(): void
    {
        $response = $this->importRepo(null, array('files' => array('_quarto.yml' => $this->noLicenseConfig())));
        $this->assertNull($response['data']['site']['license']);
        $this->assertSame(array('license:not-found'), $this->warningCodes($response));
        $this->assertSame(
            'No license was declared in the project file or found in a repository license file. Without one, the work is all rights reserved by default; get permission before publishing the import.',
            $response['data']['warnings'][0]['message']
        );
    }

    public function testAnUnreadableLicenseFileIsTheSameAsNone(): void
    {
        $response = $this->importRepo(null, array(
            'files' => array('_quarto.yml' => $this->noLicenseConfig(), 'LICENSE' => 'x'),
            'unreadable' => array('LICENSE'),
        ));
        $this->assertSame(array('license:not-found'), $this->warningCodes($response));
    }

    public function testTheLicenseFileBesideTheProjectFileIsPreferredThenTheRepositoryRoot(): void
    {
        $mit = 'Permission is hereby granted, free of charge, to any person obtaining a copy';
        $response = $this->importRepo(null, array('only' => true, 'files' => array(
            'book/_quarto.yml' => $this->noLicenseConfig(),
            'book/index.qmd' => "# Home\n\nHi.\n",
            'book/LICENSE' => 'Creative Commons Attribution 4.0 International',
            'LICENSE' => $mit,
        )));
        $this->assertSame('by', $response['data']['site']['license']);
        $response = $this->importRepo(null, array('only' => true, 'files' => array(
            'book/_quarto.yml' => $this->noLicenseConfig(),
            'book/index.qmd' => "# Home\n\nHi.\n",
            'LICENSE' => $mit,
        )));
        $this->assertSame('mit', $response['data']['site']['license']);
    }

    public function testTheShortestLicenseFileNameWinsThenLocaleOrder(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => $this->noLicenseConfig(),
            'LICENSE.txt' => 'Creative Commons Attribution 4.0 International',
            'LICENSE' => 'Permission is hereby granted, free of charge, to any person obtaining a copy',
            'COPYING' => 'Mozilla Public License Version 2.0',
        )));
        // COPYING is shorter than LICENSE
        $this->assertSame('mpl-2.0', $response['data']['site']['license']);
    }

    public function testAPageLicenseInFrontMatterThatCannotBeAppliedFallsBackToTheSiteLicense(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "---\nlicense: CC BY 3.0\n---\n\nText.\n",
        )));
        $preface = $this->itemBySlug($response['data']['items'], 'preface');
        $this->assertSame('by-sa', $preface['metadata']['quarto']['license']);
    }

    // ------------------------------------------------------------------
    // callouts, divs and chunks
    // ------------------------------------------------------------------

    public function testACalloutBecomesAStopNoteTitledByItsFirstHeadingWithItsContentRendered(): void
    {
        $setup = $this->contentsOf($this->importRepo(), 'basics/setup');
        $this->assertMatchesRegularExpression(
            '~<stop-note status="info" title="Heads up">\s*<div slot="message">\s*<p>Install the thing\.</p>\s*</div>\s*</stop-note>~',
            $setup
        );
        $this->assertDoesNotMatchRegularExpression('~<h2>Heads up</h2>~', $setup);
    }

    public function testCalloutTypesMapOntoStopNoteStatusesATitleAttributeWinsAndAnUntitledCalloutTakesItsType(): void
    {
        $body = "::: {.callout-warning title=\"Careful\"}\nW.\n:::\n\n::: {.callout-tip}\nT.\n:::\n\n"
            . ":::: callout-important\nI.\n::::\n\n::: {.callout-caution}\nC.\n:::\n";
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array('preface.qmd' => $body))), 'preface');
        $this->assertMatchesRegularExpression('~<stop-note status="warning" title="Careful">~', $contents);
        $this->assertMatchesRegularExpression('~<stop-note status="success" title="Tip">~', $contents);
        $this->assertMatchesRegularExpression('~<stop-note status="stop" title="Important">~', $contents);
        $this->assertMatchesRegularExpression('~<stop-note status="warning" title="Caution">~', $contents);
    }

    public function testNestedFencedDivsKeepTheirClassesAndIdsAndCloseInOrder(): void
    {
        $body = "::::: {#outer .columns}\n::: {.column width=50%}\nLeft.\n:::\n::: {.column}\nRight.\n:::\n:::::\n";
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array('preface.qmd' => $body))), 'preface');
        $this->assertMatchesRegularExpression('~<div id="outer" class="columns">~', $contents);
        $this->assertSame(2, preg_match_all('~<div class="column">~', $contents));
        $this->assertSame(3, preg_match_all('~</div>~', $contents));
        $this->assertDoesNotMatchRegularExpression('~:::~', $contents);
    }

    public function testContentVisibleAndContentHiddenFollowTheHtmlOutput(): void
    {
        $about = $this->contentsOf($this->importRepo(), 'about-the-author');
        $this->assertDoesNotMatchRegularExpression('~PDF only~', $about);
        $this->assertMatchesRegularExpression('~HTML only~', $about);
    }

    public function testExecutableChunksBecomeCodeInTheirLanguageWithTheirOptionsDropped(): void
    {
        $response = $this->importRepo();
        $setup = $this->contentsOf($response, 'basics/setup');
        $this->assertMatchesRegularExpression('~<pre><code class="language-r">x &lt;- 1\n</code></pre>~', $setup);
        $this->assertDoesNotMatchRegularExpression('~#\|~', $setup);
        $this->assertDoesNotMatchRegularExpression('~hidden~', $setup);
        $this->assertSame(
            array('code-outputs'),
            array_values(array_filter($response['data']['unmappedComponents'], function ($name) {
                return $name === 'code-outputs';
            }))
        );
    }

    public function testAChunkWithKnitrHeaderOptionsIsReadTheSameWay(): void
    {
        $body = "```{r, echo=FALSE}\nsecret <- 1\n```\n\n```{r setup, eval=FALSE}\nshown <- 2\n```\n";
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array('preface.qmd' => $body))), 'preface');
        $this->assertDoesNotMatchRegularExpression('~secret~', $contents);
        $this->assertMatchesRegularExpression('~shown &lt;- 2~', $contents);
    }

    public function testAPlainPandocCodeBlockAndADiagramChunkAreNotOutputsToCompute(): void
    {
        $body = "```{.python .numberLines}\nprint(1)\n```\n\n```{mermaid}\ngraph TD;\n```\n\n```text\nplain\n```\n";
        $response = $this->importRepo(null, array('only' => true, 'files' => array(
            '_quarto.yml' => "project:\n  type: book\nbook:\n  chapters:\n    - index.qmd\n",
            'index.qmd' => $body,
        )));
        $contents = $response['data']['items'][0]['contents'];
        $this->assertMatchesRegularExpression('~language-python~', $contents);
        $this->assertMatchesRegularExpression('~language-mermaid~', $contents);
        $this->assertMatchesRegularExpression('~language-text~', $contents);
        $this->assertSame(array(), $response['data']['unmappedComponents']);
    }

    public function testRawHtmlBlocksKeepTheirContentAndOtherRawBlocksAreDropped(): void
    {
        $body = "```{=html}\n<p>kept raw</p>\n```\n\n```{=latex}\n\\newpage\n```\n";
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array('preface.qmd' => $body))), 'preface');
        $this->assertMatchesRegularExpression('~<p>kept raw</p>~', $contents);
        $this->assertDoesNotMatchRegularExpression('~newpage~', $contents);
    }

    public function testDivAndShortcodeSyntaxInsideACodeBlockIsLeftAlone(): void
    {
        $body = "```markdown\n::: {.callout-note}\nNot a callout\n:::\n{{< video x.mp4 >}}\n```\n";
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array('preface.qmd' => $body))), 'preface');
        $this->assertDoesNotMatchRegularExpression('~stop-note~', $contents);
        $this->assertMatchesRegularExpression('~::: \{\.callout-note\}~', $contents);
        $this->assertMatchesRegularExpression('~\{\{&lt; video|\{\{< video~', $contents);
    }

    // ------------------------------------------------------------------
    // shortcodes and includes
    // ------------------------------------------------------------------

    public function testAVideoShortcodeBecomesAVideoPlayer(): void
    {
        $contents = $this->contentsOf($this->importRepo(), 'basics/first-steps');
        $this->assertMatchesRegularExpression(
            '~<video-player source="https://www\.youtube\.com/watch\?v=abc" media-title="Demo"></video-player>~',
            $contents
        );
        // markdown-it leaves the tag pair in a paragraph, and so does this port
        $this->assertStringContainsString('<p><video-player source=', $contents);
    }

    public function testAnIncludeIsExpandedWithoutItsFrontMatterAndOtherShortcodesAreReported(): void
    {
        $response = $this->importRepo();
        $appendix = $this->contentsOf($response, 'a1-install');
        $this->assertMatchesRegularExpression('~Shared text\.~', $appendix);
        $this->assertDoesNotMatchRegularExpression('~ignored|\{\{~', $appendix);
        $this->assertSame(
            array('code-outputs', 'cross-references', 'shortcode:brand'),
            $response['data']['unmappedComponents']
        );
    }

    public function testIncludesNestToABoundedDepth(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "{{< include _a.qmd >}}\n",
            '_a.qmd' => "A\n\n{{< include _b.qmd >}}\n",
            '_b.qmd' => "B\n\n{{< include _c.qmd >}}\n",
            '_c.qmd' => "C\n\n{{< include _d.qmd >}}\n",
            '_d.qmd' => "D\n",
        )));
        $contents = $this->contentsOf($response, 'preface');
        $this->assertMatchesRegularExpression('~<p>A</p>~', $contents);
        $this->assertMatchesRegularExpression('~<p>C</p>~', $contents);
        $this->assertDoesNotMatchRegularExpression('~<p>D</p>~', $contents);
        $this->assertTrue($response['data']['truncated']);
    }

    public function testTheIncludeCountIsCapped(): void
    {
        $this->limits(array('maxIncludes' => 1));
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "{{< include _a.qmd >}}\n\n{{< include _b.qmd >}}\n",
            '_a.qmd' => "Alpha\n",
            '_b.qmd' => "Beta\n",
        )));
        $contents = $this->contentsOf($response, 'preface');
        $this->assertMatchesRegularExpression('~Alpha~', $contents);
        $this->assertDoesNotMatchRegularExpression('~Beta~', $contents);
        $this->assertTrue($response['data']['truncated']);
    }

    // ------------------------------------------------------------------
    // inline syntax
    // ------------------------------------------------------------------

    public function testImageAttributesGiveTheAltTextLinksAndSpansLoseTheirAttributeBlocks(): void
    {
        $items = $this->importRepo()['data']['items'];
        $this->assertMatchesRegularExpression(
            '~<media-image source="files/widget\.png" alt="A blue widget"></media-image>~',
            $this->itemBySlug($items, 'preface')['contents']
        );
        $about = $this->itemBySlug($items, 'about-the-author')['contents'];
        $this->assertMatchesRegularExpression('~<span class="mark">marked text</span>~', $about);
        $this->assertMatchesRegularExpression('~<a href="https://example\.com">link</a>~', $about);
        $this->assertDoesNotMatchRegularExpression('~\{target~', $about);
    }

    public function testAFigureCaptionIsTheAltTextWhenNoFigAltIsGiven(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "![Caption text](images/widget.png){#fig-x}\n",
        ))), 'preface');
        $this->assertMatchesRegularExpression('~alt="Caption text"~', $contents);
    }

    public function testAFigureCaptionHoldingInlineCodeStillLosesItsAttributeBlock(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "![`plot` draws points.](images/widget.png){#fig-x}\n\nUse `![a](b){c}` literally.\n",
        ))), 'preface');
        $this->assertDoesNotMatchRegularExpression('~\{#fig-x\}~', $contents);
        $this->assertMatchesRegularExpression('~<media-image~', $contents);
        $this->assertMatchesRegularExpression('~<code>!\[a\]\(b\)\{c\}</code>~', $contents);
    }

    public function testACaptionedTableKeepsItsCaptionWithoutTheAttributeBlock(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "| a | b |\n|---|---|\n| 1 | 2 |\n\n: Operators in use {#tbl-ops}\n\nAfter.\n",
        ))), 'preface');
        $this->assertDoesNotMatchRegularExpression('~\{#tbl-ops\}~', $contents);
        $this->assertMatchesRegularExpression('~<em>Operators in use</em>~', $contents);
    }

    public function testHeadingAttributeBlocksAreDroppedFromSectionHeadings(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "Intro.\n\n## Why {#sec-why .unnumbered}\n\nBody.\n",
        ))), 'preface');
        $this->assertMatchesRegularExpression('~<h2>Why</h2>~', $contents);
    }

    public function testCrossReferencesKeepAReadableKeyAndAreReported(): void
    {
        $response = $this->importRepo();
        $preface = $this->contentsOf($response, 'preface');
        $this->assertMatchesRegularExpression('~See fig-widget and sec-setup\.~', $preface);
        $this->assertDoesNotMatchRegularExpression('~@fig-widget~', $preface);
        $this->assertContains('cross-references', $response['data']['unmappedComponents']);
    }

    public function testAnEmailAddressAndALiteralAtInCodeAreNotCrossReferences(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "Write to a@sec-b.example or use `@fig-x` here.\n",
        ))), 'preface');
        $this->assertMatchesRegularExpression('~a@sec-b\.example~', $contents);
        $this->assertMatchesRegularExpression('~<code>@fig-x</code>~', $contents);
    }

    public function testPandocFootnotesRender(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "Claim.[^1]\n\n[^1]: The note.\n",
        ))), 'preface');
        $this->assertMatchesRegularExpression('~footnote~', $contents);
        $this->assertMatchesRegularExpression('~The note\.~', $contents);
    }

    // ------------------------------------------------------------------
    // links, media and assets
    // ------------------------------------------------------------------

    public function testLinksToOtherPagesPointAtTheirImportedSlugsSourceOrRendered(): void
    {
        $setup = $this->contentsOf($this->importRepo(), 'basics/setup');
        $this->assertMatchesRegularExpression('~href="basics/first-steps#sec-go"~', $setup);
        $this->assertMatchesRegularExpression('~href="a1-install"~', $setup);
    }

    public function testLinksResolveRelativeToThePageThatCarriesThem(): void
    {
        $one = $this->contentsOf($this->importRepo(null, array('files' => array(
            'chapters/one.qmd' => "[sibling](two.qmd) and [up](../preface.qmd)\n",
        ))), 'going-further/one');
        $this->assertMatchesRegularExpression('~href="going-further/two"~', $one);
        $this->assertMatchesRegularExpression('~href="preface"~', $one);
    }

    public function testALinkToAFileTheProjectCarriesJoinsTheFileMapOneItDoesNotIsAbsolutized(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "[data](data/table.csv) and [gone](data/gone.csv)\n",
            'data/table.csv' => 'a,b',
        )));
        $contents = $this->contentsOf($response, 'preface');
        $this->assertMatchesRegularExpression('~href="files/table\.csv"~', $contents);
        $this->assertStringContainsString('href="' . $this->rawBase() . '/data/gone.csv"', $contents);
        $this->assertSame($this->rawBase() . '/data/table.csv', $response['data']['files']['files/table.csv']);
    }

    public function testImagesJoinTheFileMapUnderUniqueNamesOtherKindsKeepASourceUrl(): void
    {
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "![a](images/widget.png)\n\n![b](more/widget.png)\n\n![c](images/diagram.svg)\n\n![d](/images/widget.png)\n",
            'more/widget.png' => 'PNG2',
        )));
        $names = array_keys($response['data']['files']);
        sort($names);
        $this->assertSame(array('files/widget-1.png', 'files/widget.png'), $names);
        $contents = $this->contentsOf($response, 'preface');
        $this->assertSame(2, preg_match_all('~source="files/widget\.png"~', $contents));
        $this->assertMatchesRegularExpression('~source="files/widget-1\.png"~', $contents);
        $this->assertStringContainsString('<img src="' . $this->rawBase() . '/images/diagram.svg"', $contents);
    }

    public function testARemoteImageIsLeftAsItIs(): void
    {
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array(
            'preface.qmd' => "![remote](https://example.com/pic.png)\n",
        ))), 'preface');
        $this->assertMatchesRegularExpression('~source="https://example\.com/pic\.png"~', $contents);
    }

    public function testTheFileMapIsCapped(): void
    {
        $this->limits(array('maxFiles' => 1));
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "![a](images/widget.png)\n\n![b](more/other.png)\n",
            'more/other.png' => 'PNG2',
        )));
        $this->assertSame(array('files/widget.png'), array_keys($response['data']['files']));
        $this->assertTrue($response['data']['truncated']);
    }

    // ------------------------------------------------------------------
    // notebooks
    // ------------------------------------------------------------------

    public function testANotebookChapterReadsItsFrontMatterMarkdownCellsAndCodeCells(): void
    {
        $response = $this->importRepo();
        $notebook = $this->itemBySlug($response['data']['items'], 'notebook');
        $this->assertSame('Notebook chapter', $notebook['title']);
        $this->assertMatchesRegularExpression('~<h2>Intro</h2>~', $notebook['contents']);
        $this->assertMatchesRegularExpression('~Notebook text\.~', $notebook['contents']);
        $this->assertMatchesRegularExpression('~<code class="language-python">total = 1 \+ 1\ntotal\n</code>~', $notebook['contents']);
        $this->assertDoesNotMatchRegularExpression('~#\|~', $notebook['contents']);
        $this->assertContains('code-outputs', $response['data']['unmappedComponents']);
    }

    public function testANotebookThatIsNotValidJsonBecomesAnEmptyPage(): void
    {
        $response = $this->importRepo(null, array('files' => array('notebook.ipynb' => 'not json')));
        $notebook = $this->itemBySlug($response['data']['items'], 'notebook');
        $this->assertFalse(strpos($notebook['contents'], '<h2'));
    }

    // ------------------------------------------------------------------
    // website projects
    // ------------------------------------------------------------------

    public function testAWebsiteSidebarBecomesTheOutlineSectionsPageSectionsLabelsAndAutoEntries(): void
    {
        $response = $this->importRepo(null, array('only' => true, 'files' => $this->websiteFiles()));
        $this->assertSame(200, $response['status']);
        $this->assertSame(
            array(
                array('Home', 0, 0, 'home'),
                array('Guides', 0, 1, 'guides'),
                array('Install', 1, 0, 'guides/install'),
                array('Using widgets', 1, 1, 'guides/using-widgets'),
                array('Reference', 0, 2, 'reference'),
                array('A API', 1, 0, 'reference/a-api'),
                array('B API', 1, 1, 'reference/b-api'),
            ),
            array_map(function ($item) {
                return array($item['title'], $item['indent'], $item['order'], $item['slug']);
            }, $response['data']['items'])
        );
        $this->assertSame('Widget Docs', $response['data']['filename']);
    }

    public function testASidebarSectionKeepsItsLabelWhileItsPageSuppliesTheContent(): void
    {
        $items = $this->importRepo(null, array('only' => true, 'files' => $this->websiteFiles()))['data']['items'];
        $guides = $this->itemBySlug($items, 'guides');
        $this->assertSame('Guides', $guides['title']);
        $this->assertMatchesRegularExpression('~Guides start here~', $guides['contents']);
        $this->assertSame('https://docs.example.org/guides/overview.html', $guides['metadata']['source']);
    }

    public function testExternalLinksAndPagesTheRepositoryDoesNotCarryAreNotPages(): void
    {
        $items = $this->importRepo(null, array('only' => true, 'files' => $this->websiteFiles()))['data']['items'];
        $this->assertNull($this->itemByTitle($items, 'Project site'));
        $this->assertNull($this->itemByTitle($items, 'Missing'));
    }

    public function testWithNoSidebarTheNavbarIsTheOutlineAndAMenuIsAPageWithChildren(): void
    {
        $config = "project:\n  type: website\nwebsite:\n  navbar:\n    left:\n      - href: index.qmd\n        text: Start\n"
            . "      - text: Docs\n        menu:\n          - href: guides/install.qmd\n          - href: guides/use.qmd\n            text: Use it\n"
            . "    right:\n      - icon: github\n        href: https://github.com/example-org/widgets-book\n";
        $items = $this->importRepo(null, array('only' => true, 'files' => array_merge($this->websiteFiles(), array('_quarto.yml' => $config))))['data']['items'];
        $this->assertSame(
            array(
                array('Start', 0, 0),
                array('Docs', 0, 1),
                array('Install', 1, 0),
                array('Use it', 1, 1),
            ),
            array_map(function ($item) {
                return array($item['title'], $item['indent'], $item['order']);
            }, $items)
        );
    }

    // ------------------------------------------------------------------
    // the file tree fallback
    // ------------------------------------------------------------------

    public function testWithNoOutlineThePagesOnDiskAreImportedIndexFirstSkippingWhatQuartoSkips(): void
    {
        $items = $this->importRepo(null, array('only' => true, 'files' => array(
            '_quarto.yml' => "project:\n  type: default\n  output-dir: docs\n",
            'index.qmd' => "# Home\n",
            'zeta.qmd' => "# Zeta\n",
            'alpha.md' => "# Alpha\n",
            'guide/index.qmd' => "# Guide\n",
            'README.md' => "# Readme\n",
            'AGENTS.md' => "# Agents\n",
            '_partial.qmd' => "# Partial\n",
            '.hidden/page.qmd' => "# Hidden\n",
            'docs/rendered.qmd' => "# Output dir\n",
            'renv/library.qmd' => "# Dependency\n",
            'page_files/x.qmd' => "# Generated\n",
            'node_modules/pkg/readme.md' => "# Dependency\n",
        )))['data']['items'];
        $this->assertSame(
            array('index.qmd', 'alpha.md', 'zeta.qmd', 'guide/index.qmd'),
            array_map(function ($item) {
                return $item['metadata']['quarto']['path'];
            }, $items)
        );
    }

    public function testProjectRenderNarrowsThePagesAndABangEntryRemovesSome(): void
    {
        $items = $this->importRepo(null, array('only' => true, 'files' => array(
            '_quarto.yml' => "project:\n  type: default\n  render:\n    - \"*.qmd\"\n    - \"!draft-*.qmd\"\n",
            'a.qmd' => "# A\n",
            'draft-b.qmd' => "# B\n",
            'c.md' => "# C\n",
        )))['data']['items'];
        $this->assertSame(array('a.qmd'), array_map(function ($item) {
            return $item['metadata']['quarto']['path'];
        }, $items));
    }

    public function testAnOutlineThatNamesNoReadablePagesFallsBackToTheTree(): void
    {
        $items = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => "project:\n  type: book\nbook:\n  chapters:\n    - nowhere.qmd\n",
        )))['data']['items'];
        $this->assertNotEmpty($items);
        $this->assertSame('index.qmd', $this->itemBySlug($items, 'home')['metadata']['quarto']['path']);
    }

    public function testAProjectFileThatIsNotValidYamlOrUsesAliasesIsReadAsEmpty(): void
    {
        $response = $this->importRepo(null, array('files' => array('_quarto.yml' => "book: [unclosed\n  - x: : :\n")));
        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['data']['items']);
        $response = $this->importRepo(null, array('files' => array(
            '_quarto.yml' => "base: &a\n  title: x\nproject:\n  type: book\nbook:\n  <<: *a\n",
        )));
        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['data']['items']);
    }

    public function testAProjectWithNoPagesReports422(): void
    {
        $response = $this->importRepo(null, array('only' => true, 'files' => array(
            '_quarto.yml' => "project:\n  type: default\n",
            'README.md' => "# r\n",
        )));
        $this->assertSame(422, $response['status']);
        $this->assertSame('The Quarto project has no pages to import', $response['data']['error']);
    }

    // ------------------------------------------------------------------
    // unreadable pages and limits
    // ------------------------------------------------------------------

    public function testAPageThatCannotBeReadLinksBackToTheSourceSite(): void
    {
        $setup = $this->contentsOf($this->importRepo(null, array('unreadable' => array('setup.qmd'))), 'basics/setup');
        $this->assertMatchesRegularExpression(
            '~<a href="https://example\.org/widgets/setup\.html">the source site</a>~',
            $setup
        );
    }

    public function testAPageCapTruncatesWithLinksToTheSource(): void
    {
        $this->limits(array('maxPages' => 2));
        $response = $this->importRepo();
        $this->assertTrue($response['data']['truncated']);
        $this->assertMatchesRegularExpression('~Preface|teaches widgets~', $this->contentsOf($response, 'preface'));
        $this->assertMatchesRegularExpression('~the source site~', $this->contentsOf($response, 'basics'));
    }

    public function testTheFetchBudgetTruncatesTheSameWay(): void
    {
        $this->limits(array('fetchBudgetSeconds' => -1));
        $response = $this->importRepo();
        $this->assertTrue($response['data']['truncated']);
        $this->assertMatchesRegularExpression('~the source site~', $this->contentsOf($response, 'preface'));
    }

    public function testPageBodiesAreFetchedOneAtATimeWithTheConfiguredDelay(): void
    {
        $this->limits(array('requestDelayMs' => 5));
        $started = microtime(true);
        $this->importRepo(null, array('only' => true, 'files' => array(
            '_quarto.yml' => "project:\n  type: book\nbook:\n  chapters:\n    - index.qmd\n    - a.qmd\n    - b.qmd\n",
            'index.qmd' => "# I\n",
            'a.qmd' => "# A\n",
            'b.qmd' => "# B\n",
        )));
        $this->assertGreaterThanOrEqual(8, (microtime(true) - $started) * 1000);
    }

    // ------------------------------------------------------------------
    // hostile input
    // ------------------------------------------------------------------

    public function testScriptInAPageIsCarriedAsTextForTheSitesOwnSanitizerNeverExecuted(): void
    {
        unset($GLOBALS['__quartoRan']);
        $response = $this->importRepo(null, array('files' => array(
            'preface.qmd' => "Hello <script>\$GLOBALS['__quartoRan'] = true;</script>\n",
        )));
        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('__quartoRan', $GLOBALS);
    }

    public function testAttributeValuesAreEscapedIntoTheHtmlTheConverterWrites(): void
    {
        $body = "::: {.callout-note title=\"a&quot;b<i>\"}\nx\n:::\n\n![x](images/widget.png){fig-alt=\"q&quot;\\\"><b>\"}\n";
        $contents = $this->contentsOf($this->importRepo(null, array('files' => array('preface.qmd' => $body))), 'preface');
        $this->assertDoesNotMatchRegularExpression('~title="a"b~', $contents);
        $this->assertDoesNotMatchRegularExpression('~<b>"~', $contents);
    }

    // ------------------------------------------------------------------
    // PHP-side checks: the pieces that stand in for Node libraries
    // ------------------------------------------------------------------

    public function testFrontmatterWithAliasesIsIgnored(): void
    {
        $split = haxcmsImportQuartoSplitFrontmatter("---\ntitle: &t Hello\nalt: *t\n---\n# Body\n");
        $this->assertEquals(new stdClass(), $split['data']);
        $this->assertSame("# Body\n", $split['body']);
    }

    public function testOrdinaryFrontmatterIsStillParsed(): void
    {
        $split = haxcmsImportQuartoSplitFrontmatter("---\ntitle: R&D notes\n---\nbody");
        $this->assertSame('R&D notes', $split['data']->title);
        $this->assertSame('body', $split['body']);
    }

    public function testAnEmptyFileMapIsAnObjectOnTheWireLikeNode(): void
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = array('repoUrl' => $this->repoUrl());
        $client = $this->client($this->repository(array('only' => true, 'files' => array(
            '_quarto.yml' => "project:\n  type: default\n",
            'index.qmd' => "# Home\n",
        ))));
        ob_start();
        try {
            haxcmsImportQuartoRun($context, $client, array('api' => self::API, 'raw' => self::RAW));
        }
        finally {
            $printed = ob_get_clean();
        }
        $this->assertMatchesRegularExpression('~"files":\s*\{\s*\}~', $printed);
    }

    public function testEncodeUriMatchesJavascriptForBracketsAndSpaces(): void
    {
        $this->assertSame('a%20b/%5Bc%5D/d(e)!.png', haxcmsImportQuartoEncodeUri('a b/[c]/d(e)!.png'));
        $this->assertSame('a%20b%2F(c)', haxcmsImportQuartoEncodeUriComponent('a b/(c)'));
    }

    public function testCollationOrdersLikeLocaleCompareForPaths(): void
    {
        $paths = array('b.qmd', 'B.qmd', 'a-1.qmd', 'a_1.qmd', 'a1.qmd', 'A.qmd', 'a.qmd', '10.qmd', '2.qmd');
        usort($paths, 'haxcmsImportQuartoCollate');
        $this->assertSame(
            array('10.qmd', '2.qmd', 'a_1.qmd', 'a-1.qmd', 'a.qmd', 'A.qmd', 'a1.qmd', 'b.qmd', 'B.qmd'),
            $paths
        );
        if (class_exists('Collator')) {
            $collator = new Collator('en_US');
            $expected = $paths;
            shuffle($expected);
            usort($expected, function ($a, $b) use ($collator) {
                return $collator->compare($a, $b);
            });
            $this->assertSame($expected, $paths);
        }
    }

    // ------------------------------------------------------------------
    // the dispatcher case
    // ------------------------------------------------------------------

    public function testTheSiteImportDispatcherRoutesQuartoToThisConverter(): void
    {
        $context = new QuartoDispatcherContext('quarto', array());
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
        $context = new QuartoDispatcherContext('quart', array());
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
        $this->assertSame('Unsupported import platform "quart"', $response['data']['error']);
    }
}

/** The bit of request context the site import dispatcher reads. */
class QuartoDispatcherContext
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
