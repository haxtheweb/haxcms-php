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

    // ------------------------------------------------------------------
    // Pagination: PHP converts '.' to '_' in $_GET keys, so real HTTP
    // requests like ?page.limit=2&page.offset=2 arrive as page_limit/page_offset.
    // getQueryValue falls back to the underscore variant. This test sets
    // the underscore keys directly (as PHP's query parser would produce)
    // to verify the fallback works end-to-end.
    // ------------------------------------------------------------------

    public function testPaginationLimitAndOffsetRespectUnderscoreQueryKeys(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'a.txt' => 'aaa',
            'b.txt' => 'bbb',
            'c.txt' => 'ccc',
            'd.txt' => 'ddd',
            'e.txt' => 'eee',
        ));
        // Simulate real HTTP: ?page.limit=2&page.offset=2 — PHP's query
        // parser converts '.' to '_' so the keys arrive as page_limit/page_offset.
        $_GET['page_limit'] = '2';
        $_GET['page_offset'] = '2';
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $result = invokeSiteRouteHandler('files.php', $context);
        $data = $result['data']['data'];
        $this->assertCount(2, $data['files'], 'limit=2 returns 2 records');
        $this->assertSame(5, $data['total'], 'total reflects all 5 files');
        $this->assertSame(2, $data['page']['limit']);
        $this->assertSame(2, $data['page']['offset']);
        // offset=2 with default path sort => records 3 and 4 (c.txt, d.txt).
        $names = array_column($data['files'], 'name');
        sort($names);
        $this->assertSame(array('c.txt', 'd.txt'), $names);
    }

    // ------------------------------------------------------------------
    // Top-of-file global helper functions (shared with filesMutation.php
    // and referenced by HAXCMSFile.php). These are plain global functions
    // guarded by function_exists, so they can be exercised directly after
    // the route file has been included once.
    // ------------------------------------------------------------------

    private function ensureRouteHelpersLoaded(): void
    {
        include_once dirname(__DIR__, 2) . '/lib/siteRoutes/v1/files.php';
    }

    public function testCanonicalPathHelperVariants(): void
    {
        $this->ensureRouteHelpersLoaded();
        $this->assertSame('files', haxcmsSiteFileCanonicalPath(''));
        $this->assertSame('files', haxcmsSiteFileCanonicalPath('/'));
        $this->assertSame('files/image.png', haxcmsSiteFileCanonicalPath('image.png'));
        $this->assertSame('files/sub/image.png', haxcmsSiteFileCanonicalPath('/sub/image.png'));
        // Already-canonical paths pass through; backslashes normalize.
        $this->assertSame('files/sub/image.png', haxcmsSiteFileCanonicalPath('files/sub/image.png'));
        $this->assertSame('files/sub/image.png', haxcmsSiteFileCanonicalPath('sub\\image.png'));
    }

    public function testUuidFromHashHelperRejectsShortHashesAndFormatsFullOnes(): void
    {
        $this->ensureRouteHelpersLoaded();
        $this->assertSame('', haxcmsSiteFileUuidFromHash(''));
        $this->assertSame('', haxcmsSiteFileUuidFromHash('tooshort'));
        $hash = str_repeat('abcdef01', 4);
        $expected = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
        // Uppercase input is normalized to lowercase before formatting.
        $this->assertSame($expected, haxcmsSiteFileUuidFromHash(strtoupper($hash)));
    }

    public function testUuidSiteNameHelperFallsBackThroughSiteNameToLiteralSite(): void
    {
        $this->ensureRouteHelpersLoaded();
        $this->assertSame('testsite', haxcmsSiteFileUuidSiteName(new SiteRoutesFakeSite()));
        $nameOnly = new stdClass();
        $nameOnly->name = 'name-only-site';
        $this->assertSame('name-only-site', haxcmsSiteFileUuidSiteName($nameOnly));
        $this->assertSame('site', haxcmsSiteFileUuidSiteName(new stdClass()));
        $this->assertSame('site', haxcmsSiteFileUuidSiteName(null));
    }

    public function testDeterministicFileUuidHelperIsStableAndCanonicalizesSize(): void
    {
        $this->ensureRouteHelpersLoaded();
        $site = new SiteRoutesFakeSite();
        $uuid = haxcmsSiteDeterministicFileUuid($site, 'image.png', 123);
        $this->assertSame($uuid, haxcmsSiteDeterministicFileUuid($site, 'image.png', 123));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
        // Identity string is 'testsite:files/image.png:<size>' with size <= 0 floored to 0.
        $this->assertSame(haxcmsSiteFileUuidFromHash(hash('sha256', 'testsite:files/image.png:123')), $uuid);
        $this->assertSame(haxcmsSiteFileUuidFromHash(hash('sha256', 'testsite:files/image.png:0')), haxcmsSiteDeterministicFileUuid($site, 'image.png', 0));
    }

    public function testBuildFileRecordReadsRealFileMimetypeAndStats(): void
    {
        $this->ensureRouteHelpersLoaded();
        $site = $this->buildSiteWithFiles(array('real.txt' => 'hello world'));
        $record = haxcmsBuildFileRecord($site, array(
            'relativePath' => 'real.txt',
            'stats' => array('mtime' => 1700000000, 'size' => 11),
            'absolutePath' => $site->siteDirectory . '/files/real.txt',
        ));
        $this->assertSame('files/real.txt', $record['path']);
        $this->assertSame('files/real.txt', $record['url']);
        $this->assertSame('real.txt', $record['name']);
        // mime_content_type() inspects bytes, not extension (see route test above).
        $this->assertSame('text/plain', $record['mimetype']);
        $this->assertSame(11, $record['size']);
        $this->assertSame(1700000000, $record['dateCreated']);
        $this->assertSame('files/real.txt?t=1700000000', $record['fullUrl']);
        $this->assertSame(haxcmsSiteDeterministicFileUuid($site, 'files/real.txt', 11), $record['uuid']);
    }

    public function testBuildFileRecordDefaultsMissingInputFields(): void
    {
        $this->ensureRouteHelpersLoaded();
        $site = new SiteRoutesFakeSite();
        // No stats and an absolutePath that does not exist: no dateCreated,
        // no ?t= cache buster, and an unmapped extension leaves mimetype empty.
        $record = haxcmsBuildFileRecord($site, array(
            'relativePath' => 'missing.bin',
            'absolutePath' => '/nonexistent-haxcms-files-test/missing.bin',
        ));
        $this->assertSame('files/missing.bin', $record['path']);
        $this->assertSame('files/missing.bin', $record['fullUrl']);
        $this->assertSame(0, $record['dateCreated']);
        $this->assertSame(0, $record['size']);
        $this->assertSame('', $record['mimetype']);
        // No relativePath at all: apiPath degrades to 'files/'.
        $record = haxcmsBuildFileRecord($site, array('absolutePath' => '/nonexistent-haxcms-files-test/x.bin'));
        $this->assertSame('files/', $record['path']);
    }

    public function testBuildFileRecordExtensionFallbackMap(): void
    {
        $this->ensureRouteHelpersLoaded();
        $site = new SiteRoutesFakeSite();
        $cases = array(
            'photo.jpg' => 'image/jpeg',
            'photo.jpeg' => 'image/jpeg',
            'photo.png' => 'image/png',
            'photo.gif' => 'image/gif',
            'logo.svg' => 'image/svg+xml',
            'doc.pdf' => 'application/pdf',
            'notes.md' => 'text/markdown',
        );
        foreach ($cases as $name => $expectedMimetype) {
            // absolutePath does not exist so mime_content_type is skipped and
            // the extension fallback map decides the mimetype.
            $record = haxcmsBuildFileRecord($site, array(
                'relativePath' => $name,
                'absolutePath' => '/nonexistent-haxcms-files-test/' . $name,
            ));
            $this->assertSame($expectedMimetype, $record['mimetype'], $name);
        }
    }

    public function testBuildFileRecordFullUrlUsesHaxcmsGlobalBaseAndSitesDirectory(): void
    {
        $this->ensureRouteHelpersLoaded();
        $site = new SiteRoutesFakeSite();
        $GLOBALS['HAXCMS'] = (object) array('basePath' => '/base', 'sitesDirectory' => '_sites');
        try {
            $record = haxcmsBuildFileRecord($site, array(
                'relativePath' => 'img.png',
                'stats' => array('mtime' => 42, 'size' => 7),
                'absolutePath' => '/nonexistent-haxcms-files-test/img.png',
            ));
            $this->assertSame('/base/_sites/testsite/files/img.png?t=42', $record['fullUrl']);
        } finally {
            unset($GLOBALS['HAXCMS']);
        }
    }

    // ------------------------------------------------------------------
    // Remaining list filters: filter.type, filter.startsWith, filename.
    // ------------------------------------------------------------------

    public function testListFilterTypeRequiresMimetypePrefixMatch(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'image.png' => 'fake-png-bytes',
            'doc.pdf' => 'fake-pdf-bytes',
        ));
        // Fixture bytes are plain text, so no record mimetype starts with 'image'.
        $_GET['filter.type'] = 'image';
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $data = invokeSiteRouteHandler('files.php', $context)['data']['data'];
        $this->assertSame(0, $data['count']);
        $_GET['filter.type'] = 'text';
        $data = invokeSiteRouteHandler('files.php', $context)['data']['data'];
        $this->assertSame(2, $data['count']);
    }

    public function testListFilterStartsWithRequiresPathPrefixMatch(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'doc.pdf' => 'fake-pdf-bytes',
            'image.png' => 'fake-png-bytes',
        ));
        $_GET['filter.startsWith'] = 'files/doc';
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        $data = invokeSiteRouteHandler('files.php', $context)['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('doc.pdf', $data['files'][0]['name']);
    }

    public function testListFilterFilenameMatchesPathOrName(): void
    {
        $site = $this->buildSiteWithFiles(array(
            'vacation.png' => 'fake-png-bytes',
            'doc.pdf' => 'fake-pdf-bytes',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/files');
        // No path or name contains the token: both records dropped.
        $_GET['filename'] = 'nomatch-token';
        $data = invokeSiteRouteHandler('files.php', $context)['data']['data'];
        $this->assertSame(0, $data['count']);
        // The token matches one record's path or name.
        $_GET['filename'] = 'vacation';
        $data = invokeSiteRouteHandler('files.php', $context)['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('vacation.png', $data['files'][0]['name']);
    }
}
