<?php
/**
 * docx-to-html with the page the document goes into (haxtheweb/issues#3058).
 *
 * Mirrors haxcms-nodejs test/unit/convertDocxToHtml-page-images.test.cjs:
 * with siteName + itemId naming a real page, images in the document are saved
 * as files of that site, named after the page, and the HTML points at them.
 * Without a page (or one that does not resolve) images stay inline data URIs.
 *
 * Each test runs in its own process: the route file defines unguarded
 * functions shared with importDocx.php (see SystemRoutesConvertDocxToHtmlTest).
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';

class SystemRoutesConvertDocxToHtmlPageImagesTest extends TestCase
{
    /** 1x1 PNG (same payload the Node tests use). */
    private $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    private $siteName = 'docx-page-site';
    private $siteRoot;
    private $tmpRoot;
    private $docxPath;
    /** the route closure, required once per process (unguarded functions) */
    private static $handler = null;

    protected function setUp(): void
    {
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_docxpage_root_' . getmypid());
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_docxpage_' . uniqid();
        @mkdir($this->tmpRoot . '/_config/tmp/imports', 0777, true);
        $this->siteRoot = HAXCMS_ROOT . '/_sites/' . $this->siteName;
        @mkdir($this->siteRoot . '/files', 0777, true);
        @mkdir($this->siteRoot . '/pages', 0777, true);

        require_once dirname(__DIR__, 2) . '/lib/MaterializeInlineImages.php';
        require_once dirname(__DIR__, 2) . '/lib/HAXCMSFile.php';
        require_once dirname(__DIR__, 2) . '/lib/EntityRegistry.php';
        require_once dirname(__DIR__, 2) . '/lib/FileStorage.php';
        require_once dirname(__DIR__, 2) . '/lib/FileEntity.php';
        require_once dirname(__DIR__, 2) . '/lib/FilesDataStore.php';
        require_once dirname(__DIR__, 2) . '/lib/SanitizeContent.php';

        $site = new DocxPageTestSite();
        $site->name = $this->siteName;
        $site->directory = HAXCMS_ROOT . '/_sites';
        $site->siteDirectory = $this->siteRoot;
        $site->manifest = new stdClass();
        $item = new stdClass();
        $item->id = 'item-1';
        $item->title = 'Intro to HAX';
        $item->metadata = new stdClass();
        $site->manifest->items = array($item);
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = $this->siteName;

        $haxcms = new DocxPageTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        $haxcms->sitesDirectory = '_sites';
        $haxcms->sites = array($this->siteName => $site);
        $GLOBALS['HAXCMS'] = $haxcms;

        $this->docxPath = $this->buildImageDocx();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS']);
        $this->rrmdir($this->tmpRoot);
        $this->rrmdir($this->siteRoot);
        http_response_code(200);
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function buildImageDocx()
    {
        $path = $this->tmpRoot . '/upload.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body><w:p><w:r><w:t>Before</w:t></w:r></w:p>'
            . '<w:p><w:r><w:drawing><wp:inline'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<wp:docPr id="1" name="Picture 1" descr="Red"/><a:graphic>'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:blipFill><a:blip r:embed="rId7"/></pic:blipFill></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'
            . '</w:body></w:document>'
        );
        $zip->addFromString(
            'word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            . '</Relationships>'
        );
        $zip->addFromString('word/media/image1.png', base64_decode($this->pngBase64));
        $zip->close();
        return $path;
    }

    private function runRoute($body)
    {
        $_FILES = array(
            'upload' => array('name' => 'upload.docx', 'tmp_name' => $this->docxPath, 'size' => filesize($this->docxPath)),
        );
        $context = (new ReflectionClass('SystemApiRequestContext'))
            ->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = 'v1/convertDocxToHtml';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = array();
        http_response_code(200);
        ob_start();
        if (self::$handler === null) {
            self::$handler = require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/convertDocxToHtml.php';
        }
        $handler = self::$handler;
        $handler($context);
        $raw = ob_get_clean();
        return json_decode($raw, true);
    }

    private function savedImages()
    {
        $out = array();
        foreach (scandir($this->siteRoot . '/files') as $name) {
            if (preg_match('/\.(png|jpg|gif|webp)$/i', $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutAPageImagesStayInline(): void
    {
        $result = $this->runRoute(array());
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('src="data:image/png;base64,', $result['data']['contents']);
        $this->assertSame(array(), $this->savedImages());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithAPageImagesAreSavedAsSiteFilesNamedAfterIt(): void
    {
        $result = $this->runRoute(array('siteName' => $this->siteName, 'itemId' => 'item-1'));
        $this->assertSame(200, $result['status']);
        $html = $result['data']['contents'];
        $this->assertStringNotContainsString('data:image', $html);
        $files = $this->savedImages();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^intro-to-hax\./', $files[0]);
        $this->assertStringContainsString($files[0], $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnItemNotInTheSiteLeavesImagesInline(): void
    {
        $result = $this->runRoute(array('siteName' => $this->siteName, 'itemId' => 'nope'));
        $this->assertStringContainsString('src="data:image/png;base64,', $result['data']['contents']);
        $this->assertSame(array(), $this->savedImages());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASiteThatDoesNotLoadLeavesImagesInline(): void
    {
        $result = $this->runRoute(array('siteName' => 'other', 'itemId' => 'item-1'));
        $this->assertStringContainsString('src="data:image/png;base64,', $result['data']['contents']);
        $this->assertSame(array(), $this->savedImages());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlySiteNameOrOnlyItemIdIsNoPage(): void
    {
        foreach (array(array('siteName' => $this->siteName), array('itemId' => 'item-1')) as $body) {
            $result = $this->runRoute($body);
            $this->assertStringContainsString('src="data:image/png;base64,', $result['data']['contents']);
        }
        $this->assertSame(array(), $this->savedImages());
    }
}

/** HAXCMS stand-in whose loadSite answers from a fixed map. */
class DocxPageTestHaxcms extends OperationsTestHaxcms
{
    public $sites = array();
    public $sitesDirectory = '_sites';

    public function loadSite($name, $create = false, $domain = null, $build = null)
    {
        return isset($this->sites[$name]) ? $this->sites[$name] : false;
    }
}

/** Site double: the manifest plus loadNode, as HAXCMSSite has it. */
class DocxPageTestSite
{
    public $name;
    public $directory;
    public $siteDirectory;
    public $manifest;

    public function loadNode($uuid)
    {
        foreach ($this->manifest->items as $item) {
            if ($item->id === $uuid && $uuid !== '') {
                return $item;
            }
        }
        return false;
    }
}
