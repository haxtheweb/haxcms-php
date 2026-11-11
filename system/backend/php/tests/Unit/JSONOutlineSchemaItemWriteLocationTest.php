<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * JSONOutlineSchemaItem::writeLocation, the page write every saveOutline
 * contents / duplicate request ends in.
 *
 * Parity mirror of haxcms-nodejs
 * test/unit/json-outline-schema-item-write-location.test.cjs (same scenario
 * names, TestDox). The last two scenarios are todo tests on the Node side:
 * Node refuses a page file that does not exist yet and answers true for a
 * directory without writing; PHP creates the file and answers false for a
 * directory. saveOutline is unaffected in both backends (it copies the
 * boilerplate page file and checks the target is a regular file first).
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

    #[TestDox('writeLocation: a location that is a directory is not reported as written')]
    public function testADirectoryIsNotReportedAsWritten(): void
    {
        $result = $this->item('pages/a')->writeLocation('<p>dir</p>', $this->site . '/');
        $this->assertFalse($result);
    }
}
