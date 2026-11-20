<?php
/**
 * Tests for lib/PptxToHtml.php, the .pptx importer's converter.
 *
 * Parity: the twin of haxcms-nodejs test/unit/pptx-to-html.test.cjs. The
 * fixture (tests/fixtures/pptx/rich-deck.pptx) and expected HTML are the same,
 * so a converter change on either backend that breaks parity fails a test.
 */

use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/lib/PptxToHtml.php';

class PptxToHtmlTest extends TestCase
{
    const NS = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
        . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';

    private $tmpFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    private function richExpected()
    {
        return "<h1>Rich &amp; &lt;bold&gt;</h1>\n"
            . "<ol><li>Step one</li><li>Step two<ul><li>Sub bullet</li></ul></li></ol>\n"
            . "<p>Back to plain</p>\n"
            . "<ul><li><a href=\"https://hax.psu.edu\">Linked</a> x<sup>2</sup></li></ul>\n"
            . "<h1>Merged table</h1>\n"
            . "<table><thead><tr><th>H1</th><th>H2</th><th>H3</th></tr></thead><tbody><tr><td colspan=\"2\">Wide</td><td>0</td></tr></tbody></table>\n"
            . "<h1>Slide 3</h1>\n"
            . "<p><img src=\"data:image/png;base64,…\" alt=\"A red square\"></p>";
    }

    private function slide($shapes)
    {
        return '<?xml version="1.0"?><p:sld ' . self::NS . '><p:cSld><p:spTree>' . $shapes . '</p:spTree></p:cSld></p:sld>';
    }

    private function shape($type, array $paragraphs)
    {
        $ph = $type !== '' ? '<p:nvSpPr><p:nvPr><p:ph type="' . $type . '"/></p:nvPr></p:nvSpPr>' : '<p:nvSpPr><p:nvPr/></p:nvSpPr>';
        $body = '';
        foreach ($paragraphs as $text) {
            $body .= '<a:p><a:r><a:t>' . $text . '</a:t></a:r></a:p>';
        }
        return '<p:sp>' . $ph . '<p:txBody>' . $body . '</p:txBody></p:sp>';
    }

    private function zipOf(array $files)
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-pptx-');
        $this->tmpFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    public function testListsLinksSupMergedTablePictureWithAlt()
    {
        $html = PptxToHtml::convertFile(dirname(__DIR__) . '/fixtures/pptx/rich-deck.pptx');
        $this->assertSame($this->richExpected(), preg_replace('/base64,[^"]*/', 'base64,…', $html));
        $this->assertMatchesRegularExpression('/data:image\/png;base64,iVBORw0KGgo/', $html);
    }

    public function testAnH1PerSlideNumericOrderWithoutPresentationXml()
    {
        $path = $this->zipOf(array(
            'ppt/slides/slide10.xml' => $this->slide($this->shape('title', array('Ten &amp; &lt;More&gt;'))),
            'ppt/slides/slide2.xml' => $this->slide($this->shape('', array('two', 'second line'))),
            'ppt/slides/slide1.xml' => $this->slide($this->shape('ctrTitle', array('One'))),
            'docProps/app.xml' => '<props/>',
        ));
        $this->assertSame(
            "<h1>One</h1>\n<h1>two</h1>\n<p>second line</p>\n<h1>Ten &amp; &lt;More&gt;</h1>",
            PptxToHtml::convertFile($path)
        );
    }

    public function testNotesSlideNumbersAndFootersAreLeftOut()
    {
        $path = $this->zipOf(array(
            'ppt/slides/slide1.xml' => $this->slide(
                $this->shape('title', array('Title')) . $this->shape('sldNum', array('7'))
                . $this->shape('ftr', array('Footer')) . $this->shape('body', array('Point'))
            ),
        ));
        $this->assertSame("<h1>Title</h1>\n<ul><li>Point</li></ul>", PptxToHtml::convertFile($path));
    }

    public function testRefusesNonZip()
    {
        $path = tempnam(sys_get_temp_dir(), 'hax-pptx-');
        $this->tmpFiles[] = $path;
        file_put_contents($path, 'nope');
        $this->expectExceptionMessageMatches('/missing ZIP signature/');
        PptxToHtml::convertFile($path);
    }

    public function testRefusesEntityDeclarations()
    {
        $path = $this->zipOf(array(
            'ppt/slides/slide1.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "a">]><p:sld/>',
        ));
        $this->expectExceptionMessageMatches('/not valid PowerPoint XML/');
        PptxToHtml::convertFile($path);
    }
}
