<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Unit tests for lib/systemRoutes/v1/imports/convertOpenstaxToSite.php
 * (haxtheweb/issues#3168). Mirrors haxcms-nodejs
 * test/unit/convertOpenstaxToSite.test.cjs.
 *
 * The importer reads a book through OpenStax's archive API (release manifest,
 * slug lookup, book tree, one JSON document per page). haxcmsImportOpenstaxRun
 * takes the HTTP client and origin, so every request is answered by a Guzzle
 * handler over an in-memory fixture and the suite never touches the network.
 * The origin is an IP literal so SsrfGuard's address check needs no DNS.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertOpenstaxToSite.php';

class ConvertOpenstaxToSiteTest extends TestCase
{
    const ORIGIN = 'http://93.184.215.14';
    const BOOK_ID = '052b8372-0c5e-4ff6-8fd3-326377e9e91f';
    const BOOK_VERSION = '56af1c4';
    const ARCHIVE = '/apps/archive/20260604.144757';
    const PAGE_ONE = '11111111-1111-1111-1111-111111111111';
    const PAGE_TWO = '22222222-2222-2222-2222-222222222222';
    const PREFACE = '33333333-3333-3333-3333-333333333333';
    // a 1x1 PNG, standing in for an archive image resource
    const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    const LOOKUP = '/apps/cms/api/v2/pages/?type=books.Book&fields=title,cnx_id&slug=principles-finance';

    private $requested = array();
    private $routes = array();
    private $savedHaxcms;
    private $tmpRoot;

    protected function setUp(): void
    {
        $this->requested = array();
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_openstax_' . uniqid();
        $haxcms = new OperationsTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        $GLOBALS['HAXCMS'] = $haxcms;
        // the delay is real time in the page loop; nothing here needs it
        $GLOBALS['HAXCMS_OPENSTAX_LIMITS'] = array('requestDelayMs' => 0);
        $this->routes = $this->defaultRoutes();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS_OPENSTAX_LIMITS']);
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        }
        else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function limits(array $limits): void
    {
        $GLOBALS['HAXCMS_OPENSTAX_LIMITS'] = array_merge(array('requestDelayMs' => 0), $limits);
    }

    // ------------------------------------------------------------------
    // the fixture book
    // ------------------------------------------------------------------

    private function pageOneContent(): string
    {
        $two = self::PAGE_TWO;
        return <<<HTML
<html xmlns="http://www.w3.org/1999/xhtml"><head><style>/* STYLING_FOR_DEVS */ .os-figure { color: red; }</style></head>
<body><div data-type="page" id="page-1">
  <h1 data-type="document-title" id="fs-id111"><span class="os-number">1.1</span><span class="os-text">What Is Finance?</span></h1>
  <div data-type="abstract" class="os-abstract">By the end of this section you will be able to explain finance.</div>
  <p class="os-para" id="fs-id222">Finance is the <strong class="os-strong">study of money</strong>.</p>
  <figure id="Figure_01" class="medium"><span data-type="media" data-alt="a chart"><img src="../resources/abc123def456" alt="A bar chart of returns" class="os-img"/></span><div class="os-caption-container"><span class="os-title-label">Figure 1.1</span><span class="os-caption">Returns over time.</span></div></figure>
  <div data-type="note" class="finance tip" id="fs-id333"><h3 class="os-title"><span class="os-title-label">Link to Learning</span></h3><div class="body"><p>See <a href="/contents/{$two}#anchor">the next section</a> and <a href="https://example.org/paper">an external paper</a>.</p></div></div>
  <div data-type="example" id="fs-id444"><h3 data-type="title">Worked Example</h3><p>Compute <math display="inline"><semantics><mrow><mn>2</mn><mo stretchy="false">+</mo><mn>2</mn></mrow><annotation encoding="TeX">2+2</annotation></semantics></math>.</p></div>
  <table class="os-table" id="fs-id555"><thead><tr><th scope="col" class="os-th">Year</th></tr></thead><tbody><tr><td colspan="1" class="os-td">2024</td></tr></tbody></table>
</div></body></html>
HTML;
    }

    private function treeNode($id, $title, $slug, $contents = null): array
    {
        $node = array('id' => $id . '@' . self::BOOK_VERSION, 'title' => $title, 'slug' => $slug);
        if ($contents !== null) {
            $node['contents'] = $contents;
        }
        return $node;
    }

