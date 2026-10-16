<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Security (SEC-SANITIZE) regression tests: script-capable schemes on
 * custom-element attributes, entity/whitespace obfuscation, plain-text
 * decoding order, XML-illegal characters, page metadata sanitisation and
 * managed-file (Twig) rendering with hostile site titles.
 */
class SanitizeContentSchemeTest extends TestCase
{
    public static function dangerousCustomElementProvider(): array
    {
        return [
            'simple-cta link' => ['<simple-cta link="javascript:alert(1)" label="go"></simple-cta>', 'link'],
            'pdf-browser-viewer file' => ['<pdf-browser-viewer file="javascript:alert(1)"></pdf-browser-viewer>', 'file'],
            'media-image link' => ['<media-image link="javascript:alert(1)" source="x.png"></media-image>', 'link'],
            'h5p-element source' => ['<h5p-element source="javascript:1"></h5p-element>', 'source'],
            'video-player sources' => ['<video-player sources="javascript:alert(1)"></video-player>', 'sources'],
            'video-player tracks' => ['<video-player tracks="vbscript:msgbox(1)"></video-player>', 'tracks'],
            'mixed case' => ['<simple-cta link="JaVaScRiPt:alert(1)"></simple-cta>', 'link'],
            'tab inside scheme' => ["<simple-cta link=\"java\tscript:alert(1)\"></simple-cta>", 'link'],
            'newline inside scheme' => ["<simple-cta link=\"java\nscript:alert(1)\"></simple-cta>", 'link'],
            'numeric entity' => ['<simple-cta link="&#106;avascript:alert(1)"></simple-cta>', 'link'],
            'hex entity' => ['<simple-cta link="&#x6A;avascript:alert(1)"></simple-cta>', 'link'],
            'named colon entity' => ['<simple-cta link="javascript&colon;alert(1)"></simple-cta>', 'link'],
            'leading spaces' => ['<simple-cta link="   javascript:alert(1)"></simple-cta>', 'link'],
            'vbscript' => ['<simple-cta link="vbscript:msgbox(1)"></simple-cta>', 'link'],
            'data text/html' => ['<simple-cta link="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"></simple-cta>', 'link'],
            // attribute names that are not URL-like at all
            'arbitrary attr javascript' => ['<my-widget target-thing="javascript:alert(1)"></my-widget>', 'target-thing'],
            'arbitrary attr entity-encoded' => ['<my-widget foo="&#106;avascript:alert(1)"></my-widget>', 'foo'],
            'arbitrary attr double-encoded' => ['<my-widget foo="&amp;#106;avascript:alert(1)"></my-widget>', 'foo'],
            'arbitrary attr whitespace' => ["<my-widget foo=\" \t java\nscript:alert(1)\"></my-widget>", 'foo'],
            'arbitrary attr data' => ['<my-widget foo="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="></my-widget>', 'foo'],
            'arbitrary attr vbscript' => ['<my-widget foo="VBScript:msgbox(1)"></my-widget>', 'foo'],
            'arbitrary attr numeric no semicolon' => ['<my-widget foo="&amp;#106avascript:alert(1)"></my-widget>', 'foo'],
            'arbitrary attr double-encoded Tab' => ['<my-widget foo="java&amp;Tab;script:alert(1)"></my-widget>', 'foo'],
            'href double-encoded' => ['<a href="&amp;#106;avascript:alert(1)">x</a>', 'href'],
        ];
    }

