<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Unit tests for MaterializeInlineImages (#2945 / #3043).
 *
 * Mirrors haxcms-nodejs/test/unit/materializeInlineImages.test.cjs:
 * create via HAXCMSFile bulk-import, associate via FileEntity only,
 * page-title names, PNG→JPG + xl resize + q90.
 */
class MaterializeInlineImagesTest extends TestCase
{
    private $haxcms;
    private $site;
    private $savedHaxcms;
    private $tmpRoot;
    private $siteName = 'mii-site';
    private $siteRoot;
    private $configDir;

    /** 1x1 PNG base64 (same payload Node tests use). */
    private $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_mii_root_' . getmypid());
        }
        if (!is_dir(HAXCMS_ROOT)) {
            @mkdir(HAXCMS_ROOT, 0777, true);
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_mii_' . uniqid();
        $this->configDir = $this->tmpRoot . '/_config';
        @mkdir($this->configDir . '/tmp/imports', 0777, true);

        // HAXCMSFile::save writes to HAXCMS_ROOT/_sites/<name>/files/
        $this->siteRoot = HAXCMS_ROOT . '/_sites/' . $this->siteName;
        @mkdir($this->siteRoot . '/files', 0777, true);
        @mkdir($this->siteRoot . '/pages', 0777, true);

        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->configDir;
        $this->haxcms->sitesDirectory = '_sites';
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $this->site = new MiiTestSite();
        $this->site->name = $this->siteName;
        $this->site->directory = HAXCMS_ROOT . '/_sites';
        $this->site->siteDirectory = $this->siteRoot;
        $this->site->manifest = new stdClass();
        $this->site->manifest->items = array();
        $this->site->manifest->metadata = new stdClass();
        $this->site->manifest->metadata->site = new stdClass();
        $this->site->manifest->metadata->site->name = $this->siteName;
        $this->site->manifestSaves = array();
        $self = $this->site;
        $this->site->manifest->save = function () use ($self) {
            $filesLists = array();
            foreach ($self->manifest->items as $item) {
                $files = array();
                if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->files) && is_array($item->metadata->files)) {
                    $files = $item->metadata->files;
                }
                $filesLists[] = $files;
            }
            $self->manifestSaves[] = $filesLists;
            return true;
        };
        $this->site->manifest->addItem = function ($item) use ($self) {
            $self->manifest->items[] = $item;
        };

        // Ensure ImageResize is loadable
        require_once dirname(__DIR__, 2) . '/lib/MaterializeInlineImages.php';
        require_once dirname(__DIR__, 2) . '/lib/HAXCMSFile.php';
        require_once dirname(__DIR__, 2) . '/lib/EntityRegistry.php';
        require_once dirname(__DIR__, 2) . '/lib/FileStorage.php';
        require_once dirname(__DIR__, 2) . '/lib/FileEntity.php';
        require_once dirname(__DIR__, 2) . '/lib/FilesDataStore.php';
        require_once dirname(__DIR__, 2) . '/lib/SanitizeContent.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        }
        else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpRoot);
        if (is_dir($this->siteRoot)) {
            $this->rrmdir($this->siteRoot);
        }
        // parent _sites dir if empty
        $sites = HAXCMS_ROOT . '/_sites';
        if (is_dir($sites) && count(scandir($sites)) <= 2) {
            @rmdir($sites);
        }
    }

    private function rrmdir($dir)
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
            }
            else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function pngDataUri()
    {
        return 'data:image/png;base64,' . $this->pngBase64;
    }

    private function otherPngDataUri()
    {
        $img = imagecreatetruecolor(2, 2);
        $blue = imagecolorallocate($img, 0, 0, 255);
        imagefill($img, 0, 0, $blue);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private function savedImages()
    {
        $dir = $this->siteRoot . '/files';
        if (!is_dir($dir)) {
            return array();
        }
        $out = array();
        foreach (scandir($dir) as $name) {
            if (preg_match('/\.(png|jpg|gif|webp)$/i', $name)) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    private function loadFileEntity($relativePath)
    {
        $registry = new EntityRegistry($this->site);
        $storage = FileStorage::registerOn($registry);
        $uuid = $storage->getDataStore()->resolveUuidByPath($relativePath);
        return array(
            'uuid' => $uuid,
            'entity' => $uuid ? $storage->load($uuid) : null,
        );
    }

    public function testFileNameBaseFromPageTitleCleansTitles(): void
    {
        $this->assertSame('intro-to-hax', MaterializeInlineImages::fileNameBaseFromPageTitle('Intro to HAX'));
        $this->assertSame('hello-world', MaterializeInlineImages::fileNameBaseFromPageTitle('  Hello, World!  '));
        $this->assertSame('page', MaterializeInlineImages::fileNameBaseFromPageTitle(''));
        $this->assertSame('page', MaterializeInlineImages::fileNameBaseFromPageTitle(null));
    }

    public function testSavesInlinePngAsJpgNamedFromPageTitle(): void
    {
        $result = MaterializeInlineImages::materialize(
            '<p>Before</p><p><img alt="A red square" src="' . $this->pngDataUri() . '"></p>',
            $this->site,
            array('pageTitle' => 'Intro to HAX')
        );
        $files = $this->savedImages();
        $this->assertCount(1, $files);
        $this->assertSame('intro-to-hax.jpg', $files[0]);
        $this->assertStringContainsString(
            'source="files/intro-to-hax.jpg"',
            $result['html']
        );
        $this->assertStringContainsString('media-image', $result['html']);
        $this->assertCount(1, $result['uuids']);

        $loaded = $this->loadFileEntity('files/intro-to-hax.jpg');
        $this->assertNotSame('', $loaded['uuid']);
        $this->assertInstanceOf(FileEntity::class, $loaded['entity']);
        $this->assertSame(array($loaded['uuid']), $result['uuids']);
        $this->assertSame('image/jpeg', $loaded['entity']->getMimetype());
        $this->assertTrue($loaded['entity']->isImage());
    }

    public function testRepeatedImageSavedOnce(): void
    {
        $uri = $this->pngDataUri();
        $result = MaterializeInlineImages::materialize(
            '<p><img src="' . $uri . '"></p><p><img src="' . $uri . '"></p>',
            $this->site,
            array('pageTitle' => 'Repeat')
        );
        $this->assertCount(1, $this->savedImages());
        $this->assertCount(1, $result['uuids']);
        $this->assertSame(2, substr_count($result['html'], 'source="files/repeat.jpg"'));
    }

    public function testUnsupportedMimeBecomesPlaceholder(): void
    {
        $result = MaterializeInlineImages::materialize(
            '<p><img alt="vector chart" src="data:image/x-emf;base64,' . base64_encode('emf') . '"></p>',
            $this->site
        );
        $this->assertSame(array(), $this->savedImages());
        $this->assertSame(array(), $result['uuids']);
        $this->assertStringContainsString('place-holder', $result['html']);
        $this->assertStringContainsString('type="image"', $result['html']);
    }

    public function testLeavesNonDataImagesUntouched(): void
    {
        $html = '<p><img src="files/banner.jpg" alt="Banner"></p>';
        $result = MaterializeInlineImages::materialize($html, $this->site);
        $this->assertSame($html, $result['html']);
        $this->assertSame(array(), $result['uuids']);
        $this->assertSame(array(), $this->savedImages());
    }

    public function testNonStringPassthrough(): void
    {
        $u = MaterializeInlineImages::materialize(null, $this->site);
        $this->assertNull($u['html']);
        $this->assertSame(array(), $u['uuids']);
    }

    public function testOversizedPngIsResizedAndStoredAsJpg(): void
    {
        $img = imagecreatetruecolor(2400, 1800);
        $c = imagecolorallocate($img, 10, 20, 30);
        imagefill($img, 0, 0, $c);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);
        $uri = 'data:image/png;base64,' . base64_encode($bytes);

        $result = MaterializeInlineImages::materialize(
            '<p><img src="' . $uri . '"></p>',
            $this->site,
            array('pageTitle' => 'Wide Diagram')
        );
        $files = $this->savedImages();
        $this->assertCount(1, $files);
        $this->assertSame('wide-diagram.jpg', $files[0]);
        $info = getimagesize($this->siteRoot . '/files/' . $files[0]);
        $this->assertNotFalse($info);
        $this->assertLessThanOrEqual(MaterializeInlineImages::MAX_WIDTH, $info[0]);
        $this->assertLessThanOrEqual(MaterializeInlineImages::MAX_HEIGHT, $info[1]);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertCount(1, $result['uuids']);
    }

    public function testAddPageAssociatesFileEntityUuids(): void
    {
        // Use real HAXCMSSite methods where possible; stub git/alt formats.
        require_once dirname(__DIR__, 2) . '/lib/HAXCMSSite.php';
        require_once dirname(__DIR__, 2) . '/lib/JSONOutlineSchemaItem.php';

        $site = new MiiHaxcmsSite();
        $site->name = $this->siteName;
        $site->directory = HAXCMS_ROOT . '/_sites';
        $site->siteDirectory = $this->siteRoot;
        $site->manifest = new MiiManifest();
        $site->manifest->items = array();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = $this->siteName;
        $site->manifest->metadata->site->updated = time();

        $page = $site->addPage(
            null,
            'Imported Page',
            'html',
            'imported-page',
            null,
            null,
            '<p><img alt="chart" src="' . $this->pngDataUri() . '"></p>'
        );

        $files = $this->savedImages();
        $this->assertNotEmpty($files);
        $this->assertTrue((bool) preg_match('/\.jpg$/i', $files[0]));
        $this->assertIsObject($page->metadata);
        $this->assertIsArray($page->metadata->files);
        $this->assertNotEmpty($page->metadata->files);

        $loaded = $this->loadFileEntity('files/' . $files[0]);
        // siteDirectory for loadFileEntity uses $this->site — point it at same dir
        $this->site->siteDirectory = $this->siteRoot;
        $loaded = $this->loadFileEntity('files/' . $files[0]);
        $this->assertSame(array($loaded['uuid']), $page->metadata->files);

        $location = $this->siteRoot . '/' . $page->location;
        $this->assertTrue(file_exists($location));
        $content = file_get_contents($location);
        $this->assertStringContainsString('media-image', $content);
        $this->assertStringNotContainsString('data:image', $content);
    }
}

/**
 * Minimal site double for helper-level tests.
 */
class MiiTestSite
{
    public $name;
    public $directory;
    public $siteDirectory;
    public $manifest;
    public $manifestSaves = array();
}

/**
 * HAXCMSSite subclass that skips git/alt formats and uses a simple manifest.
 */
class MiiHaxcmsSite extends HAXCMSSite
{
    public $siteDirectory;

    public function gitCommit($msg = 'Committed changes')
    {
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }

    public function getUniqueSlugName($slug, $item = null, $pathauto = false)
    {
        return $slug !== '' ? $slug : 'page';
    }
}

class MiiManifest
{
    public $items = array();
    public $metadata;
    public $saves = 0;

    public function addItem($item)
    {
        $this->items[] = $item;
    }

    public function save($reorder = true)
    {
        $this->saves++;
        return true;
    }
}
