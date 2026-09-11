<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Phase 2 tests for HAXCMSSite::getSocialShareImage() (issue #3043).
 *
 * Rewired to read the uuid array from page.metadata.files, resolve each uuid
 * via the files.json datastore, and return the first image record's fullUrl.
 * Tolerates legacy object-shape entries on old pages.
 */
class SocialShareImageTest extends TestCase
{
    private $tmpDirs = array();
    private $savedHaxcms;

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = new SocialShareTestHaxcms();
        $this->tmpDirs = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
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
        $dir = sys_get_temp_dir() . '/socialshare-' . bin2hex(random_bytes(6));
        mkdir($dir . '/files', 0777, true);
        $this->tmpDirs[] = $dir;
        foreach ($files as $relativePath => $contents) {
            $fullPath = $dir . '/files/' . $relativePath;
            $parentDir = dirname($fullPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0777, true);
            }
            // If the file is an image extension, create a real GD image so
            // mime_content_type returns image/* (fake bytes get text/plain).
            $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif'), true) && function_exists('imagecreatetruecolor')) {
                $img = imagecreatetruecolor(10, 10);
                $color = imagecolorallocate($img, 255, 0, 0);
                imagefill($img, 0, 0, $color);
                if ($ext === 'png') {
                    imagepng($img, $fullPath);
                } elseif ($ext === 'gif') {
                    imagegif($img, $fullPath);
                } else {
                    imagejpeg($img, $fullPath);
                }
                imagedestroy($img);
            } else {
                file_put_contents($fullPath, $contents);
            }
        }
        $site = new SocialShareTestSite();
        $site->directory = $dir;
        $site->siteDirectory = $dir;
        $site->manifest = new SocialShareTestManifest();
        $site->manifest->metadata->site->name = 'socialshare-site';
        return $site;
    }

    // ------------------------------------------------------------------

    public function testResolvesUuidToImageFullUrl(): void
    {
        $site = $this->buildSite(array('banner.jpg' => 'jpg-bytes'));
        // Index the file so we have a uuid.
        $ds = new FilesDataStore($site);
        $uuid = $ds->resolveUuidByPath('files/banner.jpg');
        $this->assertNotSame('', $uuid);
        // Build a page with the uuid in metadata.files.
        $page = new stdClass();
        $page->id = 'page-1';
        $page->metadata = new stdClass();
        $page->metadata->files = array($uuid);
        $url = $site->getSocialShareImage($page);
        // The fullUrl should contain the path (exact format depends on basePath).
        $this->assertStringContainsString('files/banner.jpg', (string) $url);
    }

    public function testReturnsFirstImageFromMultipleUuids(): void
    {
        $site = $this->buildSite(array('doc.pdf' => 'pdf', 'photo.png' => 'png'));
        $ds = new FilesDataStore($site);
        $pdfUuid = $ds->resolveUuidByPath('files/doc.pdf');
        $pngUuid = $ds->resolveUuidByPath('files/photo.png');
        $page = new stdClass();
        $page->id = 'page-1';
        $page->metadata = new stdClass();
        $page->metadata->files = array($pdfUuid, $pngUuid);
        $url = $site->getSocialShareImage($page);
        // The image (png) should be picked, not the pdf.
        $this->assertStringContainsString('files/photo.png', (string) $url);
    }

    public function testToleratesLegacyObjectShape(): void
    {
        $site = $this->buildSite(array());
        $page = new stdClass();
        $page->id = 'page-1';
        $page->metadata = new stdClass();
        // Legacy object-shape entry (old page not yet re-saved).
        $legacyFile = new stdClass();
        $legacyFile->type = 'image/jpeg';
        $legacyFile->fullUrl = '/legacy/files/old-banner.jpg';
        $page->metadata->files = array($legacyFile);
        $url = $site->getSocialShareImage($page);
        $this->assertSame('/legacy/files/old-banner.jpg', $url);
    }

    public function testReturnsNullWhenNoImageFiles(): void
    {
        $site = $this->buildSite(array('doc.pdf' => 'pdf'));
        $ds = new FilesDataStore($site);
        $uuid = $ds->resolveUuidByPath('files/doc.pdf');
        $page = new stdClass();
        $page->id = 'page-1';
        $page->metadata = new stdClass();
        $page->metadata->files = array($uuid);
        $url = $site->getSocialShareImage($page);
        // No image in the set, and no theme banner => null (not set in cache).
        $this->assertNull($url);
    }
}

/**
 * HAXCMSSite subclass that exposes the directory/siteDirectory for datastore
 * resolution. getSocialShareImage is inherited from HAXCMSSite unchanged.
 */
class SocialShareTestSite extends HAXCMSSite
{
    // Inherited getSocialShareImage uses $this->manifest and $GLOBALS['HAXCMS'].
}

/**
 * Minimal manifest for the social share test.
 */
class SocialShareTestManifest
{
    public $items = array();
    public $metadata;
    public $title = 'Social Share Test';

    public function __construct()
    {
        $this->metadata = new stdClass();
        $this->metadata->site = new stdClass();
        $this->metadata->site->name = 'socialshare-site';
        $this->metadata->theme = new stdClass();
    }
}

/**
 * HAXCMS mock with staticCache for getSocialShareImage.
 */
class SocialShareTestHaxcms extends OperationsTestHaxcms
{
    private $staticData = array();

    public function &staticCache($name, $default_value = null, $reset = false)
    {
        if ($reset) {
            unset($this->staticData[$name]);
        }
        if (!array_key_exists($name, $this->staticData)) {
            $this->staticData[$name] = $default_value;
        }
        return $this->staticData[$name];
    }
}
