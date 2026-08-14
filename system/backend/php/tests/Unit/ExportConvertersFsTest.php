<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the filesystem/binary ExportConverters seam.
 *
 * Covers the methods NOT in ExportConvertersTest.php (which only covers pure
 * transforms): buildEpubZipString, htmlToPdfString, renderDocxFromHtml,
 * resolvePdfImageSources, resolveLocalImageFile, normalizeHtmlForDocumentExport,
 * buildSiteExportHtml, and buildItemEpubString.
 *
 * Expected values come from independent sources of truth:
 *  - The ZIP local-file-header format spec (raw byte unpacking at fixed
 *    offsets) for "mimetype is first + stored uncompressed" — NOT a re-call of
 *    the production setCompressionIndex call.
 *  - The EPUB mimetype literal 'application/epub+zip' (a known-good constant).
 *  - The PDF magic bytes '%PDF' and DOCX/ZIP magic 'PK\x03\x04'.
 *  - realpath() computed independently in the test for image resolution.
 *  - The XML/HTML escaping spec for entity-encoded titles in the OPF/chapter
 *    XHTML (independent of the production htmlspecialchars call).
 *  - Relational properties (mode-sensitivity, untouched remote/data URIs).
 *
 * Binary deps (dompdf/dompdf, phpoffice/phpword, masterminds/html5) are in
 * composer; if a class can't load the test marks skipped rather than failing.
 */
