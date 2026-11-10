<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once dirname(__DIR__, 2) . '/lib/BlockOperations.php';

/**
 * Route-level tests for contentBlocks.php (GET/PATCH
 * x/api/v1/content/{idOrSlug}/blocks, haxtheweb/issues#3270). Mirrors
 * haxcms-nodejs test/unit/content-blocks-route.test.cjs: listing, a
 * successful patch and its side effects (one write, one commit, metadata),
 * and each refusal with its status and reason.
 */
class SiteRoutesContentBlocksTest extends TestCase
{
    const PAGE = '<h2>Media</h2><p>See <img src="/i.png" alt="old"> and <a href="https://media.aanda.psu.edu/v.mp4">video</a></p><video-player source="https://media.aanda.psu.edu/v.mp4" media-title="Lecture"></video-player>';

    private function makeSite($metadata = array())
    {
        $site = new SiteRoutesContentBlocksFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('item-1', 'one', 'One', '', 0, 0, '', '', $metadata),
        );
        $site->pageContentMap = array('item-1' => self::PAGE);
        return $site;
    }

    private function invoke($site, $method, $body = array(), $idOrSlug = 'one')
    {
        $context = new SiteRoutesContentBlocksContext($site);
        $context->apiBasePath = '/x/api';
        $context->routeSuffix = 'v1/content/' . $idOrSlug . '/blocks';
        $context->method = $method;
        $context->auth = array('authenticated' => true, 'userName' => 'test-user');
        $context->setRouteParams(array('idOrSlug' => $idOrSlug));
        $context->body = $body;
        return invokeSiteRouteHandler('contentBlocks.php', $context);
    }

    private function op($site, $tag, $index, $set)
    {
        foreach (BlockOperations::listBlocks($site->pageContentMap['item-1'], $tag) as $b) {
            if ($b['index'] === $index) {
                return array('op' => 'setAttributes', 'tag' => $tag, 'index' => $index, 'before' => $b['before'], 'set' => $set);
            }
        }
        $this->fail('no ' . $tag . '#' . $index);
    }

    public function testListsAllowlistedBlocksWithDecodedAttributes(): void
    {
        $result = $this->invoke($this->makeSite(), 'GET');
        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('item-1', $data['id']);
        $this->assertSame(3, $data['count']);
        $this->assertSame(array('img', 'a', 'video-player'), array_column($data['blocks'], 'tag'));
        $this->assertSame('<img src="/i.png" alt="old">', $data['blocks'][0]['before']);
        $this->assertSame(array('alt' => 'old', 'src' => '/i.png'), $data['blocks'][0]['attributes']);
        $this->assertSame('/x/api/v1/content/one/blocks', $data['links']['self']);
    }

    public function testUnknownPageReturns404(): void
    {
        $result = $this->invoke($this->makeSite(), 'GET', array(), 'nope');
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Content not found for idOrSlug "nope"', $result['data']['data']['message']);
    }

    public function testPatchWritesOnceCommitsOnceAndFollowsMediaUrls(): void
    {
        $site = $this->makeSite(array('images' => array('/i.png'), 'videos' => array('https://media.aanda.psu.edu/v.mp4', 'other.mp4')));
        $ops = array(
            $this->op($site, 'img', 0, array('alt' => 'A water cycle diagram')),
            $this->op($site, 'video-player', 0, array('source' => 'https://media.psu.edu/v.mp4')),
            $this->op($site, 'a', 0, array('href' => 'https://media.psu.edu/v.mp4')),
        );
        $result = $this->invoke($site, 'PATCH', array('ops' => $ops, 'note' => "media <b>move</b>\nbatch 1"));
        $this->assertSame(200, $result['data']['status'], $result['raw']);
        $data = $result['data']['data'];
        $this->assertSame(3, $data['count']);
        $this->assertSame('<img src="/i.png" alt="A water cycle diagram">', $data['results'][0]['before']);
        $this->assertSame(array(0, 1, 2), array_column($data['results'], 'op'));
        $expected = str_replace(
            array('alt="old"', 'href="https://media.aanda.psu.edu/v.mp4"', 'source="https://media.aanda.psu.edu/v.mp4"'),
            array('alt="A water cycle diagram"', 'href="https://media.psu.edu/v.mp4"', 'source="https://media.psu.edu/v.mp4"'),
            self::PAGE
        );
        $this->assertSame($expected, $site->pageContentMap['item-1']);
        $this->assertSame(1, $site->writes);
        $this->assertSame(
            array('Block operations: alt on 1 img, source on 1 video-player, href on 1 a on One (item-1): media bmove/b batch 1'),
            $site->commits
        );
        $page = $site->manifest->items[0];
        $this->assertSame(array('https://media.psu.edu/v.mp4', 'other.mp4'), $page->metadata->videos);
        $this->assertSame(array('/i.png'), $page->metadata->images);
        $this->assertIsInt($page->metadata->updated);
        $this->assertSame(1, $site->manifestSaves);
    }

    public function testStaleBeforeReturns409AndWritesNothing(): void
    {
        $site = $this->makeSite();
        $op = $this->op($site, 'img', 0, array('alt' => 'x'));
        $op['before'] = '<img src="/i.png" alt="older">';
        $result = $this->invoke($site, 'PATCH', array('ops' => array($op)));
        $this->assertSame(409, $result['data']['status']);
        $this->assertSame('Block 0 no longer matches the stored page', $result['data']['data']['message']);
        $this->assertSame('stale', $result['data']['data']['reason']);
        $this->assertSame('BLOCK_STALE', $result['data']['data']['code']);
        $this->assertSame(0, $site->writes);
        $this->assertSame(array(), $site->commits);
    }

    public function testRefusalReturns400WithReasonAndOpIndex(): void
    {
        $site = $this->makeSite();
        $ops = array(
            $this->op($site, 'img', 0, array('alt' => 'fine')),
            $this->op($site, 'a', 0, array('href' => 'javascript:alert(1)')),
        );
        $result = $this->invoke($site, 'PATCH', array('ops' => $ops));
        $this->assertSame(400, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('invalid', $data['reason']);
        $this->assertSame(1, $data['op']);
        $this->assertSame('Block 1 refused (invalid): href must be an http(s) URL or a site path', $data['message']);
        $this->assertSame('BLOCK_REFUSED', $data['code']);
        $this->assertSame(self::PAGE, $site->pageContentMap['item-1']);
        $this->assertSame(0, $site->writes);
    }

    public function testLockedPageReturns423(): void
    {
        $site = $this->makeSite(array('locked' => true));
        $result = $this->invoke($site, 'PATCH', array('ops' => array($this->op($site, 'img', 0, array('alt' => 'x')))));
        $this->assertSame(423, $result['data']['status']);
        $this->assertSame('Page "one" is locked', $result['data']['data']['message']);
        $this->assertSame('locked', $result['data']['data']['reason']);
        $this->assertSame('PAGE_LOCKED', $result['data']['data']['code']);
        $this->assertSame(0, $site->writes);
    }

    public function testMissingOrTooManyOps(): void
    {
        $site = $this->makeSite();
        $none = $this->invoke($site, 'PATCH', array());
        $this->assertSame(400, $none['data']['status']);
        $this->assertSame('Missing `ops` param', $none['data']['data']['message']);
        $notList = $this->invoke($site, 'PATCH', array('ops' => array('a' => 1)));
        $this->assertSame(400, $notList['data']['status']);
        $many = array_fill(0, BlockOperations::MAX_OPS + 1, $this->op($site, 'img', 0, array('alt' => 'x')));
        $tooMany = $this->invoke($site, 'PATCH', array('ops' => $many));
        $this->assertSame(400, $tooMany['data']['status']);
        $this->assertSame('invalid ops value (at most 200 per page)', $tooMany['data']['data']['message']);
    }

    public function testSecondPatchWithTheSameBeforeIsStale(): void
    {
        $site = $this->makeSite();
        $op = $this->op($site, 'img', 0, array('alt' => 'first'));
        $first = $this->invoke($site, 'PATCH', array('ops' => array($op)));
        $this->assertSame(200, $first['data']['status']);
        $op['set'] = array('alt' => 'second');
        $second = $this->invoke($site, 'PATCH', array('ops' => array($op)));
        $this->assertSame(409, $second['data']['status']);
        $this->assertSame(1, $site->writes);
        $this->assertSame(1, count($site->commits));
    }

    public function testNormalizeNoteKeepsOneLineOfPlainText(): void
    {
        include_once dirname(__DIR__, 2) . '/lib/siteRoutes/v1/contentBlocks.php';
        $this->assertSame('Reports: balt/b text', ContentBlocksRoute::normalizeNote("Reports: <b>alt</b>\ntext"));
        $this->assertSame('scriptscriptalert(1)/script/script', ContentBlocksRoute::normalizeNote('<<script>script>alert(1)<</script>/script>'));
        $this->assertSame('ab', ContentBlocksRoute::normalizeNote("a\x00b\x7f"));
        $this->assertSame(120, mb_strlen(ContentBlocksRoute::normalizeNote(str_repeat('x', 200))));
        $this->assertSame('', ContentBlocksRoute::normalizeNote(5));
    }

    public function testSummarizeGroupsByAttributesAndTag(): void
    {
        include_once dirname(__DIR__, 2) . '/lib/siteRoutes/v1/contentBlocks.php';
        $this->assertSame(
            'alt on 2 img, block on 1 p, alt+title on 1 video-player',
            ContentBlocksRoute::summarize(array(
                array('op' => 'setAttributes', 'tag' => 'IMG', 'set' => array('alt' => 'a')),
                array('op' => 'replaceBlock', 'tag' => 'p'),
                array('op' => 'setAttributes', 'tag' => 'img', 'set' => array('ALT' => 'b')),
                array('op' => 'setAttributes', 'tag' => 'video-player', 'set' => array('title' => 'a', 'alt' => 'b')),
            ))
        );
    }
}

