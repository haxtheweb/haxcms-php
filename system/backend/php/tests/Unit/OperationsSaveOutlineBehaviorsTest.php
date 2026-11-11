<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * saveOutline: behaviors the mutation run found unpinned.
 *
 * Parity mirror of haxcms-nodejs test/unit/save-outline-behaviors.test.cjs:
 * every scenario carries the Node test's name (TestDox), so the two suites
 * can be compared line for line. The weekly Stryker run on the Node
 * saveOutline write path showed mutants no test noticed: the delete flag
 * check, duplicating into a page that already existed, nested pathauto slugs
 * and slug clash numbering (HAXCMSSite::getUniqueSlugName), and
 * updateNode / deleteNode, which every outline save goes through.
 *
 * Pages are created through saveOutline itself on an empty site, and
 * results are read back from the persisted site.json and the page files.
 */
class OperationsSaveOutlineBehaviorsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'beh-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $this->fixtureDirName = 'fixtures_outline_beh_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $this->haxcms = new SaveOutlineBehaviorsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** An empty site with the given pathauto setting, loaded as the active site. */
    private function buildSite(bool $pathauto = false): void
    {
        $siteDir = $this->siteDir();
        mkdir($siteDir . '/pages', 0777, true);
        $manifest = (object) array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->siteName,
                    'settings' => (object) array('pathauto' => $pathauto),
                ),
                'platform' => (object) array('features' => (object) array()),
            ),
            'items' => array(),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->reloadSite();
    }

    private function reloadSite(): SaveOutlineBehaviorsTestSite
    {
        $site = new SaveOutlineBehaviorsTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        return $site;
    }

    /** Pages A and B at the top level, each with its own content. */
    private function buildTwoPageSite(bool $pathauto = false): void
    {
        $this->buildSite($pathauto);
        $result = $this->save(array(
            $this->newPage('a', 'A', array('contents' => '<p>Content of A</p>')),
            $this->newPage('b', 'B', array('order' => 1, 'contents' => '<p>Content of B</p>')),
        ));
        $this->assertArrayHasKey('items', $result);
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function readItems(): array
    {
        $decoded = json_decode(file_get_contents($this->siteDir() . '/site.json'));
        return isset($decoded->items) ? array_values((array) $decoded->items) : array();
    }

    private function byTitle(string $title)
    {
        foreach ($this->readItems() as $item) {
            if ($item->title === $title) {
                return $item;
            }
        }
        return null;
    }

    private function byId(string $id)
    {
        foreach ($this->readItems() as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }
        return null;
    }

    private function titles(): array
    {
        return array_map(function ($item) {
            return $item->title;
        }, $this->readItems());
    }

    private function save(array $items): array
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array('items' => $items);
        // each request loads the site fresh, like a real request
        $this->reloadSite();
        return $this->ops->saveOutline();
    }

    private function newPage(string $id, string $title, array $extra = array()): array
    {
        return array_merge(array(
            'id' => $id,
            'title' => $title,
            'parent' => null,
            'indent' => 0,
            'order' => 0,
            'metadata' => array(),
            'new' => true,
        ), $extra);
    }

    /** The request an outline designer sends for an unchanged page. */
    private function sent($item, array $extra = array()): array
    {
        return array_merge(array(
            'id' => $item->id,
            'title' => $item->title,
            'parent' => $item->parent,
            'indent' => $item->indent,
            'order' => $item->order,
            'slug' => $item->slug,
            'metadata' => array(),
        ), $extra);
    }

    // =========================================================================
    // delete flag
    // =========================================================================

    #[TestDox('delete: only delete === true removes a page; delete false keeps it')]
    public function testDeleteOnlyTrueRemovesAPage(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $b = $this->byTitle('B');
        $result = $this->save(array(
            $this->sent($a, array('delete' => false)),
            $this->sent($b, array('delete' => 'true')),
        ));
        $this->assertArrayHasKey('items', $result);
        $titles = $this->titles();
        sort($titles);
        $this->assertSame(array('A', 'B'), $titles, 'neither false nor a non-boolean flag deletes');
        $del = $this->save(array($this->sent($a), $this->sent($b, array('delete' => true))));
        $this->assertArrayHasKey('items', $del);
        $this->assertSame(array('A'), $this->titles());
        foreach ($del['items'] as $item) {
            $this->assertNotSame($b->id, $item->id, 'the response lists what site.json lists');
        }
    }

    #[TestDox('delete: deleting a page and its new child in one request rescues nothing')]
    public function testDeletingAPageAndItsNewChildRescuesNothing(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $b = $this->byTitle('B');
        $result = $this->save(array(
            $this->sent($a),
            $this->sent($b, array('delete' => true)),
            $this->newPage('client-child', 'Child', array(
                'parent' => $b->id,
                'indent' => 1,
                'delete' => true,
            )),
        ));
        $this->assertArrayHasKey('items', $result);
        $this->assertSame(
            array('A'),
            $this->titles(),
            'the new child is deleted through its idMap id, not rescued as an orphan'
        );
    }

    // =========================================================================
    // duplicate
    // =========================================================================

    #[TestDox('duplicate: duplicating into an existing page rewrites its content and alternate formats')]
    public function testDuplicatingIntoAnExistingPage(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $b = $this->byTitle('B');
        $result = $this->save(array($this->sent($a), $this->sent($b, array('duplicate' => $a->id))));
        $this->assertArrayHasKey('items', $result);
        $this->assertStringContainsString(
            'Content of A',
            file_get_contents($this->siteDir() . '/' . $b->location)
        );
        // (this suite's site double records the alternate format writes
        // instead of rendering them)
        $this->assertContains(
            $b->id,
            $this->haxcms->loadedSite->alternateFormatsWritten,
            'the alternate formats follow'
        );
        $this->assertStringContainsString(
            'Content of A',
            file_get_contents($this->siteDir() . '/' . $a->location),
            'the source page is untouched'
        );
    }

    #[TestDox('duplicate: a new page can duplicate another new page by its client id')]
    public function testANewPageCanDuplicateAnotherNewPage(): void
    {
        $this->buildSite();
        $result = $this->save(array(
            $this->newPage('src', 'Source', array('contents' => '<p>Source body</p>')),
            $this->newPage('copy', 'Copy', array('order' => 1, 'duplicate' => 'src')),
        ));
        $this->assertArrayHasKey('items', $result);
        $copy = $this->byTitle('Copy');
        $this->assertSame($result['idMap']->copy, $copy->id);
        $this->assertStringContainsString(
            'Source body',
            file_get_contents($this->siteDir() . '/' . $copy->location)
        );
    }

    // =========================================================================
    // slugs (HAXCMSSite::getUniqueSlugName through the route)
    // =========================================================================

    #[TestDox("slugs: pathauto nests a page's slug under its ancestors' slugs")]
    public function testPathautoNestsSlugs(): void
    {
        $this->buildSite(true);
        $result = $this->save(array(
            $this->newPage('p', 'Parent'),
            $this->newPage('c', 'Child', array('parent' => 'p', 'indent' => 1)),
            $this->newPage('g', 'Grand Child', array('parent' => 'c', 'indent' => 2)),
        ));
        $this->assertArrayHasKey('items', $result);
        $this->assertSame('parent', $this->byTitle('Parent')->slug);
        $this->assertSame('parent/child', $this->byTitle('Child')->slug);
        $this->assertSame('parent/child/grand-child', $this->byTitle('Grand Child')->slug);
    }

    #[TestDox("slugs: pathauto off keeps a child's slug flat")]
    public function testPathautoOffKeepsAChildSlugFlat(): void
    {
        $this->buildSite(false);
        $result = $this->save(array(
            $this->newPage('p', 'Parent'),
            $this->newPage('c', 'Child', array('parent' => 'p', 'indent' => 1, 'slug' => 'child')),
            $this->newPage('d', 'Derived', array('parent' => 'p', 'indent' => 1, 'order' => 1)),
        ));
        $this->assertArrayHasKey('items', $result);
        $this->assertSame('child', $this->byTitle('Child')->slug);
        // a page sent without a slug gets one from its title; with pathauto
        // off a new page's derived slug still nests
        $this->assertSame('parent/derived', $this->byTitle('Derived')->slug);
    }

    #[TestDox('slugs: clashing slugs are numbered -1, -2 in request order')]
    public function testClashingSlugsAreNumbered(): void
    {
        $this->buildSite(true);
        $result = $this->save(array(
            $this->newPage('p', 'Parent'),
            $this->newPage('s1', 'Same', array('parent' => 'p', 'indent' => 1, 'order' => 0)),
            $this->newPage('s2', 'Same', array('parent' => 'p', 'indent' => 1, 'order' => 1)),
            $this->newPage('s3', 'Same', array('parent' => 'p', 'indent' => 1, 'order' => 2)),
        ));
        $this->assertArrayHasKey('items', $result);
        $idMap = $result['idMap'];
        $this->assertSame('parent/same', $this->byId($idMap->s1)->slug);
        $this->assertSame('parent/same-1', $this->byId($idMap->s2)->slug);
        $this->assertSame('parent/same-2', $this->byId($idMap->s3)->slug);
        // saving the same outline again keeps every page's own slug
        $again = $this->save(array_map(function ($item) {
            return $this->sent($item);
        }, $this->readItems()));
        $this->assertArrayHasKey('items', $again);
        $this->assertSame('parent/same', $this->byId($idMap->s1)->slug);
        $this->assertSame('parent/same-1', $this->byId($idMap->s2)->slug);
        $this->assertSame('parent/same-2', $this->byId($idMap->s3)->slug);
    }

    #[TestDox('slugs: an ancestor with an empty slug adds no leading slash')]
    public function testAnEmptyAncestorSlugAddsNoLeadingSlash(): void
    {
        $this->buildSite(true);
        $this->assertArrayHasKey('items', $this->save(array($this->newPage('p', 'Parent'))));
        // a legacy site.json can hold a page whose slug is empty
        $site = $this->reloadSite();
        $parent = $site->manifest->items[0];
        $parent->slug = '';
        $site->manifest->save(false);
        $child = $this->save(array(
            $this->newPage('c', 'Child', array('parent' => $parent->id, 'indent' => 1)),
        ));
        $this->assertArrayHasKey('items', $child);
        $this->assertSame('child', $this->byTitle('Child')->slug);
    }

    #[TestDox('slugs: an ancestor with an empty slug adds no leading slash, even when the slug clashes')]
    public function testAnEmptyAncestorSlugAddsNoLeadingSlashOnAClash(): void
    {
        $this->buildSite(true);
        $this->assertArrayHasKey('items', $this->save(array(
            $this->newPage('p', 'Parent'),
            $this->newPage('o', 'Child', array('order' => 1)),
        )));
        $site = $this->reloadSite();
        foreach ($site->manifest->items as $item) {
            if ($item->title === 'Parent') {
                $parent = $item;
            }
        }
        $parent->slug = '';
        $site->manifest->save(false);
        // "child" is taken by the top-level page
        $child = $this->save(array(
            $this->newPage('c', 'Child', array('parent' => $parent->id, 'indent' => 1)),
        ));
        $this->assertArrayHasKey('items', $child);
        $this->assertSame('child-1', $this->byId($child['idMap']->c)->slug);
    }

    #[TestDox("slugs: an empty-slug ancestor between two others adds no '//'")]
    public function testAnEmptyMiddleAncestorSlugAddsNoDoubleSlash(): void
    {
        $this->buildSite(true);
        $this->assertArrayHasKey('items', $this->save(array(
            $this->newPage('g', 'Grand'),
            $this->newPage('p', 'Parent', array('parent' => 'g', 'indent' => 1)),
        )));
        $site = $this->reloadSite();
        foreach ($site->manifest->items as $item) {
            if ($item->title === 'Parent') {
                $parent = $item;
            }
        }
        $parent->slug = '';
        $site->manifest->save(false);
        $child = $this->save(array(
            $this->newPage('c', 'Child', array('parent' => $parent->id, 'indent' => 2)),
        ));
        $this->assertArrayHasKey('items', $child);
        $this->assertSame('grand/child', $this->byId($child['idMap']->c)->slug);
    }

    // =========================================================================
    // the site methods every outline save goes through
    // =========================================================================

    #[TestDox("site methods: updateNode writes site.json in the manifest's order by default")]
    public function testUpdateNodePersistsWithoutReordering(): void
    {
        $this->buildTwoPageSite();
        $site = $this->reloadSite();
        // the manifest lists B (order 1) before A (order 0)
        $site->manifest->items = array_reverse($site->manifest->items);
        $b = $site->manifest->items[0];
        $b->title = 'B renamed';
        $this->assertSame($b, $site->updateNode($b), 'returns the page it stored');
        $this->assertSame(array('B renamed', 'A'), $this->titles(), 'persisted without re-sorting the outline');
    }

    #[TestDox('site methods: updateNode with persist false changes memory only')]
    public function testUpdateNodeWithoutPersistChangesMemoryOnly(): void
    {
        $this->buildTwoPageSite();
        $site = $this->reloadSite();
        foreach ($site->manifest->items as $item) {
            if ($item->title === 'A') {
                $a = $item;
            }
        }
        $a->title = 'A in memory';
        $this->assertSame($a, $site->updateNode($a, false));
        $this->assertSame('A in memory', $site->loadNode($a->id)->title);
        $this->assertSame($a->id, $this->byTitle('A')->id, 'site.json is not written');
    }

    #[TestDox('site methods: updateNode and deleteNode answer false for a page the site does not have')]
    public function testUnknownNodeAnswersFalse(): void
    {
        $this->buildTwoPageSite();
        $site = $this->reloadSite();
        $before = file_get_contents($this->siteDir() . '/site.json');
        $stranger = clone $site->manifest->items[0];
        $stranger->id = 'not-in-this-site';
        $this->assertFalse($site->updateNode($stranger));
        $this->assertFalse($site->deleteNode($stranger));
        $this->assertCount(2, $site->manifest->items);
        $this->assertSame($before, file_get_contents($this->siteDir() . '/site.json'), 'site.json untouched');
    }

    #[TestDox('site methods: deleteNode removes the page from site.json and answers true')]
    public function testDeleteNodeRemovesThePage(): void
    {
        $this->buildTwoPageSite();
        $this->assertArrayHasKey('items', $this->save(array(
            $this->sent($this->byTitle('A')),
            $this->sent($this->byTitle('B')),
            $this->newPage('c', 'C', array('order' => 2)),
        )));
        $site = $this->reloadSite();
        // the manifest lists C (order 2), B, A (order 0)
        $site->manifest->items = array_reverse($site->manifest->items);
        foreach ($site->manifest->items as $item) {
            if ($item->title === 'B') {
                $b = $item;
            }
        }
        $this->assertTrue($site->deleteNode($b));
        $this->assertSame(array('C', 'A'), $this->titles(), 'persisted without re-sorting the outline');
    }

    // =========================================================================
    // page file references (metadata.files)
    // =========================================================================

    #[TestDox('files: a page saved without contents keeps the file references its content has')]
    public function testAPageSavedWithoutContentsKeepsItsFileReferences(): void
    {
        $this->buildSite();
        mkdir($this->siteDir() . '/files', 0777, true);
        file_put_contents($this->siteDir() . '/files/photo.jpg', 'jpg');
        $this->assertArrayHasKey('items', $this->save(array(
            $this->newPage('a', 'A', array('contents' => '<p><img src="files/photo.jpg"></p>')),
            $this->newPage('b', 'B', array('order' => 1, 'contents' => '<p>no files</p>')),
        )));
        $a = $this->byTitle('A');
        $this->assertCount(1, $a->metadata->files, 'the reference is tracked');
        $uuid = $a->metadata->files[0];
        // a reorder sends no contents: the references are read from the page file
        $this->assertArrayHasKey('items', $this->save(array(
            $this->sent($this->byTitle('B'), array('order' => 0)),
            $this->sent($a, array('order' => 1)),
        )));
        $this->assertSame(array($uuid), $this->byTitle('A')->metadata->files);
        $this->assertSame(array(), $this->byTitle('B')->metadata->files);
        // new contents replace the set
        $this->assertArrayHasKey('items', $this->save(array(
            $this->sent($this->byTitle('A'), array('contents' => '<p>image removed</p>')),
        )));
        $this->assertSame(array(), $this->byTitle('A')->metadata->files);
    }

    #[TestDox('alternate formats: a new page sent without contents still gets its sidecar files')]
    public function testANewPageWithoutContentsGetsItsSidecars(): void
    {
        $this->buildSite();
        $result = $this->save(array($this->newPage('a', 'A')));
        $this->assertArrayHasKey('items', $result);
        // (this suite's site double records the alternate format writes
        // instead of rendering them)
        $this->assertContains(
            $this->byTitle('A')->id,
            $this->haxcms->loadedSite->alternateFormatsWritten,
            'the sidecar files are written'
        );
    }

    #[TestDox("alternate formats: a save that sends no contents leaves an existing page's sidecar files alone")]
    public function testASaveWithoutContentsLeavesExistingSidecarsAlone(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $result = $this->save(array(
            $this->sent($a, array('title' => 'A renamed')),
            $this->sent($this->byTitle('B')),
        ));
        $this->assertArrayHasKey('items', $result);
        $this->assertSame(array(), $this->haxcms->loadedSite->alternateFormatsWritten);
    }

    // =========================================================================
    // contents
    // =========================================================================

    #[TestDox('contents: null or empty contents leave the page as it is')]
    public function testNullOrEmptyContentsLeaveThePageAlone(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $b = $this->byTitle('B');
        $result = $this->save(array(
            $this->sent($a, array('contents' => null)),
            $this->sent($b, array('contents' => '')),
        ));
        $this->assertArrayHasKey('items', $result);
        $this->assertSame('<p>Content of A</p>', file_get_contents($this->siteDir() . '/' . $a->location));
        $this->assertSame('<p>Content of B</p>', file_get_contents($this->siteDir() . '/' . $b->location));
    }

    // =========================================================================
    // failure
    // =========================================================================

    #[TestDox('failure: a crash on the first page writes nothing')]
    public function testACrashOnTheFirstPageWritesNothing(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        // A's page file is gone, so the save re-creates it from the
        // boilerplate, and that copy fails
        unlink($this->siteDir() . '/' . $a->location);
        rmdir(dirname($this->siteDir() . '/' . $a->location));
        $siteJson = file_get_contents($this->siteDir() . '/site.json');
        SaveOutlineBehaviorsTestSite::$failCopy = true;
        $thrown = null;
        try {
            $this->save(array(
                $this->sent($a, array('title' => 'A renamed')),
                $this->sent($this->byTitle('B')),
            ));
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            SaveOutlineBehaviorsTestSite::$failCopy = false;
        }
        $this->assertNotNull($thrown, 'the failure propagates');
        $this->assertSame('copy failed', $thrown->getMessage());
        $this->assertSame($siteJson, file_get_contents($this->siteDir() . '/site.json'), 'site.json untouched');
        $this->assertSame(0, $this->haxcms->loadedSite->feedRebuilds, 'the feeds are not rebuilt');
    }

    #[TestDox("failure: a crash after the structure is saved keeps the new pages' folders")]
    public function testACrashAfterTheStructureIsSavedKeepsNewFolders(): void
    {
        $this->buildTwoPageSite();
        // the delete pass fails after the outline (with the new page) was written
        SaveOutlineBehaviorsTestSite::$failCommitPrefix = 'Page deleted';
        $thrown = null;
        try {
            $this->save(array(
                $this->sent($this->byTitle('A')),
                $this->sent($this->byTitle('B'), array('delete' => true)),
                $this->newPage('n', 'New', array('order' => 2)),
            ));
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            SaveOutlineBehaviorsTestSite::$failCommitPrefix = null;
        }
        $this->assertNotNull($thrown, 'the failure propagates');
        $this->assertSame('commit failed', $thrown->getMessage());
        $created = $this->byTitle('New');
        $this->assertNotNull($created, 'site.json lists the new page');
        $this->assertFileExists($this->siteDir() . '/' . $created->location, 'and its page folder is still there');
    }

    // =========================================================================
    // contents and delete, as the client sees them
    // =========================================================================

    #[TestDox('contents: new contents on an existing page rewrite its sidecar files')]
    public function testNewContentsOnAnExistingPageRewriteItsSidecars(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $result = $this->save(array($this->sent($a, array('contents' => '<p>Rewritten body</p>'))));
        $this->assertArrayHasKey('items', $result);
        // (this suite's site double records the alternate format writes
        // instead of rendering them)
        $this->assertSame(array($a->id), $this->haxcms->loadedSite->alternateFormatsWritten);
        $this->assertSame('<p>Rewritten body</p>', file_get_contents($this->siteDir() . '/' . $a->location));
    }

    #[TestDox('contents: contents that are not HTML are refused with a 400')]
    public function testContentsThatAreNotHtmlAreRefused(): void
    {
        $this->buildTwoPageSite();
        $a = $this->byTitle('A');
        $result = $this->save(array($this->sent($a, array('contents' => 'just text'))));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid page contents', $result['__failed']['message']);
        $this->assertSame('<p>Content of A</p>', file_get_contents($this->siteDir() . '/' . $a->location));
    }

    #[TestDox("delete: a deleted page is committed as 'Page deleted: <title> (<id>)'")]
    public function testADeletedPageIsCommitted(): void
    {
        $this->buildTwoPageSite();
        $b = $this->byTitle('B');
        $result = $this->save(array($this->sent($this->byTitle('A')), $this->sent($b, array('delete' => true))));
        $this->assertArrayHasKey('items', $result);
        $this->assertContains('Page deleted: B (' . $b->id . ')', $this->haxcms->loadedSite->gitCommits);
    }
}

