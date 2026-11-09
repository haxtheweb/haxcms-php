<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Page structure history (haxtheweb/issues#3191). Mirrors haxcms-nodejs
 * test/unit/revisions-structure-history.test.cjs: outline saves only change
 * site.json, so getNodeRevisions also reports the commits where the page's
 * outline entry changed (move, rename, publish toggle) as kind "structure".
 *
 * Builds a REAL temp git repo whose site.json and page file evolve.
 */
class StructureHistoryTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'structure-site';
    private $siteRoot;
    private $hashes = array();
    private $items = array();

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_rev_root');
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_structure_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildHistory();

        $this->haxcms = new StructureHistoryTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $site = new StructureHistoryTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->haxcms->validRequestToken = true;

        $this->ops = new Operations();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        shell_exec('rm -rf ' . escapeshellarg($this->tmpRoot));
    }

    private function git(array $args): string
    {
        $parts = array_merge(array('git', '--no-pager', '-c', 'user.name=Test Author', '-c', 'user.email=author@example.com'), $args);
        $escaped = array_map('escapeshellarg', $parts);
        return trim((string) shell_exec('cd ' . escapeshellarg($this->siteRoot) . ' && ' . implode(' ', $escaped) . ' 2>&1'));
    }

    private function unit($id, $title, $order)
    {
        return array('id' => $id, 'indent' => 0, 'location' => 'pages/' . $id . '/index.html', 'slug' => strtolower(str_replace(' ', '-', $title)), 'order' => $order, 'parent' => null, 'title' => $title, 'description' => '', 'metadata' => array());
    }

    private function commit($name, $message): void
    {
        $manifest = array(
            'id' => 'site-structure-uuid',
            'title' => 'Structure Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => array(
                'site' => array('name' => $this->siteName, 'settings' => array('pathauto' => false), 'created' => 1, 'updated' => 1),
                'platform' => array('features' => new stdClass()),
            ),
            'items' => array($this->items['u1'], $this->items['u2'], $this->items['p']),
        );
        file_put_contents($this->siteRoot . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->git(array('add', '.'));
        $this->git(array('commit', '--quiet', '--allow-empty', '-m', $message));
        $this->hashes[$name] = $this->git(array('rev-parse', 'HEAD'));
    }

    // same history as the nodejs test, newest last
    private function buildHistory(): void
    {
        mkdir($this->siteRoot . '/pages/item-p', 0777, true);
        $this->git(array('init', '--quiet'));
        $this->items = array(
            'u1' => $this->unit('item-u1', 'Unit 1 Cells', 0),
            'u2' => $this->unit('item-u2', 'Unit 2 Energy', 1),
            'p' => array('id' => 'item-p', 'indent' => 1, 'location' => 'pages/item-p/index.html', 'slug' => 'photosynthesis', 'order' => 2, 'parent' => 'item-u1', 'title' => 'Photosynthesis', 'description' => '', 'metadata' => array('published' => false)),
        );
        $page = $this->siteRoot . '/pages/item-p/index.html';
        file_put_contents($page, "<p>one</p>\n");
        $this->commit('created', '[S:siteCreate] A new journey begins: Demo (x)');
        file_put_contents($page, "<p>two</p>\n");
        $this->commit('edit1', '[H:updateItem:aaaa] Page details updated: Photosynthesis (item-p)');
        $this->items['p']['parent'] = 'item-u2';
        $this->items['p']['order'] = 0;
        $this->commit('moved', '[H:updateSiteOutline:bbbb] Outline updated in bulk');
        $this->items['u2']['title'] = 'Unit 2 Energy and Light';
        $this->commit('parentRenamed', '[H:updateSiteOutline:cccc] Outline updated in bulk');
        $this->items['u1']['title'] = 'Unit 1 Cells and Structure';
        $this->commit('otherRenamed', '[H:updateSiteOutline:dddd] Outline updated in bulk');
        $this->items['p']['title'] = 'Photosynthesis basics';
        $this->items['p']['metadata']['published'] = true;
        $this->commit('renamedPublished', '[A:updateSiteOutline:eeee] Outline updated in bulk');
        file_put_contents($page, "<p>three</p>\n");
        $this->items['p']['slug'] = 'photosynthesis-basics';
        $this->commit('edit2', '[H:updateItem:ffff] Page details updated: Photosynthesis basics (item-p)');
    }

    private function listRevisions($limit = null, $offset = null)
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'item-p'),
        );
        if ($limit !== null) {
            $this->ops->params['limit'] = $limit;
        }
        if ($offset !== null) {
            $this->ops->params['offset'] = $offset;
        }
        $result = $this->ops->getNodeRevisions();
        $this->assertSame(200, $result['status']);
        return $result['data'];
    }

    private function row(array $data, $name)
    {
        foreach ($data['revisions'] as $row) {
            if ($row['hash'] === $this->hashes[$name]) {
                return $row;
            }
        }
        return null;
    }

    public function testListsContentAndStructureCommitsNewestFirst(): void
    {
        $data = $this->listRevisions();
        $this->assertSame(
            array($this->hashes['edit2'], $this->hashes['renamedPublished'], $this->hashes['moved'], $this->hashes['edit1'], $this->hashes['created']),
            array_column($data['revisions'], 'hash')
        );
        $this->assertSame(array('content', 'structure', 'structure', 'content', 'content'), array_column($data['revisions'], 'kind'));
        $this->assertSame(5, $data['total']);
        $this->assertSame(300, $data['structureScanLimit']);
    }

    public function testMoveReportsParentTitleWithIdsAndPosition(): void
    {
        $moved = $this->row($this->listRevisions(), 'moved');
        $this->assertSame(array(
            array('field' => 'parent', 'from' => 'Unit 1 Cells', 'to' => 'Unit 2 Energy', 'fromId' => 'item-u1', 'toId' => 'item-u2'),
            array('field' => 'order', 'from' => '2', 'to' => '0'),
        ), $moved['changes']);
        $this->assertSame('updateSiteOutline', $moved['op']);
        $this->assertSame('H', $moved['actor']);
    }

    public function testRenameAndPublishInOneOutlineSave(): void
    {
        $row = $this->row($this->listRevisions(), 'renamedPublished');
        $this->assertSame(array(
            array('field' => 'title', 'from' => 'Photosynthesis', 'to' => 'Photosynthesis basics'),
            array('field' => 'published', 'from' => 'false', 'to' => 'true'),
        ), $row['changes']);
        $this->assertSame('A', $row['actor']);
    }

    public function testParentRenameOrOtherPagesAreNotThisPage(): void
    {
        $data = $this->listRevisions();
        $this->assertNull($this->row($data, 'parentRenamed'));
        $this->assertNull($this->row($data, 'otherRenamed'));
    }

    public function testContentCommitThatAlsoChangedTheEntryStaysContent(): void
    {
        $data = $this->listRevisions();
        $this->assertSame('content', $data['revisions'][0]['kind']);
        $this->assertSame(array(array('field' => 'slug', 'from' => 'photosynthesis', 'to' => 'photosynthesis-basics')), $data['revisions'][0]['changes']);
        $this->assertSame(array(), $this->row($data, 'edit1')['changes']);
    }

    public function testPagingCountsStructureRows(): void
    {
        $data = $this->listRevisions(2, 1);
        $this->assertSame(5, $data['total']);
        $this->assertSame(2, $data['count']);
        $this->assertSame(array(2, 3), array_column($data['revisions'], 'revisionNumber'));
        $this->assertSame(array('structure', 'structure'), array_column($data['revisions'], 'kind'));
    }

    public function testHelpersSnapshotAndDiff(): void
    {
        $manifest = array('items' => array($this->unit('a', 'Alpha', 0), array('id' => 'b', 'title' => 'Beta', 'parent' => 'a', 'order' => 1, 'metadata' => array('hideInMenu' => true))));
        $this->assertSame(array(
            'title' => 'Beta', 'slug' => '', 'parent' => 'Alpha', 'parentId' => 'a', 'order' => '1',
            'published' => 'false', 'hideInMenu' => 'true', 'locked' => 'false',
        ), StructureHistory::itemSnapshot($manifest, 'b'));
        $this->assertNull(StructureHistory::itemSnapshot($manifest, 'missing'));
        $snap = StructureHistory::itemSnapshot($manifest, 'a');
        $this->assertSame(array(), StructureHistory::diffSnapshots(null, $snap));
        $this->assertSame(array(), StructureHistory::diffSnapshots($snap, $snap));
    }

    public function testInvalidSiteJsonVersionsAreSkipped(): void
    {
        file_put_contents($this->siteRoot . '/site.json', '{ not json');
        $this->git(array('commit', '--quiet', '-am', 'broken'));
        $bad = $this->git(array('rev-parse', 'HEAD'));
        $this->assertSame(array(), StructureHistory::findStructureChanges($this->siteRoot, 'item-p', array($bad, $this->hashes['edit2'])));
        $blobs = StructureHistory::readBlobs($this->siteRoot, array($bad . ':site.json', str_repeat('0', 40) . ':site.json'));
        $this->assertSame('{ not json', $blobs[0]);
        $this->assertNull($blobs[1]);
    }

    /**
     * A history of large site.json versions (each well over one pipe
     * buffer) with title, parent, order and flag changes and a broken
     * version, as hashes newest first.
     */
    private function buildLargeHistory(): array
    {
        $hashes = array();
        for ($c = 0; $c < 30; $c++) {
            if ($c === 13) {
                $body = '{ broken json ' . str_repeat('x', 70000);
            } else {
                $items = array();
                for ($i = 0; $i < 600; $i++) {
                    $items[] = array(
                        'id' => 'item-' . $i,
                        'title' => 'Page ' . $i . ($i === 5 ? ' v' . intdiv($c, 3) : ''),
                        'slug' => 'page-' . $i,
                        'parent' => ($i === 5 && $c % 4 === 0) ? 'item-1' : null,
                        'order' => $i === 5 ? $c % 5 : $i,
                        'location' => 'pages/item-' . $i . '/index.html',
                        'description' => 'Description for page ' . $i,
                        'metadata' => array(
                            'published' => !($i === 5 && $c % 7 === 0),
                            'hideInMenu' => $i === 5 && $c % 6 === 0,
                        ),
                    );
                }
                $body = json_encode(array('title' => 'Site', 'items' => $items), JSON_PRETTY_PRINT);
            }
            file_put_contents($this->siteRoot . '/site.json', $body);
            $this->git(array('add', '.'));
            $this->git(array('commit', '--quiet', '-m', 'large ' . $c));
            array_unshift($hashes, $this->git(array('rev-parse', 'HEAD')));
        }
        return $hashes;
    }

    /**
     * The pre-change findStructureChanges: every blob read up front.
     */
    private static function referenceFindStructureChanges($dir, $itemId, $hashes): array
    {
        $result = array();
        $specs = array();
        foreach ($hashes as $hash) {
            $specs[] = $hash . ':site.json';
        }
        $blobs = StructureHistory::readBlobs($dir, $specs);
        $newer = StructureHistory::itemSnapshot(StructureHistory::parseManifest($blobs[0]), $itemId);
        for ($i = 1; $i < count($hashes); $i++) {
            $older = StructureHistory::itemSnapshot(StructureHistory::parseManifest($blobs[$i]), $itemId);
            $changes = StructureHistory::diffSnapshots($older, $newer);
            if (count($changes) > 0) {
                $result[$hashes[$i - 1]] = $changes;
            }
            $newer = $older;
        }
        return $result;
    }

    public function testForEachBlobYieldsExactlyWhatReadBlobsReturnsInOrder(): void
    {
        $hashes = $this->buildLargeHistory();
        $specs = array();
        foreach ($hashes as $hash) {
            $specs[] = $hash . ':site.json';
        }
        // a missing object in the middle and at the end
        array_splice($specs, 10, 0, array(str_repeat('0', 40) . ':site.json'));
        $specs[] = $hashes[0] . ':does-not-exist.json';
        $expected = StructureHistory::readBlobs($this->siteRoot, $specs);
        $seen = array();
        StructureHistory::forEachBlob($this->siteRoot, $specs, function ($index, $blob) use (&$seen, $expected) {
            $seen[] = $index;
            $this->assertSame($expected[$index], $blob, 'spec ' . $index);
        });
        $this->assertSame(array_keys($specs), $seen);
        $this->assertGreaterThan(65536, strlen($expected[0]));
    }

    public function testFindStructureChangesMatchesTheReadEverythingVersion(): void
    {
        $hashes = $this->buildLargeHistory();
        foreach (array('item-5', 'item-1', 'item-599', 'missing') as $itemId) {
            $this->assertSame(
                self::referenceFindStructureChanges($this->siteRoot, $itemId, $hashes),
                StructureHistory::findStructureChanges($this->siteRoot, $itemId, $hashes),
                $itemId
            );
        }
        $this->assertGreaterThan(5, count(StructureHistory::findStructureChanges($this->siteRoot, 'item-5', $hashes)));
    }

    public function testForEachBlobReadsEverySpecAsNullWhenGitCannotRunThere(): void
    {
        $seen = array();
        StructureHistory::forEachBlob(sys_get_temp_dir() . '/no-such-dir-' . uniqid(), array('a:site.json', 'b:site.json'), function ($index, $blob) use (&$seen) {
            $seen[] = array($index, $blob);
        });
        $this->assertSame(array(array(0, null), array(1, null)), $seen);
    }
}

class StructureHistoryTestHaxcms extends OperationsTestHaxcms
{
}

class StructureHistoryTestSite extends HAXCMSSite
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
