<?php
use PHPUnit\Framework\TestCase;

include_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteRouteUtils.php';
include_once dirname(__DIR__, 2) . '/lib/siteRoutes/v1/ExportConverters.php';

/**
 * Security (SEC-SSRF): DOCX export must never let PhpWord's
 * Html::parseImage fetch an <img src> itself (raw @file_get_contents ->
 * SSRF for URLs, arbitrary / cross-site file read for paths). Every image is
 * resolved by ExportConverters into an inline data: URI (site files via
 * resolveLocalImageFile, remote via SsrfGuard) or dropped, and
 * renderDocxFromHtml re-checks the tree PhpWord will actually parse.
 */
class ExportConvertersDocxImagesTest extends TestCase
{
    private $tmpRoot;
    private $siteDir;
    private $outsideDir;
    private $sitePng;
    private $secretPng;

    private static function makePng($w, $h, $rgb)
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to build fixture images');
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_docximg_' . uniqid();
        $this->siteDir = $this->tmpRoot . '/sites/mysite';
        $this->outsideDir = $this->tmpRoot . '/sites/othersite';
        mkdir($this->siteDir . '/files', 0777, true);
        mkdir($this->outsideDir . '/files', 0777, true);
        $this->sitePng = self::makePng(3, 2, array(0, 128, 0));
        $this->secretPng = self::makePng(7, 5, array(200, 0, 0));
        file_put_contents($this->siteDir . '/files/ok.png', $this->sitePng);
        file_put_contents($this->siteDir . '/files/my image.png', $this->sitePng);
        file_put_contents($this->siteDir . '/files/notes.txt', 'not an image');
        file_put_contents($this->outsideDir . '/files/secret.png', $this->secretPng);
        $this->siteDir = realpath($this->siteDir);
        $this->outsideDir = realpath($this->outsideDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir($dir)
    {
        if (!is_string($dir) || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            if (is_dir($p) && !is_link($p)) {
                $this->rrmdir($p);
            }
            else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function dataUri($bytes)
    {
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private function imgSrcs($html)
    {
        preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/i', $html, $m);
        return $m[1];
    }

    // ---------------------------------------------------------------
    // resolveDocxImageDataUri / resolveDocxImageSources
    // ---------------------------------------------------------------

    public function testSiteRelativeAndBasePathImagesAreInlined(): void
    {
        foreach (array('files/ok.png', '/mysite/files/ok.png', '/files/ok.png', 'ok.png', 'files/ok.png?v=3#x', 'files/my%20image.png') as $src) {
            $this->assertSame(
                $this->dataUri($this->sitePng),
                ExportConverters::resolveDocxImageDataUri($src, '/mysite/', $this->siteDir),
                $src
            );
        }
    }

    public function testPathsOutsideTheSiteAreDropped(): void
    {
        $sources = array(
            $this->outsideDir . '/files/secret.png',        // absolute filesystem path (other site)
            '../othersite/files/secret.png',
            'files/../../othersite/files/secret.png',
            '/mysite/../othersite/files/secret.png',
            '%2e%2e/othersite/files/secret.png',
            '/etc/passwd',
            "files/ok.png\0.jpg",
        );
        foreach ($sources as $src) {
            $this->assertSame('', ExportConverters::resolveDocxImageDataUri($src, '/mysite/', $this->siteDir), $src);
        }
    }

    public function testNonHttpSchemesAreDropped(): void
    {
        $sources = array(
            'file://' . $this->outsideDir . '/files/secret.png',
            'FILE:///etc/passwd',
            'php://filter/resource=' . $this->outsideDir . '/files/secret.png',
            'phar:///tmp/x.phar/a.png',
            'ftp://8.8.8.8/a.png',
            'javascript:alert(1)',
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'data:text/html;base64,PHNjcmlwdD4=',
            'C:\\Windows\\win.ini',
            '\\\\server\\share\\a.png',
        );
        foreach ($sources as $src) {
            $this->assertSame('', ExportConverters::resolveDocxImageDataUri($src, '/mysite/', $this->siteDir), $src);
        }
    }

    public function testNonImageLocalFileIsDropped(): void
    {
        $this->assertSame('', ExportConverters::resolveDocxImageDataUri('files/notes.txt', '/mysite/', $this->siteDir));
    }

    public function testWithoutASiteDirectoryLocalImagesAreDropped(): void
    {
        $this->assertSame('', ExportConverters::resolveDocxImageDataUri('files/ok.png', '/', ''));
    }

    public function testDataUrisAreRevalidated(): void
    {
        $this->assertSame($this->dataUri($this->sitePng), ExportConverters::resolveDocxImageDataUri($this->dataUri($this->sitePng), '/', ''));
        $this->assertSame('', ExportConverters::resolveDocxImageDataUri('data:image/png;base64,' . base64_encode('not a png'), '/', ''));
        $this->assertSame('', ExportConverters::resolveDocxImageDataUri('data:image/png;base64,@@@', '/', ''));
    }

    public function testRemoteImagesGoThroughTheFetcherWithTheCap(): void
    {
        $calls = array();
        $png = $this->sitePng;
        $fetcher = function ($url, $maxBytes) use (&$calls, $png) {
            $calls[] = array($url, $maxBytes);
            return $png;
        };
        $this->assertSame($this->dataUri($png), ExportConverters::resolveDocxImageDataUri('https://cdn.example.org/a.png', '/', '', $fetcher));
        $this->assertSame($this->dataUri($png), ExportConverters::resolveDocxImageDataUri('//cdn.example.org/b.png', '/', '', $fetcher));
        $this->assertSame(array(
            array('https://cdn.example.org/a.png', SsrfGuard::DEFAULT_MAX_RESPONSE_BYTES),
            array('https://cdn.example.org/b.png', SsrfGuard::DEFAULT_MAX_RESPONSE_BYTES),
        ), $calls);
    }

    public function testRemoteFetchFailuresAndNonImagesAreDropped(): void
    {
        $throws = function () { throw new SsrfGuardException('nope', 'SSRF_TOO_LARGE'); };
        $html = function () { return '<html>not an image</html>'; };
        $this->assertSame('', ExportConverters::resolveDocxImageDataUri('https://cdn.example.org/a.png', '/', '', $throws));
        $this->assertSame('', ExportConverters::resolveDocxImageDataUri('https://cdn.example.org/a.png', '/', '', $html));
    }

    public function testDefaultRemoteFetchRefusesPrivateTargetsWithoutNetwork(): void
    {
        foreach (array('http://127.0.0.1/x.png', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/x.png', 'http://[64:ff9b::7f00:1]/x.png') as $src) {
            $this->assertSame('', ExportConverters::resolveDocxImageDataUri($src, '/', ''), $src);
        }
    }

    public function testResolveDocxImageSourcesRewritesKeepsAndDrops(): void
    {
        $html = '<p>a</p><img src="files/ok.png" alt="ok" srcset="http://169.254.169.254/x 2x" sizes="10px"/>'
            . '<img src="' . $this->outsideDir . '/files/secret.png" alt="secret"/>'
            . '<img src="file:///etc/passwd"/><img alt="nosrc"/>';
        $out = ExportConverters::resolveDocxImageSources($html, '/mysite/', $this->siteDir);
        $this->assertSame(array($this->dataUri($this->sitePng)), $this->imgSrcs($out));
        $this->assertStringNotContainsString('srcset', $out);
        $this->assertStringNotContainsString('169.254', $out);
        $this->assertStringContainsString('<p', $out);
    }

    public function testTotalImageBudgetDefaultsTo50MiB(): void
    {
        $this->assertSame(50 * 1024 * 1024, ExportConverters::DOCX_MAX_TOTAL_IMAGE_BYTES);
    }

    public function testImagesPastTheTotalBudgetAreDroppedAndNotFetched(): void
    {
        $png = $this->sitePng;
        $size = strlen($png);
        $calls = array();
        $fetcher = function ($url, $maxBytes) use (&$calls, $png) {
            $calls[] = array($url, $maxBytes);
            return $png;
        };
        $html = '<img src="https://cdn.example.org/1.png"/><img src="files/ok.png"/>'
            . '<img src="https://cdn.example.org/2.png"/><img src="https://cdn.example.org/3.png"/>';
        // room for exactly two images
        $out = ExportConverters::resolveDocxImageSources($html, '/mysite/', $this->siteDir, $fetcher, $size * 2);
        $this->assertSame(array($this->dataUri($png), $this->dataUri($png)), $this->imgSrcs($out));
        // the remaining budget caps each fetch; once spent, nothing is fetched
        $this->assertSame(array(array('https://cdn.example.org/1.png', $size * 2)), $calls);
    }

    public function testRepeatedImageCountsAgainstTheBudgetEachTime(): void
    {
        $size = strlen($this->sitePng);
        $html = str_repeat('<img src="files/ok.png"/>', 5);
        $out = ExportConverters::resolveDocxImageSources($html, '/mysite/', $this->siteDir, null, $size * 3);
        $this->assertCount(3, $this->imgSrcs($out));
    }

    public function testZeroBudgetDropsAllImages(): void
    {
        $out = ExportConverters::resolveDocxImageSources('<p>x</p><img src="files/ok.png"/>', '/mysite/', $this->siteDir, null, 0);
        $this->assertSame(array(), $this->imgSrcs($out));
        $this->assertStringContainsString('<p', $out);
    }

    public function testHtmlToDocxStringHonoursTheBudget(): void
    {
        $png = $this->sitePng;
        $fetcher = function () use ($png) { return $png; };
        $media = $this->docxMedia(ExportConverters::htmlToDocxString(
            '<p>x</p><img src="https://cdn.example.org/a.png"/><img src="https://cdn.example.org/b.png"/>',
            '/',
            null,
            $fetcher,
            strlen($png) + 1
        ));
        $this->assertCount(1, $media);
    }

    // ---------------------------------------------------------------
    // enforceDocxImageSources (the gate in front of PhpWord)
    // ---------------------------------------------------------------

    public function testGateLeavesSafeHtmlUntouched(): void
    {
        $html = '<p>x</p><img src="' . $this->dataUri($this->sitePng) . '"/>';
        $this->assertSame($html, ExportConverters::enforceDocxImageSources($html));
    }

    public function testGateRemovesUnsafeImages(): void
    {
        $html = '<p>x</p><img src="/etc/passwd"/><img src="http://127.0.0.1/"/><img/>';
        $out = ExportConverters::enforceDocxImageSources($html);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('<p>x</p>', $out);
    }

    public function testGateCatchesEntitySmuggledImages(): void
    {
        // PhpWord html_entity_decode()s numeric entities BEFORE parsing, so
        // this text becomes a real <img> element for PhpWord.
        $html = '<p>&#60;img src="' . $this->outsideDir . '/files/secret.png"/&#62;</p>';
        $out = ExportConverters::enforceDocxImageSources($html);
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('secret.png', $out);
    }

    public function testGateFailsClosedOnUnparseableHtml(): void
    {
        $this->assertNull(ExportConverters::enforceDocxImageSources('<p><b></p>'));
    }

    // ---------------------------------------------------------------
    // end to end: what ends up inside the .docx
    // ---------------------------------------------------------------

    private function docxMedia($docx)
    {
        $this->assertIsString($docx);
        $path = $this->tmpRoot . '/out_' . uniqid() . '.docx';
        file_put_contents($path, $docx);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $media = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'word/media/') === 0) {
                $media[] = $zip->getFromIndex($i);
            }
        }
        $zip->close();
        return $media;
    }

    private function fakeSite()
    {
        $site = new stdClass();
        $site->siteDirectory = $this->siteDir;
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = 'mysite';
        return $site;
    }

    public function testRenderDocxNoLongerReadsAbsoluteLocalPaths(): void
    {
        // Before the fix PhpWord would is_file() this path and embed the
        // other site's image.
        $docx = ExportConverters::renderDocxFromHtml('<p>x</p><img src="' . $this->outsideDir . '/files/secret.png"/>', $this->tmpRoot);
        $this->assertSame(array(), $this->docxMedia($docx));
    }

    public function testRenderDocxEmbedsInlineImageAndCleansItsTempDir(): void
    {
        $before = glob($this->tmpRoot . '/haxcms_docx_*');
        $docx = ExportConverters::renderDocxFromHtml('<p>x</p><img src="' . $this->dataUri($this->sitePng) . '"/>', $this->tmpRoot);
        $media = $this->docxMedia($docx);
        $this->assertCount(1, $media);
        $this->assertSame($this->sitePng, $media[0]);
        $this->assertSame($before, glob($this->tmpRoot . '/haxcms_docx_*'), 'per-export temp dir removed');
    }

    public function testHtmlToDocxStringEmbedsOnlyTheSiteImage(): void
    {
        if (!method_exists('SiteRouteUtils', 'getSiteDirectory')) {
            $this->markTestSkipped('SiteRouteUtils::getSiteDirectory unavailable');
        }
        $site = $this->fakeSite();
        if (SiteRouteUtils::getSiteDirectory($site) !== $this->siteDir) {
            $this->markTestSkipped('fake site does not resolve to the fixture directory');
        }
        $html = '<html><body><h1>T</h1>'
            . '<media-image source="files/ok.png" alt="ok"></media-image>'
            . '<img src="' . $this->outsideDir . '/files/secret.png"/>'
            . '<img src="file://' . $this->outsideDir . '/files/secret.png"/>'
            . '<img src="http://127.0.0.1:1/never.png"/>'
            . '<p>&#60;img src="' . $this->outsideDir . '/files/secret.png"/&#62;</p>'
            . '</body></html>';
        $media = $this->docxMedia(ExportConverters::htmlToDocxString($html, '/mysite/', $site));
        $this->assertCount(1, $media, 'only the in-site image is embedded');
        $this->assertSame($this->sitePng, $media[0]);
    }

    public function testHtmlToDocxStringWithStubbedRemoteImage(): void
    {
        $png = $this->sitePng;
        $fetched = array();
        $fetcher = function ($url) use (&$fetched, $png) {
            $fetched[] = $url;
            return $png;
        };
        $media = $this->docxMedia(ExportConverters::htmlToDocxString(
            '<p>x</p><img src="https://cdn.example.org/pic.png"/>',
            '/',
            null,
            $fetcher
        ));
        $this->assertSame(array('https://cdn.example.org/pic.png'), $fetched);
        $this->assertCount(1, $media);
        $this->assertSame($png, $media[0]);
    }
}
