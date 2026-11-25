<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * convertHaxcmsToSite from an uploaded HAXcms site zip (haxtheweb/issues#3405).
 * PHP twin of haxcms-nodejs test/unit/convert-haxcms-zip-to-site.test.cjs:
 * the zip path gives the URL import's result (items with contents, files
 * staged under the bulk-import root) and never reads outside the folder that
 * holds site.json.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertHaxcmsToSite.php';

class ConvertHaxcmsZipToSiteTest extends TestCase
{
    private $savedHaxcms;
    private $savedFiles;
    private $tmpBase;

    private const SITE_JSON = array(
        'title' => 'My 90s page',
        'description' => 'Under construction',
        'metadata' => array(
            'site' => array('name' => 'my-90s-page'),
            'theme' => array('element' => 'clean-one'),
        ),
        'items' => array(
            array(
                'id' => 'item-1',
                'location' => 'pages/home/index.html',
                'metadata' => array(
                    'files' => array(
                        array('url' => 'files/guestbook.gif'),
                        array('url' => 'files/missing.png'),
                        array('url' => '../../etc/passwd'),
                        array('url' => 'https://example.com/a.png'),
                    ),
                ),
            ),
            array('id' => 'item-2', 'location' => '../outside.html'),
        ),
    );

    protected function setUp(): void
    {
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedFiles = $_FILES;
        $this->tmpBase = sys_get_temp_dir() . '/haxcms_zip_test_' . uniqid();
        mkdir($this->tmpBase, 0755, true);
        $mock = new stdClass();
        $mock->configDirectory = $this->tmpBase . '/config';
        $GLOBALS['HAXCMS'] = $mock;
    }

    protected function tearDown(): void
    {
        $_FILES = $this->savedFiles;
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpBase);
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
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeZip(string $prefix, ?array $siteJson = null, ?callable $extra = null): string
    {
        $path = $this->tmpBase . '/upload-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString($prefix . 'site.json', json_encode($siteJson === null ? self::SITE_JSON : $siteJson));
        $zip->addFromString($prefix . 'pages/home/index.html', '<p>Welcome to my homepage</p>');
        $zip->addFromString($prefix . 'files/guestbook.gif', 'GIF89a-fake');
        $zip->addFromString('outside.html', '<p>outside the site folder</p>');
        if ($extra !== null) {
            $extra($zip);
        }
        $zip->close();
        return $path;
    }

    private function importZip(string $path): array
    {
        $_FILES = array('file' => array('name' => 'site.zip', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)));
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = array();
        ob_start();
        haxcmsImportConvertHaxcmsToSite($context);
        return json_decode(ob_get_clean(), true);
    }

    public static function provideRoots(): array
    {
        return array('at the top' => array(''), 'inside a folder' => array('my-90s-page/'));
    }

    #[DataProvider('provideRoots')]
    public function testSiteZipImportsLikeTheUrlImport(string $prefix): void
    {
        $response = $this->importZip($this->makeZip($prefix));
        $this->assertSame(200, $response['status']);
        $data = $response['data'];
        $this->assertSame('my-90s-page', $data['filename']);
        $this->assertSame(array('title' => 'My 90s page', 'description' => 'Under construction', 'theme' => 'clean-one'), $data['site']);
        $this->assertSame(array(), $data['siteFiles']);
        $this->assertSame('<p>Welcome to my homepage</p>', $data['items'][0]['contents']);
        $this->assertSame('', $data['items'][1]['contents']);
        $this->assertSame(array('files/guestbook.gif'), array_keys($data['files']));
        $staged = $data['files']['files/guestbook.gif'];
        $this->assertStringStartsWith($this->tmpBase . '/config/tmp/imports/', $staged);
        $this->assertSame('GIF89a-fake', file_get_contents($staged));
    }

    public function testAFileThatIsNotAZipIsRefused(): void
    {
        $path = $this->tmpBase . '/not-a-zip.zip';
        file_put_contents($path, '<html>not a zip</html>');
        $response = $this->importZip($path);
        $this->assertSame(400, $response['status']);
        $this->assertSame('NOT_A_ZIP', $response['data']['reason']);
    }

    public function testAZipWithoutSiteJsonIsRefused(): void
    {
        $path = $this->tmpBase . '/plain.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('index.html', '<p>hi</p>');
        $zip->close();
        $response = $this->importZip($path);
        $this->assertSame(400, $response['status']);
        $this->assertSame('INVALID_SITE', $response['data']['reason']);
    }

    public function testSiteJsonDeeperThanOneFolderIsNotASiteZip(): void
    {
        $path = $this->tmpBase . '/deep.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('a/b/site.json', json_encode(self::SITE_JSON));
        $zip->close();
        $response = $this->importZip($path);
        $this->assertSame(400, $response['status']);
        $this->assertSame('INVALID_SITE', $response['data']['reason']);
    }

    public function testSiteJsonWithoutItemsIsRefused(): void
    {
        $response = $this->importZip($this->makeZip('', array('title' => 'x')));
        $this->assertSame(400, $response['status']);
        $this->assertSame('INVALID_SITE', $response['data']['reason']);
    }

    public function testPageContentComesBackAsWritten(): void
    {
        $response = $this->importZip($this->makeZip('', null, function ($zip) {
            $zip->addFromString('pages/home/index.html', '<p>hi</p><script>alert(1)</script>');
        }));
        $this->assertSame(200, $response['status']);
        $this->assertSame('<p>hi</p><script>alert(1)</script>', $response['data']['items'][0]['contents']);
    }

    public function testSiteRelativePathKeepsPathsInsideTheSiteFolder(): void
    {
        $this->assertSame('files/a.png', HAXCMSSiteZip::siteRelativePath('files/a.png'));
        $this->assertSame('files/a.png', HAXCMSSiteZip::siteRelativePath('./files/a.png?x=1#y'));
        $this->assertSame('files/a.png', HAXCMSSiteZip::siteRelativePath('files/../files/a.png'));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath('../a.png'));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath('files/../../a.png'));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath('/etc/passwd'));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath('https://example.com/a.png'));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath('files\\a.png'));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath(''));
        $this->assertSame('', HAXCMSSiteZip::siteRelativePath(null));
    }
}