    #[DataProvider('dangerousCustomElementProvider')]
    public function testDangerousSchemesAreRemoved(string $html, string $attribute): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage($html);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $out);
        $this->assertStringNotContainsStringIgnoringCase('vbscript', $out);
        $this->assertStringNotContainsStringIgnoringCase('data:', $out);
        $this->assertDoesNotMatchRegularExpression('/\s' . preg_quote($attribute, '/') . '=/i', $out);
    }

    public function testUrlNamedCustomAttributesUseTheAllowlist(): void
    {
        // non-script but non-allowlisted scheme on a URL-named attribute: removed
        $out = SanitizeContent::sanitizeHTMLForStorage('<simple-cta link="file:///etc/passwd" label="x"></simple-cta>');
        $this->assertStringNotContainsString('link=', $out);
        $this->assertStringContainsString('label="x"', $out);
    }

    public static function safeAttributeProvider(): array
    {
        return [
            'relative link' => ['<simple-cta link="pages/next" label="go"></simple-cta>', 'link="pages/next"'],
            'anchor link' => ['<simple-cta link="#section-2"></simple-cta>', 'link="#section-2"'],
            'https link' => ['<simple-cta link="https://hax.psu.edu/"></simple-cta>', 'link="https://hax.psu.edu/"'],
            'mailto link' => ['<simple-cta link="mailto:a@b.co"></simple-cta>', 'link="mailto:a@b.co"'],
            'tel link' => ['<simple-cta link="tel:+18005551234"></simple-cta>', 'link="tel:+18005551234"'],
            'relative source' => ['<media-image source="files/x.png"></media-image>', 'source="files/x.png"'],
            'pdf file' => ['<pdf-browser-viewer file="files/doc.pdf"></pdf-browser-viewer>', 'file="files/doc.pdf"'],
            'title with leading word and colon' => ['<p title="Note: hi">x</p>', 'title="Note: hi"'],
            'label with colon' => ['<simple-cta label="Warning: read this" link="x"></simple-cta>', 'label="Warning: read this"'],
            'caption step colon' => ['<media-image caption="Step 1: open" source="x.png"></media-image>', 'caption="Step 1: open"'],
            'text with later colon' => ['<my-widget foo="see javascript: later"></my-widget>', 'foo="see javascript: later"'],
            'json-ish sources' => ['<video-player sources=\'[{"src":"files/v.mp4"}]\'></video-player>', 'files/v.mp4'],
            'time value' => ['<my-widget start="10:30"></my-widget>', 'start="10:30"'],
            'iron-icon name' => ['<simple-icon icon="icons:check"></simple-icon>', 'icon="icons:check"'],
            'hax icon name' => ['<simple-icon-button icon="hax:page-edit"></simple-icon-button>', 'icon="hax:page-edit"'],
            'boolean attribute' => ['<my-widget correct></my-widget>', 'correct'],
            'non-script scheme on non-URL attr' => ['<my-widget foo="ftp://x"></my-widget>', 'foo="ftp://x"'],
        ];
    }

    #[DataProvider('safeAttributeProvider')]
    public function testLegitimateAttributesSurvive(string $html, string $expectedFragment): void
    {
        $out = SanitizeContent::sanitizeHTMLForStorage($html);
        $this->assertStringContainsString($expectedFragment, $out);
    }

    public static function schemeDetectionProvider(): array
    {
        return [
            ['javascript:alert(1)', 'javascript'],
            ["  JAVA\tSCRIPT:x", 'javascript'],
            ['&#106;avascript:x', 'javascript'],
            ['&#106avascript:x', 'javascript'],
            ['&#X6a;avascript:x', 'javascript'],
            ['&#x6A avascript:x', 'javascript'],
            ['javascript&colon;x', 'javascript'],
            ['java&Tab;script:x', 'javascript'],
            ['java&NewLine;script:x', 'javascript'],
            // named entities are case-sensitive and need ';'
            ['javascript&COLON;x', ''],
            ['javascript&colonx', ''],
            // not part of the scheme entity set
            ['javascript&#58x', 'javascript'],
            ['https://x', 'https'],
            ['note: hi', 'note'],
            ['icons:check', 'icons'],
            ['/relative:thing', ''],
            ['#frag:x', ''],
            ['1abc:x', ''],
            ['', ''],
            [null, ''],
        ];
    }

    #[DataProvider('schemeDetectionProvider')]
    public function testGetNormalizedScheme(?string $input, string $expected): void
    {
        $this->assertSame($expected, SanitizeContent::getNormalizedScheme($input));
    }

    public function testDecodeSchemeEntitiesSinglePassAndInvalidCodePoints(): void
    {
        // one pass only: &#38;#106; -> &#106; (not decoded again)
        $this->assertSame('&#106;', SanitizeContent::decodeSchemeEntities('&#38;#106;'));
        $this->assertSame('', SanitizeContent::decodeSchemeEntities('&#0;'));
        $this->assertSame('', SanitizeContent::decodeSchemeEntities('&#x110000;'));
        $this->assertSame('', SanitizeContent::decodeSchemeEntities('&#xD800;'));
        $this->assertSame('', SanitizeContent::decodeSchemeEntities('&#99999999999999999999;'));
        $this->assertSame("\u{1F600}", SanitizeContent::decodeSchemeEntities('&#x1F600;'));
        $this->assertSame(':+.', SanitizeContent::decodeSchemeEntities('&colon;&plus;&period;'));
        // html_entity_decode-only named entities are left alone
        $this->assertSame('&amp;&lt;&quot;', SanitizeContent::decodeSchemeEntities('&amp;&lt;&quot;'));
    }

    public static function plainTextProvider(): array
    {
        return [
            'encoded img stays tagless' => ['&lt;img src=x onerror=alert(1)&gt;Title', 'Title'],
            'real tags stripped' => ['<b>Bold</b> title', 'Bold title'],
            'entities decoded' => ['Tom &amp; Jerry', 'Tom & Jerry'],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('plainTextProvider')]
    public function testPlainTextDecodesBeforeStripping(?string $input, string $expected): void
    {
        $out = SanitizeContent::plainText($input);
        $this->assertSame($expected, $out);
        $this->assertStringNotContainsString('<', $out);
    }

    public function testEscapeXMLValueStripsIllegalControlCharacters(): void
    {
        $value = "a\x00b\x01c\x08d\x0Be\x0Cf\x1Fg\tH\nI\rJ\u{FFFE}K\u{1F600}";
        $out = SanitizeContent::escapeXMLValue($value);
        $this->assertSame("abcdefg\tH\nI\rJK\u{1F600}", $out);
        $doc = simplexml_load_string('<r>' . SanitizeContent::escapeXMLValue("x\x03<y>&") . '</r>');
        $this->assertNotFalse($doc);
        $this->assertSame('x<y>&', (string)$doc);
    }

    public function testSanitizePageMetadata(): void
    {
        $themes = (object)array(
            'clean-one' => (object)array('element' => 'clean-one', 'name' => 'Clean one'),
        );
        $incoming = (object)array(
            'image' => 'javascript:alert(1)',
            'linkUrl' => 'https://ok.example/',
            'tags' => 'a &amp; b & c',
            'icon' => '"><img src=x onerror=alert(1)>',
            'accentColor' => '',
            'linkTarget' => 7,
            'relatedItems' => array('x<y>'),
            'theme' => (object)array('key' => 'clean-one', 'element' => '<evil>'),
            'published' => true,
            'created' => 5,
            'ratio' => 1.5,
            'customThing' => '<b>x</b>',
            'images' => array('files/a.png', '<x>'),
            'deep' => array('a' => array('b' => array('c' => array('d' => array('e' => array('f' => 'too deep')))))),
            'nested' => (object)array('constructor' => 'x', 'ok' => '"q"'),
            '__proto__' => 'x',
            'constructor' => 'x',
            'prototype' => 'x',
        );
        $res = SanitizeContent::sanitizePageMetadata($incoming, $themes);
        $out = $res['values'];
        $this->assertSame(array('image', 'accentColor'), $res['remove']);
        $this->assertFalse(property_exists($out, 'image'));
        $this->assertSame('https://ok.example/', $out->linkUrl);
        // no double-encoding: existing entities survive, bare & is escaped
        $this->assertSame('a &amp; b &amp; c', $out->tags);
        $this->assertSame('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $out->icon);
        $this->assertSame('7', $out->linkTarget);
        $this->assertSame(array('x&lt;y&gt;'), $out->relatedItems);
        $this->assertSame('clean-one', $out->theme->key);
        $this->assertSame('clean-one', $out->theme->element);
        $this->assertTrue($out->published);
        $this->assertSame(5, $out->created);
        $this->assertSame(1.5, $out->ratio);
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $out->customThing);
        $this->assertSame(array('files/a.png', '&lt;x&gt;'), $out->images);
        // depth limit 5: the 6th level is dropped
        $this->assertFalse(property_exists($out->deep->a->b->c->d, 'e'));
        $this->assertFalse(property_exists($out->nested, 'constructor'));
        $this->assertSame('&quot;q&quot;', $out->nested->ok);
        $this->assertFalse(property_exists($out, '__proto__'));
        $this->assertFalse(property_exists($out, 'constructor'));
        $this->assertFalse(property_exists($out, 'prototype'));
        // idempotent: a second pass over the output changes nothing
        $again = SanitizeContent::sanitizePageMetadata(json_decode(json_encode($out)), $themes)['values'];
        $this->assertSame($out->tags, $again->tags);
        $this->assertSame($out->icon, $again->icon);
    }

    public function testApplyPageMetadataRemovesBadThemeAndEscapesStoredValues(): void
    {
        $themes = (object)array('clean-one' => (object)array('element' => 'clean-one'));
        $page = new stdClass();
        $page->metadata = (object)array(
            'theme' => (object)array('key' => 'clean-one'),
            'image' => 'files/old.png',
            'untouched' => '<kept as stored>',
        );
        // client echoes back a stored-but-malicious value: still escaped
        SanitizeContent::applyPageMetadata($page, array(
            'theme' => '_none_',
            'image' => 'vbscript:x',
            'icon' => '<svg onload=alert(1)>',
        ), $themes);
        $this->assertFalse(isset($page->metadata->theme));
        $this->assertFalse(isset($page->metadata->image));
        $this->assertSame('&lt;svg onload=alert(1)&gt;', $page->metadata->icon);
        $this->assertSame('<kept as stored>', $page->metadata->untouched);

        SanitizeContent::applyPageMetadata($page, array('theme' => 'not-installed'), $themes);
        $this->assertFalse(isset($page->metadata->theme));
        SanitizeContent::applyPageMetadata($page, array('theme' => 'clean-one'), $themes);
        $this->assertSame('clean-one', $page->metadata->theme->key);
        $this->assertFalse(isset($themes->{'clean-one'}->key), 'shared themes entry must not be mutated');
    }

    public function testManagedTemplatesStayParseableWithHostileTitle(): void
    {
        $boilerplate = dirname(__DIR__, 4) . '/boilerplate/site';
        $this->assertFileExists($boilerplate . '/manifest.json');
        $title = "Evil\\\"\n,\"injected\":\"1\r\n\t\x01\\";
        $description = "line1\nline2 \\u0022 \"quoted\" <b>x</b>";
        $vars = array(
            'title' => SanitizeContent::sanitizeManagedTemplateText($title),
            'description' => SanitizeContent::sanitizeManagedTemplateText($description),
            'short' => 'evil-site',
            'version' => '1.0.0',
            'basePath' => '/sites/evil-site/',
            'hexCode' => '#000000',
            'lang' => 'en',
        );
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($boilerplate));
        foreach (array('manifest.json', 'package.json') as $template) {
            $decoded = json_decode($twig->render($template, $vars), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), $template . ' must stay valid JSON');
            $this->assertArrayNotHasKey('injected', $decoded);
            $this->assertStringNotContainsString("\n", $decoded['description']);
        }
        $manifest = json_decode($twig->render('manifest.json', $vars), true);
        $this->assertStringStartsWith('Evil', $manifest['name']);
        $xml = simplexml_load_string($twig->render('imsmanifest.xml', $vars));
        $this->assertNotFalse($xml);

        // control: unsanitized values would have broken manifest.json
        $raw = $vars;
        $raw['title'] = $title;
        json_decode($twig->render('manifest.json', $raw), true);
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error());
    }
}
