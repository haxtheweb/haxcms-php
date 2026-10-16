<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage tests for lib/ReportHelpers.php (HAXCMSReportHelpers):
 *
 *   - getSiteName / validateSiteToken / normalizeActiveId param handling
 *   - buildSummaryData: counts of every analyzePage metric, site metadata
 *     created/updated, manifest title, active-item overrides, readTime
 *   - buildLinkData: aggregation by href, empty-text fallback, dedupe
 *   - buildContentData: per-page record shape
 *   - buildMediaData: source/src/parent-src resolution, type detection
 *     (audio/video/image/h5p/iframe variants), alt/title inheritance,
 *     mediaStatus error/warning/info branches, transcript variants,
 *     internal/external/unknown source classification
 *   - getSelectedItems: published filtering, activeId branch walking
 *     (parents, orphans, cycle guard, id-less items)
 *   - getUpdatedItems sorting + slicing, getItemMetaValue object/array/missing
 *   - getPageContent failure modes (no methods, no page, non-string, no id)
 *   - resolveLocalSource via reflection (origin + port + basePath resolution)
 */
class ReportHelpersCoverageTest extends TestCase
{
    private $savedHaxcms;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
    }

    private function item($id, $title, $slug, $metadata = array(), $parent = null)
    {
        $obj = new stdClass();
        $obj->id = $id;
        $obj->title = $title;
        $obj->slug = $slug;
        $obj->location = 'pages/' . $slug . '/index.html';
        $obj->parent = $parent;
        $obj->metadata = (object) $metadata;
        return $obj;
    }

    private function bareItem($parent = null)
    {
        // item without an id at all
        $obj = new stdClass();
        $obj->title = 'No Id';
        $obj->slug = 'no-id';
        $obj->parent = $parent;
        return $obj;
    }

    private function parentlessItem()
    {
        // item with an id but no parent property at all
        $obj = new stdClass();
        $obj->id = 'parentless';
        $obj->title = 'Parentless';
        return $obj;
    }

    private function makeSite($items, $manifest = null)
    {
        if (is_null($manifest)) {
            $manifest = new stdClass();
            $manifest->items = $items;
        }
        $site = new ReportHelpersFakeSite($manifest);
        return $site;
    }

    public function testGetSiteNameVariants()
    {
        $this->assertSame('one', HAXCMSReportHelpers::getSiteName(array('site' => array('name' => ' one '))));
        $this->assertSame('two', HAXCMSReportHelpers::getSiteName(array('siteName' => ' two ')));
        $this->assertSame('', HAXCMSReportHelpers::getSiteName(array()));
        $this->assertSame('', HAXCMSReportHelpers::getSiteName(array('site' => 'not-an-array')));
    }

    public function testValidateSiteToken()
    {
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array(), 'site-one'));
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'tok'), ''));
        $GLOBALS['HAXCMS'] = new ReportHelpersFakeHaxcms();
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'wrong'), 'site-one'));
        $this->assertTrue(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'tok'), 'site-one'));
        // value must be composed from the active user + site name
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'tok'), 'other-site'));
    }

    public function testNormalizeActiveIdVariants()
    {
        $this->assertNull(HAXCMSReportHelpers::normalizeActiveId(array()));
        $this->assertNull(HAXCMSReportHelpers::normalizeActiveId(array('activeId' => null)));
        $this->assertNull(HAXCMSReportHelpers::normalizeActiveId(array('activeId' => '')));
        $this->assertNull(HAXCMSReportHelpers::normalizeActiveId(array('activeId' => 'null')));
        $this->assertSame('id-1', HAXCMSReportHelpers::normalizeActiveId(array('activeId' => ' id-1 ')));
    }

    public function testBuildSummaryDataEmptySite()
    {
        $manifest = new stdClass();
        $manifest->items = array();
        $manifest->metadata = (object) array(
            'site' => (object) array('created' => 1000000, 'updated' => 2000000),
        );
        $manifest->title = 'My Site';
        $site = $this->makeSite(array(), $manifest);
        $data = HAXCMSReportHelpers::buildSummaryData($site);
        $this->assertSame(0, $data['pages']);
        $this->assertSame(0, $data['objectives']);
        $this->assertSame(0, $data['video']);
        $this->assertSame(0, $data['readTime']);
        $this->assertSame('My Site', $data['title']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1000000), $data['created']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 2000000), $data['updated']);
        $this->assertSame(array(), $data['updatedItems']);
    }

    public function testBuildSummaryDataCountsAllMetrics()
    {
        $content = <<<HTML
<audio src="files/a.mp3"></audio>
<audio-player source="files/b.mp3"></audio-player>
<self-check></self-check>
<multiple-choice></multiple-choice>
<iframe class="entity_iframe" src="https://example.com/x"></iframe>
<iframe class="elmsmedia_h5p_content" src="https://example.com/h5p/embed"></iframe>
<iframe src="https://youtube.com/watch?v=1"></iframe>
<iframe src="https://vimeo.com/2"></iframe>
<instruction-card type="objectives"><ul><li>One</li><li>Two</li></ul></instruction-card>
<page-flag>note</page-flag>
<media-image></media-image>
<img src="files/rich.jpg" alt="Sunset over hills">
<simple-img src="files/b.jpg" alt="b.jpg">
<h1>Title</h1><h2>Sub</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6><relative-heading></relative-heading>
<table><tr><td>1</td></tr></table>
<my-custom-tag>x</my-custom-tag><another-thing>y</another-thing>
<a href="https://one.example.com">First Link</a>
<a href="http://two.example.com">Second</a>
<a href="https://one.example.com"></a>
<a href="">empty</a>
<place-holder></place-holder>
<site-remote-content></site-remote-content>
<video src="files/v.mp4"></video>
<video><source src="files/v2.mp4"></video>
<video-player track="files/cc.vtt"></video-player>
<a11y-media-player></a11y-media-player>
<p>Some words to count here for the word count metric</p>
HTML;
        $site = $this->makeSite(array($this->item('rich', 'Rich', 'rich', array('pageType' => 'content'))));
        $site->setContent('rich', $content);
        $data = HAXCMSReportHelpers::buildSummaryData($site);
        $this->assertSame(1, $data['pages']);
        $this->assertSame(2, $data['objectives']);
        $this->assertSame(1, $data['authorNotes']);
        $this->assertSame(22, $data['specialTags']);
        $this->assertSame(1, $data['dataTables']);
        $this->assertSame(7, $data['headings']);
        $this->assertSame(6, $data['video']);
        $this->assertSame(1, $data['h5p']);
        $this->assertSame(2, $data['audio']);
        $this->assertSame(3, $data['links']);
        $this->assertSame(1, $data['readTime']);
    }

    public function testBuildSummaryDataActiveItemOverrides()
    {
        $items = array(
            $this->item('root', 'Root Title', 'root', array('created' => 5000, 'updated' => 6000)),
            $this->item('child', 'Child', 'child', array('published' => false), 'root'),
            $this->item('grand', 'Grand', 'grand', array(), 'child'),
        );
        $manifest = new ReportHelpersFakeManifest($items, (object) array(
            'site' => (object) array('created' => 100, 'updated' => 200),
        ), 'Site Title');
        $site = $this->makeSite($items, $manifest);
        $data = HAXCMSReportHelpers::buildSummaryData($site, array('activeId' => 'root'));
        // branch selection: root + grandchild, unpublished child excluded
        $this->assertSame(2, $data['pages']);
        // active item overrides site title/created/updated
        $this->assertSame('Root Title', $data['title']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 5000), $data['created']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 6000), $data['updated']);
        $this->assertSame(array('root', 'grand'), $this->idsOf($data['updatedItems']));
    }

    public function testBuildSummaryDataSkipsUnpublishedWithoutActiveId()
    {
        $items = array(
            $this->item('a', 'A', 'a'),
            $this->item('b', 'B', 'b', array('published' => false)),
        );
        $site = $this->makeSite($items);
        $data = HAXCMSReportHelpers::buildSummaryData($site);
        $this->assertSame(1, $data['pages']);
    }

    public function testBuildLinkDataAggregatesByHref()
    {
        $site = $this->makeSite(array(
            $this->item('p1', 'P1', 'p1'),
            $this->item('p2', 'P2', 'p2'),
        ));
        $site->setContent('p1', '<a href="https://shared.example.com">Shared</a>' .
            '<a href="https://a.example.com">A</a>' .
            '<a href="https://empty.example.com"></a>' .
            '<a href="">skipped</a>' .
            '<a href="/relative">rel</a>');
        $site->setContent('p2', '<a href="https://shared.example.com">Also Shared</a>');
        $data = HAXCMSReportHelpers::buildLinkData($site);
        $this->assertArrayHasKey('https://shared.example.com', $data['linkData']);
        $entries = $data['linkData']['https://shared.example.com'];
        $this->assertCount(2, $entries);
        $this->assertSame('Shared', $entries[0]['linkTitle']);
        $this->assertSame('p1', $entries[0]['itemId']);
        $this->assertSame('Also Shared', $entries[1]['linkTitle']);
        $this->assertSame('p2', $entries[1]['itemId']);
        $this->assertSame('A', $data['linkData']['https://a.example.com'][0]['linkTitle']);
        // empty link text falls back to the href
        $this->assertSame('https://empty.example.com', $data['linkData']['https://empty.example.com'][0]['linkTitle']);
        $this->assertArrayNotHasKey('', $data['linkData']);
        $this->assertArrayNotHasKey('/relative', $data['linkData']);
    }

    public function testBuildContentDataStructure()
    {
        $site = $this->makeSite(array(
            $this->item('c1', 'Content One', 'c1', array('pageType' => 'page', 'created' => 300, 'updated' => 400)),
        ));
        $site->setContent('c1', '<h2>Hi</h2><p>one two three</p>');
        $data = HAXCMSReportHelpers::buildContentData($site);
        $this->assertCount(1, $data['contentData']);
        $record = $data['contentData'][0];
        $this->assertSame('c1', $record['id']);
        $this->assertSame('Content One', $record['title']);
        $this->assertSame('c1', $record['slug']);
        $this->assertSame('pages/c1/index.html', $record['location']);
        $this->assertSame('page', $record['pageType']);
        $this->assertSame(0, $record['images']);
        $this->assertSame(0, $record['dataTables']);
        $this->assertSame(0, $record['videos']);
        // 3 words -> ceil(3/225) = 1
        $this->assertSame(1, $record['readTime']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 300), $record['created']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 400), $record['updated']);
    }

    public function testBuildContentDataEmptyAndMissingContent()
    {
        $site = $this->makeSite(array(
            $this->item('blank', 'Blank', 'blank'),
            $this->item('noload', 'No Load', 'noload'),
            $this->item('badtype', 'Bad Type', 'badtype'),
            $this->bareItem(),
        ));
        // whitespace-only content -> empty analysis
        $site->setContent('blank', "   \n  ");
        // loadNode returns false -> no content
        $site->pageOverrides['noload'] = false;
        // non-string page content -> treated as empty
        $site->setContent('badtype', array('not-a-string'));
        $data = HAXCMSReportHelpers::buildContentData($site);
        $this->assertCount(4, $data['contentData']);
        foreach ($data['contentData'] as $record) {
            $this->assertSame(0, $record['videos']);
            $this->assertSame(0, $record['audio']);
            $this->assertSame(0, $record['images']);
            $this->assertSame(0, $record['readTime']);
        }
        // item with no id produces an empty id field
        $this->assertSame('', $data['contentData'][3]['id']);
    }

    public function testBuildContentDataWithoutSiteMethods()
    {
        // plain stdClass site has no loadNode/getPageContent methods
        $manifest = new stdClass();
        $manifest->items = array($this->item('x', 'X', 'x'));
        $site = new stdClass();
        $site->manifest = $manifest;
        $data = HAXCMSReportHelpers::buildContentData($site);
        $this->assertCount(1, $data['contentData']);
        $this->assertSame('x', $data['contentData'][0]['id']);
        $this->assertSame(0, $data['contentData'][0]['h5p']);
        // manifest without an items array yields nothing
        $site2 = new stdClass();
        $site2->manifest = new stdClass();
        $this->assertSame(array('contentData' => array()), HAXCMSReportHelpers::buildContentData($site2));
        // manifest with an orderTree method gets asked for the ordered items
        $ordered = new ReportHelpersOrderingManifest(array(
            $this->item('o1', 'O1', 'o1'),
            $this->item('o2', 'O2', 'o2'),
        ));
        $site3 = $this->makeSite($ordered->items, $ordered);
        $site3->setContent('o1', '<p>first</p>');
        $data3 = HAXCMSReportHelpers::buildContentData($site3);
        $this->assertCount(2, $data3['contentData']);
        $this->assertSame('o1', $data3['contentData'][0]['id']);
    }

    public function testBuildMediaDataRecordsAndStatuses()
    {
        $content = <<<HTML
<media-image></media-image>
<img src="null-alt.jpg" alt="null">
<img src="files/same-name.jpg" alt="same-name.jpg">
<img src="files/same-src.jpg" alt="files/same-src.jpg">
<img src="files/t-eq.jpg" alt="Same" title="Same">
<img src="files/empty-alt.jpg" alt="">
<img src="files/pic.jpg" alt="A picture of things">
<img src="files/ok.jpg" alt="Sunset over hills">
<span alt="Span alt"><img src="files/parent-alt.jpg"></span>
<span title="PT"><img src="files/parent-title.jpg" alt="Alt PT"></span>
<span media-title="PMT"><img src="files/parent-mt.jpg" alt="Alt PMT"></span>
<img src="files/mt.jpg" media-title="MT" alt="Alt MT">
<img src="///" alt="Sunset over hills">
<video-player src="files/v-none.mp4"></video-player>
<video-player src="files/v-track.mp4" track="files/cc.vtt"></video-player>
<video-player src="files/v-json.mp4" tracks='[{"src":"files/cc.json.vtt"}]'></video-player>
<video-player src="files/v-json-nosrc.mp4" tracks='[{"kind":"cc"}]'></video-player>
<video-player src="files/v-empty.mp4" tracks="[]"></video-player>
<video-player src="files/v-obj.mp4" tracks="{}"></video-player>
<video-player src="files/v-garbage.mp4" tracks="garbage"></video-player>
<video-player src="files/v-null.mp4" tracks="null"></video-player>
<video-player src="files/v-nulltrack.mp4" track="null"></video-player>
<video-player src="files/v-objsrc.mp4" tracks='{"src":"files/cc.obj.vtt"}'></video-player>
<video-player src="files/v-list.mp4" tracks='["files/cc.list.vtt"]'></video-player>
<video-player src="files/v-child.mp4"><track src="cc.vtt"></video-player>
<video src="files/plain.mp4"></video>
<embed src="https://youtube.com/embed/x"></embed>
<object class="elmsmedia_h5p_content"></object>
<object></object>
<meme-maker></meme-maker>
HTML;
        $site = $this->makeSite(array($this->item('media', 'Media', 'media')));
        $site->setContent('media', $content);
        $data = HAXCMSReportHelpers::buildMediaData($site);
        $bySource = array();
        foreach ($data['mediaData'] as $record) {
            $bySource[$record['source']] = $record;
            $this->assertSame('media', $record['itemId']);
        }
        // images: alt/title handling and mediaStatus variants
        $this->assertSame('error', $this->findRecord($data['mediaData'], 'unknown', 'image')['status']);
        // elmsmedia h5p object surfaces as an unknown-source h5p record
        $this->assertSame('h5p', $this->findRecord($data['mediaData'], 'unknown', 'h5p')['type']);
        $this->assertSame('other', $this->findRecord($data['mediaData'], 'unknown', 'other')['type']);
        $this->assertSame('error', $bySource['null-alt.jpg']['status']);
        $this->assertSame('error', $bySource['files/same-name.jpg']['status']);
        $this->assertSame('error', $bySource['files/same-src.jpg']['status']);
        $this->assertSame('error', $bySource['files/t-eq.jpg']['status']);
        $this->assertSame('warning', $bySource['files/empty-alt.jpg']['status']);
        $this->assertSame('warning', $bySource['files/pic.jpg']['status']);
        $this->assertSame('info', $bySource['files/ok.jpg']['status']);
        // alt inherited from the parent element
        $this->assertSame('Span alt', $bySource['files/parent-alt.jpg']['alt']);
        $this->assertSame('info', $bySource['files/parent-alt.jpg']['status']);
        // title inherited from the parent element
        $this->assertSame('PT', $bySource['files/parent-title.jpg']['title']);
        // media-title inherited from the parent element
        $this->assertSame('PMT', $bySource['files/parent-mt.jpg']['title']);
        // media-title attribute wins over nothing
        $this->assertSame('MT', $bySource['files/mt.jpg']['title']);
        // unresolvable name falls back to 'unknown'
        $this->assertSame('unknown', $bySource['///']['name']);
        // video-player without any transcript -> warning
        $this->assertSame('warning', $bySource['files/v-none.mp4']['status']);
        $this->assertSame('info', $bySource['files/v-track.mp4']['status']);
        $this->assertSame('info', $bySource['files/v-json.mp4']['status']);
        $this->assertSame('warning', $bySource['files/v-json-nosrc.mp4']['status']);
        $this->assertSame('warning', $bySource['files/v-empty.mp4']['status']);
        $this->assertSame('warning', $bySource['files/v-obj.mp4']['status']);
        $this->assertSame('info', $bySource['files/v-garbage.mp4']['status']);
        $this->assertSame('warning', $bySource['files/v-null.mp4']['status']);
        $this->assertSame('warning', $bySource['files/v-nulltrack.mp4']['status']);
        // tracks as a json object with src + tracks as a list of strings
        $this->assertSame('info', $bySource['files/v-objsrc.mp4']['status']);
        $this->assertSame('info', $bySource['files/v-list.mp4']['status']);
        // child <track> node counts as a transcript
        $this->assertSame('info', $bySource['files/v-child.mp4']['status']);
        // plain video elements always count as having transcripts
        $this->assertSame('info', $bySource['files/plain.mp4']['status']);
        // type detection across elements
        $this->assertSame('video', $bySource['https://youtube.com/embed/x']['type']);
        $this->assertSame('video', $bySource['files/plain.mp4']['type']);
        // empty-source records surface with the 'unknown' source value
        $emptySourceTypes = array();
        foreach ($data['mediaData'] as $record) {
            if ($record['source'] === 'unknown') {
                $emptySourceTypes[] = $record['type'];
            }
        }
        $this->assertContains('image', $emptySourceTypes);
        $this->assertContains('h5p', $emptySourceTypes);
        $this->assertContains('other', $emptySourceTypes);
    }

    public function testBuildMediaDataTypeDetection()
    {
        $content = <<<HTML
<embed src="https://youtube.com/embed/x"></embed>
<object class="elmsmedia_h5p_content"></object>
<object></object>
<iframe src="https://vimeo.com/9"></iframe>
<iframe src="https://plain.example.com/embed"></iframe>
<meme-maker></meme-maker>
<audio src="files/a.mp3"></audio>
<video><source src="files/parent-src.mp4"></video>
HTML;
        $site = $this->makeSite(array($this->item('types', 'Types', 'types')));
        $site->setContent('types', $content);
        $data = HAXCMSReportHelpers::buildMediaData($site);
        $types = array();
        foreach ($data['mediaData'] as $record) {
            $types[$record['source']] = $record['type'];
        }
        $this->assertSame('video', $types['https://youtube.com/embed/x']);
        $this->assertSame('video', $types['https://vimeo.com/9']);
        $this->assertSame('other', $types['https://plain.example.com/embed']);
        $this->assertSame('audio', $types['files/a.mp3']);
        // source element inherits its parent video type + src
        $this->assertSame('video', $types['files/parent-src.mp4']);
        // empty-source records surface with the 'unknown' source value
        $emptySourceTypes = array();
        foreach ($data['mediaData'] as $record) {
            if ($record['source'] === 'unknown') {
                $emptySourceTypes[] = $record['type'];
            }
        }
        $this->assertContains('h5p', $emptySourceTypes);
        $this->assertContains('other', $emptySourceTypes);
    }

    public function testBuildMediaDataResolvesSourceClassification()
    {
        $content = '<img src="files/rel.jpg" alt="Sunset over hills">' .
            '<img src="https://cdn.example.com/a/b.mp3" alt="Sunset over hills">' .
            '<img src="http://x" alt="Sunset over hills">' .
            '<audio-player source="files/from-source.mp3"></audio-player>';
        $site = $this->makeSite(array($this->item('src', 'Src', 'src')));
        $site->setContent('src', $content);
        $data = HAXCMSReportHelpers::buildMediaData($site);
        $bySource = array();
        foreach ($data['mediaData'] as $record) {
            $bySource[$record['source']] = $record;
        }
        // relative sources stay internal and unresolved (siteLocation is empty)
        $this->assertArrayHasKey('files/rel.jpg', $bySource);
        $this->assertSame('internal', $bySource['files/rel.jpg']['locType']);
        $this->assertSame('rel.jpg', $bySource['files/rel.jpg']['name']);
        // absolute sources stay external with basename name
        $this->assertSame('external', $bySource['https://cdn.example.com/a/b.mp3']['locType']);
        $this->assertSame('b.mp3', $bySource['https://cdn.example.com/a/b.mp3']['name']);
        // no path component -> name falls back to the whole source
        $this->assertSame('x', $bySource['http://x']['name']);
        // source attribute preferred over src; name is the basename
        $this->assertSame('from-source.mp3', $bySource['files/from-source.mp3']['name']);
        $this->assertSame('internal', $bySource['files/from-source.mp3']['locType']);
    }

    public function testGetSelectedItemsWithActiveIdWalksBranch()
    {
        $items = array(
            $this->item('root', 'Root', 'root'),
            $this->item('child', 'Child', 'child', array(), 'root'),
            $this->item('grand', 'Grand', 'grand', array(), 'child'),
            // orphan: parent not present in the map
            $this->item('orphan', 'Orphan', 'orphan', array(), 'missing-parent'),
            // cycle: a <-> b walks the guard to 5000 then stops
            $this->item('cyc-a', 'CycA', 'cyc-a', array(), 'cyc-b'),
            $this->item('cyc-b', 'CycB', 'cyc-b', array(), 'cyc-a'),
            // id-less and parent-less items exercise the map guards
            $this->bareItem(),
            $this->parentlessItem(),
        );
        $site = $this->makeSite($items);
        $selected = null;
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'getSelectedItems');
        $ref->setAccessible(true);
        $selected = $ref->invoke(null, $site, 'root');
        $this->assertSame(array('root', 'child', 'grand'), $this->idsOf($selected));
        // deep activeId: only the node itself (ancestors are not members)
        $selected = $ref->invoke(null, $site, 'grand');
        $this->assertSame(array('grand'), $this->idsOf($selected));
        // non-matching active id: only orphans/cycles excluded, nothing matches
        $selected = $ref->invoke(null, $site, 'zzz');
        $this->assertSame(array(), $this->idsOf($selected));
        // unpublished nodes are excluded even inside the active branch
        $items2 = array(
            $this->item('r2', 'R2', 'r2'),
            $this->item('k2', 'K2', 'k2', array('published' => false), 'r2'),
        );
        $site2 = $this->makeSite($items2);
        $selected = $ref->invoke(null, $site2, 'r2');
        $this->assertSame(array('r2'), $this->idsOf($selected));
    }

    public function testGetUpdatedItemsSortsAndSlices()
    {
        $items = array();
        for ($i = 1; $i <= 8; $i++) {
            $items[] = $this->item('u' . $i, 'U' . $i, 'u' . $i, array('updated' => $i * 1000));
        }
        // equal timestamps exercise the comparator's 0 branch
        $items[] = $this->item('u8-tie', 'U8 Tie', 'u8-tie', array('updated' => 8000));
        // negative timestamps clamp to epoch
        $items[] = $this->item('neg', 'Neg', 'neg', array('updated' => -5));
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'getUpdatedItems');
        $ref->setAccessible(true);
        $updated = $ref->invoke(null, $items);
        $this->assertCount(6, $updated);
        // newest first; the tie sorts stably right after u8
        $this->assertSame(array('u8', 'u8-tie', 'u7', 'u6', 'u5', 'u4'), $this->idsOf($updated));
        foreach ($updated as $record) {
            $this->assertArrayHasKey('title', $record);
            $this->assertArrayHasKey('slug', $record);
            $ts = strtotime($record['metadata']['updated']);
            $this->assertGreaterThan(0, $ts);
        }
        // negative -> epoch
        $neg = $ref->invoke(null, array($this->item('neg2', 'N', 'neg2', array('updated' => -9))));
        $this->assertSame('1970-01-01T00:00:00Z', $neg[0]['metadata']['updated']);
        $this->assertSame(array(), $ref->invoke(null, array()));
    }

    public function testGetItemMetaValueObjectArrayAndMissing()
    {
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'getItemMetaValue');
        $ref->setAccessible(true);
        $obj = $this->item('a', 'A', 'a', array('pageType' => 'portfolio', 'published' => false));
        $this->assertSame('portfolio', $ref->invoke(null, $obj, 'pageType', ''));
        $this->assertFalse($ref->invoke(null, $obj, 'published', null));
        // array metadata
        $arr = new stdClass();
        $arr->id = 'b';
        $arr->metadata = array('pageType' => 'essay');
        $this->assertSame('essay', $ref->invoke(null, $arr, 'pageType', ''));
        // missing metadata -> default
        $none = new stdClass();
        $none->id = 'c';
        $this->assertSame('fallback', $ref->invoke(null, $none, 'pageType', 'fallback'));
        // metadata present but key missing -> default
        $this->assertSame('fallback', $ref->invoke(null, $obj, 'nope', 'fallback'));
    }

    public function testGetPageContentFailureModes()
    {
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'getPageContent');
        $ref->setAccessible(true);
        $site = $this->makeSite(array());
        // item without an id
        $this->assertSame('', $ref->invoke(null, $site, $this->bareItem()));
        // site without loadNode/getPageContent methods
        $plain = new stdClass();
        $this->assertSame('', $ref->invoke(null, $plain, $this->item('x', 'X', 'x')));
        // loadNode returns null
        $this->assertSame('', $ref->invoke(null, $site, $this->item('ghost', 'G', 'ghost')));
        // non-string content
        $site->setContent('bad', 42);
        $this->assertSame('', $ref->invoke(null, $site, $this->item('bad', 'B', 'bad')));
        // valid passthrough
        $site->setContent('good', '<p>ok</p>');
        $this->assertSame('<p>ok</p>', $ref->invoke(null, $site, $this->item('good', 'G', 'good')));
    }

    public function testResolveLocalSourceViaReflection()
    {
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'resolveLocalSource');
        $ref->setAccessible(true);
        $this->assertSame('', $ref->invoke(null, '', 'http://localhost'));
        $this->assertSame('a.jpg', $ref->invoke(null, 'a.jpg', ''));
        $this->assertSame('a.jpg', $ref->invoke(null, 'a.jpg', 'not a url'));
        $this->assertSame('a.jpg', $ref->invoke(null, 'a.jpg', 'mailto:user@host'));
        $this->assertSame('http://localhost/a.jpg', $ref->invoke(null, 'a.jpg', 'http://localhost'));
        $this->assertSame('http://localhost:8181/a.jpg', $ref->invoke(null, 'a.jpg', 'http://localhost:8181'));
        $this->assertSame(
            'http://localhost:8181/sites/mysite/a.jpg',
            $ref->invoke(null, 'a.jpg', 'http://localhost:8181/sites/mysite/')
        );
        $this->assertSame('http://localhost/files/a.jpg', $ref->invoke(null, '/files/a.jpg', 'http://localhost'));
        // parse_url failures return the source untouched
        $this->assertSame('a.jpg', $ref->invoke(null, 'a.jpg', 'http://'));
    }

    public function testResolveMediaSourceViaReflection()
    {
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'resolveMediaSource');
        $ref->setAccessible(true);
        $empty = $ref->invoke(null, '', '');
        $this->assertSame('unknown', $empty['source']);
        $this->assertSame('unknown', $empty['name']);
        $this->assertSame('external', $empty['locType']);
        $internal = $ref->invoke(null, 'files/a.jpg', '');
        $this->assertSame('internal', $internal['locType']);
        $this->assertSame('files/a.jpg', $internal['source']);
        $external = $ref->invoke(null, 'https://cdn.example.com/a/b.mp3', '');
        $this->assertSame('external', $external['locType']);
        $this->assertSame('b.mp3', $external['name']);
        $noPath = $ref->invoke(null, 'http://x', '');
        $this->assertSame('x', $noPath['name']);
    }

    public function testToISOTimeClampsNegative()
    {
        $ref = new ReflectionMethod('HAXCMSReportHelpers', 'toISOTime');
        $ref->setAccessible(true);
        $this->assertSame('1970-01-01T00:00:00Z', $ref->invoke(null, -5));
        $this->assertSame('1970-01-01T00:00:00Z', $ref->invoke(null, 'junk'));
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1700000000), $ref->invoke(null, 1700000000));
    }

    private function idsOf($items)
    {
        $ids = array();
        foreach ($items as $item) {
            $ids[] = is_array($item) ? $item['id'] : $item->id;
        }
        return $ids;
    }

    private function findRecord($records, $source, $type)
    {
        foreach ($records as $record) {
            if ($record['source'] === $source && $record['type'] === $type) {
                return $record;
            }
        }
        return null;
    }
}

