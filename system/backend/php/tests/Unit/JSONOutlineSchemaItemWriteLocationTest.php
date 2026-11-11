<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * JSONOutlineSchemaItem::writeLocation, the page write every saveOutline
 * contents / duplicate request ends in.
 *
 * Parity mirror of haxcms-nodejs
 * test/unit/json-outline-schema-item-write-location.test.cjs (same scenario
 * names, TestDox). A missing page file is created when its folder exists
 * (no folders are made); a directory or a dangling symlink at the location
 * is refused.
 *
 * writeLocation joins basePath and location as strings, so basePath ends
 * with "/".
 */
class JSONOutlineSchemaItemWriteLocationTest extends TestCase
{
    private $root;
    private $site;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/jos_item_write_' . uniqid();
        $this->site = $this->root . '/site';
        mkdir($this->site . '/pages/a', 0777, true);
        file_put_contents($this->site . '/pages/a/index.html', '<p>old</p>');
        mkdir($this->root . '/outside', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
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

    private function item(string $location): JSONOutlineSchemaItem
    {
        $item = new JSONOutlineSchemaItem();
        $item->location = $location;
        return $item;
    }

    #[TestDox('writeLocation: the body is written to the page file')]
    public function testTheBodyIsWritten(): void
    {
        $result = $this->item('pages/a/index.html')->writeLocation('<p>new</p>', $this->site . '/');
        $this->assertNotFalse($result);
        $this->assertSame('<p>new</p>', file_get_contents($this->site . '/pages/a/index.html'));
    }

    #[TestDox('writeLocation: an empty body is stored as an empty paragraph')]
    public function testAnEmptyBodyIsAnEmptyParagraph(): void
    {
        $result = $this->item('pages/a/index.html')->writeLocation('', $this->site . '/');
        $this->assertNotFalse($result, 'the write succeeds');
        $this->assertSame('<p></p>', file_get_contents($this->site . '/pages/a/index.html'));
    }

    #[TestDox('writeLocation: a location outside the base path is refused')]
    public function testALocationOutsideTheBaseIsRefused(): void
    {
        $target = $this->root . '/outside/index.html';
        file_put_contents($target, '<p>outside</p>');
        $result = $this->item('../outside/index.html')->writeLocation('<p>escaped</p>', $this->site . '/');
        $this->assertFalse($result);
        $this->assertSame('<p>outside</p>', file_get_contents($target));
    }

    #[TestDox('writeLocation: when nothing was written the answer is exactly false')]
    public function testNothingWrittenIsExactlyFalse(): void
    {
        $file = $this->site . '/pages/missing/index.html';
        $result = $this->item('pages/missing/index.html')->writeLocation('<p>body</p>', $this->site . '/');
        if (file_exists($file)) {
            $this->assertNotFalse($result, 'a write that happened reports success');
            $this->assertSame('<p>body</p>', file_get_contents($file));
        } else {
            $this->assertFalse($result);
        }
    }

    #[TestDox('writeLocation: a page file that does not exist yet is created')]
    public function testAMissingPageFileIsCreated(): void
    {
        mkdir($this->site . '/pages/b', 0777, true);
        $result = $this->item('pages/b/index.html')->writeLocation('<p>b</p>', $this->site . '/');
        $this->assertNotFalse($result);
        $this->assertSame('<p>b</p>', file_get_contents($this->site . '/pages/b/index.html'));
    }

    #[TestDox('writeLocation: a missing page folder is not created')]
    public function testAMissingPageFolderIsNotCreated(): void
    {
        $result = $this->item('pages/c/index.html')->writeLocation('<p>c</p>', $this->site . '/');
        $this->assertFalse($result);
        $this->assertFalse(file_exists($this->site . '/pages/c'));
    }

    #[TestDox("writeLocation: a location ending in '/' is refused")]
    public function testALocationEndingInASlashIsRefused(): void
    {
        mkdir($this->site . '/pages/d', 0777, true);
        $result = $this->item('pages/d/')->writeLocation('<p>d</p>', $this->site . '/');
        $this->assertFalse($result);
        $this->assertTrue(is_dir($this->site . '/pages/d'));
        $this->assertFalse(file_exists($this->site . '/pages/e'));
        $missing = $this->item('pages/e/')->writeLocation('<p>e</p>', $this->site . '/');
        $this->assertFalse($missing);
        $this->assertFalse(file_exists($this->site . '/pages/e'));
    }

    #[TestDox('writeLocation: a location that is a directory is not reported as written')]
    public function testADirectoryIsNotReportedAsWritten(): void
    {
        $result = $this->item('pages/a')->writeLocation('<p>dir</p>', $this->site . '/');
        $this->assertFalse($result);
        $this->assertTrue(is_dir($this->site . '/pages/a'));
    }

    #[TestDox('writeLocation: a dangling symlink out of the base path is not followed')]
    public function testADanglingSymlinkIsNotFollowed(): void
    {
        $outsideTarget = $this->root . '/outside/created.html';
        symlink($outsideTarget, $this->site . '/pages/a/link.html');
        $result = $this->item('pages/a/link.html')->writeLocation('<p>escaped</p>', $this->site . '/');
        $this->assertFalse($result);
        $this->assertFalse(file_exists($outsideTarget));
    }
}
