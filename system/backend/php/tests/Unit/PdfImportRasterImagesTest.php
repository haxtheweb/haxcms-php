<?php
/**
 * PDF import of non-JPEG images (haxtheweb/issues#3120): PNGs embedded as
 * FlateDecode / raw 8-bit samples, the way Chrome (haxcms-nodejs export,
 * "Save as PDF") and Dompdf (haxcms-php export) write them.
 *
 * Fixtures mirror both encoders exactly:
 *   - Chrome: [/ICCBased n] colour space, FlateDecode of raw rows, /SMask for
 *     transparency
 *   - Dompdf: DeviceRGB / DeviceGray, FlateDecode with /DecodeParms
 *     /Predictor 15 (PNG row filters), /SMask for transparency
 * plus a real Dompdf export -> import of a transparent PNG.
 *
 * Node parity: pdfToSemanticHtml.js decodes these through pdf.js + sharp.
 * Transparent areas flatten onto white on both backends.
 */

use PHPUnit\Framework\TestCase;

class PdfImportRasterImagesTest extends TestCase
{
    private $tmpPaths = array();

    protected function setUp(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is required to decode imported PNG images');
        }
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
        $path = tempnam(sys_get_temp_dir(), 'hax-pdf-raster-');
        file_put_contents($path, $content);
        $this->tmpPaths[] = $path;
        return $path;
    }

    /** $w x $h samples, left half $left colour, right half $right (1 or 3 bytes each) */
    private function halves($w, $h, $left, $right)
    {
        $row = str_repeat($left, intdiv($w, 2)) . str_repeat($right, $w - intdiv($w, 2));
        return str_repeat($row, $h);
    }

    /** prefix every row with PNG filter byte 0 (what Dompdf's /Predictor 15 stream holds) */
    private function filterRows($samples, $rowBytes)
    {
        $out = '';
        foreach (str_split($samples, $rowBytes) as $row) {
            $out .= "\0" . $row;
        }
        return $out;
    }

    /**
     * One-page PDF painting /Im1 between two lines of text.
     * $image: array('dict' => '<< ... >>' without /Length, 'data' => stream bytes)
     * $smask: optional same shape, referenced as /SMask from the image.
     * $iccComponents: when set, the image uses [/ICCBased <profile>] with /N.
     */
    private function buildPdf(array $image, $smask = null, $iccComponents = null)
    {
        $objects = array();
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> /XObject << /Im1 5 0 R >> >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $next = 7;
        $extra = '';
        if ($smask !== null) {
            $extra .= ' /SMask ' . $next . ' 0 R';
            $objects[$next] = rtrim($smask['dict'], " >") . ' /Length ' . strlen($smask['data']) . " >>\nstream\n" . $smask['data'] . "\nendstream";
            $next++;
        }
        $dict = $image['dict'];
        if ($iccComponents !== null) {
            $profile = 'not-a-real-icc-profile';
            $objects[$next] = '<< /N ' . $iccComponents . ' /Length ' . strlen($profile) . " >>\nstream\n" . $profile . "\nendstream";
            $dict = str_replace('/ColorSpace /ICC', '/ColorSpace [/ICCBased ' . $next . ' 0 R]', $dict);
            $next++;
        }
        $objects[5] = rtrim($dict, " >") . $extra . ' /Length ' . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
        $stream = 'BT /F1 12 Tf 72 740 Td (Above the image.) Tj ET '
            . 'q 200 0 0 150 72 450 cm /Im1 Do Q '
            . 'BT /F1 12 Tf 72 300 Td (Below the image.) Tj ET';
        $objects[6] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        ksort($objects);
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

    /** decode the only imported image; returns [gd, html] */
    private function importOne($path)
    {
        $html = haxcmsSystemConvertPdfToSemanticHtml($path);
        $this->assertSame(1, preg_match_all('/<img\b[^>]*>/', $html), preg_replace('/base64,[^"]+/', 'base64,...', $html));
        $this->assertSame(1, preg_match('/src="data:image\/jpeg;base64,([^"]+)"/', $html, $m));
        $gd = imagecreatefromstring(base64_decode($m[1]));
        $this->assertNotFalse($gd);
        return array($gd, $html);
    }

    private function rgbAt($gd, $x, $y)
    {
        $c = imagecolorat($gd, $x, $y);
        return array(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
    }

    private function assertNear(array $expected, array $actual, $tolerance = 12)
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertLessThanOrEqual($tolerance, abs($expected[$i] - $actual[$i]), json_encode(array('expected' => $expected, 'actual' => $actual)));
        }
    }

    // --- Chrome layout -------------------------------------------------------

    public function testChromeStyleIccFlateImageImportsInReadingOrder(): void
    {
        $samples = $this->halves(40, 30, "\xDC\x28\x28", "\x28\x3C\xDC");
        list($gd, $html) = $this->importOne($this->buildPdf(array(
            'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /ICC /BitsPerComponent 8 /Filter /FlateDecode >>',
            'data' => gzcompress($samples),
        ), null, 3));
        $this->assertSame(40, imagesx($gd));
        $this->assertSame(30, imagesy($gd));
        $this->assertNear(array(220, 40, 40), $this->rgbAt($gd, 5, 15));
        $this->assertNear(array(40, 60, 220), $this->rgbAt($gd, 35, 15));
        $this->assertStringContainsString(' width="40">', $html);
        $above = strpos($html, '<p>Above the image.</p>');
        $image = strpos($html, '<img ');
        $below = strpos($html, '<p>Below the image.</p>');
        $this->assertTrue($above < $image && $image < $below);
    }

    public function testChromeStyleTransparencyFlattensOntoWhite(): void
    {
        // colour under transparent pixels is black, as Chrome writes it
        $samples = $this->halves(40, 30, "\x00\x00\x00", "\x1E\xA0\x3C");
        $alpha = $this->halves(40, 30, "\x00", "\xFF");
        list($gd) = $this->importOne($this->buildPdf(
            array(
                'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /ICC /BitsPerComponent 8 /Filter /FlateDecode >>',
                'data' => gzcompress($samples),
            ),
            array(
                'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode >>',
                'data' => gzcompress($alpha),
            ),
            3
        ));
        $this->assertNear(array(255, 255, 255), $this->rgbAt($gd, 5, 15), 3);
        $this->assertNear(array(30, 160, 60), $this->rgbAt($gd, 35, 15));
    }

    // --- Dompdf layout -------------------------------------------------------

    public function testDompdfStylePredictorRowsImport(): void
    {
        $samples = $this->halves(40, 30, "\xDC\x28\x28", "\x28\x3C\xDC");
        list($gd) = $this->importOne($this->buildPdf(array(
            'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /Columns 40 /BitsPerComponent 8>> /ColorSpace /DeviceRGB /BitsPerComponent 8 >>',
            'data' => gzcompress($this->filterRows($samples, 120)),
        )));
        $this->assertNear(array(220, 40, 40), $this->rgbAt($gd, 5, 15));
        $this->assertNear(array(40, 60, 220), $this->rgbAt($gd, 35, 15));
    }

    public function testDompdfStyleGrayscaleWithPartialAlphaBlendsTowardWhite(): void
    {
        $gray = $this->halves(40, 30, "\x00", "\x00");
        $alpha = $this->halves(40, 30, "\x80", "\xFF");
        list($gd) = $this->importOne($this->buildPdf(
            array(
                'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /Columns 40 /BitsPerComponent 8>> /ColorSpace /DeviceGray /BitsPerComponent 8 >>',
                'data' => gzcompress($this->filterRows($gray, 40)),
            ),
            array(
                'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /Columns 40 /BitsPerComponent 8>> /ColorSpace /DeviceGray /BitsPerComponent 8 >>',
                'data' => gzcompress($this->filterRows($alpha, 40)),
            )
        ));
        // 50% black over white ~ mid grey; fully opaque black stays black
        $this->assertNear(array(127, 127, 127), $this->rgbAt($gd, 5, 15));
        $this->assertNear(array(0, 0, 0), $this->rgbAt($gd, 35, 15));
    }

    public function testUnfilteredRawSamplesImport(): void
    {
        $samples = $this->halves(40, 30, "\xDC\x28\x28", "\x28\x3C\xDC");
        list($gd) = $this->importOne($this->buildPdf(array(
            'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /DeviceRGB /BitsPerComponent 8 >>',
            'data' => $samples,
        )));
        $this->assertNear(array(220, 40, 40), $this->rgbAt($gd, 5, 15));
    }

    // --- unsupported encodings are skipped, never fatal ----------------------

    public function testUnsupportedRasterEncodingsAreSkipped(): void
    {
        $cases = array(
            '16 bits per component' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /DeviceRGB /BitsPerComponent 16 /Filter /FlateDecode >>',
            'CMYK' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /DeviceCMYK /BitsPerComponent 8 /Filter /FlateDecode >>',
            'Decode array' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Decode [1 0 1 0 1 0] /Filter /FlateDecode >>',
        );
        foreach ($cases as $label => $dict) {
            $html = haxcmsSystemConvertPdfToSemanticHtml($this->buildPdf(array(
                'dict' => $dict,
                'data' => gzcompress(str_repeat("\x10", 40 * 30 * 3)),
            )));
            $this->assertStringNotContainsString('<img', $html, $label);
            $this->assertStringContainsString('<p>Above the image.</p>', $html, $label);
        }
        // sample length that fits neither raw nor filtered rows
        $html = haxcmsSystemConvertPdfToSemanticHtml($this->buildPdf(array(
            'dict' => '<< /Type /XObject /Subtype /Image /Width 40 /Height 30 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode >>',
            'data' => gzcompress('short'),
        )));
        $this->assertStringNotContainsString('<img', $html);
    }

    // --- real Dompdf export -> import ----------------------------------------

    public function testDompdfExportOfTransparentPngImportsWithWhiteBackground(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteRouteUtils.php';
        require_once dirname(__DIR__, 2) . '/lib/siteRoutes/v1/ExportConverters.php';
        $siteDir = sys_get_temp_dir() . '/hax-pdf-raster-site-' . uniqid();
        mkdir($siteDir . '/files', 0777, true);
        $this->tmpPaths[] = $siteDir;
        // green square on a transparent background
        $png = imagecreatetruecolor(120, 80);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagefilledrectangle($png, 40, 20, 79, 59, imagecolorallocatealpha($png, 30, 160, 60, 0));
        imagepng($png, $siteDir . '/files/diagram.png');
        imagedestroy($png);
        $site = new stdClass();
        $site->siteDirectory = $siteDir;
        $pdf = ExportConverters::htmlToPdfString(
            '<p>Diagram below.</p><p><img src="files/diagram.png" alt="diagram"></p><p>After the diagram.</p>',
            '/sites/raster/',
            $site
        );
        list($gd, $html) = $this->importOne($this->tempFile($pdf));
        $this->assertSame(120, imagesx($gd));
        $this->assertNear(array(255, 255, 255), $this->rgbAt($gd, 5, 5), 3);
        $this->assertNear(array(30, 160, 60), $this->rgbAt($gd, 60, 40));
        $image = strpos($html, '<img ');
        $this->assertLessThan($image, strpos($html, '<p>Diagram below.</p>'));
        $this->assertGreaterThan($image, strpos($html, '<p>After the diagram.</p>'));
    }
}
