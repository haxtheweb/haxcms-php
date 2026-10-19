<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Network-path tests for the small imports/*.php converters (Notion, HAXcms,
 * WordPress, Plone, ELMS:LN, Pressbooks). Each converter takes an optional
 * Guzzle client, so the whole fetch flow is answered by a handler over
 * in-memory fixtures. Hosts are public IP literals so SsrfGuard needs no DNS
 * (same pattern as ConvertDrupalToSiteTest). The validation-only branches
 * stay in the per-converter Convert*Test.php files.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertNotionToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertHaxcmsToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertWordpressToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertPloneToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertElmslnToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertPressbooksToSite.php';

class ConvertRemoteSourcesHttpTest extends TestCase
{
    const HOST = '93.184.215.30';
    const RAW = '93.184.215.31';

    /** path(+query) => array(status, body) | string body */
    private $routes = array();
    private $requests = array();
    private $savedHaxcms;
    private $tmpBase;

    protected function setUp(): void
    {
        $this->routes = array();
        $this->requests = array();
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        unset($_FILES['upload'], $_FILES['file'], $_FILES['file-upload']);
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if ($this->tmpBase !== null) {
            exec('rm -rf ' . escapeshellarg($this->tmpBase));
            $this->tmpBase = null;
        }
    }

    // ------------------------------------------------------------------
    // fixture plumbing
    // ------------------------------------------------------------------

    private function client(): Client
    {
        $routes = &$this->routes;
        $requests = &$this->requests;
        $handler = function (RequestInterface $request, array $options) use (&$routes, &$requests) {
            $uri = $request->getUri();
            $key = $uri->getHost() . rawurldecode($uri->getPath());
            $requests[] = $key . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
            // exact path+query match wins over a path-only match
            $withQuery = $key . '?' . $uri->getQuery();
            $hit = isset($routes[$withQuery]) ? $routes[$withQuery] : (isset($routes[$key]) ? $routes[$key] : null);
            if ($hit === null) {
                return new FulfilledPromise(new Response(404, array(), 'not found'));
            }
            if (is_array($hit)) {
                return new FulfilledPromise(new Response($hit[0], array(), $hit[1]));
            }
            return new FulfilledPromise(new Response(200, array(), $hit));
        };
        return new Client(array('handler' => HandlerStack::create($handler)));
    }

    private function route(string $path, $body, int $status = 200, string $host = self::HOST): void
    {
        $this->routes[$host . $path] = array($status, is_string($body) ? $body : json_encode($body));
    }

    private function ctx(array $body): stdClass
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = $body;
        return $context;
    }

    private function capture(callable $fn): array
    {
        ob_start();
        try {
            $fn();
        } finally {
            $printed = ob_get_clean();
        }
        return json_decode($printed, true);
    }

    private function base(): string
    {
        return 'http://' . self::HOST;
    }

    // ------------------------------------------------------------------
    // Notion (GitHub-hosted export)
    // ------------------------------------------------------------------

    private function notion(array $body): array
    {
        $client = $this->client();
        $origins = array('api' => 'http://' . self::HOST, 'raw' => 'http://' . self::RAW);
        return $this->capture(function () use ($body, $client, $origins) {
            haxcmsImportConvertNotionToSite($this->ctx($body), $client, $origins);
        });
    }

    public function testNotionBuildsPagesAndSubPagesFromRepoTree(): void
    {
        $hash = str_repeat('a1', 16);
        $this->route('/repos/acme/notes/contents/', array(
            array('type' => 'file', 'name' => 'Welcome ' . $hash . '.md'),
            array('type' => 'file', 'name' => 'image.png'),
            array('type' => 'file', 'name' => 'Broken.md'),
            array('name' => 'no-type-entry'),
            array('type' => 'dir', 'name' => 'Guides ' . $hash),
            array('type' => 'dir', 'name' => 'Empty'),
        ));
        $this->route('/acme/notes/dev/Welcome ' . $hash . '.md', "# Hello\n\nWorld", 200, self::RAW);
        $this->route('/repos/acme/notes/contents/Guides ' . $hash, array(
            array('type' => 'file', 'name' => 'Setup ' . $hash . '.md'),
            array('type' => 'file', 'name' => 'Missing.md'),
            array('type' => 'file', 'name' => 'diagram.svg'),
        ));
        $this->route('/acme/notes/dev/Guides ' . $hash . '/Setup ' . $hash . '.md', 'Install *it*', 200, self::RAW);
        // Empty dir lookup returns a non-array payload
        $this->route('/repos/acme/notes/contents/Empty', '"nope"');

        $res = $this->notion(array('repoUrl' => 'https://github.com/acme/notes/tree/dev', 'parentId' => 'root-1'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('notes', $res['data']['filename']);
        $titles = array_column($res['data']['items'], 'title');
        $this->assertSame(array('Welcome', 'Broken', 'Guides', 'Setup', 'Missing', 'Empty'), $titles);
        $items = $res['data']['items'];
        $this->assertStringContainsString('<h1>Hello</h1>', $items[0]['contents']);
        $this->assertSame('root-1', $items[0]['parent']);
        // markdown fetch failure leaves empty contents
        $this->assertSame('', $items[1]['contents']);
        // sub-pages hang off the directory item
        $this->assertSame($items[2]['id'], $items[3]['parent']);
        $this->assertStringContainsString('<em>it</em>', $items[3]['contents']);
        $this->assertSame('', $items[4]['contents']);
        $this->assertSame('guides', $items[2]['slug']);
        $this->assertSame(range(0, 5), array_column($items, 'order'));
    }

    public function testNotionDefaultsBranchToMainAndNullParent(): void
    {
        $this->route('/repos/acme/notes/contents/', array(array('type' => 'file', 'name' => 'A.md')));
        $this->route('/acme/notes/main/A.md', 'a', 200, self::RAW);
        $res = $this->notion(array('repoUrl' => 'https://github.com/acme/notes', 'parentId' => 'null'));
        $this->assertSame(200, $res['status']);
        $this->assertNull($res['data']['items'][0]['parent']);
        $this->assertContains(self::HOST . '/repos/acme/notes/contents/?ref=main', $this->requests);
    }

    public function testNotionSubdirectoryFetchFailureIsSwallowed(): void
    {
        $this->route('/repos/acme/notes/contents/', array(array('type' => 'dir', 'name' => 'Gone')));
        $res = $this->notion(array('repoUrl' => 'https://github.com/acme/notes'));
        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['data']['items']);
        $this->assertSame('Gone', $res['data']['items'][0]['title']);
    }

    public function testNotionUnreachableRepoReturns400(): void
    {
        $res = $this->notion(array('repoUrl' => 'https://github.com/acme/missing'));
        $this->assertSame(400, $res['status']);
        $this->assertStringStartsWith('Unable to access GitHub repository: ', $res['data']['error']);
    }

    public function testNotionNonArrayContentsReturns400(): void
    {
        $this->route('/repos/acme/notes/contents/', 'not json');
        $res = $this->notion(array('repoUrl' => 'https://github.com/acme/notes'));
        $this->assertSame(400, $res['status']);
        $this->assertSame('Invalid GitHub repository contents', $res['data']['error']);
    }

    // ------------------------------------------------------------------
    // HAXcms
    // ------------------------------------------------------------------

    private function haxcms(array $body): array
    {
        $client = $this->client();
        return $this->capture(function () use ($body, $client) {
            haxcmsImportConvertHaxcmsToSite($this->ctx($body), $client);
        });
    }

    private function useStagingRoot(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/haxcms-remote-' . uniqid();
        mkdir($this->tmpBase, 0755, true);
        $GLOBALS['HAXCMS'] = new stdClass();
        $GLOBALS['HAXCMS']->configDirectory = $this->tmpBase;
    }

    public function testHaxcmsImportsItemsFilesAndSiteFiles(): void
    {
        $this->useStagingRoot();
        $this->route('/course/site.json', array(
            'metadata' => array('site' => array('name' => 'my-course')),
            'items' => array(
                array('id' => 'a', 'title' => 'A', 'slug' => '../Evil Slug', 'location' => 'pages/a/index.html',
                    'metadata' => array('files' => array(
                        array('url' => 'files/pic.png'),
                        array('url' => 'files/missing.pdf'),
                        array('name' => 'no-url'),
                    ))),
                array('id' => 'b', 'title' => 'B', 'location' => 'pages/b/index.html'),
                array('id' => 'c', 'title' => 'C'),
            ),
        ));
        $this->route('/course/pages/a/index.html', '<p>A body</p>');
        $this->route('/course/files/pic.png', 'PNGDATA');
        $this->route('/course/theme/theme.css', 'body{}');
        $this->route('/course/theme/theme.html', '   ');
        $this->route('/course/custom/build/custom.es6.js', '// custom comment script here');

        // iam. host gets rewritten to oer.; IP literal has no iam. so stays put
        $res = $this->haxcms(array('repoUrl' => $this->base() . '/course/site.json'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('my-course', $res['data']['filename']);
        $items = $res['data']['items'];
        $this->assertSame('<p>A body</p>', $items[0]['contents']);
        $this->assertSame('', $items[1]['contents']);
        $this->assertArrayNotHasKey('contents', $items[2]);
        $this->assertStringNotContainsString('..', $items[0]['slug']);
        $this->assertSame(array('files/pic.png'), array_keys($res['data']['files']));
        $staged = $res['data']['files']['files/pic.png'];
        $this->assertFileExists($staged);
        $this->assertSame('PNGDATA', file_get_contents($staged));
        $this->assertSame(array('theme/theme.css' => $this->base() . '/course/theme/theme.css'), $res['data']['siteFiles']);
    }

    public function testHaxcmsFilenameFallsBackToPathBasename(): void
    {
        $this->route('/sites/fallback/site.json', array('items' => array()));
        $this->route('/sites/fallback/custom/build/custom.es6.js', 'console.log(1)');
        $res = $this->haxcms(array('repoUrl' => $this->base() . '/sites/fallback/'));
        $this->assertSame(200, $res['status']);
        $this->assertSame('fallback', $res['data']['filename']);
        $this->assertArrayHasKey('custom/build/custom.es6.js', $res['data']['siteFiles']);
    }

    public function testHaxcmsSiteJsonFetchFailureReturns400(): void
    {
        $res = $this->haxcms(array('repoUrl' => $this->base() . '/nothing'));
        $this->assertSame(400, $res['status']);
        $this->assertStringStartsWith('Unable to fetch site.json: ', $res['data']['error']);
    }

    public function testHaxcmsInvalidSiteJsonStructureReturns400(): void
    {
        $this->route('/bad/site.json', array('title' => 'no items'));
        $res = $this->haxcms(array('repoUrl' => $this->base() . '/bad'));
        $this->assertSame(400, $res['status']);
        $this->assertSame('Invalid site.json structure', $res['data']['error']);
    }

    public function testHaxcmsBlockedHostReturns400(): void
    {
        // SSRF guard rejects loopback before any request is made
        $res = $this->haxcms(array('repoUrl' => 'http://127.0.0.1/site'));
        $this->assertSame(400, $res['status']);
        $this->assertStringStartsWith('Unable to fetch site.json: ', $res['data']['error']);
        $this->assertSame(array(), $this->requests);
    }

    // ------------------------------------------------------------------
    // WordPress
    // ------------------------------------------------------------------

    private function wordpress(array $body): array
    {
        $client = $this->client();
        return $this->capture(function () use ($body, $client) {
            haxcmsImportConvertWordpressToSite($this->ctx($body), $client);
        });
    }

    private function wpPage(int $id, int $parent, string $title): array
    {
        return array(
            'id' => $id, 'parent' => $parent, 'slug' => strtolower($title),
            'title' => array('rendered' => $title),
            'content' => array('rendered' => '<p>' . $title . ' body</p>'),
            'excerpt' => array('rendered' => $title . ' excerpt'),
        );
    }

    public function testWordpressBuildsHierarchyAndSiteTitle(): void
    {
        $pages = array($this->wpPage(1, 0, 'Home'), $this->wpPage(2, 1, 'Child'), $this->wpPage(3, 99, 'Orphan'),
            array('parent' => 0));
        $this->routes[self::HOST . '/wp/wp-json/wp/v2/pages?per_page=100&page=1&orderby=menu_order&order=asc']
            = array(200, json_encode($pages));
        $this->route('/wp/wp-json/', array('name' => 'My Blog'));

        $res = $this->wordpress(array('repoUrl' => $this->base() . '/wp/', 'parentId' => 'top'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('My Blog', $res['data']['filename']);
        $this->assertSame(array('baseUrl' => $this->base() . '/wp', 'adapter' => 'pages', 'pageCount' => 4), $res['data']['wordpress']);
        $items = $res['data']['items'];
        $this->assertSame('top', $items[0]['parent']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame('top', $items[2]['parent']);
        $this->assertSame('Home excerpt', $items[0]['description']);
        // record with no id/title/content uses defaults
        $this->assertSame('Page', $items[3]['title']);
        $this->assertSame('', $items[3]['contents']);
        $this->assertSame('page', $items[3]['slug']);
    }

    public function testWordpressPaginatesFullBatchesAndFallsBackTitle(): void
    {
        $batch = array();
        for ($i = 1; $i <= 100; $i++) {
            $batch[] = $this->wpPage($i, 0, 'P' . $i);
        }
        $q = '/wp-json/wp/v2/pages?per_page=100&page=%d&orderby=menu_order&order=asc';
        $this->routes[self::HOST . sprintf($q, 1)] = array(200, json_encode($batch));
        $this->routes[self::HOST . sprintf($q, 2)] = array(200, json_encode(array()));
        $res = $this->wordpress(array('repoUrl' => $this->base()));
        $this->assertSame(200, $res['status']);
        $this->assertCount(100, $res['data']['items']);
        // /wp-json/ 404 -> default title
        $this->assertSame('wordpress-import', $res['data']['filename']);
    }

    public function testWordpressNoPagesReturns422(): void
    {
        $this->route('/wp-json/wp/v2/pages', array());
        $res = $this->wordpress(array('repoUrl' => $this->base()));
        $this->assertSame(422, $res['status']);
        $this->assertSame('No pages found at ' . $this->base(), $res['data']['error']);
    }

    public function testWordpressFetchFailureReturns422(): void
    {
        $res = $this->wordpress(array('repoUrl' => $this->base()));
        $this->assertSame(422, $res['status']);
        $this->assertStringStartsWith('Unable to fetch WordPress pages: ', $res['data']['error']);
    }

    public function testWordpressUnknownAdapterReturns400(): void
    {
        $res = $this->wordpress(array('repoUrl' => $this->base(), 'adapter' => 'posts'));
        $this->assertSame(400, $res['status']);
        $this->assertSame('unknown adapter `posts`; valid adapters: pages', $res['data']['error']);
    }

    // ------------------------------------------------------------------
    // Plone
    // ------------------------------------------------------------------

    private function plone(array $body): array
    {
        $client = $this->client();
        return $this->capture(function () use ($body, $client) {
            haxcmsImportConvertPloneToSite($this->ctx($body), $client);
        });
    }

    private function ploneSearchPath(string $prefix = ''): string
    {
        return $prefix . '/@search?portal_type:list=Document&portal_type:list=Page&sort_on=getObjPositionInParent&b_size=100';
    }

    public function testPloneFetchesItemsWithRichAndPlainText(): void
    {
        $b = $this->base() . '/plone';
        $this->route('/plone/@site', array('title' => 'Plone Site'));
        $this->routes[self::HOST . $this->ploneSearchPath('/plone')] = array(200, json_encode(array('items' => array(
            array('@id' => $b . '/about/team', 'title' => 'Team', 'description' => 'The team'),
            array('@id' => $b . '/plain', 'title' => 'Plain'),
            array('@id' => $b . '/arr', 'title' => 'Arr'),
            array('@id' => $b . '/broken', 'title' => 'Broken'),
            array('title' => 'No Url'),
            array(),
        ))));
        $this->route('/plone/about/team', array('title' => 'Team Full', 'text' => array('data' => '<p>rich</p>')));
        $this->route('/plone/plain', array('text' => 'plain text'));
        $this->route('/plone/arr', array('text' => array('other' => 1)));

        $res = $this->plone(array('repoUrl' => $b . '/', 'parentId' => 'p'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('Plone Site', $res['data']['filename']);
        $items = $res['data']['items'];
        $this->assertSame('Team Full', $items[0]['title']);
        $this->assertSame('<p>rich</p>', $items[0]['contents']);
        $this->assertSame('The team', $items[0]['description']);
        $this->assertSame('p', $items[0]['parent']);
        $this->assertSame('plain text', $items[1]['contents']);
        $this->assertSame('', $items[2]['contents']);
        $this->assertSame('', $items[3]['contents']);
        $this->assertSame('no-url', $items[4]['slug']);
        $this->assertSame('Page', $items[5]['title']);
        $this->assertSame(range(0, 5), array_column($items, 'order'));
    }

    public function testPloneSiteInfoFailureKeepsDefaultTitle(): void
    {
        $this->routes[self::HOST . $this->ploneSearchPath()] = array(200, json_encode(array('items' => array())));
        $res = $this->plone(array('repoUrl' => $this->base()));
        $this->assertSame(200, $res['status']);
        $this->assertSame('plone-import', $res['data']['filename']);
        $this->assertSame(array(), $res['data']['items']);
    }

    public function testPloneSearchFailureReturns400(): void
    {
        $res = $this->plone(array('repoUrl' => $this->base()));
        $this->assertSame(400, $res['status']);
        $this->assertStringStartsWith('Unable to search Plone site: ', $res['data']['error']);
    }

    public function testPloneInvalidSearchPayloadReturns400(): void
    {
        $this->routes[self::HOST . $this->ploneSearchPath()] = array(200, json_encode(array('results' => array())));
        $res = $this->plone(array('repoUrl' => $this->base()));
        $this->assertSame(400, $res['status']);
        $this->assertSame('Invalid Plone search response', $res['data']['error']);
    }

    // ------------------------------------------------------------------
    // ELMS:LN
    // ------------------------------------------------------------------

    private function elmsln(array $body): array
    {
        $client = $this->client();
        return $this->capture(function () use ($body, $client) {
            haxcmsImportConvertElmslnToSite($this->ctx($body), $client);
        });
    }

    public function testElmslnRemapsIdsAndCollectsFiles(): void
    {
        $this->route('/course/site.json', array('title' => 'Course', 'items' => array(
            array('id' => 'old-1', 'title' => 'Intro', 'slug' => 'intro', 'location' => '/pages/intro/index.html',
                'order' => 5, 'description' => 'd', 'metadata' => array('files' => array(
                    array('url' => 'files/a.pdf'), array('url' => ''), 'bad', array('url' => 7),
                ))),
            array('id' => 'old-2', 'parent' => 'old-1', 'title' => 'Child', 'location' => 'pages/missing.html'),
            array('parent' => 'ghost'),
        )));
        $this->route('/course/pages/intro/index.html', '<p>intro</p>');

        $res = $this->elmsln(array('repoUrl' => $this->base() . '/course/', 'parentId' => 'top'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('Course', $res['data']['filename']);
        $items = $res['data']['items'];
        $this->assertNotSame('old-1', $items[0]['id']);
        $this->assertSame('top', $items[0]['parent']);
        $this->assertSame(5, $items[0]['order']);
        $this->assertSame('<p>intro</p>', $items[0]['contents']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame('child', $items[1]['slug']);
        $this->assertSame('', $items[1]['contents']);
        $this->assertSame(1, $items[1]['order']);
        $this->assertSame('Page', $items[2]['title']);
        $this->assertSame('top', $items[2]['parent']);
        $this->assertSame(array(), $items[2]['metadata']);
        $this->assertSame(array('files/a.pdf' => $this->base() . '/course/files/a.pdf'), $res['data']['files']);
    }

    public function testElmslnDefaultTitle(): void
    {
        $this->route('/site.json', array('items' => array()));
        $res = $this->elmsln(array('repoUrl' => $this->base()));
        $this->assertSame(200, $res['status']);
        $this->assertSame('elmsln-import', $res['data']['filename']);
    }

    public function testElmslnFetchFailureReturns400(): void
    {
        $res = $this->elmsln(array('repoUrl' => $this->base()));
        $this->assertSame(400, $res['status']);
        $this->assertStringStartsWith('Unable to fetch site.json from ' . $this->base() . ': ', $res['data']['error']);
    }

    public function testElmslnInvalidStructureReturns400(): void
    {
        $this->route('/site.json', array('title' => 'x'));
        $res = $this->elmsln(array('repoUrl' => $this->base()));
        $this->assertSame(400, $res['status']);
        $this->assertSame('Invalid site.json structure at ' . $this->base(), $res['data']['error']);
    }

    // ------------------------------------------------------------------
    // Pressbooks
    // ------------------------------------------------------------------

    private function pressbooks(array $body): array
    {
        $client = $this->client();
        return $this->capture(function () use ($body, $client) {
            haxcmsImportConvertPressbooksToSite($this->ctx($body), $client);
        });
    }

    public function testPressbooksBuildsPartsChaptersAndMatter(): void
    {
        $this->route('/book/wp-json/pressbooks/v2/toc', array(
            'title' => array('rendered' => 'Open Book'),
            'parts' => array(
                array('title' => array('rendered' => 'Part One'), 'chapters' => array(
                    array('title' => array('rendered' => 'Ch 1'), 'content' => array('rendered' => '<p>one</p>')),
                    array('title' => 'Plain Ch'),
                    array(),
                )),
                array('title' => 'Plain Part'),
                array(),
            ),
            'front-matter' => array(array('title' => array('rendered' => 'Preface'), 'content' => array('rendered' => 'pre'))),
            'back-matter' => array(array('title' => 'Appendix'), array()),
        ));

        $res = $this->pressbooks(array('repoUrl' => $this->base() . '/book/', 'parentId' => 'top'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('Open Book', $res['data']['filename']);
        $this->assertSame(array('baseUrl' => $this->base() . '/book', 'title' => 'Open Book'), $res['data']['site']);
        $titles = array_column($res['data']['items'], 'title');
        $this->assertSame(array('Part One', 'Ch 1', 'Plain Ch', 'Chapter', 'Plain Part', 'Part', 'Preface', 'Appendix', 'back-matter'), $titles);
        $items = $res['data']['items'];
        $this->assertSame('top', $items[0]['parent']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame('<p>one</p>', $items[1]['contents']);
        $this->assertSame('pre', $items[6]['contents']);
        $this->assertSame('top', $items[6]['parent']);
    }

    public function testPressbooksEmptyTocReturns422(): void
    {
        $this->route('/wp-json/pressbooks/v2/toc', array('parts' => array()));
        $res = $this->pressbooks(array('repoUrl' => $this->base()));
        $this->assertSame(422, $res['status']);
        $this->assertSame('Pressbooks import produced no pages to import', $res['data']['error']);
    }

    public function testPressbooksNonArrayTocReturns422(): void
    {
        $this->route('/wp-json/pressbooks/v2/toc', '<html>');
        $res = $this->pressbooks(array('repoUrl' => $this->base()));
        $this->assertSame(422, $res['status']);
        $this->assertSame('Invalid Pressbooks TOC response', $res['data']['error']);
    }

    public function testPressbooksFetchFailureReturns422(): void
    {
        $res = $this->pressbooks(array('repoUrl' => $this->base()));
        $this->assertSame(422, $res['status']);
        $this->assertStringStartsWith('Unable to fetch Pressbooks TOC: ', $res['data']['error']);
    }

    public function testPressbooksDefaultBookTitle(): void
    {
        $this->route('/wp-json/pressbooks/v2/toc', array('front-matter' => array(array('title' => 'Intro'))));
        $res = $this->pressbooks(array('repoUrl' => $this->base()));
        $this->assertSame(200, $res['status']);
        $this->assertSame('pressbooks-import', $res['data']['filename']);
    }
}
