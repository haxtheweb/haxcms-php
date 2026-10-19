<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Area 5 edge cases for the imports/*.php converters:
 *  - Drupal and VitePress pure helpers called directly (no network): menu
 *    and book forest strategies, file/url normalisers, media resolution,
 *    JS-literal parser branches, asset path resolution.
 *  - Gitbook and HTML-URL network flows through a Guzzle handler on public
 *    IP-literal hosts (same pattern as ConvertRemoteSourcesHttpTest).
 *
 * Defensive branches deliberately left alone: catch blocks around
 * Michelf/Yaml/DOMDocument that the libraries never trigger on string input,
 * and the getElementById(null) fallbacks after a successful loadHTML.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertDrupalToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertVitepressToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertGitbookToSite.php';
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertHtmlToSite.php';

class ConvertImportEdgeCasesTest extends TestCase
{
    const API = '93.184.215.40';
    const RAW = '93.184.215.41';

    private $routes = array();

    protected function setUp(): void
    {
        $this->routes = array();
        unset($_FILES['upload'], $_FILES['file'], $_FILES['file-upload']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS_DRUPAL_LIMITS']);
    }

    private function client(): Client
    {
        $routes = &$this->routes;
        $handler = function (RequestInterface $request) use (&$routes) {
            $uri = $request->getUri();
            $key = $uri->getHost() . rawurldecode($uri->getPath());
            $q = $uri->getQuery();
            $hit = ($q !== '' && isset($routes[$key . '?' . $q])) ? $routes[$key . '?' . $q] : (isset($routes[$key]) ? $routes[$key] : null);
            if ($hit === null) {
                return new FulfilledPromise(new Response(404, array(), 'nf'));
            }
            return new FulfilledPromise(new Response(200, array(), is_string($hit) ? $hit : json_encode($hit)));
        };
        return new Client(array('handler' => HandlerStack::create($handler)));
    }

    private function ctx(array $body): stdClass
    {
        $c = new stdClass();
        $c->apiBasePath = '/system/api';
        $c->routeSuffix = '';
        $c->body = $body;
        return $c;
    }

    private function capture(callable $fn): array
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = ob_get_clean();
        }
        return json_decode($out, true);
    }

    // ==================================================================
    // Drupal: discovery / pagination / node helpers
    // ==================================================================

    public function testDrupalPublicWrapperRejectsMissingRepoUrlWithoutNetwork(): void
    {
        $res = $this->capture(function () {
            haxcmsImportConvertDrupalToSite($this->ctx(array()));
        });
        $this->assertSame('missing `repoUrl` param', $res['data']['error']);
    }

    public function testDrupalFetchJsonSsrfBlockedReturnsNull(): void
    {
        $this->assertNull(haxcmsImportDrupalFetchJSON($this->client(), 'http://127.0.0.1/jsonapi'));
    }

    public function testDrupalBaseCandidatesAndLinkHelpers(): void
    {
        $this->assertSame(array(), haxcmsImportDrupalBuildBaseCandidates('not a url'));
        $this->assertSame(
            array('http://h.test:8080/a/b', 'http://h.test:8080/a', 'http://h.test:8080'),
            haxcmsImportDrupalBuildBaseCandidates('http://h.test:8080/a/b')
        );
        $this->assertSame(array(), haxcmsImportDrupalGetDiscoveryLinks('x'));
        $this->assertSame('u?page[limit]=5', haxcmsImportDrupalWithPageLimit('u?page[limit]=5'));
        $this->assertSame('u?a=1&page[limit]=10', haxcmsImportDrupalWithPageLimit('u?a=1', 10));
        $this->assertSame(array(), haxcmsImportDrupalFetchCollectionByHref($this->client(), null));
        $this->assertNull(haxcmsImportDrupalGetMenuLinkContentHref(array()));
        $this->assertSame(array('node--page' => 'h'), haxcmsImportDrupalGetNodeCollectionLinks(array(
            'node--page' => array('href' => 'h'), 'node--bad' => 'str', 'file--file' => array('href' => 'f'),
        )));
    }

    public function testDrupalNodeTitleSegmentAndSorting(): void
    {
        $this->assertSame(0, haxcmsImportDrupalGetNodeNid('x'));
        $this->assertSame('Node 4', haxcmsImportDrupalGetNodeTitle(array('attributes' => array('drupal_internal__nid' => 4))));
        $this->assertSame('Node', haxcmsImportDrupalGetNodeTitle(array()));
        $a = array('attributes' => array('drupal_internal__nid' => 2, 'title' => 'Same'));
        $b = array('attributes' => array('drupal_internal__nid' => 1, 'title' => 'same'));
        $this->assertSame(array($b, $a), haxcmsImportDrupalSortNodeRecords(array($a, $b)));
        $c1 = array('attributes' => array('drupal_internal__nid' => 9, 'created' => 'x'));
        $c2 = array('attributes' => array('drupal_internal__nid' => 3, 'created' => 'x'));
        $this->assertSame(array($c2, $c1), haxcmsImportDrupalSortNodeRecordsByCreated(array($c1, $c2)));

        $map = array();
        $this->assertSame('a', haxcmsImportDrupalUniqueSegment('a', $map, 5));
        $this->assertSame('a-5', haxcmsImportDrupalUniqueSegment('a', $map, 5));
        $this->assertSame('a-5-2', haxcmsImportDrupalUniqueSegment('a', $map, 5));
        $this->assertSame('a-5-3', haxcmsImportDrupalUniqueSegment('a', $map, 5));
    }

    public function testDrupalFilenameFromUrl(): void
    {
        $this->assertSame('my-site', haxcmsImportDrupalGetFilenameFromUrl('https://h.test/my-site/jsonapi'));
        $this->assertSame('h-test', haxcmsImportDrupalGetFilenameFromUrl('https://h.test/jsonapi'));
        $this->assertSame('drupal-import', haxcmsImportDrupalGetFilenameFromUrl('nope'));
    }

    // ==================================================================
    // Drupal: file map + url normalisers
    // ==================================================================

    public function testDrupalPathnameAndFileKeyNormalisers(): void
    {
        $this->assertSame('/p', haxcmsImportDrupalStripUrlExtras(' /p?x=1#h '));
        $this->assertNull(haxcmsImportDrupalPathnameFromFileUrl(''));
        $this->assertNull(haxcmsImportDrupalPathnameFromFileUrl('//cdn/x.png'));
        $this->assertSame('/files/a.png', haxcmsImportDrupalPathnameFromFileUrl('https://h.test/files/a.png'));
        $this->assertNull(haxcmsImportDrupalPathnameFromFileUrl('https://h.test'));
        $this->assertNull(haxcmsImportDrupalPathnameFromFileUrl('data:image/png;base64,xx'));
        $this->assertNull(haxcmsImportDrupalPathnameFromFileUrl('relative/x.png'));
        $this->assertSame('other/x.png', haxcmsImportDrupalFileKeyFromPath('/other/x.png'));
        $this->assertNull(haxcmsImportDrupalFileKeyFromPath('/files/../x.png'));
        $this->assertSame('https://x.test/a', haxcmsImportDrupalAbsolutizeFileUrl('https://x.test/a', 'http://h.test'));
        $this->assertSame('rel', haxcmsImportDrupalAbsolutizeFileUrl('rel', 'nope'));
        $this->assertSame('http://h.test:81/rel', haxcmsImportDrupalAbsolutizeFileUrl('rel', 'http://h.test:81'));
    }

    public function testDrupalBuildFileMapSkipReasonsAndTruncation(): void
    {
        $GLOBALS['HAXCMS_DRUPAL_LIMITS'] = array('maxFiles' => 1);
        $rec = function ($id, $url) {
            return array('id' => $id, 'attributes' => array('status' => true, 'uri' => array('url' => $url), 'filename' => $id));
        };
        $map = haxcmsImportDrupalBuildFileMap(array(
            'junk',
            $rec('a', '/sites/default/files/a.png'),
            $rec('dup', '/sites/default/files/a.png'),
            $rec('rel', 'relative.png'),
            $rec('dots', '/files/..%2F/../x.png'),
            $rec('b', '/sites/default/files/b.png'),
        ), 'http://h.test');
        $this->assertSame(array('a.png'), array_keys($map['files']));
        $this->assertTrue($map['truncated']);
        $this->assertSame(1, $map['skipped']['duplicate']);
        $this->assertSame(1, $map['skipped']['no-url']);
        $this->assertSame(1, $map['skipped']['invalid-path']);
        $this->assertSame(array(), haxcmsImportDrupalBuildFileMap(null, 'x')['files']);
    }

    // ==================================================================
    // Drupal: media
    // ==================================================================

    public function testDrupalMediaResolutionBranches(): void
    {
        $this->assertNull(haxcmsImportDrupalFirstRelationshipData('x'));
        $this->assertSame(array('id' => 'f'), haxcmsImportDrupalFirstRelationshipData(array('data' => array(array('id' => 'f')))));
        $this->assertNull(haxcmsImportDrupalFirstRelationshipData(array('data' => array())));

        $fileMap = array('filesByUuid' => array(
            'f1' => array('key' => 'v.mp4', 'ref' => 'files/v.mp4', 'url' => 'u', 'filename' => ''),
            'f2' => array('key' => 'd.pdf', 'ref' => 'files/d.pdf', 'url' => 'u', 'filename' => 'd.pdf'),
        ));
        // remote video with array oembed value and no name -> titled by url
        $remote = haxcmsImportDrupalResolveMediaRecord(array('type' => 'media--remote_video',
            'attributes' => array('field_media_oembed_video' => array('value' => 'https://v.test/1'))), $fileMap);
        $this->assertSame('https://v.test/1', $remote['title']);
        $this->assertNull(haxcmsImportDrupalResolveMediaRecord(array('type' => 'media--remote_video',
            'attributes' => array('field_media_oembed_video' => 'javascript:x')), $fileMap));
        // video found through a non-preferred relationship, titled by file key
        $video = haxcmsImportDrupalResolveMediaRecord(array('type' => 'media--video', 'relationships' => array(
            'other' => array('data' => array('type' => 'file--file', 'id' => 'f1')))), $fileMap);
        $this->assertSame('v.mp4', $video['title']);
        $this->assertStringContainsString('<video-player', $video['element']);
        // unknown bundle -> generic file link, titled by filename
        $file = haxcmsImportDrupalResolveMediaRecord(array('type' => 'media--custom', 'relationships' => array(
            'field_x' => array('data' => array('type' => 'file--file', 'id' => 'f2')))), $fileMap);
        $this->assertSame('<a href="files/d.pdf">d.pdf</a>', $file['element']);
        // no file relationship / skipped file
        $this->assertNull(haxcmsImportDrupalResolveMediaRecord(array('type' => 'media--image'), $fileMap));
        $this->assertNull(haxcmsImportDrupalResolveMediaRecord(array('type' => 'media--image', 'relationships' => array(
            'field_media_image' => array('data' => array('type' => 'file--file', 'id' => 'gone')))), $fileMap));
        $stats = haxcmsImportDrupalBuildMediaMap(array('x', array('id' => 'm', 'type' => 'media--image')), $fileMap)['stats'];
        $this->assertSame(array('total' => 1, 'resolved' => 0, 'unresolved' => 1), $stats);
        $this->assertSame(array('total' => 0, 'resolved' => 0, 'unresolved' => 0), haxcmsImportDrupalBuildMediaMap(null, $fileMap)['stats']);
    }

    public function testDrupalBodyAndEmbedHelpers(): void
    {
        $this->assertSame('', haxcmsImportDrupalGetNodeBodyValue('x'));
        $this->assertSame('<p>s</p>', haxcmsImportDrupalGetNodeBodyValue(array('attributes' => array('body' => '<p>s</p>'))));
        $this->assertSame('', haxcmsImportDrupalGetNodeBodyValue(array('attributes' => array())));
        $this->assertSame('u2', haxcmsImportDrupalExtractUuidFromAttrs(" data-entity-uuid='u2'"));
        $this->assertSame('', haxcmsImportDrupalExtractUuidFromAttrs('none'));
        $this->assertSame('', haxcmsImportDrupalReplaceMediaEmbeds('', array()));
        $this->assertSame('<p>x</p>', haxcmsImportDrupalReplaceMediaEmbeds('<p>x</p>', array()));
        $this->assertSame('[E]', haxcmsImportDrupalReplaceMediaEmbeds('<drupal-media data-entity-uuid="m"/>',
            array('m' => array('element' => '[E]'))));
        $this->assertSame(array(), haxcmsImportDrupalRenderFieldMediaElements(array('relationships' => array(
            'field_media' => array('data' => array()))), array()));
        $this->assertSame(array(), haxcmsImportDrupalRenderFieldMediaElements(array('relationships' => array(
            'field_media' => array('data' => array('id' => 'm')))), array('m' => null)));
        $this->assertSame('<a href="/x">', haxcmsImportDrupalAbsolutizeRootUrls('<a href="/x">', 'nope'));
        $this->assertSame('<img src="http://h.test:81/x">', haxcmsImportDrupalAbsolutizeRootUrls('<img src="/x">', 'http://h.test:81'));
    }

    public function testDrupalFileReferenceRewriting(): void
    {
        $map = array('filesByPath' => array('/files/a.png' => 'files/a.png'));
        $this->assertNull(haxcmsImportDrupalFileReferenceFor('', $map));
        $this->assertNull(haxcmsImportDrupalFileReferenceFor('//cdn/a.png', $map));
        $this->assertSame('files/a.png', haxcmsImportDrupalFileReferenceFor('https://h.test/files/a.png', $map));
        $this->assertNull(haxcmsImportDrupalFileReferenceFor('https://h.test', $map));
        $this->assertNull(haxcmsImportDrupalFileReferenceFor('mailto:a@b', $map));
        $this->assertNull(haxcmsImportDrupalFileReferenceFor('/', $map));
        $this->assertSame('files/a.png 1x, /other.png 2x',
            haxcmsImportDrupalRewriteSrcsetValue('/files/a.png 1x, , /other.png 2x', $map));
        $this->assertSame('', haxcmsImportDrupalRewriteFileReferences('', $map));
        $this->assertSame('<p>x</p>', haxcmsImportDrupalRewriteFileReferences('<p>x</p>', array('filesByPath' => array())));
        $out = haxcmsImportDrupalRewriteFileReferences('<img src="" srcset="/files/a.png 1x"><a href="/files/a.png">a</a>', $map);
        $this->assertStringContainsString('srcset="files/a.png 1x"', $out);
        $this->assertStringContainsString('href="files/a.png"', $out);
    }

    // ==================================================================
    // Drupal: menu / book / outline forests
    // ==================================================================

    public function testDrupalMenuUriAndPluginParsing(): void
    {
        $this->assertSame(0, haxcmsImportDrupalParseNodeIdFromMenuUri(''));
        $this->assertSame(7, haxcmsImportDrupalParseNodeIdFromMenuUri('internal:/node/7'));
        $this->assertSame(8, haxcmsImportDrupalParseNodeIdFromMenuUri('https://h.test/node/8/edit'));
        $this->assertSame(0, haxcmsImportDrupalParseNodeIdFromMenuUri('route:<front>'));
        $this->assertSame('', haxcmsImportDrupalParseParentLinkId('x'));
        $this->assertSame('plain', haxcmsImportDrupalParseParentLinkId(array('attributes' => array('parent' => 'plain'))));
        $this->assertSame('rel', haxcmsImportDrupalParseParentLinkId(array('relationships' => array('parent' => array('data' => array('id' => 'rel'))))));
        $this->assertSame('', haxcmsImportDrupalParseParentLinkId(array()));
        $this->assertSame('', haxcmsImportDrupalParsePluginIdValue('  '));
        $this->assertSame('', haxcmsImportDrupalParsePluginIdValue(null));
        $rel = array();
        haxcmsImportDrupalAddRelation($rel, 5, 0, 3);
        haxcmsImportDrupalAddRelation($rel, 5, 0, 1);
        $this->assertSame(1, $rel[5]['weight']);
    }

    public function testDrupalMenuItemForestBranches(): void
    {
        $pageSet = array(10 => true, 11 => true, 12 => true);
        $this->assertNull(haxcmsImportDrupalNormalizeMenuItem('x', array()));
        // flat collection: nids via route / url / alias; parent chain skips a views link
        $flat = haxcmsImportDrupalForestFromMenuItems(array(
            'junk',
            array('id' => 'menu_link_content:a', 'attributes' => array('url' => '/node/10', 'weight' => 0)),
            array('id' => 'views:v', 'attributes' => array('url' => '/news', 'parent' => 'menu_link_content:a')),
            array('id' => 'menu_link_content:b', 'attributes' => array('url' => '/alias-11', 'parent' => 'views:v')),
            array('id' => 'menu_link_content:c', 'attributes' => array('url' => '/x', 'parent' => 'missing')),
        ), $pageSet, array('/alias-11' => 11));
        $this->assertSame(array(0 => array(10), 10 => array(11)), $flat['childrenByParent']);
        // nested children payload (raw['children'] form, no attributes wrapper)
        $nested = haxcmsImportDrupalForestFromMenuItems(array(
            array('title' => 'Hub', 'url' => '/hub', 'children' => array(
                array('url' => '/node/12', 'weight' => 1),
            )),
        ), $pageSet, array());
        $this->assertSame(array(0 => array(12)), $nested['childrenByParent']);
        $this->assertNull(haxcmsImportDrupalForestFromMenuItems(array(), $pageSet, array()));
        $this->assertNull(haxcmsImportDrupalForestFromMenuItems(array(array('url' => '/none')), $pageSet, array()));
    }

    public function testDrupalMenuItemsListAndMenuNames(): void
    {
        $this->assertSame(array(1), haxcmsImportDrupalExtractMenuItemsList(array('data' => array(1))));
        $this->assertSame(array(2), haxcmsImportDrupalExtractMenuItemsList(array('items' => array(2))));
        $this->assertNull(haxcmsImportDrupalExtractMenuItemsList(array('nope' => 1)));
        $this->assertNull(haxcmsImportDrupalExtractMenuItemsList(null));
        $this->assertSame(array('main', 'footer', 'side'), haxcmsImportDrupalBuildMenuNameCandidates(array(
            'junk',
            array('attributes' => array('drupal_internal__id' => 'footer')),
            array('attributes' => array('drupal_internal__id' => 'admin')),
            array('attributes' => array('drupal_internal__id' => 'footer')),
            array('id' => 'side', 'attributes' => array()),
            array('attributes' => array()),
        )));
    }

    public function testDrupalMenuItemsForestValidPayloadWithoutPagesStopsProbing(): void
    {
        $this->routes[self::API . '/jsonapi/menu_items/main'] = array('data' => array(array('attributes' => array('url' => '/none'))));
        $forest = haxcmsImportDrupalFetchMenuItemsForest($this->client(), 'http://' . self::API, array(), array(1 => true), array());
        $this->assertNull($forest);
    }

    public function testDrupalMenuLinksForestPicksBestNonMainMenuAndAppendsOthers(): void
    {
        $pageSet = array(1 => true, 2 => true, 3 => true);
        $link = function ($id, $menu, $nid, $weight = 0, $parent = '') {
            return array('id' => $id, 'attributes' => array('menu_name' => $menu, 'weight' => $weight,
                'parent' => $parent, 'link' => array('uri' => 'entity:node/' . $nid)));
        };
        $forest = haxcmsImportDrupalForestFromMenuLinks($pageSet, array(
            'junk',
            $link('m1', 'main', 99),
            $link('s1', 'side', 1),
            $link('s2', 'side', 2, 0, 'menu_link_content:s1'),
            $link('f1', 'footer', 3, 5),
            $link('f2', 'footer', 1),
        ));
        $this->assertSame('menu-link-content', $forest['source']);
        $this->assertSame(array(0 => array(1, 3), 1 => array(2)), $forest['childrenByParent']);
        $this->assertNull(haxcmsImportDrupalForestFromMenuLinks($pageSet, array()));
        $this->assertNull(haxcmsImportDrupalForestFromMenuLinks($pageSet, array('junk')));
        $this->assertNull(haxcmsImportDrupalForestFromMenuLinks($pageSet, array($link('x', 'main', 50))));
    }

    public function testDrupalBookFieldVariants(): void
    {
        $pageSet = array(1 => true, 2 => true, 3 => true, 4 => true);
        $node = function ($nid, $attrs) {
            return array('attributes' => array_merge(array('drupal_internal__nid' => $nid), $attrs));
        };
        $forest = haxcmsImportDrupalForestFromBookFields(array(
            'junk',
            $node(1, array('book' => array('parent' => 0, 'menu_order' => 2))),
            $node(2, array('book' => array('parent_nid' => 1))),
            $node(3, array('field_book_parent_nid' => 1, 'book_order' => 4)),
            $node(4, array('book_parent' => 0)),
            $node(99, array('book_parent' => 1)),
            $node(1, array()),
        ), $pageSet);
        $this->assertSame('book-fields', $forest['source']);
        $this->assertSame(array(0 => array(4, 1), 1 => array(2, 3)), $forest['childrenByParent']);
        $this->assertNull(haxcmsImportDrupalForestFromBookFields(array(), $pageSet));
        $this->assertNull(haxcmsImportDrupalForestFromBookFields(array($node(1, array())), $pageSet));
    }

    // ==================================================================
    // VitePress: fetch helpers, config parsing, literal parser, paths
    // ==================================================================

    public function testVitepressFetchHelpersErrorBranches(): void
    {
        $client = $this->client();
        try {
            haxcmsImportVitepressFetchJson($client, 'http://127.0.0.1/x', 'thing');
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Unable to reach thing: ', $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }
        $this->routes[self::API . '/notjson'] = 'nope';
        try {
            haxcmsImportVitepressFetchJson($client, 'http://' . self::API . '/notjson', 'thing');
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Unable to parse thing', $e->getMessage());
        }
        $this->assertNull(haxcmsImportVitepressFetchText($client, 'http://127.0.0.1/x'));
    }

    public function testVitepressConfigPathRanking(): void
    {
        $this->assertNull(haxcmsImportVitepressFindConfigPath(array('README.md' => true)));
        $this->assertSame('a/.vitepress/config.ts', haxcmsImportVitepressFindConfigPath(array(
            'b/c/.vitepress/config.ts' => true, 'a/.vitepress/config.ts' => true, 'z/.vitepress/config.ts' => true,
        )));
        $this->assertSame('.vitepress/config.js', haxcmsImportVitepressFindConfigPath(array(
            'site/.vitepress/config.js' => true, '.vitepress/config.js' => true,
        )));
    }

    public function testVitepressConfigReadingAndSidebarShapes(): void
    {
        $config = haxcmsImportVitepressReadConfig("export default { title: 'T', sidebar: { '/a/': [{ text: 'A' }], '/b/': 'x' } }");
        $this->assertSame('T', $config['title']);
        $this->assertCount(1, $config['sidebar']);
        $this->assertNull(haxcmsImportVitepressReadConfig("sidebar: [ fn() ]")['sidebar']);
        $this->assertNull(haxcmsImportVitepressNormalizeSidebar('str'));
        $this->assertNull(haxcmsImportVitepressNormalizeSidebar(new stdClass()));
        // key only inside a string / comment is ignored
        $this->assertNull(haxcmsImportVitepressReadStringValue("// title: 'no'\n/* title: 'no' */ x = \"title: 'no'\"", 'title'));
        $this->assertNull(haxcmsImportVitepressReadStringValue('title: someVar', 'title'));
        $this->assertNull(haxcmsImportVitepressReadLiteralValue('sidebar:', 'sidebar'));
        $this->assertNull(haxcmsImportVitepressReadLiteralValue('sidebar: 5', 'sidebar'));
        $this->assertNull(haxcmsImportVitepressReadLiteralValue('sidebar: [1, "]"', 'sidebar'));
        $this->assertSame("['a\\'b', \"]\"]", haxcmsImportVitepressReadLiteralValue("sidebar: ['a\\'b', \"]\"]", 'sidebar'));
        $this->assertNull(haxcmsImportVitepressNormalizeLicense(5));
        $this->assertSame('by-nc', haxcmsImportVitepressNormalizeLicense(' CC-BY-NC '));
    }

    public function testVitepressJsLiteralParserBranches(): void
    {
        $value = haxcmsImportVitepressParseJsLiteral("/* c */ [true, false, null, -1.5e2, 'a\\nb\\tc\\q', `t`, { 'k': 1, j: [] }] // tail");
        $this->assertSame(array(true, false, null, -150.0, "a\nb\tcq", 't'), array_slice($value, 0, 6));
        $this->assertSame(1, $value[6]->k);
        foreach (array(
            '[1] x' => 'unexpected content after the literal',
            '' => 'unexpected end of the literal',
            '[foo]' => 'unsupported value at 1',
            '[1 2]' => 'expected , or ] at 3',
            '{1: 2}' => 'expected a key at 1',
            '{a 1}' => 'expected : after a',
            '{a: 1 b}' => 'expected , or } at 6',
            '`${x}`' => 'template expressions are not plain data',
            "'open" => 'unterminated string',
        ) as $source => $message) {
            try {
                haxcmsImportVitepressParseJsLiteral($source);
                $this->fail('expected failure for ' . $source);
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage(), $source);
            }
        }
    }

    private function vitepressRun(array $paths, string $docsRoot = 'docs', ?string $base = null): array
    {
        return array(
            'source' => array(
                'paths' => array_fill_keys($paths, true), 'docsRoot' => $docsRoot,
                'rawBase' => 'http://raw/o/r/main', 'title' => 'Repo Title', 'license' => null,
                'config' => array('base' => $base, 'workTitle' => null, 'defaultAuthor' => null),
            ),
            'files' => array(), 'filesByPath' => array(), 'fileNames' => array(), 'truncated' => false,
            'unmapped' => array(), 'slugByPath' => array(),
        );
    }

    public function testVitepressAssetPathsUniqueNamesAndAbsoluteUrls(): void
    {
        $run = $this->vitepressRun(array('docs/public/logo.png', 'docs/guide/a/pic.png', 'docs/b/pic.png'), 'docs', '/site/');
        $node = array('path' => 'docs/guide/a/page.md');
        $this->assertSame('docs/public/logo.png', haxcmsImportVitepressResolveAssetPath('/site/logo.png', $node, $run));
        $this->assertNull(haxcmsImportVitepressResolveAssetPath('/missing.png', $node, $run));
        $this->assertSame('docs/b/pic.png', haxcmsImportVitepressResolveAssetPath('../../b/pic.png', $node, $run));
        $this->assertSame('docs/b/pic.png', haxcmsImportVitepressResolveAssetPath('b/pic.png', array(), $run));

        $this->assertSame('files/pic.png', haxcmsImportVitepressRegisterAsset('pic.png', $node, $run));
        $this->assertSame('files/pic.png', haxcmsImportVitepressRegisterAsset('pic.png?v=1', $node, $run));
        $this->assertSame('files/pic-1.png', haxcmsImportVitepressRegisterAsset('../../b/pic.png', $node, $run));
        $this->assertNull(haxcmsImportVitepressRegisterAsset('https://x/y.png', $node, $run));
        $this->assertNull(haxcmsImportVitepressRegisterAsset('script.exe', $node, $run));
        $names = array('fileNames' => array('a.png' => true, 'a-1.png' => true));
        $this->assertSame('a-2.png', haxcmsImportVitepressUniqueFileName('x/a.png', $names));
        $noExt = array('fileNames' => array('README' => true));
        $this->assertSame('README-1', haxcmsImportVitepressUniqueFileName('README', $noExt));

        $this->assertSame('mailto:a', haxcmsImportVitepressAbsoluteSourceUrl('mailto:a', $node, $run));
        $this->assertSame('http://raw/o/r/main/docs/nope.pdf', haxcmsImportVitepressAbsoluteSourceUrl('/nope.pdf', $node, $run));
        $this->assertSame('http://raw/o/r/main/docs/guide/a/x%20y.pdf', haxcmsImportVitepressAbsoluteSourceUrl('x y.pdf', $node, $run));
        $this->assertSame('http://raw/o/r/main/docs/z.pdf', haxcmsImportVitepressAbsoluteSourceUrl('z.pdf', array(), $run));
        $this->assertSame('a/c', haxcmsImportVitepressNormalizePath('./a/b/../c/'));
    }

    public function testVitepressComponentsAttributesAndLicense(): void
    {
        $run = $this->vitepressRun(array());
        $html = haxcmsImportVitepressMapVueComponents('<VideoEmbed src="" /><VideoEmbed src="https://v/x.mp4" caption="Cap" /><Foo>keep</Foo>', array(), $run);
        $this->assertStringContainsString('media-title="Cap"', $html);
        $this->assertStringContainsString('<div slot="caption">Cap</div>', $html);
        $this->assertStringContainsString('keep', $html);
        $this->assertArrayHasKey('Foo', $run['unmapped']);
        $this->assertSame(array('a' => 'x', 'b' => 'y', 'c' => 'z', 'flag' => 'true'),
            haxcmsImportVitepressParseAttributes("a=\"x\" b='y' c={z} flag"));
        $this->assertSame(array(), haxcmsImportVitepressParseAttributes('   '));
        $this->assertSame('', haxcmsImportVitepressRewriteHtml('  ', array(), $run));
        $element = haxcmsImportVitepressLicenseElement('by', array('title' => '', 'metadata' => array('source' => 's')), $run, 'Ada');
        $this->assertSame('<license-element license="by" title="Repo Title" creator="Ada" source="s"></license-element>', $element);
        $run['source']['config']['workTitle'] = 'Work';
        $this->assertStringContainsString('title="Work"', haxcmsImportVitepressLicenseElement('by', array('title' => null, 'metadata' => array()), $run, null));
    }

    public function testVitepressRewriteMediaSkipsEmptyImagesAndResolvesRelativeVideo(): void
    {
        $run = $this->vitepressRun(array('docs/guide/clip.mp4'));
        $node = array('path' => 'docs/guide/page.md');
        $html = haxcmsImportVitepressRewriteHtml(
            '<p><img src="" alt="none"><video-player source="clip.mp4"></video-player>'
            . '<video-player source="missing.mp4"></video-player><video-player source="files/x.mp4"></video-player></p>',
            $node,
            $run
        );
        $this->assertStringContainsString('<img src="" alt="none">', $html);
        $this->assertStringContainsString('source="files/clip.mp4"', $html);
        $this->assertStringContainsString('source="http://raw/o/r/main/docs/guide/missing.mp4"', $html);
        $this->assertStringContainsString('source="files/x.mp4"', $html);
        $this->assertSame('<p></p>', haxcmsImportVitepressSourceFallback(''));
    }

    public function testDrupalCollectionStopsOnPayloadWithoutData(): void
    {
        $this->routes[self::API . '/jsonapi/node/page'] = array('meta' => array());
        $this->assertSame(array(), haxcmsImportDrupalFetchCollectionByHref($this->client(), 'http://' . self::API . '/jsonapi/node/page'));
    }

    public function testDrupalMenuItemEdgeBranches(): void
    {
        $pageSet = array(20 => true, 21 => true);
        // parent plugin id that is not in the collection -> promoted to top level;
        // children nested under the attributes wrapper are walked
        $forest = haxcmsImportDrupalForestFromMenuItems(array(
            array('id' => 'menu_link_content:x', 'attributes' => array('url' => '/node/20', 'parent' => 'menu_link_content:ghost')),
        ), $pageSet, array());
        $this->assertSame(array(0 => array(20)), $forest['childrenByParent']);
        $nested = haxcmsImportDrupalForestFromMenuItems(array(
            array('attributes' => array('url' => '/node/20', 'children' => array(
                array('attributes' => array('url' => '/node/21')),
            ))),
        ), $pageSet, array());
        $this->assertSame(array(0 => array(20), 20 => array(21)), $nested['childrenByParent']);
    }

    public function testDrupalMenuLinksSkipsNonPageAndTieBreaksOtherMenus(): void
    {
        $pageSet = array(1 => true, 2 => true, 3 => true);
        $link = function ($id, $menu, $nid, $weight = 0) {
            return array('id' => $id, 'attributes' => array('menu_name' => $menu, 'weight' => $weight,
                'link' => array('uri' => 'entity:node/' . $nid)));
        };
        $forest = haxcmsImportDrupalForestFromMenuLinks($pageSet, array(
            $link('m1', 'main', 1),
            $link('m2', 'main', 99),
            $link('f3', 'footer', 3, 2),
            $link('f2', 'footer', 2, 2),
        ));
        // equal weights fall back to nid order
        $this->assertSame(array(0 => array(1, 2, 3)), $forest['childrenByParent']);
    }

    // ==================================================================
    // Gitbook network flow
    // ==================================================================

    private function gitbook(array $body): array
    {
        $client = $this->client();
        return $this->capture(function () use ($body, $client) {
            haxcmsImportConvertGitbookToSite($this->ctx($body), $client,
                array('api' => 'http://' . self::API, 'raw' => 'http://' . self::RAW));
        });
    }

    public function testGitbookResolvesDefaultBranchTreeSummaryAndPages(): void
    {
        $this->routes[self::API . '/repos/acme/book'] = array('default_branch' => 'master');
        $this->routes[self::API . '/repos/acme/book/git/trees/master?recursive=1'] = array('tree' => array(
            array('path' => 'docs/SUMMARY.md'), array('path' => 'assets/pic.png'), array('path' => 'intro.md'),
        ));
        $this->routes[self::RAW . '/acme/book/master/docs/SUMMARY.md'] = "* [Intro](intro.md)\n  * [Missing](missing.md)\n* [Anchor](#)\n";
        $this->routes[self::RAW . '/acme/book/master/intro.md'] = "Hi ![p](assets/pic.png)";

        $res = $this->gitbook(array('repoUrl' => 'https://github.com/acme/book', 'parentId' => 'top'));

        $this->assertSame(200, $res['status']);
        $this->assertSame('book', $res['data']['filename']);
        $this->assertSame(array('files/assets/pic.png'), array_keys($res['data']['files']));
        $items = $res['data']['items'];
        $this->assertSame(array('Intro', 'Missing', 'Anchor'), array_column($items, 'title'));
        $this->assertSame('top', $items[0]['parent']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertStringContainsString('files/assets/pic.png', $items[0]['contents']);
        $this->assertSame('', $items[1]['contents']);
    }

    public function testGitbookFallsBackToMainAndRootSummary(): void
    {
        // repo meta + tree 404: branch defaults to main, root SUMMARY.md fetched directly
        $this->routes[self::RAW . '/acme/book/main/SUMMARY.md'] = "* [A](a.md)\n";
        $res = $this->gitbook(array('repoUrl' => 'https://github.com/acme/book'));
        $this->assertSame(200, $res['status']);
        $this->assertSame('A', $res['data']['items'][0]['title']);
        $this->assertSame('', $res['data']['items'][0]['parent']);
    }

    public function testGitbookTreeSummaryFetchFailureFallsBackToRoot(): void
    {
        $this->routes[self::API . '/repos/acme/book/git/trees/dev?recursive=1'] = array('tree' => array(array('path' => 'x/summary.md')));
        $this->routes[self::RAW . '/acme/book/dev/SUMMARY.md'] = "* [B](b.md)\n";
        $res = $this->gitbook(array('repoUrl' => 'https://github.com/acme/book/tree/dev'));
        $this->assertSame('B', $res['data']['items'][0]['title']);
    }

    public function testGitbookMissingSummaryReturns400(): void
    {
        $res = $this->gitbook(array('repoUrl' => 'https://github.com/acme/book/tree/dev'));
        $this->assertSame(400, $res['status']);
        $this->assertSame('Could not find SUMMARY.md in https://github.com/acme/book/tree/dev', $res['data']['error']);
    }

    public function testGitbookPureHelperGuards(): void
    {
        $this->assertSame(array(array(), array()), haxcmsGitbookBuildFileMaps(array('junk', array('path' => 5)), 'o', 'r', 'b'));
        $this->assertNull(haxcmsGitbookFindSummaryPath('x'));
        $this->assertNull(haxcmsGitbookFindSummaryPath(array('junk')));
        $this->assertSame(array(), haxcmsGitbookSummaryHtmlToItems('Just a paragraph', function () { return ''; }, array(), null));
    }

    // ==================================================================
    // HTML import by URL
    // ==================================================================

    public function testHtmlImportFromUrlAndFetchFailure(): void
    {
        $this->routes[self::API . '/pages/doc.html'] = '<h1>Doc</h1><p>Body</p>';
        $client = $this->client();
        $ok = $this->capture(function () use ($client) {
            haxcmsImportConvertHtmlToSite($this->ctx(array('repoUrl' => 'http://' . self::API . '/pages/doc.html')), $client);
        });
        $this->assertSame(200, $ok['status']);
        $this->assertSame('doc.html', $ok['data']['filename']);
        $this->assertNotEmpty($ok['data']['items']);

        $bad = $this->capture(function () use ($client) {
            haxcmsImportConvertHtmlToSite($this->ctx(array('repoUrl' => 'http://' . self::API . '/missing.html')), $client);
        });
        $this->assertSame(400, $bad['status']);
        $this->assertStringStartsWith('Unable to fetch URL: ', $bad['data']['error']);

        // no injected client: the default one is built, and SsrfGuard refuses
        // the loopback target before any request leaves the process
        $blocked = $this->capture(function () {
            haxcmsImportConvertHtmlToSite($this->ctx(array('repoUrl' => 'http://127.0.0.1/x.html')));
        });
        $this->assertSame(400, $blocked['status']);
        $this->assertStringStartsWith('Unable to fetch URL: ', $blocked['data']['error']);
    }
}