class ExportConvertersFsTest extends TestCase
{
    private $tmpRoot;
    private $tmpFiles = array();
    private $savedHaxcms;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/ecfs_' . uniqid();
        @mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        foreach ($this->tmpFiles as $f) {
            if (is_string($f) && file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = array();
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Write a binary string to a temp .zip file and return the path so the
     * test can open it with ZipArchive. Paths are tracked for tearDown cleanup.
     */
    private function writeTempZip(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ecfs_zip_') . '.zip';
        file_put_contents($path, $data);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Create a temp site directory tree with an image file at $relImage
     * (relative to the site dir). Returns [siteDir, realSiteDir, realImage].
     */
    private function makeSiteDirWithImage(string $relImage = 'files/pic.jpg', string $content = 'JPEGDATA'): array
    {
        $siteDir = $this->tmpRoot . '/site';
        @mkdir($siteDir . '/files', 0777, true);
        $fullRel = $siteDir . '/' . $relImage;
        @mkdir(dirname($fullRel), 0777, true);
        file_put_contents($fullRel, $content);
        return array($siteDir, realpath($siteDir), realpath($fullRel));
    }

    // ------------------------------------------------------------------
    // buildEpubZipString
    // ------------------------------------------------------------------

    public function testBuildEpubZipStringProducesValidZipWithMimetypeFirstAndStored(): void
    {
        $bookMeta = array(
            'title' => 'My Book',
            'author' => 'Author A',
            'publisher' => 'Pub',
            'description' => 'Desc',
            'lang' => 'en',
            'date' => '2024-01-01T00:00:00+00:00',
            'identifier' => 'urn:uuid:fixed-test-id',
            'coverPath' => '',
            'basePath' => '/',
            'siteDirectory' => '',
        );
        $chapters = array(
            array('title' => 'Chapter One', 'xhtmlContent' => '<p>Hello</p>', 'filename' => 'chapter-1.xhtml'),
        );
        $css = 'body{color:red}';

        $data = ExportConverters::buildEpubZipString($bookMeta, $chapters, $css);

        $this->assertTrue(strlen($data) > 0);
        // ZIP magic bytes (independent: the PK zip format, not the production call).
        $this->assertSame(0, strncmp($data, "PK\x03\x04", 4));

        // Parse the first local file header directly per the ZIP format spec:
        // offset 8  = compression method (2 bytes, little-endian) -> must be 0 (STORED)
        // offset 26 = filename length (2 bytes, LE)
        // offset 30 = filename
        $compMethod = unpack('v', substr($data, 8, 2))[1];
        $this->assertSame(0, $compMethod, 'EPUB mimetype entry must be stored uncompressed (CM_STORE = 0)');
        $nameLen = unpack('v', substr($data, 26, 2))[1];
        $firstName = substr($data, 30, $nameLen);
        $this->assertSame('mimetype', $firstName, 'EPUB mimetype entry must be the first entry');

        // Round-trip through ZipArchive for content assertions.
        $path = $this->writeTempZip($data);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));
        $this->assertNotFalse($zip->getFromName('META-INF/container.xml'));
        $this->assertStringContainsString('OEBPS/content.opf', $zip->getFromName('META-INF/container.xml'));

        $chapter = $zip->getFromName('OEBPS/chapter-1.xhtml');
        $this->assertNotFalse($chapter);
        $this->assertStringContainsString('<title>Chapter One</title>', $chapter);
        $this->assertStringContainsString('<h1>Chapter One</h1>', $chapter);
        $this->assertStringContainsString('<p>Hello</p>', $chapter);

        $opf = $zip->getFromName('OEBPS/content.opf');
        $this->assertNotFalse($opf);
        $this->assertStringContainsString('<dc:title>My Book</dc:title>', $opf);
        $this->assertStringContainsString('<dc:creator>Author A</dc:creator>', $opf);
        $this->assertStringContainsString('<dc:identifier id="book-id">urn:uuid:fixed-test-id</dc:identifier>', $opf);
        // The OPF manifest references chapters by id/href, not by title text;
        // the chapter title appears in nav.xhtml and toc.ncx (asserted below).
        $this->assertStringContainsString('href="chapter-1.xhtml"', $opf);
        $this->assertStringContainsString('<itemref idref="chapter-1"/>', $opf);

        $this->assertSame($css, $zip->getFromName('OEBPS/styles.css'));
        $this->assertStringContainsString('Chapter One', $zip->getFromName('OEBPS/nav.xhtml'));
        $this->assertStringContainsString('Chapter One', $zip->getFromName('OEBPS/toc.ncx'));
        $zip->close();
    }

    public function testBuildEpubZipStringXMLEscapesTitlesInOpfAndChapter(): void
    {
        // Independent source of truth: the XML escaping spec (& < > become
        // entities under ENT_QUOTES | ENT_XML1). A title with all three must
        // appear entity-encoded in both the OPF dc:title and the chapter title.
        $bookMeta = array(
            'title' => 'A & B <C>',
            'author' => 'Auth',
            'identifier' => 'urn:uuid:escape-id',
            'date' => '2024-01-02T00:00:00+00:00',
            'basePath' => '/',
            'siteDirectory' => '',
        );
        $chapters = array(
            array('title' => 'Ch & <x>', 'xhtmlContent' => '<p>p</p>'),
        );
        $data = ExportConverters::buildEpubZipString($bookMeta, $chapters, '');
        $path = $this->writeTempZip($data);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $opf = $zip->getFromName('OEBPS/content.opf');
        $this->assertStringContainsString('<dc:title>A &amp; B &lt;C&gt;</dc:title>', $opf);
        // default chapter filename when none supplied: chapter-1.xhtml
        $chapter = $zip->getFromName('OEBPS/chapter-1.xhtml');
        $this->assertStringContainsString('<title>Ch &amp; &lt;x&gt;</title>', $chapter);
        $this->assertStringContainsString('<h1>Ch &amp; &lt;x&gt;</h1>', $chapter);
        $zip->close();
    }

    public function testBuildEpubZipStringEmbedsLocalCoverAndMeta(): void
    {
        list($siteDir, $realSiteDir, $realCover) = $this->makeSiteDirWithImage('files/cover.jpg', 'FAKEJPEGBYTES');

        $bookMeta = array(
            'title' => 'Cover Book',
            'author' => 'Auth',
            'identifier' => 'urn:uuid:cover-id',
            'date' => '2024-01-03T00:00:00+00:00',
            'coverPath' => 'files/cover.jpg',
            'basePath' => '/mysite/',
            'siteDirectory' => $siteDir,
        );
        $chapters = array(
            array('title' => 'Only Chapter', 'xhtmlContent' => '<p>body</p>'),
        );
        $data = ExportConverters::buildEpubZipString($bookMeta, $chapters, '');
        $path = $this->writeTempZip($data);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $cover = $zip->getFromName('OEBPS/cover.jpg');
        $this->assertSame('FAKEJPEGBYTES', $cover);
        $opf = $zip->getFromName('OEBPS/content.opf');
        $this->assertStringContainsString('id="cover-image"', $opf);
        $this->assertStringContainsString('href="cover.jpg"', $opf);
        $this->assertStringContainsString('media-type="image/jpeg"', $opf);
        $this->assertStringContainsString('<meta name="cover" content="cover-image"/>', $opf);
        $zip->close();
    }

    public function testBuildEpubZipStringUsesDefaultCssWhenNoneProvided(): void
    {
        $bookMeta = array(
            'title' => 'B',
            'identifier' => 'urn:uuid:dcss-id',
            'date' => '2024-01-04T00:00:00+00:00',
            'basePath' => '/',
            'siteDirectory' => '',
        );
        $data = ExportConverters::buildEpubZipString($bookMeta, array(), '');
        $path = $this->writeTempZip($data);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $css = $zip->getFromName('OEBPS/styles.css');
        $this->assertSame(ExportConverters::defaultEpubCss(), $css);
        $zip->close();
    }

    // ------------------------------------------------------------------
    // htmlToPdfString
    // ------------------------------------------------------------------

    public function testHtmlToPdfStringProducesPdfMagicBytes(): void
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            $this->markTestSkipped('dompdf/dompdf not available');
        }
        $pdf = ExportConverters::htmlToPdfString('<p>Hello PDF</p>', '/', null);
        $this->assertTrue(is_string($pdf) && strlen($pdf) > 0);
        $this->assertSame(0, strncmp($pdf, '%PDF', 4));
    }

    public function testHtmlToPdfStringWithSiteDirectoryStillProducesPdf(): void
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            $this->markTestSkipped('dompdf/dompdf not available');
        }
        list($siteDir, $realSiteDir) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        $site = new ExportConvertersTestSite();
        $site->siteDirectory = $siteDir;
        // No images in the HTML -> exercises the chroot/site-directory branch
        // without requiring dompdf to rasterize a real image.
        $pdf = ExportConverters::htmlToPdfString('<p>Hello with site dir</p>', '/mysite/', $site);
        $this->assertTrue(is_string($pdf) && strlen($pdf) > 0);
        $this->assertSame(0, strncmp($pdf, '%PDF', 4));
    }

    // ------------------------------------------------------------------
    // renderDocxFromHtml
    // ------------------------------------------------------------------

    public function testRenderDocxFromHtmlProducesValidDocxZip(): void
    {
        if (!class_exists('\\PhpOffice\\PhpWord\\PhpWord')) {
            $this->markTestSkipped('phpoffice/phpword not available');
        }
        $docx = ExportConverters::renderDocxFromHtml('<h1>Title</h1><p>Body text</p>', $this->tmpRoot);
        $this->assertTrue(is_string($docx) && strlen($docx) > 0, 'renderDocxFromHtml should return a non-empty binary string');
        $this->assertSame(0, strncmp($docx, "PK\x03\x04", 4));
        $path = $this->writeTempZip($docx);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $document = $zip->getFromName('word/document.xml');
        $this->assertNotFalse($document, 'DOCX must contain word/document.xml');
        $this->assertStringContainsString('Title', $document);
        $this->assertStringContainsString('Body text', $document);
        $zip->close();
    }

    // ------------------------------------------------------------------
    // resolvePdfImageSources
    // ------------------------------------------------------------------

    public function testResolvePdfImageSourcesRewritesLocalImageToRealpath(): void
    {
        list($siteDir, $realSiteDir, $realImage) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        $html = '<p><img src="files/pic.jpg" alt="pic"></p>';
        $out = ExportConverters::resolvePdfImageSources($html, '/mysite/', $realSiteDir);
        $this->assertStringContainsString('src="' . $realImage . '"', $out);
        $this->assertStringNotContainsString('src="files/pic.jpg"', $out);
    }

    public static function untouchedSrcProvider(): array
    {
        return [
            'data URI untouched' => ['<img src="data:image/png;base64,iVBORw0KGgo=">', 'src="data:image/png;base64,iVBORw0KGgo="'],
            'https URL untouched' => ['<img src="https://example.com/x.png">', 'src="https://example.com/x.png"'],
            'protocol-relative untouched' => ['<img src="//cdn.example.com/x.png">', 'src="//cdn.example.com/x.png"'],
        ];
    }

    #[DataProvider('untouchedSrcProvider')]
    public function testResolvePdfImageSourcesLeavesRemoteAndDataUrisUntouched(string $html, string $expectedSrc): void
    {
        list($siteDir, $realSiteDir) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        $out = ExportConverters::resolvePdfImageSources($html, '/mysite/', $realSiteDir);
        $this->assertStringContainsString($expectedSrc, $out);
    }

    public function testResolvePdfImageSourcesLeavesMissingLocalImageUnchanged(): void
    {
        list($siteDir, $realSiteDir) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        $html = '<img src="files/missing.jpg">';
        $out = ExportConverters::resolvePdfImageSources($html, '/mysite/', $realSiteDir);
        // resolution fails -> src left unchanged
        $this->assertStringContainsString('src="files/missing.jpg"', $out);
    }

    public function testResolvePdfImageSourcesEmptyHtmlReturnsEmpty(): void
    {
        $this->assertSame('', ExportConverters::resolvePdfImageSources('', '/mysite/', '/whatever'));
    }

    public function testResolvePdfImageSourcesInvalidSiteDirReturnsHtmlUnchanged(): void
    {
        $html = '<img src="files/pic.jpg">';
        $out = ExportConverters::resolvePdfImageSources($html, '/mysite/', $this->tmpRoot . '/does-not-exist');
        $this->assertStringContainsString('src="files/pic.jpg"', $out);
    }

    // ------------------------------------------------------------------
    // resolveLocalImageFile
    // ------------------------------------------------------------------

    public function testResolveLocalImageFileReturnsRealpathWhenFileExists(): void
    {
        list($siteDir, $realSiteDir, $realImage) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        $this->assertSame($realImage, ExportConverters::resolveLocalImageFile('files/pic.jpg', '/mysite/', $realSiteDir));
    }

    public function testResolveLocalImageFileFindsFileUnderFilesSubdir(): void
    {
        list($siteDir, $realSiteDir) = $this->makeSiteDirWithImage('files/sub/deep.png', 'PNGDATA');
        $expected = realpath($siteDir . '/files/sub/deep.png');
        $this->assertSame($expected, ExportConverters::resolveLocalImageFile('sub/deep.png', '/mysite/', $realSiteDir));
    }

    public function testResolveLocalImageFileStripsBasePathPrefix(): void
    {
        list($siteDir, $realSiteDir, $realImage) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        $this->assertSame($realImage, ExportConverters::resolveLocalImageFile('/mysite/files/pic.jpg', '/mysite/', $realSiteDir));
    }

    public static function resolveLocalImageFileFailureProvider(): array
    {
        return [
            'missing file' => ['files/nope.jpg'],
            'parent traversal' => ['../etc/passwd'],
            'null byte' => ["files/x\x00.jpg"],
            'empty src' => [''],
        ];
    }

    #[DataProvider('resolveLocalImageFileFailureProvider')]
    public function testResolveLocalImageFileReturnsEmptyStringForFailures(string $src): void
    {
        list($siteDir, $realSiteDir) = $this->makeSiteDirWithImage('files/pic.jpg', 'JPEGDATA');
        // Per the docblock contract: returns an empty string (not false/null)
        // when the file cannot be found, is unreadable, or escapes the site dir.
        $this->assertSame('', ExportConverters::resolveLocalImageFile($src, '/mysite/', $realSiteDir));
    }

    // ------------------------------------------------------------------
    // normalizeHtmlForDocumentExport
    // ------------------------------------------------------------------

    public function testNormalizeConvertsMediaImageToImgWithResolvedSrc(): void
    {
        $html = '<media-image source="images/pic.jpg" alt="A pic"></media-image>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/mysite/', array(), 'epub');
        $this->assertStringContainsString('<img', $out);
        $this->assertStringContainsString('src="/mysite/images/pic.jpg"', $out);
        $this->assertStringContainsString('alt="A pic"', $out);
        $this->assertStringNotContainsString('media-image', $out);
    }

    public function testNormalizeConvertsSimpleImgToImgWithResolvedSrc(): void
    {
        $html = '<simple-img src="images/y.jpg" alt="Y"></simple-img>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('src="/site/images/y.jpg"', $out);
        $this->assertStringContainsString('alt="Y"', $out);
    }

    public function testNormalizeLeavesRootRelativeAndAbsoluteImgSrcUntouched(): void
    {
        $rootRel = ExportConverters::normalizeHtmlForDocumentExport('<img src="/abs/path.png" alt="a">', '/site/', array(), 'epub');
        $this->assertStringContainsString('src="/abs/path.png"', $rootRel);
        $abs = ExportConverters::normalizeHtmlForDocumentExport('<img src="https://example.com/x.png" alt="e">', '/site/', array(), 'epub');
        $this->assertStringContainsString('src="https://example.com/x.png"', $abs);
    }

    public function testNormalizeConvertsYoutubeVideoPlayerToNocookieEmbed(): void
    {
        $html = '<video-player source="https://www.youtube.com/watch?v=abc123"></video-player>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/abc123', $out);
        $this->assertStringNotContainsString('video-player', $out);
    }

    public function testNormalizeConvertsYoutuBeLinkToEmbed(): void
    {
        $html = '<video-player source="https://youtu.be/xyz789"></video-player>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/xyz789', $out);
    }

    public function testNormalizeConvertsNonYoutubeVideoToIframeWithOriginalUrl(): void
    {
        $html = '<a11y-media-player source="https://vimeo.com/12345"></a11y-media-player>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('https://vimeo.com/12345', $out);
        $this->assertStringNotContainsString('a11y-media-player', $out);
    }

    public function testNormalizeRemovesVideoPlayerWithNoSource(): void
    {
        $html = '<video-player></video-player><p>kept</p>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('kept', $out);
        $this->assertStringNotContainsString('video-player', $out);
    }

    public function testNormalizeStripsStyleAttributesFromTables(): void
    {
        $html = '<table style="width:100%"><tr style="color:red"><td style="border:1px">x</td></tr></table>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('<table', $out);
        $this->assertStringContainsString('<td', $out);
    }

    public function testNormalizeEpubModeRewritesInternalSlugLinkToXhtml(): void
    {
        $item = (object)array('slug' => 'page-1');
        $html = '<p><a href="page-1">link</a></p>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array($item), 'epub');
        $this->assertStringContainsString('href="page-1.xhtml"', $out);
    }

    public function testNormalizeEpubModeRewritesQueryStyleInternalLink(): void
    {
        $item = (object)array('slug' => 'page-2');
        $html = '<a href="?q=page-2">link</a>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array($item), 'epub');
        $this->assertStringContainsString('href="page-2.xhtml"', $out);
    }

    public function testNormalizeEpubModeKeepsAbsoluteExternalLink(): void
    {
        $html = '<a href="https://example.com/path">ext</a>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array(), 'epub');
        $this->assertStringContainsString('href="https://example.com/path"', $out);
    }

    public function testNormalizeDocxModeDoesNotRewriteSlugLinks(): void
    {
        // Relational property: link-to-xhtml rewriting is epub-specific. In
        // docx mode an internal slug link must stay a plain slug, not become a
        // .xhtml file reference.
        $item = (object)array('slug' => 'page-1');
        $html = '<a href="page-1">link</a>';
        $out = ExportConverters::normalizeHtmlForDocumentExport($html, '/site/', array($item), 'docx');
        $this->assertStringContainsString('href="page-1"', $out);
        $this->assertStringNotContainsString('page-1.xhtml', $out);
    }

    public function testNormalizeEmptyHtmlReturnsEmpty(): void
    {
        $this->assertSame('', ExportConverters::normalizeHtmlForDocumentExport('', '/site/', array(), 'epub'));
    }

    // ------------------------------------------------------------------
    // buildSiteExportHtml
    // ------------------------------------------------------------------

    public function testBuildSiteExportHtmlProducesSiteWrapperWithAllItems(): void
    {
        $site = new ExportConvertersTestSite();
        $site->manifest = (object)array(
            'title' => 'My Site',
            'metadata' => (object)array('site' => (object)array('name' => 'mysite')),
            'items' => array(
                (object)array('id' => 'i1', 'slug' => 'page-1', 'title' => 'Page One'),
                (object)array('id' => 'i2', 'slug' => 'page-2', 'title' => 'Page Two'),
            ),
        );
        $site->pageContentMap = array('i1' => '<p>Content one</p>', 'i2' => '<p>Content two</p>');

        $html = ExportConverters::buildSiteExportHtml($site);
        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<title>My Site</title>', $html);
        $this->assertStringContainsString('<main data-haxcms-export="site"', $html);
        $this->assertStringContainsString('data-title="My Site"', $html);
        $this->assertStringContainsString('<h1>My Site</h1>', $html);
        $this->assertStringContainsString('<article data-item-id="i1" data-item-slug="page-1">', $html);
        $this->assertStringContainsString('<h2>Page One</h2>', $html);
        $this->assertStringContainsString('<p>Content one</p>', $html);
        $this->assertStringContainsString('<article data-item-id="i2" data-item-slug="page-2">', $html);
        $this->assertStringContainsString('<h2>Page Two</h2>', $html);
        $this->assertStringContainsString('<p>Content two</p>', $html);
    }

    public function testBuildSiteExportHtmlWithMagicWrapsInPrintThemeAndCdn(): void
    {
        $site = new ExportConvertersTestSite();
        $site->manifest = (object)array(
            'title' => 'My Site',
            'metadata' => (object)array('site' => (object)array('name' => 'mysite')),
            'items' => array(
                (object)array('id' => 'i1', 'slug' => 'page-1', 'title' => 'Page One'),
            ),
        );
        $site->pageContentMap = array('i1' => '<p>Content one</p>');

        $html = ExportConverters::buildSiteExportHtml($site, '', '/cdn/');
        $this->assertStringContainsString('<haxcms-print-theme>', $html);
        $this->assertStringContainsString('</haxcms-print-theme>', $html);
        $this->assertStringContainsString('window.__appCDN="/cdn/"', $html);
        $this->assertStringContainsString('<link rel="preconnect" crossorigin href="/cdn/">', $html);
        $this->assertStringContainsString('href="/cdn/build.js"', $html);
        $this->assertStringContainsString('src="/cdn/build.js"', $html);
        // content still rendered inside the print theme
        $this->assertStringContainsString('<div data-jos-item-id="i1">', $html);
        $this->assertStringContainsString('<p>Content one</p>', $html);
    }

    public function testBuildSiteExportHtmlAncestorFilterExcludesOtherBranch(): void
    {
        $manifest = new ExportConvertersTestManifest();
        $manifest->title = 'Site';
        $manifest->metadata = (object)array('site' => (object)array('name' => 's'));
        $manifest->items = array(
            (object)array('id' => 'i1', 'slug' => 'p1', 'title' => 'Page One', 'parent' => ''),
            (object)array('id' => 'i2', 'slug' => 'p2', 'title' => 'Page Two', 'parent' => 'i1'),
            (object)array('id' => 'i3', 'slug' => 'p3', 'title' => 'Page Three', 'parent' => ''),
        );
        $site = new ExportConvertersTestSite();
        $site->manifest = $manifest;
        $site->pageContentMap = array('i1' => '<p>c1</p>', 'i2' => '<p>c2</p>', 'i3' => '<p>c3</p>');

        $html = ExportConverters::buildSiteExportHtml($site, 'i1');
        $this->assertStringContainsString('<h2>Page One</h2>', $html);
        $this->assertStringContainsString('<h2>Page Two</h2>', $html);
        $this->assertStringNotContainsString('Page Three', $html);
        $this->assertStringNotContainsString('<p>c3</p>', $html);
    }

    // ------------------------------------------------------------------
    // buildItemEpubString
    // ------------------------------------------------------------------

    public function testBuildItemEpubStringProducesValidEpubZip(): void
    {
        $site = new ExportConvertersTestSite();
        $site->manifest = (object)array(
            'title' => 'Item Site',
            'metadata' => (object)array('site' => (object)array('name' => 'itemsite')),
            'items' => array(
                (object)array('id' => 'i1', 'slug' => 'page-1', 'title' => 'Page One'),
            ),
        );
        $site->pageContentMap = array('i1' => '<p>Body of page one</p>');
        $item = (object)array('id' => 'i1', 'slug' => 'page-1', 'title' => 'Page One');

        $data = ExportConverters::buildItemEpubString($site, $item, '/itemsite/');

        $this->assertTrue(strlen($data) > 0);
        $this->assertSame(0, strncmp($data, "PK\x03\x04", 4));
        // mimetype first + stored (independent ZIP-format parse).
        $compMethod = unpack('v', substr($data, 8, 2))[1];
        $this->assertSame(0, $compMethod);
        $nameLen = unpack('v', substr($data, 26, 2))[1];
        $this->assertSame('mimetype', substr($data, 30, $nameLen));

        $path = $this->writeTempZip($data);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));
        $chapter = $zip->getFromName('OEBPS/page-1.xhtml');
        $this->assertNotFalse($chapter);
        $this->assertStringContainsString('<h1>Page One</h1>', $chapter);
        $this->assertStringContainsString('Body of page one', $chapter);
        $opf = $zip->getFromName('OEBPS/content.opf');
        $this->assertStringContainsString('<dc:title>Page One</dc:title>', $opf);
        $zip->close();
    }
}

