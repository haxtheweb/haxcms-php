<?php
/**
 * PDF export -> import parity with haxcms-nodejs (pdf-export-roundtrip and
 * pdf-to-semantic-html tests there).
 *
 * Regression for: a page with a tall photo exported to a 3-page PDF in Times
 * with the photo running off the page; importing a PDF where the photo was
 * split across pages produced two copies; importing Dompdf's own export lost
 * the photo entirely (its images live in resources inherited from the page
 * tree, which smalot does not read).
 *
 * Covers:
 *   - the export print stylesheet and its injection
 *   - Dompdf export of a tall photo stays on one page and imports with one
 *     image, between the paragraphs it sat between
 *   - an image painted on two pages imports once
 *   - images in inherited (page tree) resources import, and only on the
 *     pages that paint them
 *   - display width cap and downscale to 1600px
 */

use PHPUnit\Framework\TestCase;

class PdfExportImportRoundTripTest extends TestCase
{
    private $tmpPaths = array();

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importPdf.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpPaths as $path) {
            $this->removePath($path);
        }
        $this->tmpPaths = array();
    }

    private function removePath($path)
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removePath($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }

    private function tempFile($content)
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-pdf-rt-');
        file_put_contents($path, $content);
        $this->tmpPaths[] = $path;
        return $path;
    }

    private function jpeg($width, $height)
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 40));
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * Build a PDF that shares one DCTDecode image (/Im1) across pages.
     * $pages: list of array('texts' => [[y, text]], 'paints' => [[x, y, w, h]]).
     * $inheritResources: put fonts + image on the /Pages node (Dompdf style)
     * instead of on each page.
     */
    private function buildPdf(array $pages, $jpeg, $width, $height, $inheritResources = false)
    {
        $count = count($pages);
        $objects = array();
        // 1 catalog, 2 pages, 3 font, 4 image, then (page, content) pairs
        $kids = array();
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $resources = '/Resources << /Font << /F1 3 0 R >> /XObject << /Im1 4 0 R >> >>';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count
            . ($inheritResources ? ' ' . $resources : '') . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg)
            . " >>\nstream\n" . $jpeg . "\nendstream";
        foreach ($pages as $i => $page) {
            $stream = '';
            foreach ((isset($page['texts']) ? $page['texts'] : array()) as $t) {
                $stream .= 'BT /F1 12 Tf 72 ' . $t[0] . ' Td (' . $t[1] . ') Tj ET ';
            }
            foreach ((isset($page['paints']) ? $page['paints'] : array()) as $p) {
                $stream .= 'q ' . $p[2] . ' 0 0 ' . $p[3] . ' ' . $p[0] . ' ' . $p[1] . ' cm /Im1 Do Q ';
            }
            $pageObj = 5 + $i * 2;
            $contentObj = $pageObj + 1;
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . ($inheritResources ? '' : $resources . ' ') . '/Contents ' . $contentObj . ' 0 R >>';
            $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $last = max(array_keys($objects));
        $pdf = "%PDF-1.4\n";
        $offsets = array();
        for ($n = 1; $n <= $last; $n++) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $objects[$n] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($last + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $last; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size " . ($last + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $this->tempFile($pdf);
    }

    private function imgTags($html)
    {
        preg_match_all('/<img\b[^>]*>/', $html, $m);
        return $m[0];
    }

    private function stripData($html)
    {
        return preg_replace('/base64,[^"]+/', 'base64,...', $html);
    }

    // --- export stylesheet ---------------------------------------------

    public function testPrintStylesheetSetsReadableTypeAndKeepsImagesOnOnePage(): void
    {
        $css = ExportConverters::PDF_EXPORT_PRINT_CSS;
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*A4/', $css);
        $this->assertMatchesRegularExpression('/body\s*\{[^}]*"DejaVu Sans"/', $css);
        $this->assertMatchesRegularExpression('/img\s*\{[^}]*max-width:\s*100%/', $css);
        $this->assertMatchesRegularExpression('/img\s*\{[^}]*max-height:\s*130mm/', $css);
        $this->assertMatchesRegularExpression('/img\s*\{[^}]*page-break-inside:\s*avoid/', $css);
    }

    public function testWithPdfExportStylesInjectsIntoHeadAndCentresLoneImages(): void
    {
        $out = ExportConverters::withPdfExportStyles(
            '<head><meta charset="utf-8" /></head><p><img src="a.jpg"></p><p>text <img src="b.jpg"></p>'
        );
        $this->assertSame(0, strpos($out, '<head><style>'));
        $this->assertSame(1, substr_count($out, '<style>'));
        $this->assertStringContainsString('<p class="hax-pdf-image"><img src="a.jpg"></p>', $out);
        $this->assertStringContainsString('<p>text <img src="b.jpg"></p>', $out);
        $this->assertSame(0, strpos(ExportConverters::withPdfExportStyles('<p>x</p>'), '<head><style>'));
    }

    // --- export -> import round trip (Dompdf, always available) ---------

    public function testTallPhotoExportsToOnePageAndImportsOnceBetweenParagraphs(): void
    {
        $siteDir = sys_get_temp_dir() . '/hax-pdf-rt-site-' . uniqid();
        mkdir($siteDir . '/files', 0777, true);
        $this->tmpPaths[] = $siteDir;
        // portrait phone-photo aspect (3:4)
        file_put_contents($siteDir . '/files/photo.jpg', $this->jpeg(900, 1200));
        $site = new stdClass();
        $site->siteDirectory = $siteDir;
        $html = '<h1>Lesson 1: Conclusion</h1><h2>Lesson 1 Conclusion</h2>'
            . '<p>Wrap up Lesson 1 by revisiting the learning objectives.</p>'
            . '<p><img src="files/photo.jpg" alt="team photo"></p>'
            . '<p>Text after the image.</p>';
        $pdf = ExportConverters::htmlToPdfString($html, '/sites/rt/', $site);
        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $path = $this->tempFile($pdf);

        $parser = new \Smalot\PdfParser\Parser();
        $this->assertCount(1, $parser->parseFile($path)->getPages(), 'photo is scaled to fit, not pushed or cropped across pages');

        $imported = haxcmsSystemConvertPdfToSemanticHtml($path);
        $images = $this->imgTags($imported);
        $this->assertCount(1, $images, $this->stripData($imported));
        $this->assertStringContainsString('<h1>Lesson 1: Conclusion</h1>', $imported);
        $this->assertStringContainsString('<h2>Lesson 1 Conclusion</h2>', $imported);
        $imageAt = strpos($imported, '<img ');
        $this->assertLessThan($imageAt, strpos($imported, '<p>Wrap up Lesson 1'));
        $this->assertGreaterThan($imageAt, strpos($imported, '<p>Text after the image.</p>'));
        $this->assertStringEndsWith(' width="450">', $images[0]);
    }

    // --- importer -------------------------------------------------------

    public function testImagePaintedOnTwoPagesImportsOnce(): void
    {
        // top of the photo on page 1, bottom on page 2: same /Im1 object
        $path = $this->buildPdf(array(
            array('texts' => array(array(740, 'Page one text')), 'paints' => array(array(72, -400, 300, 1100))),
            array('texts' => array(array(200, 'Page two text')), 'paints' => array(array(72, 300, 300, 1100))),
        ), $this->jpeg(32, 40), 32, 40);
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertCount(1, $this->imgTags($html), $this->stripData($html));
        $this->assertStringContainsString('<p>Page one text</p>', $html);
        $this->assertStringContainsString('<p>Page two text</p>', $html);
    }

    public function testImagesInInheritedResourcesImportOnlyWherePainted(): void
    {
        // Dompdf layout: one /Resources on the /Pages node; page 1 paints the
        // image, page 2 does not
        $path = $this->buildPdf(array(
            array('texts' => array(array(740, 'Above the image.'), array(300, 'Below the image.')), 'paints' => array(array(72, 400, 200, 300))),
            array('texts' => array(array(740, 'Second page, no image.'))),
        ), $this->jpeg(32, 40), 32, 40, true);
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertCount(1, $this->imgTags($html), $this->stripData($html));
        $above = strpos($html, '<p>Above the image.</p>');
        $image = strpos($html, '<img ');
        $below = strpos($html, '<p>Below the image.</p>');
        $second = strpos($html, '<p>Second page, no image.</p>');
        $this->assertNotFalse($above);
        $this->assertNotFalse($below);
        $this->assertTrue($above < $image && $image < $below && $below < $second, $this->stripData($html));
    }

    public function testDisplayWidthKeepsPortraitPhotosToAScreen(): void
    {
        // 300x400 natural size wins; 900x1200 is capped by the 600px height box
        $small = haxcmsSystemConvertPdfToSemanticHtml($this->buildPdf(
            array(array('texts' => array(array(740, 'p')), 'paints' => array(array(72, 100, 300, 400)))),
            $this->jpeg(300, 400), 300, 400
        ));
        $this->assertStringEndsWith(' width="300">', $this->imgTags($small)[0]);
        $tall = haxcmsSystemConvertPdfToSemanticHtml($this->buildPdf(
            array(array('texts' => array(array(740, 't')), 'paints' => array(array(72, 100, 300, 400)))),
            $this->jpeg(900, 1200), 900, 1200
        ));
        $this->assertStringEndsWith(' width="450">', $this->imgTags($tall)[0]);
    }

    public function testLargeImagesAreDownscaledToTheMaxEdge(): void
    {
        $html = haxcmsSystemConvertPdfToSemanticHtml($this->buildPdf(
            array(array('texts' => array(array(740, 'big')), 'paints' => array(array(72, 100, 300, 400)))),
            $this->jpeg(1800, 2400), 1800, 2400
        ));
        $this->assertSame(1, preg_match('/base64,([^"]+)"/', $html, $m));
        $size = getimagesizefromstring(base64_decode($m[1]));
        $this->assertSame(1200, $size[0]);
        $this->assertSame(1600, $size[1]);
    }
}
