<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * convertHaxcmsToSite from an uploaded HAXcms site zip (haxtheweb/issues#3405).
 * PHP twin of haxcms-nodejs test/unit/convert-haxcms-zip-to-site.test.cjs:
 * the zip path gives the URL import's result (items with contents, files
 * staged under the bulk-import root), refuses anything that is not a HAX
 * site outline, never reads outside the folder that holds site.json, and
 * answers only while site zip import is on.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertHaxcmsToSite.php';

class ConvertHaxcmsZipToSiteTest extends TestCase
{
    private $savedHaxcms;
    private $savedFiles;
    private $savedEnv;
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
                'title' => 'Home',
                'location' => 'pages/home/index.html',
                'parent' => null,
                'indent' => 0,
                'order' => 0,
                'metadata' => array(
                    'files' => array(
                        array('url' => 'files/guestbook.gif'),
                        array('url' => 'files/missing.png'),
                    ),
                ),
            ),
        ),
    );

    protected function setUp(): void
    {
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedFiles = $_FILES;
        $this->savedEnv = getenv('HAXCMS_SITE_ZIP_IMPORT');
        // off unless turned on: these tests turn it on and check the off
        // answer on its own
        putenv('HAXCMS_SITE_ZIP_IMPORT=true');
        $this->tmpBase = sys_get_temp_dir() . '/haxcms_zip_test_' . uniqid();
        mkdir($this->tmpBase, 0755, true);
        $mock = new stdClass();
        $mock->configDirectory = $this->tmpBase . '/config';
        $GLOBALS['HAXCMS'] = $mock;
    }

    protected function tearDown(): void
    {
        $_FILES = $this->savedFiles;
        putenv($this->savedEnv === false ? 'HAXCMS_SITE_ZIP_IMPORT' : 'HAXCMS_SITE_ZIP_IMPORT=' . $this->savedEnv);
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
        $zip->addFromString($prefix . 'custom/build/custom.es6.js', '// custom comment script here');
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
        $this->assertSame('<p>Welcome to my homepage</p>', $data['items'][0]['contents']);
        $this->assertSame(array('files/guestbook.gif'), array_keys($data['files']));
        $staged = $data['files']['files/guestbook.gif'];
        $this->assertStringStartsWith($this->tmpBase . '/config/tmp/imports/', $staged);
        $this->assertSame('GIF89a-fake', file_get_contents($staged));
        // the untouched boilerplate custom build carries nothing
        $this->assertSame(array(), $data['siteFiles']);
    }

    public function testTheCustomBuildComesBackStagedInSiteFiles(): void
    {
        $response = $this->importZip($this->makeZip('', null, function ($zip) {
            $zip->addFromString('custom/build/custom.es6.js', 'export class GeoTheme {}');
        }));
        $this->assertSame(200, $response['status']);
        $staged = $response['data']['siteFiles']['custom/build/custom.es6.js'];
        $this->assertStringStartsWith($this->tmpBase . '/config/tmp/imports/', $staged);
        $this->assertSame('export class GeoTheme {}', file_get_contents($staged));
    }

    public function testSiteZipImportIsOffUnlessTheOperatorTurnsItOn(): void
    {
        putenv('HAXCMS_SITE_ZIP_IMPORT');
        $response = $this->importZip($this->makeZip(''));
        $this->assertSame(403, $response['status']);
        $this->assertSame('DISABLED', $response['data']['reason']);
        $this->assertSame('site-zip-import is disabled', $response['data']['error']);
        // config security.siteZipImport turns it on too
        $GLOBALS['HAXCMS']->config = new stdClass();
        $GLOBALS['HAXCMS']->config->security = new stdClass();
        $GLOBALS['HAXCMS']->config->security->siteZipImport = true;
        $this->assertSame(200, $this->importZip($this->makeZip(''))['status']);
    }

    public function testAnUploadOverTheConversionCapIsRefusedBeforeItIsRead(): void
    {
        $GLOBALS['HAXCMS']->config = new stdClass();
        $GLOBALS['HAXCMS']->config->security = new stdClass();
        // a cap smaller than the zip
        $GLOBALS['HAXCMS']->config->security->conversionMaxUploadMb = 0.0001;
        $response = $this->importZip($this->makeZip(''));
        $this->assertSame(400, $response['status']);
        $this->assertSame('ZIP_BUDGET', $response['data']['reason']);
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

    public static function provideNotASite(): array
    {
        return array(
            'no items' => array(function (&$site) { unset($site['items']); }),
            'no pages' => array(function (&$site) { $site['items'] = array(); }),
            'a page outside pages/' => array(function (&$site) { $site['items'][0]['location'] = '../outside.html'; }),
            'a page that is not .html' => array(function (&$site) { $site['items'][0]['location'] = 'pages/home/index.php'; }),
            'a file outside files/' => array(function (&$site) { $site['items'][0]['metadata']['files'][] = array('url' => '../../etc/passwd'); }),
            'a file given as an address' => array(function (&$site) { $site['items'][0]['metadata']['files'][] = array('url' => 'https://example.com/a.png'); }),
            'two pages with one id' => array(function (&$site) { $site['items'][] = $site['items'][0]; }),
            'an id with unsafe characters' => array(function (&$site) { $site['items'][0]['id'] = '../item'; }),
            'a title that is not text' => array(function (&$site) { $site['items'][0]['title'] = array('a' => 1); }),
        );
    }

    #[DataProvider('provideNotASite')]
    public function testASiteJsonThatIsNotAHaxSiteIsRefusedWhole(callable $change): void
    {
        $site = self::SITE_JSON;
        $change($site);
        $this->assertFalse(HAXCMSSiteZip::isSiteOutline($site));
        $response = $this->importZip($this->makeZip('', $site));
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