    /** path (with query) => array('json' => ...) | array('body' => string, 'type' => string) | array('status' => int) */
    private function defaultRoutes(): array
    {
        $book = self::BOOK_ID;
        $version = self::BOOK_VERSION;
        $archive = self::ARCHIVE;
        $contents = $archive . '/contents/' . $book . '@' . $version;
        return array(
            '/rex/release.json' => array('json' => array(
                'archiveUrl' => $archive,
                'books'      => array($book => array('defaultVersion' => $version)),
            )),
            self::LOOKUP => array('json' => array('items' => array(array('title' => 'Principles of Finance', 'cnx_id' => $book)))),
            $contents . '.json' => array('json' => array(
                'title'   => 'Principles of Finance',
                'license' => array(
                    'name' => 'Creative Commons Attribution-NonCommercial-ShareAlike License',
                    'url'  => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
                ),
                'tree'    => array('contents' => array(
                    $this->treeNode(self::PREFACE, 'Preface', 'preface'),
                    $this->treeNode($book, '<span class="os-number">Chapter 1</span><span class="os-text">Introduction to Finance</span>', '1-introduction-to-finance', array(
                        $this->treeNode(self::PAGE_ONE, '<span class="os-number">1.1</span><span class="os-text">What Is Finance?</span>', '1-1-what-is-finance'),
                        $this->treeNode(self::PAGE_TWO, '1.2 The Role of Finance', '1-2-the-role-of-finance'),
                    )),
                )),
            )),
            $contents . ':' . self::PREFACE . '.json' => array('json' => array(
                'slug' => 'preface', 'title' => 'Preface',
                'content' => '<div data-type="page"><h1 data-type="document-title">Preface</h1><p>Welcome to the book.</p></div>',
            )),
            $contents . ':' . self::PAGE_ONE . '.json' => array('json' => array(
                'slug' => '1-1-what-is-finance', 'title' => 'What Is Finance?', 'abstract' => 'Explain finance.',
                'content' => $this->pageOneContent(),
            )),
            $contents . ':' . self::PAGE_TWO . '.json' => array('json' => array(
                'slug' => '1-2-the-role-of-finance', 'title' => 'The Role of Finance',
                'content' => '<div data-type="page"><h1 data-type="document-title">1.2 The Role of Finance</h1><p class="os-para">Second section body.</p></div>',
            )),
            $archive . '/resources/abc123def456' => array('body' => base64_decode(self::PNG_BASE64), 'type' => 'image/png'),
        );
    }

    private function client(): Client
    {
        $handler = function (RequestInterface $request, array $options) {
            $uri = $request->getUri();
            $key = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
            $this->requested[] = (string) $uri;
            if (!isset($this->routes[$key])) {
                return new FulfilledPromise(new Response(404, array(), 'unexpected request: ' . $key));
            }
            $entry = $this->routes[$key];
            $status = isset($entry['status']) ? $entry['status'] : 200;
            if (isset($entry['json'])) {
                return new FulfilledPromise(new Response($status, array('Content-Type' => 'application/json'), json_encode($entry['json'])));
            }
            $body = isset($entry['body']) ? $entry['body'] : '';
            $type = isset($entry['type']) ? $entry['type'] : 'text/plain';
            return new FulfilledPromise(new Response($status, array('Content-Type' => $type), $body));
        };
        return new Client(array('handler' => HandlerStack::create($handler)));
    }

