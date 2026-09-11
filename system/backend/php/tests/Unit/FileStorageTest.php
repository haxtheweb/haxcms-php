<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Phase 2 tests for FileStorage + FilesDataStore (issue #3043).
 *
 * load by uuid O(1), resolveUuidByPath, save upsert, delete removes record +
 * scrubs uuid from all pages page.metadata.files, reconcileMissingFromDisk
 * builds disk files missing from index, flagOrphans non-destructive, uuid
 * stable across size change, missing-index auto-build.
 */
class FileStorageTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/filestorage-' . bin2hex(random_bytes(6));
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

    private function buildRegistry($site)
    {
        $registry = new EntityRegistry($site);
        return FileStorage::registerOn($registry);
    }

    private function buildRegistryDefinition($site)
    {
        $registry = new EntityRegistry($site);
        return $registry->getDefinition('file');
    }

    // ------------------------------------------------------------------

    public function testLoadByUuidReturnsFileEntity(): void
    {
        $site = $this->buildSite(array('banner.jpg' => 'fake-jpeg-bytes'));
        $storage = $this->buildRegistry($site);
        // list triggers reconcile so the file is indexed
        $storage->list();
        $records = $storage->getDataStore()->getRecords();
        $this->assertCount(1, $records);
        $uuid = $records[0]['uuid'];
        $entity = $storage->load($uuid);
        $this->assertNotNull($entity);
        $this->assertInstanceOf('FileEntity', $entity);
        $this->assertSame($uuid, $entity->getUuid());
        $this->assertSame('files/banner.jpg', $entity->getPath());
    }

    public function testLoadByUnknownUuidReturnsNull(): void
    {
        $site = $this->buildSite(array());
        $storage = $this->buildRegistry($site);
        $this->assertNull($storage->load('00000000-0000-0000-0000-000000000000'));
    }

    public function testResolveUuidByPathUpsertsUnknownPath(): void
    {
        $site = $this->buildSite(array('photo.png' => 'png-bytes'));
        $storage = $this->buildRegistry($site);
        $ds = $storage->getDataStore();
        $uuid = $ds->resolveUuidByPath('files/photo.png');
        $this->assertNotSame('', $uuid);
        // Second call returns the same uuid from the index (O(1)).
        $this->assertSame($uuid, $ds->resolveUuidByPath('files/photo.png'));
        // Record now persisted in files.json.
        $record = $ds->getByUuid($uuid);
        $this->assertNotNull($record);
        $this->assertSame('files/photo.png', $record['path']);
    }

    public function testSaveUpsertsIntoFilesJson(): void
    {
        $site = $this->buildSite(array());
        $storage = $this->buildRegistry($site);
        $definition = $this->buildRegistryDefinition($site);
        $entity = new FileEntity($definition, array(
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'path' => 'files/manual.txt',
            'name' => 'manual.txt',
            'mimetype' => 'text/plain',
            'size' => 10,
        ));
        $this->assertTrue($storage->save($entity));
        $loaded = $storage->load('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $this->assertNotNull($loaded);
        $this->assertSame('manual.txt', $loaded->getName());
    }

    public function testSaveRejectsMissingRequiredFields(): void
    {
        $site = $this->buildSite(array());
        $storage = $this->buildRegistry($site);
        $definition = $this->buildRegistryDefinition($site);
        $entity = new FileEntity($definition, array(
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            // missing path, name, mimetype
        ));
        $this->assertFalse($storage->save($entity));
    }

    public function testDeleteRemovesRecordAndScrubsUuidFromPages(): void
    {
        $site = $this->buildSite(array('photo.png' => 'png-bytes'));
        $storage = $this->buildRegistry($site);
        $ds = $storage->getDataStore();
        $uuid = $ds->resolveUuidByPath('files/photo.png');
        // Simulate a page metadata.files with the uuid.
        $page = new stdClass();
        $page->id = 'page-1';
        $page->metadata = new stdClass();
        $page->metadata->files = array($uuid, 'other-uuid');
        $site->manifest->items = array($page);
        // Stub save on the site so scrubUuidFromPages doesn't fail.
        $site->saveInvoked = false;
        // We can't add a method to the fake; FileStorage checks method_exists.
        // The fake SiteRoutesFakeSite has no save() method, so it falls through
        // to manifest->save() — but the fake manifest has no save() either.
        // That's fine: scrubUuidFromPages only saves if modified, and the
        // modification happens in-memory regardless.
        $storage->delete($uuid);
        // Record removed from files.json.
        $this->assertNull($ds->getByUuid($uuid));
        // Uuid scrubbed from page.metadata.files (in-memory).
        $this->assertNotContains($uuid, $site->manifest->items[0]->metadata->files);
        $this->assertContains('other-uuid', $site->manifest->items[0]->metadata->files);
    }

    public function testReconcileMissingFromDiskIndexesNewFiles(): void
    {
        $site = $this->buildSite(array('a.txt' => 'aaa', 'b.txt' => 'bbb'));
        $storage = $this->buildRegistry($site);
        $ds = $storage->getDataStore();
        // First load auto-builds, so we have 2 records.
        $ds->load();
        $this->assertCount(2, $ds->getRecords());
        // Drop a new file on disk.
        file_put_contents($site->siteDirectory . '/files/c.txt', 'ccc');
        $added = $ds->reconcileMissingFromDisk();
        $this->assertSame(1, $added);
        $this->assertCount(3, $ds->getRecords());
    }

    public function testFlagOrphansNonDestructive(): void
    {
        $site = $this->buildSite(array('keep.txt' => 'k', 'gone.txt' => 'g'));
        $storage = $this->buildRegistry($site);
        $ds = $storage->getDataStore();
        $ds->load();
        // Remove the disk file for gone.txt.
        @unlink($site->siteDirectory . '/files/gone.txt');
        $orphans = $ds->flagOrphans();
        $this->assertCount(1, $orphans);
        $this->assertSame('files/gone.txt', $orphans[0]['path']);
        // files.json NOT mutated — record still present.
        $this->assertCount(2, $ds->getRecords());
    }

    public function testUuidStableAcrossSizeChange(): void
    {
        $site = $this->buildSite(array('img.jpg' => 'small'));
        $storage = $this->buildRegistry($site);
        $ds = $storage->getDataStore();
        $uuid1 = $ds->resolveUuidByPath('files/img.jpg');
        // Change the file size on disk.
        file_put_contents($site->siteDirectory . '/files/img.jpg', 'much-larger-content-now');
        // The persisted uuid should NOT change (it's stable in files.json).
        $uuid2 = $ds->resolveUuidByPath('files/img.jpg');
        $this->assertSame($uuid1, $uuid2, 'Uuid must be stable across size changes (files.json-sourced)');
    }

    public function testMissingIndexAutoBuildsFromDisk(): void
    {
        $site = $this->buildSite(array('x.txt' => 'xxx', 'y.txt' => 'yyy'));
        $storage = $this->buildRegistry($site);
        $ds = $storage->getDataStore();
        // No files.json on disk yet. load() should auto-build.
        $this->assertFalse($ds->exists());
        $ds->load();
        $this->assertTrue($ds->exists());
        $this->assertCount(2, $ds->getRecords());
    }

    public function testListReturnsEntitiesAndReconciles(): void
    {
        $site = $this->buildSite(array('one.txt' => '1'));
        $storage = $this->buildRegistry($site);
        $entities = $storage->list();
        $this->assertCount(1, $entities);
        $this->assertInstanceOf('FileEntity', $entities[0]);
        // Add a file after, list again — should auto-index.
        file_put_contents($site->siteDirectory . '/files/two.txt', '2');
        $entities = $storage->list();
        $this->assertCount(2, $entities);
    }
}
