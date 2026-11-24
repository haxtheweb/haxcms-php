<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/DocxToHtml.php';

/**
 * DocxToHtml mirrors haxcms-nodejs: mammoth (elephant-php here), then
 * processDocxHtml and stripMSWord from src/lib/convertUtils.js. Expected
 * strings below are what haxcms-nodejs returns for the same input.
 */
class DocxToHtmlTest extends TestCase
{
    public function testStripMSWordMatchesNode(): void
    {
        $input = "<p class=\"MsoNormal\" style=\"margin-left:1in\">Hi<br />there</p>\n\n<p>&nbsp;</p>"
            . '<b><p>Bold</p></b><span style="x">s</span><!-- c --><style>x</style><li style="a">i</li>';
        $this->assertSame('<p>Hithere</p><p>Bold</p>s<li>i</li>', DocxToHtml::stripMSWord($input));
    }

    public function testStripMSWordTreatsUnicodeSpacesLikeJavaScript(): void
    {
        $input = "<p>a</p>  \u{00A0} <p>b</p><p>\u{3000}</p>";
        $this->assertSame('<p>a</p><p>b</p>', DocxToHtml::stripMSWord($input));
    }

    public function testStripMSWordIsLinearOnUnterminatedOpeners(): void
    {
        $input = str_repeat('<!--<span<style', 20000);
        $start = microtime(true);
        DocxToHtml::stripMSWord($input);
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function testTopLevelElementsSerializeLikeNodeHtmlParser(): void
    {
        $elements = DocxToHtml::topLevelElements(
            '<p><img alt="a" src="b" /></p>text<p>x<br />y &amp; z</p>'
            . '<ul><li><a href="u"><a href="u">t</a></a></li></ul>'
        );
        $this->assertSame(array('P', 'P', 'UL'), array_column($elements, 'tagName'));
        $this->assertSame('<p><img alt="a" src="b" ></p>', $elements[0]['html']);
        $this->assertSame('<p>x<br>y &amp; z</p>', $elements[1]['html']);
        $this->assertSame("x\ny & z", $elements[1]['text']);
        // nested anchors are kept as written, like haxcms-nodejs
        $this->assertSame('<ul><li><a href="u"><a href="u">t</a></a></li></ul>', $elements[2]['html']);
    }
}