/**
 * Minimal fake site for buildSiteExportHtml / buildItemEpubString.
 *
 * SiteRouteUtils::getItemContent() calls $site->loadNode($id) then
 * $site->getPageContent($page); this fake returns canned HTML from a per-id
 * map so the real ExportConverters assembly + EPUB packaging runs against
 * deterministic content without a pages/*.html filesystem fixture.
 */
class ExportConvertersTestSite
{
    public $manifest;
    public $siteDirectory;
    public $directory;
    public $name;
    public $language;
    public $pageContentMap = array();

    public function loadNode($id)
    {
        return (object)array('id' => (string) $id);
    }

    public function getPageContent($page)
    {
        $id = isset($page->id) ? (string) $page->id : '';
        if (is_array($this->pageContentMap) && array_key_exists($id, $this->pageContentMap)) {
            return $this->pageContentMap[$id];
        }
        return '';
    }
}

/**
 * Minimal fake manifest with a findBranch() stub so the ancestor-filtering
 * branch of buildSiteExportHtml can be characterized without the real
 * JSONOutlineSchema tree walk.
 */
class ExportConvertersTestManifest
{
    public $title;
    public $metadata;
    public $items;

    public function findBranch($ancestor)
    {
        $out = array();
        foreach ($this->items as $item) {
            if (!$item) {
                continue;
            }
            $id = isset($item->id) ? (string) $item->id : '';
            $parent = isset($item->parent) ? (string) $item->parent : '';
            if ($id === (string) $ancestor || $parent === (string) $ancestor) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