    private function import($body = null): array
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = is_array($body) ? $body : array('repoUrl' => 'https://openstax.org/details/books/principles-finance');
        ob_start();
        try {
            haxcmsImportOpenstaxRun($context, $this->client(), self::ORIGIN);
        }
        finally {
            $printed = ob_get_clean();
        }
        $decoded = json_decode($printed, true);
        $this->assertIsArray($decoded, 'response was not JSON: ' . $printed);
        return $decoded;
    }

    private function contentsRoute(string $suffix): string
    {
        return self::ARCHIVE . '/contents/' . self::BOOK_ID . '@' . self::BOOK_VERSION . $suffix;
    }

    // ------------------------------------------------------------------
    // request validation
    // ------------------------------------------------------------------

    public static function badRequests(): array
    {
        return array(
            'missing'      => array(array(), 'missing'),
            'off-site'     => array(array('repoUrl' => 'https://example.org/details/books/principles-finance'), 'openstax.org'),
            'lookalike'    => array(array('repoUrl' => 'https://openstax.org.evil.example/details/books/principles-finance'), 'openstax.org'),
            'internal'     => array(array('repoUrl' => 'http://127.0.0.1/books/principles-finance'), 'openstax.org'),
            'no slug'      => array(array('repoUrl' => 'https://openstax.org/subjects/business'), 'book slug'),
            'not a url'    => array(array('repoUrl' => 'not a url'), 'valid URL'),
        );
    }

    /** @dataProvider badRequests */
    public function testRejectsAMissingOffSiteOrSlugLessRepoUrl(array $body, string $expected): void
    {
        $response = $this->import($body);
        $this->assertSame(400, $response['status'], json_encode($response));
        $this->assertStringContainsString($expected, $response['data']['error']);
        $this->assertSame(array(), $this->requested, 'nothing is fetched for a bad request');
    }

    public function testAcceptsWwwHostAndReaderUrl(): void
    {
        $response = $this->import(array('repoUrl' => 'https://www.openstax.org/books/principles-finance/pages/1-1-what-is-finance'));
        $this->assertSame(200, $response['status'], json_encode($response));
        $this->assertSame('principles-finance', $response['data']['filename']);
    }

    public function testRequestsGoOnlyToTheConfiguredOpenstaxOrigin(): void
    {
        $this->import();
        $this->assertNotEmpty($this->requested);
        foreach ($this->requested as $url) {
            $this->assertStringStartsWith(self::ORIGIN . '/', $url);
        }
    }

    // ------------------------------------------------------------------
    // upstream failures
    // ------------------------------------------------------------------

    public function testReportsABookOpenstaxDoesNotPublishAs422(): void
    {
        $this->routes[self::LOOKUP]['json'] = array('items' => array());
        $response = $this->import();
        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('no book with the slug', $response['data']['error']);
        $this->assertSame(array(), $response['data']['items']);
        $this->assertNull($response['data']['filename']);
    }

    public function testReportsAnUnreadableTableOfContentsAs422(): void
    {
        $this->routes[$this->contentsRoute('.json')]['json'] = array('title' => 'Principles of Finance');
        $response = $this->import();
        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('no table of contents', $response['data']['error']);
    }

    public function testReportsAnUpstreamHttpErrorAs422(): void
    {
        $this->routes['/rex/release.json'] = array('status' => 503, 'json' => array());
        $response = $this->import();
        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('OpenStax returned 503', $response['data']['error']);
    }

    public function testReportsABookOpenstaxIsNotPublishingAs422(): void
    {
        $this->routes['/rex/release.json']['json']['books'] = new stdClass();
        $response = $this->import();
        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('not currently publishing', $response['data']['error']);
    }

    public function testReportsAFailedPageFetchAs422(): void
    {
        unset($this->routes[$this->contentsRoute(':' . self::PAGE_TWO . '.json')]);
        $response = $this->import();
        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('the page "1.2 The Role of Finance"', $response['data']['error']);
    }

    // ------------------------------------------------------------------
    // the outline
    // ------------------------------------------------------------------

    public function testBuildsTheChapterAndSectionHierarchyAsJosItems(): void
    {
        $response = $this->import();
        $this->assertSame(200, $response['status'], json_encode($response));
        $items = $response['data']['items'];
        $this->assertSame('principles-finance', $response['data']['filename']);
        $this->assertCount(4, $items);
        list($preface, $chapter, $sectionOne, $sectionTwo) = $items;
        $this->assertSame('Preface', $preface['title']);
        $this->assertSame(0, $preface['indent']);
        $this->assertNull($preface['parent']);
        $this->assertSame('preface', $preface['slug']);
        $this->assertSame('Chapter 1 Introduction to Finance', $chapter['title']);
        $this->assertSame(0, $chapter['indent']);
        $this->assertSame(1, $chapter['order']);
        $this->assertSame(1, $sectionOne['indent']);
        $this->assertSame($chapter['id'], $sectionOne['parent'], 'sections hang off their chapter');
        $this->assertSame(0, $sectionOne['order']);
        $this->assertSame(1, $sectionTwo['order']);
        $this->assertSame($chapter['slug'] . '/1-1-what-is-finance', $sectionOne['slug'], 'nested slugs carry the chapter path');
        $this->assertSame('<p></p>', $chapter['contents'], 'a chapter heading is a landing page');
        $this->assertSame('Explain finance.', $sectionOne['description']);
    }

    public function testCarriesTheBookLicenseSourceAndAttribution(): void
    {
        $response = $this->import();
        $this->assertSame('by-nc-sa', $response['data']['site']['license'], 'the license is read per book, not assumed');
        $section = $response['data']['items'][2];
        $this->assertSame('openstax', $section['metadata']['sourceType']);
        $this->assertSame('https://openstax.org/books/principles-finance/pages/1-1-what-is-finance', $section['metadata']['source']);
        $this->assertSame('Principles of Finance', $section['metadata']['openstax']['bookTitle']);
        $this->assertSame('OpenStax / Rice University', $section['metadata']['openstax']['publisher']);
        $this->assertSame('https://creativecommons.org/licenses/by-nc-sa/4.0/', $section['metadata']['openstax']['license']['url']);
        $this->assertNotEmpty($section['metadata']['openstax']['accessed'], 'access date recorded');
    }

    public static function licenses(): array
    {
        return array(
            'url code'          => array(array('url' => 'https://creativecommons.org/licenses/by/4.0/'), 'by'),
            'url by-nd'         => array(array('url' => 'https://creativecommons.org/licenses/by-nd/4.0/'), 'by-nd'),
            'url by-nc-nd'      => array(array('url' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/'), 'by-nc-nd'),
            'name nc nd'        => array(array('name' => 'Attribution-NonCommercial-NoDerivatives'), 'by-nc-nd'),
            'name nc'           => array(array('name' => 'Attribution NonCommercial'), 'by-nc'),
            'name sa'           => array(array('name' => 'Attribution-ShareAlike'), 'by-sa'),
            'unknown'           => array(array('name' => 'Something else'), 'by'),
            'none'              => array(array(), 'by'),
        );
    }

    /** @dataProvider licenses */
    public function testMapsTheLicenseOntoASiteLicenseCode(array $license, string $expected): void
    {
        $this->assertSame($expected, haxcmsImportOpenstaxLicenseCode($license));
    }

    // ------------------------------------------------------------------
    // page markup
    // ------------------------------------------------------------------

    public function testStripsTheOpenstaxStylingLayerButKeepsTheStructure(): void
    {
        $html = $this->import()['data']['items'][2]['contents'];
        foreach (array('os-para', 'os-figure', 'fs-id', 'data-type=', 'STYLING_FOR_DEVS', '<style', 'class=') as $junk) {
            $this->assertStringNotContainsString($junk, $html, $junk . ' should be gone: ' . substr($html, 0, 400));
        }
        $this->assertStringNotContainsString('<h1', $html, 'the page title becomes the item title');
        $this->assertStringContainsString('<figure>', $html, 'figures survive');
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th scope="col">', $html, 'tables keep headers');
        $this->assertStringContainsString('<td colspan="1">', $html, 'cell spans survive');
        $this->assertStringContainsString('Returns over time.', $html, 'captions survive as text');
        $this->assertStringContainsString('<strong>study of money</strong>', $html, 'inline markup survives');
        $this->assertStringContainsString('By the end of this section', $html, 'learning objectives survive');
    }

    public function testKeepsMathMlIncludingTheAttributesThatCarryNotation(): void
    {
        $html = $this->import()['data']['items'][2]['contents'];
        $this->assertStringContainsString('<math display="inline">', $html);
        $this->assertStringContainsString('<mo stretchy="false">+</mo>', $html, 'attributes inside math are left alone');
        $this->assertStringContainsString('<annotation encoding="TeX">', $html);
    }

    public function testStagesImagesIntoFilesAndRendersThemAsMediaImage(): void
    {
        $response = $this->import();
        $files = $response['data']['files'];
        $this->assertSame(array('files/abc123def456.png'), array_keys($files), 'named from the resource with the served type');
        $staged = $files['files/abc123def456.png'];
        $this->assertSame(base64_decode(self::PNG_BASE64), file_get_contents($staged), 'the staged bytes are the image');
        $this->assertSame(realpath($this->tmpRoot . '/_config/tmp/imports'), dirname(realpath($staged)), 'staged where createSite accepts bulk imports');
        $html = $response['data']['items'][2]['contents'];
        $this->assertStringContainsString(
            '<media-image source="files/abc123def456.png" alt="A bar chart of returns"></media-image>',
            $html,
            'rendered as media-image, the markup the docx import produces'
        );
        $this->assertStringNotContainsString('<img', $html, 'no raw img left for a staged image');
    }

    public function testLeavesAnImageAloneWhenItCannotBeStaged(): void
    {
        $this->routes[self::ARCHIVE . '/resources/abc123def456'] = array('body' => '<svg/>', 'type' => 'image/svg+xml');
        $response = $this->import();
        $this->assertSame(array(), $response['data']['files'], 'nothing staged for an unsupported type');
        $this->assertStringContainsString(
            '<img src="' . self::ORIGIN . self::ARCHIVE . '/resources/abc123def456"',
            $response['data']['items'][2]['contents'],
            'the image still loads from OpenStax'
        );
    }

    public function testPointsInBookLinksAtImportedPagesAndLeavesExternalOnesAlone(): void
    {
        $response = $this->import();
        $html = $response['data']['items'][2]['contents'];
        $sectionTwo = $response['data']['items'][3];
        $this->assertStringContainsString('<a href="' . $sectionTwo['slug'] . '#anchor">', $html, 'in-book link rewritten: ' . $html);
        $this->assertStringContainsString('<a href="https://example.org/paper">', $html, 'external links are untouched');
    }

    public function testAbsolutizesRelativeLinksThatPointOutsideTheBook(): void
    {
        $this->routes[$this->contentsRoute(':' . self::PAGE_TWO . '.json')]['json']['content'] =
            '<div data-type="page"><p><a href="../resources/handout.pdf">handout</a> <a href="#top">top</a> <a href="mailto:a@b.example">mail</a></p></div>';
        $html = $this->import()['data']['items'][3]['contents'];
        $this->assertStringContainsString('<a href="' . self::ORIGIN . '/apps/archive/resources/handout.pdf">', $html);
        $this->assertStringContainsString('<a href="#top">', $html);
        $this->assertStringContainsString('<a href="mailto:a@b.example">', $html);
    }

    public function testEmptyPageContentBecomesAnEmptyParagraph(): void
    {
        $this->routes[$this->contentsRoute(':' . self::PREFACE . '.json')]['json']['content'] = '';
        $this->assertSame('<p></p>', $this->import()['data']['items'][0]['contents']);
    }

    // ------------------------------------------------------------------
    // fetching and limits
    // ------------------------------------------------------------------

    public function testFetchesEachPageOnceInReadingOrder(): void
    {
        $this->import();
        $pageIds = array();
        foreach ($this->requested as $url) {
            if (preg_match('#contents/[0-9a-f-]+@[^:]+:([0-9a-f-]+)\.json$#', $url, $m)) {
                $pageIds[] = $m[1];
            }
        }
        $this->assertSame(array(self::PREFACE, self::PAGE_ONE, self::PAGE_TWO), $pageIds, 'reading order, no repeats');
    }

    public function testStopsAtThePageCapAndLinksUnimportedPagesToTheirSource(): void
    {
        $this->limits(array('maxPages' => 1));
        $response = $this->import();
        $this->assertSame(200, $response['status'], json_encode($response));
        $this->assertTrue($response['data']['truncated'], 'the caller is told the import was cut short');
        $items = $response['data']['items'];
        $this->assertStringContainsString('Welcome to the book', $items[0]['contents'], 'the first page is imported');
        $this->assertSame(
            '<p>Read this page on <a href="https://openstax.org/books/principles-finance/pages/1-1-what-is-finance">OpenStax</a>.</p>',
            $items[2]['contents'],
            'pages past the cap link out instead of arriving empty'
        );
    }

    public function testStopsAtTheTimeBudget(): void
    {
        $this->limits(array('fetchBudgetSeconds' => -1));
        $response = $this->import();
        $this->assertTrue($response['data']['truncated']);
        $this->assertStringContainsString('Read this page on', $response['data']['items'][0]['contents']);
    }

    public function testReportsAnUntruncatedImportAsComplete(): void
    {
        $this->assertFalse($this->import()['data']['truncated']);
    }

    public function testStopsStagingImagesAtTheImageCap(): void
    {
        $this->limits(array('maxImages' => 0));
        $response = $this->import();
        $this->assertSame(array(), $response['data']['files'], 'no image is staged once the cap is reached');
        $this->assertStringContainsString(
            '<img src="' . self::ORIGIN . self::ARCHIVE . '/resources/abc123def456"',
            $response['data']['items'][2]['contents']
        );
    }

    public function testStopsStagingImagesAtTheByteBudget(): void
    {
        $this->limits(array('maxImageBytes' => 1));
        $this->assertSame(array(), $this->import()['data']['files']);
    }

    public function testFailedImportRemovesImagesItAlreadyStaged(): void
    {
        // page one stages its image, then page two fails
        unset($this->routes[$this->contentsRoute(':' . self::PAGE_TWO . '.json')]);
        $response = $this->import();
        $this->assertSame(422, $response['status']);
        $staging = $this->tmpRoot . '/_config/tmp/imports';
        $left = is_dir($staging) ? array_diff(scandir($staging), array('.', '..')) : array();
        $this->assertSame(array(), array_values($left), 'nothing is left staged for a failed import');
    }
}
