<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FaultInjectionFileWrapper.php';

/**
 * JSONOutlineSchema::save() replaces site.json atomically
 * (JSONOutlineSchema::writeFileAtomic, haxtheweb/issues#3287): readers see the
 * previous file or the complete new one, a failed write throws and leaves the
 * previous file and no temp file, and a site.json that does not parse is never
 * loaded as an empty outline. Mirrors haxcms-nodejs
 * test/unit/json-outline-schema-atomic-write.test.cjs. The saveOutline /
 * route-level scenarios are FaultInjection*Test.php.
 */
class JSONOutlineSchemaAtomicWriteTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jos_atomic_' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        FaultInjectionFileWrapper::uninstall();
        foreach (scandir($this->dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
    }

    private function schemaFor(string $file, string $title): JSONOutlineSchema
    {
        $jos = new JSONOutlineSchema();
        $jos->file = $file;
        $jos->title = $title;
        return $jos;
    }

    private function tempFiles(): array
    {
        return array_values(array_filter(scandir($this->dir), function ($entry) {
            return substr($entry, -4) === '.tmp';
        }));
    }

    private function titleOf(string $file): string
    {
        return json_decode((string) file_get_contents($file))->title;
    }

    public function testSaveWritesTheWholeFileAndLeavesNoTempFile(): void
    {
        $file = $this->dir . '/site.json';
        $this->assertGreaterThan(0, $this->schemaFor($file, 'First')->save());
        $this->assertSame('First', $this->titleOf($file));
        $this->schemaFor($file, 'Second')->save();
        $this->assertSame('Second', $this->titleOf($file));
        $this->assertSame(array(), $this->tempFiles());
    }

    public function testSaveKeepsThePermissionsSiteJsonHad(): void
    {
        $file = $this->dir . '/site.json';
        file_put_contents($file, '{}');
        chmod($file, 0640);
        $this->schemaFor($file, 'Kept')->save();
        clearstatcache();
        $this->assertSame(0640, fileperms($file) & 0777);
    }

    public function testASymlinkedSiteJsonIsWrittenThroughToItsTarget(): void
    {
        $real = $this->dir . '/real.json';
        $link = $this->dir . '/site.json';
        file_put_contents($real, '{}');
        if (!@symlink($real, $link)) {
            $this->markTestSkipped('symlinks are not available');
        }
        $this->schemaFor($link, 'Through')->save();
        $this->assertTrue(is_link($link), 'the link stays a link');
        $this->assertSame('Through', $this->titleOf($real));
        $this->assertSame(array(), $this->tempFiles());
    }

    public function testAWriteThatStopsPartWayThrowsAndKeepsThePreviousFile(): void
    {
        $file = $this->dir . '/site.json';
        $this->schemaFor($file, 'Before')->save();
        $before = file_get_contents($file);
        $dir = $this->dir;
        FaultInjectionFileWrapper::install(array(
            array('op' => 'write', 'match' => function ($path) use ($dir) {
                return strpos($path, $dir . '/.site.json.') === 0;
            }, 'errno' => 'ENOSPC'),
        ));
        try {
            $this->schemaFor($file, 'After')->save();
            $this->fail('a failed write throws');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unable to write site.json', $e->getMessage());
        } finally {
            FaultInjectionFileWrapper::uninstall();
        }
        $this->assertSame($before, file_get_contents($file));
        $this->assertSame(array(), $this->tempFiles());
    }

    public function testAFailedRenameThrowsAndKeepsThePreviousFile(): void
    {
        $file = $this->dir . '/site.json';
        $this->schemaFor($file, 'Before')->save();
        $before = file_get_contents($file);
        FaultInjectionFileWrapper::install(array(
            array('op' => 'rename', 'match' => function ($path) use ($file) {
                return $path === $file;
            }, 'errno' => 'EXDEV'),
        ));
        try {
            $this->schemaFor($file, 'After')->save();
            $this->fail('a failed rename throws');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unable to write site.json', $e->getMessage());
        } finally {
            FaultInjectionFileWrapper::uninstall();
        }
        $this->assertSame($before, file_get_contents($file));
        $this->assertSame(array(), $this->tempFiles());
    }

    public function testAnOutlineThatCannotBeEncodedThrowsAndKeepsThePreviousFile(): void
    {
        $file = $this->dir . '/site.json';
        // invalid UTF-8 and INF: json_encode fails on both (haxcms-nodejs'
        // JSON.stringify fails on a circular structure or a BigInt)
        foreach (array("bad \xB1\x31 bytes", INF) as $bad) {
            $this->schemaFor($file, 'Before')->save();
            $before = file_get_contents($file);
            $jos = $this->schemaFor($file, 'After');
            $jos->metadata = (object) array('site' => (object) array('bad' => $bad));
            try {
                $jos->save();
                $this->fail('an outline that cannot be encoded throws');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('unable to encode site.json', $e->getMessage());
            }
            $this->assertSame($before, file_get_contents($file), 'site.json is left as it was');
            $this->assertSame(array(), $this->tempFiles());
            // once the value is gone the same object saves normally
            $jos->metadata = (object) array('site' => (object) array());
            $this->assertGreaterThan(0, $jos->save());
            $this->assertSame('After', $this->titleOf($file));
        }
    }

    public function testASiteJsonThatDoesNotParseIsNotLoadedAsAnEmptyOutline(): void
    {
        foreach (array('{"title": "cut sh', '', 'null', '[1, 2]') as $bytes) {
            $file = $this->dir . '/site.json';
            file_put_contents($file, $bytes);
            $jos = new JSONOutlineSchema();
            try {
                $jos->load($file);
                $this->fail('loading ' . var_export($bytes, true) . ' throws');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a valid JSON outline', $e->getMessage());
            }
            $this->assertSame($bytes, file_get_contents($file), 'the file is left as it was');
        }
    }
}