/**
 * Fake site exposing loadNode + getPageContent with controllable content map.
 */
class ReportHelpersFakeSite
{
    public $manifest;
    public $pageOverrides = array();
    private $content = array();

    public function __construct($manifest = null)
    {
        $this->manifest = $manifest;
    }

    public function setContent($id, $content)
    {
        $this->content[$id] = $content;
    }

    public function loadNode($id)
    {
        if (array_key_exists($id, $this->pageOverrides)) {
            return $this->pageOverrides[$id];
        }
        if (array_key_exists($id, $this->content)) {
            $page = new stdClass();
            $page->id = $id;
            return $page;
        }
        return null;
    }

    public function getPageContent($page)
    {
        return $this->content[$page->id];
    }
}

/**
 * Manifest double supporting getItemById for the activeId summary branch.
 */
class ReportHelpersFakeManifest
{
    public $items = array();
    public $metadata;
    public $title = '';
    private $byId = array();

    public function __construct($items = array(), $metadata = null, $title = '')
    {
        $this->items = $items;
        $this->metadata = $metadata;
        $this->title = $title;
        foreach ($items as $item) {
            if (isset($item->id)) {
                $this->byId[$item->id] = $item;
            }
        }
    }

    public function getItemById($id)
    {
        return isset($this->byId[$id]) ? $this->byId[$id] : null;
    }
}

/**
 * Manifest double exposing an orderTree method for the ordering branch.
 */
class ReportHelpersOrderingManifest
{
    public $items = array();

    public function __construct($items = array())
    {
        $this->items = $items;
    }

    public function orderTree($items)
    {
        return $items;
    }
}

/**
 * Fake harness for validateSiteToken; only accepts 'tok' + 'user:site-one'.
 */
class ReportHelpersFakeHaxcms
{
    public function validateRequestToken($token, $value)
    {
        return $token === 'tok' && $value === 'user:site-one';
    }

    public function getActiveUserName()
    {
        return 'user';
    }
}
