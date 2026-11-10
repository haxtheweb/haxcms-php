<?php
use PHPUnit\Framework\TestCase;

/**
 * HAXCMSSite::removeCreatedPageFolder() (haxtheweb/issues#3287, FI-OUT-01 /
 * FI-NODE-02): removes a page folder a failed save created, and nothing
 * outside the site's pages/ directory. Mirrors haxcms-nodejs
 * test/unit/remove-created-page-folder.test.cjs.
 */
class RemoveCreatedPageFolderTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rm_page_folder_' . uniqid();
        mkdir($this->root . '/demo/pages/new-page/assets', 0777, true);
        file_put_contents($this->root . '/demo/pages/new-page/index.html', '<p></p>');
        file_put_contents($this->root . '/demo/pages/new-page/assets/a.txt', 'a');
        mkdir($this->root . '/outside', 0777, true);
        file_put_contents($this->root . '/demo/site.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
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

    private function site(): HAXCMSSite
    {
        $site = new HAXCMSSite();
        $site->directory = $this->root;
        $site->manifest = new JSONOutlineSchema();
        $site->manifest->metadata = (object) array('site' => (object) array('name' => 'demo'));
        return $site;
    }

    public function testRemovesAPageFolderUnderPages(): void
    {
        $this->assertTrue($this->site()->removeCreatedPageFolder($this->root . '/demo/pages/new-page'));
        $this->assertDirectoryDoesNotExist($this->root . '/demo/pages/new-page');
        $this->assertDirectoryExists($this->root . '/demo/pages');
    }

    public function testNeverRemovesAnythingOutsidePages(): void
    {
        $site = $this->site();
        foreach (array(
            $this->root . '/demo/pages',
            $this->root . '/demo',
            $this->root . '/outside',
            $this->root . '/demo/pages/../../outside',
            '',
        ) as $folder) {
            $this->assertFalse($site->removeCreatedPageFolder($folder), $folder);
        }
        $this->assertDirectoryExists($this->root . '/outside');
        $this->assertFileExists($this->root . '/demo/site.json');
        if (@symlink($this->root . '/outside', $this->root . '/demo/pages/linked')) {
            $this->assertFalse($site->removeCreatedPageFolder($this->root . '/demo/pages/linked'));
            $this->assertDirectoryExists($this->root . '/outside');
        }
    }
}
