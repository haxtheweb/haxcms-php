<?php
/**
 * PDF import puts images between the lines around them on Chrome PDFs
 * (haxtheweb/issues#3181).
 *
 * Chrome / Skia start every page with a flip ("0.24 0 0 -0.24 0 792 cm").
 * Smalot's getDataTm() composes a cm with the one before it in the wrong
 * order, so its line positions came back far outside the page and the
 * import put every image after the page text. Text positions now come from
 * a walk of the same commands with the transforms composed in the right
 * order, used only when getDataTm() put a line off the page.
 */

use PHPUnit\Framework\TestCase;

class PdfImportChromeImageOrderTest extends TestCase
{
    protected function setUp(): void
    {
        require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/importPdf.php';
    }

    public function testChromePdfInterleavesImagesWithText(): void
    {
        // Printed by headless Chrome from chrome-images.html (same folder).
        $html = haxcmsSystemConvertPdfToSemanticHtml(dirname(__DIR__) . '/fixtures/pdf/chrome-images.pdf');
        $order = array();
        preg_match_all('/<(h1|p|img)\b[^>]*>([^<]*)/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $order[] = $match[1] === 'img' ? 'img' : $match[2];
        }
        $this->assertSame(
            array(
                'Images in order',
                'First paragraph before the red image.',
                'img',
                'Second paragraph between the images.',
                'img',
                'Last paragraph after the blue image.',
            ),
            $order
        );
    }

    public function testTextShowYsComposeTheFlipFirst(): void
    {
        $commands = array(
            array('o' => 'cm', 'c' => '.24 0 0 -.24 0 792'),
            array('o' => 'q', 'c' => ''),
            array('o' => 'cm', 'c' => '3.125 0 0 3.125 115.625 115.625'),
            array('o' => 'BT', 'c' => ''),
            array('o' => 'Tm', 'c' => '1 0 0 -1 8 50'),
            array('o' => 'Tj', 'c' => 'a'),
            array('o' => 'ET', 'c' => ''),
            array('o' => 'BT', 'c' => ''),
            array('o' => 'Td', 'c' => '0 100'),
            array('o' => 'TL', 'c' => '20'),
            array('o' => "'", 'c' => 'b'),
            array('o' => 'TJ', 'c' => 'c'),
            array('o' => 'ET', 'c' => ''),
            array('o' => 'Q', 'c' => ''),
        );
        $ys = haxcmsSystemGetPdfTextShowYs($commands);
        $this->assertCount(3, $ys);
        // 792 - 0.24 * (115.625 + 3.125 * y)
        $this->assertEqualsWithDelta(726.75, $ys[0], 0.001);
        // Td 0 100 then ' moves down one line of 20: y = 80
        $this->assertEqualsWithDelta(704.25, $ys[1], 0.001);
        $this->assertEqualsWithDelta(704.25, $ys[2], 0.001);
        $this->assertNull(haxcmsSystemGetPdfTextShowYs('not commands'));
    }
}
