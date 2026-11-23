<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * JSONOutlineSchemaItem::readLocation, the page read behind every page load.
 * The rule (Security SEC-PATH): a page is read only from inside the base
 * folder it is given. An empty location, a NUL byte, a ".." segment or a
 * symlink whose real target lies outside the base reads nothing (false).
 *
 * Parity mirror of haxcms-nodejs
 * test/unit/json-outline-schema-item-read-location.test.cjs (same scenario
 * names, TestDox). Twin of JSONOutlineSchemaItemWriteLocationTest.
 */
class JSONOutlineSchemaItemReadLocationTest extends TestCase
{
    private $root;
    private $site;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/jos_item_read_' . uniqid();
        $this->site = $this->root . '/site';
        mkdir($this->site . '/pages/a', 0777, true);
        file_put_contents($this->site . '/pages/a/index.html', '<p>page a</p>');
        mkdir($this->root . '/outside', 0777, true);
        file_put_contents($this->root . '/outside/secret.html', '<p>secret</p>');
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

    private function read(string $location)
    {
        $item = new JSONOutlineSchemaItem();
        $item->location = $location;
        return $item->readLocation($this->site . '/');
    }

    #[TestDox('reads a page inside the base')]
    public function testReadsAPageInsideTheBase(): void
    {
        $this->assertSame('<p>page a</p>', $this->read('pages/a/index.html'));
    }

    #[TestDox('an empty location reads nothing')]
    public function testAnEmptyLocationReadsNothing(): void
    {
        $this->assertFalse($this->read(''));
    }

    #[TestDox('a location that is not a string reads nothing')]
    public function testALocationThatIsNotAStringReadsNothing(): void
    {
        $item = new JSONOutlineSchemaItem();
        $item->location = null;
        $this->assertFalse($item->readLocation($this->site . '/'));
    }

    #[TestDox('a NUL byte reads nothing')]
    public function testANulByteReadsNothing(): void
    {
        $this->assertFalse($this->read("pages/a/index.html\0.txt"));
    }

    #[TestDox('a .. segment reads nothing, even one that ends inside the base')]
    public function testADotDotSegmentReadsNothing(): void
    {
        $this->assertFalse($this->read('../outside/secret.html'));
        $this->assertFalse($this->read('pages/../../outside/secret.html'));
        $this->assertFalse($this->read('pages/a/../a/index.html'));
    }

    #[TestDox('a symlink to a file outside the base reads nothing')]
    public function testASymlinkOutsideTheBaseReadsNothing(): void
    {
        symlink($this->root . '/outside/secret.html', $this->site . '/pages/a/link.html');
        $this->assertFalse($this->read('pages/a/link.html'));
    }

    #[TestDox('a symlink to a file inside the base is read')]
    public function testASymlinkInsideTheBaseIsRead(): void
    {
        symlink($this->site . '/pages/a/index.html', $this->site . '/pages/a/alias.html');
        $this->assertSame('<p>page a</p>', $this->read('pages/a/alias.html'));
    }

    #[TestDox('a page that does not exist reads nothing')]
    public function testAMissingPageReadsNothing(): void
    {
        $this->assertFalse($this->read('pages/b/index.html'));
    }
}
