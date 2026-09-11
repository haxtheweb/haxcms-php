<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Phase 2 tests for page-save uuid tracking (issue #3043).
 *
 * FileContentScanner::rebuildPageFilesUuids rebuilds page.metadata.files as
 * a deduped uuid-string array from a content path-scan. A file removed from
 * the content drops out of the set; an unknown path is upserted into files.json
 * first (deterministic uuid at first ingest) then resolved.
 */
class PageSaveFilesTrackingTest extends TestCase
{
    private $tmpDirs = array();

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
        $this->tmpDirs = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        unset($GLOBALS['HAXCMS']);
    }

    private function removeDirRecursive($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildSite($files = array())
    {
        $site = new SiteRoutesFakeSite();
        $dir = sys_get_temp_dir() . '/pagesave-' . bin2hex(random_bytes(6));
        mkdir($dir . '/files', 0777, true);
        $this->tmpDirs[] = $dir;
        foreach ($files as $relativePath => $contents) {
            $fullPath = $dir . '/files/' . $relativePath;
            $parentDir = dirname($fullPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0777, true);
            }
            file_put_contents($fullPath, $contents);
        }
        $site->siteDirectory = $dir;
        return $site;
    }

    public function testRebuildSetsUuidArrayFromContentScan(): void
    {
        $site = $this->buildSite(array('banner.jpg' => 'jpg', 'doc.pdf' => 'pdf'));
        $page = new stdClass();
        $page->metadata = new stdClass();
        $html = '<img src="files/banner.jpg"><a href="files/doc.pdf">doc</a>';
        $uuids = FileContentScanner::rebuildPageFilesUuids($site, $page, $html);
        $this->assertCount(2, $uuids);
        $this->assertSame($uuids, $page->metadata->files);
        // Each entry is a uuid string.
        foreach ($uuids as $uuid) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $uuid
            );
        }
    }

    public function testFileRemovedFromContentDropsOutOfSet(): void
    {
        $site = $this->buildSite(array('keep.jpg' => 'k', 'gone.jpg' => 'g'));
        $page = new stdClass();
        $page->metadata = new stdClass();
        // First save references both files.
        $html1 = '<img src="files/keep.jpg"><img src="files/gone.jpg">';
        $uuids1 = FileContentScanner::rebuildPageFilesUuids($site, $page, $html1);
        $this->assertCount(2, $uuids1);
        // Second save removes gone.jpg from content.
        $html2 = '<img src="files/keep.jpg">';
        $uuids2 = FileContentScanner::rebuildPageFilesUuids($site, $page, $html2);
        $this->assertCount(1, $uuids2);
        // The kept uuid is still present.
        $keepUuid = $uuids1[0];
        $this->assertContains($keepUuid, $uuids2);
    }

    public function testUnknownPathUpsertedThenResolved(): void
    {
        $site = $this->buildSite(array('new.txt' => 'new-content'));
        $page = new stdClass();
        $page->metadata = new stdClass();
        $html = '<img src="files/new.txt">';
        $uuids = FileContentScanner::rebuildPageFilesUuids($site, $page, $html);
        $this->assertCount(1, $uuids);
        $uuid = $uuids[0];
        // The unknown path was upserted into files.json and can now be loaded.
        $ds = new FilesDataStore($site);
        $record = $ds->getByUuid($uuid);
        $this->assertNotNull($record);
        $this->assertSame('files/new.txt', $record['path']);
    }

    public function testEmptyContentYieldsEmptyArray(): void
    {
        $site = $this->buildSite(array());
        $page = new stdClass();
        $page->metadata = new stdClass();
        $page->metadata->files = array('old-uuid-1', 'old-uuid-2');
        $uuids = FileContentScanner::rebuildPageFilesUuids($site, $page, '<p>no files</p>');
        $this->assertSame(array(), $uuids);
        $this->assertSame(array(), $page->metadata->files);
    }

    public function testCreatesMetadataIfMissing(): void
    {
        $site = $this->buildSite(array('x.jpg' => 'x'));
        $page = new stdClass();
        // No metadata property at all.
        $uuids = FileContentScanner::rebuildPageFilesUuids($site, $page, '<img src="files/x.jpg">');
        $this->assertCount(1, $uuids);
        $this->assertSame($uuids, $page->metadata->files);
    }
}
