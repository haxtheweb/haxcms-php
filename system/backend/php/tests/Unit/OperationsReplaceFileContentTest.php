<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Operations whose upload-source check accepts a fixture file, since
 * is_uploaded_file() is false outside a real HTTP upload.
 */
class ReplaceFileContentTestOperations extends Operations
{
    protected function isReplaceUploadSource($tmpPath)
    {
        return is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath);
    }
}

/**
 * replaceFileContent (POST /x/api/v1/files/{fileUuid}/content),
 * haxtheweb/issues#1149: replace an image's contents with an uploaded image,
 * keeping its path, name and format. Mirrors the haxcms-nodejs tests in
 * test/unit/files-routes-handlers.test.cjs (describe replaceFileContent).
 */
class OperationsReplaceFileContentTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'replace-site';
    private $siteRoot;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_fileops_root');
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_replace_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        mkdir($this->siteRoot . '/files', 0777, true);
        $this->writePng($this->siteRoot . '/files/banner.png', 240, 160);
        file_put_contents(
            $this->siteRoot . '/files/logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'
        );
        $manifest = (object) array(
            'id' => 'site-replace-uuid',
            'title' => 'Replace Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->siteName,
                    'settings' => (object) array('pathauto' => false),
                    'created' => time(),
                    'updated' => time(),
                ),
                'platform' => (object) array('features' => (object) array()),
            ),
            'items' => array(),
        );
        file_put_contents($this->siteRoot . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->haxcms = new ReplaceFileContentTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $site = new ReplaceFileContentTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->ops = new ReplaceFileContentTestOperations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        $this->rrmdir($this->tmpRoot);
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

    private function writePng(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
        imagepng($img, $path);
        imagedestroy($img);
    }

    /** an upload as PHP leaves it: a temp file outside files/ */
    private function makeUpload(int $width, int $height): array
    {
        $tmp = $this->tmpRoot . '/upload-' . uniqid();
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 128, 0));
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);
        return array('name' => 'crop.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp));
    }

    private function validParams(string $path): array
    {
        $this->haxcms->validRequestToken = true;
        return array(
            'site_token' => 'good',
            'siteName' => $this->siteName,
            'path' => $path,
        );
    }

    public function testReplacesAPngInPlaceKeepingPathAndFormat(): void
    {
        $this->ops->params = $this->validParams('files/banner.png');
        // a JPG upload still lands as a PNG: re-encoded into the file's format
        $this->ops->rawParams = array('upload' => $this->makeUpload(160, 90));
        $result = $this->ops->replaceFileContent();
        $this->assertSame(200, $result['status']);
        $this->assertSame('replace-content', $result['data']['operation']);
        $this->assertSame('files/banner.png', $result['data']['path']);
        $info = getimagesize($this->siteRoot . '/files/banner.png');
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertSame(160, $info[0]);
        $this->assertSame(90, $info[1]);
        $site = $this->haxcms->loadedSite;
        $this->assertSame(array('File content replaced: files/banner.png'), $site->gitCommits);
    }

    public function testRequiresASiteToken(): void
    {
        $this->ops->params = array('siteName' => $this->siteName, 'path' => 'files/banner.png');
        $this->ops->rawParams = array('upload' => $this->makeUpload(10, 10));
        $result = $this->ops->replaceFileContent();
        $this->assertSame(403, $result['__failed']['status']);
    }

    public function testRejectsARequestWithoutAnUpload(): void
    {
        $this->ops->params = $this->validParams('files/banner.png');
        $result = $this->ops->replaceFileContent();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Missing file upload', $result['__failed']['message']);
    }

    public function testRejectsFormatsItCannotRoundTripAndUploadsThatAreNotImages(): void
    {
        $this->ops->params = $this->validParams('files/logo.svg');
        $this->ops->rawParams = array('upload' => $this->makeUpload(10, 10));
        $result = $this->ops->replaceFileContent();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Only JPG, PNG and WebP images can be replaced', $result['__failed']['message']);
        // a text file posing as an image
        $fake = $this->tmpRoot . '/fake-' . uniqid();
        file_put_contents($fake, '<script>alert(1)</script>');
        $this->ops->params = $this->validParams('files/banner.png');
        $this->ops->rawParams = array('upload' => array('tmp_name' => $fake, 'error' => UPLOAD_ERR_OK));
        $result = $this->ops->replaceFileContent();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('The uploaded file must be a JPG, PNG or WebP image', $result['__failed']['message']);
        $info = getimagesize($this->siteRoot . '/files/banner.png');
        $this->assertSame(240, $info[0]);
        $this->assertSame(array(), $this->haxcms->loadedSite->gitCommits);
    }

    public function testRejectsPathsOutsideFiles(): void
    {
        $this->ops->params = $this->validParams('../site.json');
        $this->ops->rawParams = array('upload' => $this->makeUpload(10, 10));
        $result = $this->ops->replaceFileContent();
        $this->assertSame(400, $result['__failed']['status']);
    }
}

class ReplaceFileContentTestHaxcms extends OperationsTestHaxcms
{
}

/** HAXCMSSite that records commits instead of running git */
class ReplaceFileContentTestSite extends HAXCMSSite
{
    public $gitCommits = array();

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
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
}
