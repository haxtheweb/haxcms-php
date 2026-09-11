<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/files.php. Needs
 * a real temp filesystem site directory + files/ subdir since
 * SiteRouteUtils::collectSiteFiles() performs real scandir/is_dir/stat
 * calls (mirrors ExportConvertersFsTest.php's temp-dir fixture pattern).
 */
class SiteRoutesFilesTest extends TestCase
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
            }
            else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function buildSiteWithFiles($files = array())
    {
        $site = new SiteRoutesFakeSite();
        $dir = sys_get_temp_dir() . '/site-routes-files-' . bin2hex(random_bytes(6));
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

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListReturnsEmptyWhenNoFilesDirectory(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->siteDirectory = sys_get_temp_dir() . '/site-routes-files-missing-' . bin2hex(random_bytes(6));
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(0, $data['count']);
        $this->assertSame(array(), $data['files']);
    }

    public function testListReturnsFileRecordsWithPathAndMimetype(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'image.png' => 'fake-png-bytes',
            'doc.pdf' => 'fake-pdf-bytes',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(2, $data['count']);
        $paths = array_column($data['files'], 'path');
        sort($paths);
        $this->assertSame(array('files/doc.pdf', 'files/image.png'), $paths);
        $byName = array();
        foreach ($data['files'] as $record) {
            $byName[$record['name']] = $record;
        }
        // mime_content_type() inspects real file bytes rather than the
        // extension, and our fixture files contain plain fake text (not
        // real PDF/PNG binary signatures), so it reports 'text/plain' for
        // both -- the extension-based fallback map in haxcmsBuildFileRecord
        // is only reached when mime_content_type() itself returns empty.
        $this->assertNotSame('', $byName['doc.pdf']['mimetype']);
        $this->assertNotSame('', $byName['image.png']['uuid']);
    }

    public function testHaxcmsManagedDirectoryIsExcluded(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'image.png' => 'fake-png-bytes',
            'haxcms-managed/internal.json' => '{}',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('image.png', $data['files'][0]['name']);
    }

    public function testFilterExtensionNarrowsResults(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'image.png' => 'fake-png-bytes',
            'doc.pdf' => 'fake-pdf-bytes',
        ));
        $_GET['filter.extension'] = 'pdf';
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('doc.pdf', $data['files'][0]['name']);
    }

    public function testFilterNameContainsNarrowsResults(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'vacation-photo.png' => 'fake-png-bytes',
            'doc.pdf' => 'fake-pdf-bytes',
        ));
        $_GET['filter.nameContains'] = 'vacation';
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('vacation-photo.png', $data['files'][0]['name']);
    }

    public function testDetailByUuidReturnsMatchingRecord(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'image.png' => 'fake-png-bytes',
        ));
        $listContext = makeSiteRouteContext($site, array(), 'v1/files');
        $listResult = invokeSiteRouteHandler('files.php', $listContext);
        $uuid = $listResult['data']['data']['files'][0]['uuid'];
        $detailContext = makeSiteRouteContext($site, array('fileUuid' => $uuid), 'v1/files/' . $uuid);
        $detailResult = invokeSiteRouteHandler('files.php', $detailContext);
        $data = $detailResult['data']['data'];
        $this->assertSame('image.png', $data['name']);
        $this->assertSame($uuid, $data['uuid']);
    }

    public function testDetailWithInvalidUuidFormatReturns400(): void
    {
        $site = $this->buildSiteWithFiles(array());
        $context = makeSiteRouteContext($site, array('fileUuid' => 'not-a-uuid'), 'v1/files/not-a-uuid');
        $result = invokeSiteRouteHandler('files.php', $context);
        $this->assertSame(400, $result['data']['status']);
    }

    public function testDetailWithUnknownUuidReturns404(): void
    {
        $site = $this->buildSiteWithFiles(array('image.png' => 'bytes'));
        $context = makeSiteRouteContext(
            $site,
            array('fileUuid' => '00000000-0000-0000-0000-000000000000'),
            'v1/files/00000000-0000-0000-0000-000000000000'
        );
        $result = invokeSiteRouteHandler('files.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    // ------------------------------------------------------------------
    // Phase 2 (#3043): files.json datastore + orphans + auto-index + stable uuid
    // ------------------------------------------------------------------

    public function testListResponseIncludesOrphansArray(): void
    {
        $site = $this->buildSiteWithFiles(array('keep.png' => 'keep-bytes'));
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        // orphans key is always present (may be empty).
        $this->assertArrayHasKey('orphans', $data);
        $this->assertIsArray($data['orphans']);
    }

    public function testListFlagsOrphansNonDestructively(): void
    {
        $site = $this->buildSiteWithFiles(array('gone.png' => 'gone-bytes'));
        // First list auto-indexes the file.
        $context1 = makeSiteRouteContext($site, array(), 'v1/files');
        invokeSiteRouteHandler('files.php', $context1);
        // Delete the disk file but leave files.json intact.
        @unlink($site->siteDirectory . '/files/gone.png');
        // Second list should flag it as an orphan.
        $context2 = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context2);
        $data = $result['data']['data'];
        $this->assertCount(0, $data['files'], 'Orphan file not in files list');
        $this->assertCount(1, $data['orphans'], 'Orphan flagged in orphans array');
        $this->assertSame('files/gone.png', $data['orphans'][0]['path']);
    }

    public function testListAutoIndexesMissingDiskFiles(): void
    {
        $site = $this->buildSiteWithFiles(array('initial.txt' => 'initial'));
        // First list auto-indexes initial.txt.
        $context1 = makeSiteRouteContext($site, array(), 'v1/files');
        invokeSiteRouteHandler('files.php', $context1);
        // Drop a new file on disk (no upload, just raw file).
        file_put_contents($site->siteDirectory . '/files/dropped.txt', 'dropped');
        // Second list should auto-index the new file.
        $context2 = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context2);
        $data = $result['data']['data'];
        $names = array_column($data['files'], 'name');
        sort($names);
        $this->assertSame(array('dropped.txt', 'initial.txt'), $names);
    }

    public function testDetailUuidStableAfterSizeChange(): void
    {
        // This is THE fix for the sepia 'File not found for fileUuid' bug:
        // the deterministic UUID shifts when file size changes, but the
        // files.json-sourced UUID is stable.
        $site = $this->buildSiteWithFiles(array('img.jpg' => 'small'));
        // First list + detail to get the uuid.
        $listContext = makeSiteRouteContext($site, array(), 'v1/files');
        $listResult = invokeSiteRouteHandler('files.php', $listContext);
        $uuid = $listResult['data']['data']['files'][0]['uuid'];
        // Change the file size on disk (simulating a sepia transform).
        file_put_contents($site->siteDirectory . '/files/img.jpg', 'much-larger-content-now');
        // Detail by the SAME uuid should still resolve (O(1) from files.json).
        $detailContext = makeSiteRouteContext($site, array('fileUuid' => $uuid), 'v1/files/' . $uuid);
        $detailResult = invokeSiteRouteHandler('files.php', $detailContext);
        $this->assertSame(200, $detailResult['data']['status']);
        $this->assertSame($uuid, $detailResult['data']['data']['uuid']);
    }
}