/**
 * OperationsTestHaxcms subclass with the outlineSchema (newItem for new
 * pages) seam saveOutline needs.
 */
class SaveOutlineBehaviorsTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }
}

/**
 * HAXCMSSite double: records git commits and alternate-format writes; real
 * manifest, loadNode, updateNode, addItem, deleteNode, recurseCopy and
 * getPageContent.
 */
class SaveOutlineBehaviorsTestSite extends HAXCMSSite
{
    public $gitCommits = array();
    public $alternateFormatsWritten = array();

    public static $failCommitPrefix = null;

    public function gitCommit($msg = 'Committed changes')
    {
        if (self::$failCommitPrefix !== null && strpos($msg, self::$failCommitPrefix) === 0) {
            throw new RuntimeException('commit failed');
        }
        $this->gitCommits[] = $msg;
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public $feedRebuilds = 0;
    public static $failCopy = false;

    public function updateAlternateFormats($format = null)
    {
        $this->feedRebuilds++;
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        $this->alternateFormatsWritten[] = is_object($page) ? $page->id : strval($page);
        return true;
    }

    public function recurseCopy($src, $dst, $skip = array())
    {
        if (self::$failCopy) {
            throw new RuntimeException('copy failed');
        }
        return parent::recurseCopy($src, $dst, $skip);
    }
}
