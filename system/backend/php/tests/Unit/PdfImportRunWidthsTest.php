<?php
/**
 * PDF import joins text runs by where each run really ends
 * (haxtheweb/issues#3181).
 *
 * Chrome / Skia write text as many short positioned runs, often one kerning
 * pair or ligature each. Joining them by a guessed run width scattered spaces
 * through words ("C on clu sion"). The run width now comes from the font's
 * glyph widths: /W + /DW for Type0 (CID) fonts, /FirstChar + /Widths for
 * simple fonts, with ligature glyphs counted once. Fonts without widths keep
 * the old estimate, so their output is unchanged.
 */

use PHPUnit\Framework\TestCase;

class PdfImportRunWidthsTest extends TestCase
{
    private $tempFiles = array();

    protected function setUp(): void
    {
        require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importPdf.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function tempFile($content)
    {
        $path = tempnam(sys_get_temp_dir(), 'pdfwidths');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * One page, one simple font with /Widths (every glyph 500 units), and one
     * Tj per run at the given x.
     */
    private function buildPdfWithWidths(array $runs)
    {
        $widths = implode(' ', array_fill(0, 95, 500));
        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F4 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /FirstChar 32 /LastChar 126 /Widths [' . $widths . '] >>',
        );
        $stream = '';
        foreach ($runs as $run) {
            $stream .= 'BT /F4 12 Tf ' . $run[1] . ' 700 Td (' . $run[0] . ') Tj ET ';
        }
        $objects[5] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $pdf = "%PDF-1.4\n";
        $offsets = array();
        for ($i = 1; $i <= 5; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }

    public function testChromePdfKeepsWordsWhole(): void
    {
        // Printed by headless Chrome from chrome-short-runs.html (same folder).
        $html = haxcmsSystemConvertPdfToSemanticHtml(dirname(__DIR__) . '/fixtures/pdf/chrome-short-runs.pdf');
        $this->assertStringContainsString('<h1>Lesson 1: Conclusion</h1>', $html);
        $this->assertStringContainsString('<h2>Lesson 1 Conclusion</h2>', $html);
        $this->assertStringContainsString(
            '<p>Wrap up Lesson 1 by revisiting the learning objectives, summarizing key takeaways, and pointing toward next steps in the course.</p>',
            $html
        );
        // ligature glyphs (ffi, fi, ffl) and kerning pairs split runs mid-word
        $this->assertStringContainsString('office affinity efficient waffle.', $html);
        $this->assertStringContainsString('Kerning pairs: AVATAR Tower WAVE Toyota.', $html);
    }

    public function testRunsThatTouchJoinAndARealGapIsASpace(): void
    {
        // 12pt, 500 units a glyph: each character advances 6pt.
        $path = $this->tempFile($this->buildPdfWithWidths(array(
            array('Con', 72),
            array('clu', 90),   // starts where "Con" ends: same word
            array('sion', 108),
            array('next', 138), // 6pt (half an em) after "sion": a space
        )));
        $this->assertSame('<p>Conclusion next</p>', haxcmsSystemConvertPdfToSemanticHtml($path));
    }

    public function testTextWidthCountsLigatureGlyphsOnce(): void
    {
        $metrics = array(
            'widths' => array('f' => 300.0, 'i' => 200.0, 'ffi' => 800.0, 'o' => 500.0),
            'maxLength' => 3,
            'default' => null,
        );
        $this->assertSame(1300.0, haxcmsSystemPdfTextWidthUnits('offi', $metrics));
        $this->assertSame(800.0, haxcmsSystemPdfTextWidthUnits('fo', $metrics));
        // unknown character, no default width: no width at all
        $this->assertNull(haxcmsSystemPdfTextWidthUnits('oz', $metrics));
        $metrics['default'] = 1000.0;
        $this->assertSame(1500.0, haxcmsSystemPdfTextWidthUnits('oz', $metrics));
        $this->assertNull(haxcmsSystemPdfTextWidthUnits('o', null));
    }
}