class SiteRoutesContentBlocksContext extends SiteApiRequestContext
{
    public $body = array();

    public function getBody()
    {
        return is_array($this->body) ? $this->body : array();
    }
}

/** A page whose writes land back in the fake site's content map. */
class SiteRoutesContentBlocksFakePage
{
    public $id;
    public $slug;
    public $title;
    public $location;
    public $metadata;
    public $site;

    public function writeLocation($body, $basePath = '')
    {
        $this->site->pageContentMap[$this->id] = $body;
        $this->site->writes++;
        return strlen($body);
    }
}

class SiteRoutesContentBlocksFakeSite extends SiteRoutesFakeSite
{
    public $writes = 0;
    public $commits = array();
    public $manifestSaves = 0;

    public function __construct()
    {
        parent::__construct();
        $this->manifest = new SiteRoutesContentBlocksFakeManifest($this);
    }

    public function loadNode($id)
    {
        foreach ($this->manifest->items as $item) {
            if ($item->id === $id) {
                $page = new SiteRoutesContentBlocksFakePage();
                foreach (array('id', 'slug', 'title', 'location') as $key) {
                    $page->{$key} = $item->{$key};
                }
                $page->metadata = isset($item->metadata) ? clone $item->metadata : new stdClass();
                $page->site = $this;
                return $page;
            }
        }
        return false;
    }

    public function updateNode($page, $persist = true)
    {
        foreach ($this->manifest->items as $key => $item) {
            if ($item->id === $page->id) {
                $this->manifest->items[$key] = $page;
                return $page;
            }
        }
        return false;
    }

    public function writePageAlternateFormats($page, $html = '')
    {
        return true;
    }

    public function gitCommit($message = '')
    {
        $this->commits[] = $message;
        return true;
    }
}

class SiteRoutesContentBlocksFakeManifest extends SiteRoutesFakeManifest
{
    private $owner;

    public function __construct($owner)
    {
        parent::__construct();
        $this->owner = $owner;
    }

    public function save($reload = true)
    {
        $this->owner->manifestSaves++;
        return true;
    }
}
