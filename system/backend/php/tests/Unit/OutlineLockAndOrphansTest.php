<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * haxtheweb/issues#3360: what a lock protects and where a deleted page's
 * children go, on both write paths.
 *
 *  - A page that was locked and stays locked keeps its title and content
 *    through a bulk outline save; it can still move, and its slug follows.
 *    A save that locks or unlocks a page applies its other changes to it.
 *    A bulk save may delete a locked page.
 *  - A deleted page's children move up one level, into its place, with
 *    slugs and depths to match: through deleteNode and through a bulk save
 *    that deletes the parent but keeps the children. A deleted page inside
 *    another deleted page hands its children on. A save that deletes a whole
 *    branch (what outline-designer sends) moves nothing.
 *
 * Parity mirror of haxcms-nodejs test/unit/outline-lock-and-orphans.test.cjs:
 * every scenario carries the Node test's name (TestDox).
 */
class OutlineLockAndOrphansTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'lock-orphans-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $this->fixtureDirName = 'fixtures_lock_orphans_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $this->haxcms = new OutlineLockAndOrphansTestHaxcms();
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
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    /** An empty site with pathauto on, loaded as the active site. */
    private function buildSite(): void
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
                    'settings' => (object) array('pathauto' => true),
                ),
                'platform' => (object) array('features' => (object) array()),
            ),
            'items' => array(),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->reloadSite();
    }

    private function reloadSite(): OutlineLockAndOrphansTestSite
    {
        $site = new OutlineLockAndOrphansTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        return $site;
    }

    /** Unit (0) > Lesson (0) > Activity (0); Unit > Quiz (1); Next unit (1) */
    private function buildCourse(): void
    {
        $this->buildSite();
        $result = $this->save(array(
            $this->newPage('u', 'Unit', array('contents' => '<p>u</p>')),
            $this->newPage('l', 'Lesson', array('parent' => 'u', 'indent' => 1, 'contents' => '<p>l</p>')),
            $this->newPage('a', 'Activity', array('parent' => 'l', 'indent' => 2, 'contents' => '<p>a</p>')),
            $this->newPage('q', 'Quiz', array('parent' => 'u', 'indent' => 1, 'order' => 1, 'contents' => '<p>q</p>')),
            $this->newPage('n', 'Next unit', array('order' => 1, 'contents' => '<p>n</p>')),
        ));
        $this->assertArrayHasKey('items', $result, json_encode($result));
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

    private function pageContent($item): string
    {
        return file_get_contents($this->siteDir() . '/' . $item->location);
    }

    private function save(array $items): array
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array('items' => json_decode(json_encode($items)));
        $this->reloadSite();
        return $this->ops->saveOutline();
    }

    private function del(string $id): array
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => $id),
        );
        $this->reloadSite();
        return $this->ops->deleteNode();
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

    /** What outline-designer sends for a page: everything site.json has for it. */
    private function sent($item, array $extra = array()): array
    {
        return array_merge(array(
            'id' => $item->id,
            'title' => $item->title,
            'parent' => $item->parent,
            'indent' => $item->indent,
            'order' => $item->order,
            'slug' => $item->slug,
            'metadata' => isset($item->metadata) ? (array) $item->metadata : array(),
        ), $extra);
    }

    /** Every page as [title, parent title, order, indent, slug], by title. */
    private function shape(): array
    {
        $items = $this->readItems();
        $titleOf = array();
        foreach ($items as $item) {
            $titleOf[$item->id] = $item->title;
        }
        $rows = array();
        foreach ($items as $item) {
            $rows[] = array(
                $item->title,
                $item->parent ? (isset($titleOf[$item->parent]) ? $titleOf[$item->parent] : $item->parent) : null,
                (int) $item->order,
                (int) $item->indent,
                $item->slug,
            );
        }
        usort($rows, function ($x, $y) {
            return strcmp($x[0], $y[0]);
        });
        return $rows;
    }

    private function saveAll(callable $map): array
    {
        $out = array();
        foreach ($this->readItems() as $item) {
            $out[] = $map($item);
        }
        return $this->save($out);
    }

    private function lockPage(string $title)
    {
        $target = $this->byTitle($title);
        $result = $this->saveAll(function ($i) use ($target) {
            if ($i->id === $target->id) {
                $meta = isset($i->metadata) ? (array) $i->metadata : array();
                $meta['locked'] = true;
                return $this->sent($i, array('metadata' => $meta));
            }
            return $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        return $this->byTitle($title);
    }

    // =========================================================================
    // adoptChildren
    // =========================================================================

    #[TestDox("adoptChildren: children take the deleted page's place under its parent")]
    public function testAdoptChildrenTakesTheDeletedPagesPlace(): void
    {
        $items = array(
            (object) array('id' => 'u', 'parent' => null, 'order' => 0),
            (object) array('id' => 'a', 'parent' => 'l', 'order' => 1),
            (object) array('id' => 'b', 'parent' => 'l', 'order' => 0),
            (object) array('id' => 'q', 'parent' => 'u', 'order' => 1),
            (object) array('id' => 'r', 'parent' => 'u', 'order' => 2),
        );
        $moved = OutlineUpkeep::adoptChildren($items, (object) array('id' => 'l', 'parent' => 'u', 'order' => 0));
        $this->assertSame(array('b', 'a'), array_map(function ($i) {
            return $i->id;
        }, $moved));
        $this->assertSame('u', $items[2]->parent);
        $this->assertSame(0, $items[2]->order);
        $this->assertSame(1, $items[1]->order);
        $this->assertSame(2, $items[3]->order);
        $this->assertSame(3, $items[4]->order);
        $this->assertSame(0, $items[0]->order);
    }

    #[TestDox('adoptChildren: a page without children moves nothing')]
    public function testAdoptChildrenWithoutChildrenMovesNothing(): void
    {
        $items = array((object) array('id' => 'u', 'parent' => null, 'order' => 0));
        $this->assertSame(array(), OutlineUpkeep::adoptChildren($items, (object) array('id' => 'x', 'parent' => null, 'order' => 0)));
        $this->assertSame(array(), OutlineUpkeep::adoptChildren($items, null));
        $this->assertSame(0, $items[0]->order);
    }

    // =========================================================================
    // single delete
    // =========================================================================

    #[TestDox("delete: a deleted page's children move up one level into its place")]
    public function testDeleteMovesChildrenUpOneLevel(): void
    {
        $this->buildCourse();
        $result = $this->del($this->byTitle('Lesson')->id);
        $this->assertSame(200, $result['status'], json_encode($result));
        $this->assertSame(array(
            array('Activity', 'Unit', 0, 1, 'unit/activity'),
            array('Next unit', null, 1, 0, 'next-unit'),
            array('Quiz', 'Unit', 1, 1, 'unit/quiz'),
            array('Unit', null, 0, 0, 'unit'),
        ), $this->shape());
    }

    #[TestDox("delete: a top-level page's children move to the top level in its place")]
    public function testDeleteTopLevelMovesChildrenToTheTopLevel(): void
    {
        $this->buildCourse();
        $result = $this->del($this->byTitle('Unit')->id);
        $this->assertSame(200, $result['status'], json_encode($result));
        $this->assertSame(array(
            array('Activity', 'Lesson', 0, 1, 'lesson/activity'),
            array('Lesson', null, 0, 0, 'lesson'),
            array('Next unit', null, 2, 0, 'next-unit'),
            array('Quiz', null, 1, 0, 'quiz'),
        ), $this->shape());
    }

    // =========================================================================
    // bulk save: deletes
    // =========================================================================

    #[TestDox("bulk delete: children the save keeps move up one level into the parent's place")]
    public function testBulkDeleteMovesKeptChildrenUp(): void
    {
        $this->buildCourse();
        $lesson = $this->byTitle('Lesson');
        $result = $this->saveAll(function ($i) use ($lesson) {
            return $i->id === $lesson->id ? $this->sent($i, array('delete' => true)) : $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame(array(
            array('Activity', 'Unit', 0, 1, 'unit/activity'),
            array('Next unit', null, 1, 0, 'next-unit'),
            array('Quiz', 'Unit', 1, 1, 'unit/quiz'),
            array('Unit', null, 0, 0, 'unit'),
        ), $this->shape());
    }

    #[TestDox('bulk delete: a deleted page inside a deleted page hands its children on')]
    public function testBulkDeleteNestedHandsChildrenOn(): void
    {
        $this->buildCourse();
        $gone = array($this->byTitle('Unit')->id => true, $this->byTitle('Lesson')->id => true);
        $result = $this->saveAll(function ($i) use ($gone) {
            return isset($gone[$i->id]) ? $this->sent($i, array('delete' => true)) : $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame(array(
            array('Activity', null, 0, 0, 'activity'),
            array('Next unit', null, 2, 0, 'next-unit'),
            array('Quiz', null, 1, 0, 'quiz'),
        ), $this->shape());
    }

    #[TestDox('bulk delete: deleting a whole branch, as outline-designer does, moves nothing')]
    public function testBulkDeleteWholeBranchMovesNothing(): void
    {
        $this->buildCourse();
        $gone = array($this->byTitle('Lesson')->id => true, $this->byTitle('Activity')->id => true);
        $result = $this->saveAll(function ($i) use ($gone) {
            return isset($gone[$i->id]) ? $this->sent($i, array('delete' => true)) : $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame(array(
            array('Next unit', null, 1, 0, 'next-unit'),
            array('Quiz', 'Unit', 1, 1, 'unit/quiz'),
            array('Unit', null, 0, 0, 'unit'),
        ), $this->shape());
    }

    #[TestDox('bulk delete: a locked page can be deleted')]
    public function testBulkDeleteCanDeleteALockedPage(): void
    {
        $this->buildCourse();
        $next = $this->byTitle('Next unit');
        $result = $this->saveAll(function ($i) use ($next) {
            return $i->id === $next->id
                ? $this->sent($i, array('delete' => true, 'metadata' => array('locked' => true)))
                : $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertNull($this->byTitle('Next unit'));
    }

    // =========================================================================
    // bulk save: locked pages
    // =========================================================================

    #[TestDox("lock: a bulk save keeps a locked page's title and content and applies the rest")]
    public function testLockKeepsTitleAndContent(): void
    {
        $this->buildCourse();
        $quiz = $this->lockPage('Quiz');
        $before = $this->pageContent($quiz);
        $next = $this->byTitle('Next unit');
        $result = $this->saveAll(function ($i) use ($quiz, $next) {
            if ($i->id === $quiz->id) {
                return $this->sent($i, array('title' => 'Renamed quiz', 'contents' => '<p>replaced</p>'));
            }
            if ($i->id === $next->id) {
                return $this->sent($i, array('title' => 'Unit two', 'contents' => '<p>new</p>'));
            }
            return $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $after = $this->byId($quiz->id);
        $this->assertSame('Quiz', $after->title);
        $this->assertTrue($after->metadata->locked);
        $this->assertSame($before, $this->pageContent($after));
        $two = $this->byId($next->id);
        $this->assertSame('Unit two', $two->title, 'the unlocked page still changes');
        $this->assertStringContainsString('new', $this->pageContent($two));
    }

    #[TestDox('lock: a bulk save can move a locked page and its slug follows')]
    public function testLockedPageCanMove(): void
    {
        $this->buildCourse();
        $quiz = $this->lockPage('Quiz');
        $next = $this->byTitle('Next unit');
        $result = $this->saveAll(function ($i) use ($quiz, $next) {
            return $i->id === $quiz->id
                ? $this->sent($i, array('parent' => $next->id, 'order' => 0))
                : $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $after = $this->byId($quiz->id);
        $this->assertSame($next->id, $after->parent);
        $this->assertSame('next-unit/quiz', $after->slug);
        $this->assertSame('Quiz', $after->title);
    }

    #[TestDox('lock: unlocking and renaming in one bulk save renames the page')]
    public function testUnlockAndRename(): void
    {
        $this->buildCourse();
        $quiz = $this->lockPage('Quiz');
        $result = $this->saveAll(function ($i) use ($quiz) {
            if ($i->id === $quiz->id) {
                $meta = (array) $i->metadata;
                $meta['locked'] = false;
                return $this->sent($i, array('title' => 'Final quiz', 'contents' => '<p>edited</p>', 'metadata' => $meta));
            }
            return $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $after = $this->byId($quiz->id);
        $this->assertSame('Final quiz', $after->title);
        $this->assertStringContainsString('edited', $this->pageContent($after));
    }

    #[TestDox('lock: renaming and locking in one bulk save renames the page')]
    public function testLockAndRename(): void
    {
        $this->buildCourse();
        $quiz = $this->byTitle('Quiz');
        $result = $this->saveAll(function ($i) use ($quiz) {
            if ($i->id === $quiz->id) {
                $meta = isset($i->metadata) ? (array) $i->metadata : array();
                $meta['locked'] = true;
                return $this->sent($i, array('title' => 'Final quiz', 'metadata' => $meta));
            }
            return $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $after = $this->byId($quiz->id);
        $this->assertSame('Final quiz', $after->title);
        $this->assertTrue($after->metadata->locked);
    }

    #[TestDox('lock: a save that sends no lock flag keeps what site.json says')]
    public function testNoLockFlagKeepsStoredLock(): void
    {
        $this->buildCourse();
        $quiz = $this->lockPage('Quiz');
        $result = $this->saveAll(function ($i) use ($quiz) {
            $out = $this->sent($i, $i->id === $quiz->id ? array('title' => 'Sneaky') : array());
            unset($out['metadata']);
            return $out;
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame('Quiz', $this->byId($quiz->id)->title);
    }

    #[TestDox('lock: a new page sent as locked gets its title and content')]
    public function testNewLockedPageGetsTitleAndContent(): void
    {
        $this->buildSite();
        $result = $this->save(array(
            $this->newPage('x', 'Locked from the start', array(
                'contents' => '<p>x</p>',
                'metadata' => array('locked' => true),
            )),
        ));
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $made = $this->byTitle('Locked from the start');
        $this->assertNotNull($made);
        $this->assertStringContainsString('<p>x</p>', $this->pageContent($made));
    }

    // =========================================================================
    // bulk save: a page listed before the ancestor the save renames or moves
    // =========================================================================

    /** Every page but $last as sent (through $map), then $last. */
    private function saveWithLast($last, array $lastExtra, ?callable $map = null): array
    {
        $out = array();
        foreach ($this->readItems() as $item) {
            if ($item->id === $last->id) {
                continue;
            }
            $out[] = $map ? $map($item) : $this->sent($item);
        }
        $out[] = $this->sent($last, $lastExtra);
        return $this->save($out);
    }

    #[TestDox("slug order: a page listed before its renamed parent gets the parent's new address")]
    public function testSlugOrderRenamedParentListedLast(): void
    {
        $this->buildCourse();
        $result = $this->saveWithLast($this->byTitle('Unit'), array('title' => 'Unit one'));
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame(array(
            array('Activity', 'Lesson', 0, 2, 'unit-one/lesson/activity'),
            array('Lesson', 'Unit one', 0, 1, 'unit-one/lesson'),
            array('Next unit', null, 1, 0, 'next-unit'),
            array('Quiz', 'Unit one', 1, 1, 'unit-one/quiz'),
            array('Unit one', null, 0, 0, 'unit-one'),
        ), $this->shape());
    }

    #[TestDox('slug order: a page moved under a parent renamed later in the same save')]
    public function testSlugOrderMovedUnderParentRenamedLater(): void
    {
        $this->buildCourse();
        $next = $this->byTitle('Next unit');
        $quiz = $this->byTitle('Quiz');
        $result = $this->saveWithLast($next, array('title' => 'Unit two'), function ($i) use ($quiz, $next) {
            return $i->id === $quiz->id
                ? $this->sent($i, array('parent' => $next->id, 'order' => 0))
                : $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame('unit-two/quiz', $this->byId($quiz->id)->slug);
    }

    #[TestDox('slug order: a custom address is left alone when its parent is renamed later')]
    public function testSlugOrderCustomAddressLeftAlone(): void
    {
        $this->buildCourse();
        $quiz = $this->byTitle('Quiz');
        $result = $this->saveWithLast($this->byTitle('Unit'), array('title' => 'Unit one'), function ($i) use ($quiz) {
            if ($i->id === $quiz->id) {
                $meta = isset($i->metadata) ? (array) $i->metadata : array();
                $meta['overridePathauto'] = true;
                return $this->sent($i, array('slug' => 'my-quiz', 'metadata' => $meta));
            }
            return $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame('my-quiz', $this->byId($quiz->id)->slug);
    }

    #[TestDox('slug order: a parents-first save keeps numbered addresses as they are')]
    public function testSlugOrderParentsFirstKeepsNumberedAddresses(): void
    {
        $this->buildCourse();
        $out = array();
        foreach ($this->readItems() as $item) {
            $out[] = $this->sent($item);
        }
        $out[] = $this->newPage('q2', 'Quiz', array(
            'parent' => $this->byTitle('Unit')->id,
            'indent' => 1,
            'order' => 2,
        ));
        $result = $this->save($out);
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $before = $this->shape();
        $result = $this->saveAll(function ($i) {
            return $this->sent($i);
        });
        $this->assertArrayHasKey('items', $result, json_encode($result));
        $this->assertSame($before, $this->shape());
    }
}

class OutlineLockAndOrphansTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }
}

/** HAXCMSSite double: no git, no feeds or managed files; real manifest and pages. */
class OutlineLockAndOrphansTestSite extends HAXCMSSite
{
    public function gitCommit($msg = 'Committed changes')
    {
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }
}
