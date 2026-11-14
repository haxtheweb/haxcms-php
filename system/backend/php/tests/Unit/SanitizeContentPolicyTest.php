<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/SanitizeContent.php';

/**
 * The storage policy shared with haxcms-nodejs (haxtheweb/issues#3273): the
 * decided behaviors (mirroring haxcms-nodejs test/unit/sanitize-parity.test.cjs),
 * the HTML5 serializer, and the PHP 8.3 tree reshaping that makes
 * stored HTML identical to what a browser and haxcms-nodejs build.
 */
class SanitizeContentPolicyTest extends TestCase
{
    private function store($html, $options = array())
    {
        return SanitizeContent::sanitizeHTMLForStorage($html, $options);
    }

    public function testPolicyFileLoads(): void
    {
        $policy = json_decode(file_get_contents(dirname(__DIR__, 2) . '/lib/haxcms-sanitize-policy.json'), true);
        $this->assertSame(1, $policy['version']);
        $this->assertContains('a', $policy['allowedTags']);
        $this->assertContains('iframe', $policy['allowedTags']);
        $this->assertContains('script', $policy['removeWithContent']);
        $this->assertContains('cookie', $policy['clobberNames']);
        foreach (array('script', 'style', 'svg', 'math', 'noscript', 'object', 'embed') as $tag) {
            $this->assertNotContains($tag, $policy['allowedTags']);
        }
    }

    public function testLinksKeepTargetAndRel(): void
    {
        $html = '<a href="https://haxtheweb.org/" target="_blank" rel="noopener">HAX</a>';
        $this->assertSame($html, $this->store($html));
    }

    public function testHaxAttributesOnStandardElementsStay(): void
    {
        $html = '<h2 background-color="light-blue" text-align="left">T</h2><table bordered="" sort=""><tbody><tr><td>1</td></tr></tbody></table><template preserve-content=""><b>x</b></template>';
        $this->assertSame($html, $this->store($html));
    }

    public function testAttributeValuesAreStoredAsWritten(): void
    {
        $html = "<p data-x=\"  pad  \" title=\"\ttab\">a</p><a href=\"files/HAX.psu World \u{00E9}.png\">x</a>";
        $this->assertSame($html, $this->store($html));
    }

    public function testCommentsThatCouldHideMarkupGo(): void
    {
        $this->assertSame(
            '<!-- GENERATED FILE --><p>a</p>',
            $this->store('<!-- GENERATED FILE --><p>a</p><!-- <img src=x onerror=alert(1)> --><!-- a > b --><?php x ?>')
        );
    }

    public function testUntrustedImportsDropEveryCommentAndIframe(): void
    {
        $this->assertSame('<p>ok</p>', SanitizeContent::sanitizeUntrustedHTML("<!-- secret --><p>ok</p><iframe src=\"https://e.x\"></iframe>\0"));
    }

    public function testOnlyRealDomClobberingNamesAreDropped(): void
    {
        $this->assertSame(
            '<div id="images">anchor</div><h2 id="title">t</h2><img src="a.png"><form><input><input name="q"></form>',
            $this->store('<div id="images">anchor</div><h2 id="title">t</h2><img name="cookie" src="a.png"><form><input name="action"><input name="q"></form>')
        );
    }

    public function testPluginAndFallbackElementsAreUnwrappedOrRemoved(): void
    {
        $this->assertSame('fallback', $this->store('<object data="x.swf"><param name="a">fallback</object><noscript><b>n</b></noscript><xmp>x</xmp><math><mi>m</mi></math>'));
    }

    public function testMxssSequencesInAttributeValuesRemoveTheAttribute(): void
    {
        $this->assertSame('<img src="a.png">', $this->store('<img src="a.png" alt="a --> b" title="</title><b>">'));
    }

    public function testIsAttributeIsRemoved(): void
    {
        $this->assertSame('<div title="t">a</div>', $this->store('<div is="x-y" title="t">a</div>'));
    }

    public function testMarkupProbeRemovesCommentNextToTagLikeText(): void
    {
        $this->assertSame('<p> after</p>', $this->store('<p><em>&lt;script&gt;<!-- c --></em> after</p>'));
    }

    public function testLeadingNewlineAfterPreIsDroppedOnce(): void
    {
        $once = $this->store("<pre>\n\nx</pre>");
        $this->assertSame('<pre>x</pre>', $once);
        $this->assertSame($once, $this->store($once));
    }

    public function testSerializerEscapesLikeHtml5(): void
    {
        $this->assertSame(
            "<p title=\"a &amp; b &quot;q&quot; <x> &nbsp;\">a &amp; b &lt; c &gt; d &nbsp; \"e\" 'f'</p>",
            $this->store("<p title=\"a &amp; b &quot;q&quot; &lt;x&gt; &nbsp;\">a &amp; b &lt; c &gt; d &nbsp; \"e\" 'f'</p>")
        );
    }

    public function testValuelessAttributesAreEmpty(): void
    {
        $this->assertSame(
            '<a11y-tab disabled="">x</a11y-tab><select multiple=""><option selected="">1</option></select><x-y card=""></x-y>',
            $this->store('<a11y-tab disabled>x</a11y-tab><select multiple><option selected>1</option></select><x-y card></x-y>')
        );
    }

    public function testTablesGetTheHtml5Shape(): void
    {
        $this->assertSame(
            "<table>\n  <tbody><tr><td>1</td></tr>\n</tbody></table>",
            $this->store("<table>\n  <tr><td>1</td></tr>\n</table>")
        );
        $this->assertSame(
            '<table><colgroup><col></colgroup><tbody><tr><td>1</td></tr></tbody></table>',
            $this->store('<table><col><tr><td>1</td></tr></table>')
        );
        $this->assertSame('<p>a</p>x<p>b</p>', $this->store('<p>a</p><td>x</td><p>b</p>'));
    }

    public function testLibxmlFosterParentsLikeHaxcmsNodejs(): void
    {
        if (class_exists('\Dom\HTMLDocument')) {
            // the HTML5 parser puts stray table text before the table, as the
            // spec and browsers do; haxcms-nodejs' parse5 puts it after
            $this->markTestSkipped('PHP 8.3 only');
        }
        $this->assertSame(
            '<p>para</p><table><colgroup><col></colgroup><tbody><tr><td>1</td></tr></tbody></table>ab',
            $this->store('<table>a<p>para</p><col><tr><td>1</td></tr>b</table>')
        );
    }

    public function testHtml5VoidElementsDoNotSwallowContent(): void
    {
        $this->assertSame(
            '<video controls=""><source src="/v.mp4"><track src="/t.vtt">fallback</video><p>a<wbr>b</p><p><img src="/a.png">after</p>',
            $this->store('<video controls><source src="/v.mp4"><track src="/t.vtt">fallback</video><p>a<wbr>b</p><p><image src="/a.png">after</p>')
        );
    }

    public function testPhp84ParsesAsHtml5(): void
    {
        if (!class_exists('\Dom\HTMLDocument')) {
            $this->markTestSkipped('Dom\HTMLDocument needs PHP 8.4');
        }
        // only an HTML5 parser turns </br> into a second <br> and reads
        // <textarea> content as text
        $this->assertSame('<p>a<br>b<br></p><textarea>&lt;b&gt;x&lt;/b&gt;</textarea>', $this->store('<p>a<br>b</br></p><textarea><b>x</b></textarea>'));
    }

    public function testCarriageReturnsBecomeNewlines(): void
    {
        $this->assertSame("<p title=\"a\nb\">c\nd</p>", $this->store("<p title=\"a\r\nb\">c\rd</p>"));
    }
}
