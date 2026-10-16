<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for HAXCMSSite's per-page alternate format
 * generation, lib/HAXCMSSite.php: writePageAlternateFormats (public),
 * getPageAlternateLocation (public), and the private serialization helpers
 * (getPageAlternatePayload, htmlToMarkdown, encodePageAlternateYaml,
 * getPageAlternateXml, getPageAlternateXmlNode, getSafeXmlTagName,
 * getSafeXmlCdata) exercised via reflection where there is no public seam.
 *
 * Sidecar files are verified on disk inside a real temp-site fixture
 * (pages/<id>/index.{md,json,yaml,xml} beside index.html), complementing
 * HAXCMSSiteVariantNegotiationTest which only pre-seeds sidecars to test
 * serving/negotiation. yaml_emit is unavailable in this environment, so the
 * YAML assertions accept both the native ext-yaml output ('---' preamble)
 * and the documented JSON-fallback encoding (JSON is valid YAML 1.2).
 */
class HAXCMSSiteAlternateFormatsTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $tmpRoot;
    private $siteName = 'altsite';
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_alt_formats_' . uniqid();
        $this->buildSiteFixture($this->siteName);
        $this->haxcms = new HAXCMSAlternateFormatsTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = new HAXCMSAlternateFormatsTestSite();
        $this->site->load($this->tmpRoot, '/', $this->siteName);
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function buildSiteFixture($name): void
    {
        $dir = $this->tmpRoot . '/' . $name;
        mkdir($dir . '/pages/item-page-1', 0777, true);
        file_put_contents($dir . '/pages/item-page-1/index.html', '<p>Home content</p>');
        $manifest = (object) array(
            'id' => 'site-alt-formats-uuid',
            'title' => 'Alternate Formats Test Site',
            'author' => 'Tester',
            'description' => 'A test site for alternate format generation',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array('name' => $name),
            ),
            'items' => array(
                (object) array(
                    'id' => 'item-page-1', 'title' => 'Home', 'slug' => 'home',
                    'location' => 'pages/item-page-1/index.html', 'order' => 0,
                    'parent' => null, 'indent' => 0, 'description' => 'Home page',
                    'metadata' => (object) array('created' => 1000, 'updated' => 2000),
                ),
            ),
        );
        file_put_contents($dir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod(HAXCMSSite::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->site, $args);
    }

    private function homePage()
    {
        return $this->site->loadNode('item-page-1');
    }

    // =========================================================================
    // getPageAlternateLocation (public)
    // =========================================================================

    public static function pageAlternateLocationProvider(): array
    {
        return [
            'html replaced' => ['pages/a/index.html', 'md', 'pages/a/index.md'],
            'htm replaced' => ['pages/a/index.htm', 'json', 'pages/a/index.json'],
            'uppercase html match' => ['pages/a/INDEX.HTML', 'xml', 'pages/a/INDEX.xml'],
            'uppercase extension arg lowercased' => ['pages/a/index.html', 'MD', 'pages/a/index.md'],
            'leading dot stripped from extension' => ['pages/a/index.html', '.md', 'pages/a/index.md'],
            'no html extension appends' => ['pages/a/index', 'md', 'pages/a/index.md'],
            'backslashes normalized' => ['pages\\a\\index.html', 'md', 'pages/a/index.md'],
            'yml kept as given (normalization only in request parsing)' => ['pages/a/index.html', 'yml', 'pages/a/index.yml'],
        ];
    }

    #[DataProvider('pageAlternateLocationProvider')]
    public function testGetPageAlternateLocation(string $location, string $extension, string $expected): void
    {
        $this->assertSame($expected, $this->site->getPageAlternateLocation($location, $extension));
    }

    public function testGetPageAlternateLocationEmptyLocationReturnsEmptyString(): void
    {
        $this->assertSame('', $this->site->getPageAlternateLocation('', 'md'));
    }

    public function testGetPageAlternateLocationNonStringLocationReturnsEmptyString(): void
    {
        $this->assertSame('', $this->site->getPageAlternateLocation(null, 'md'));
    }

    // =========================================================================
    // writePageAlternateFormats (public) — sidecar files on disk
    // =========================================================================

    public function testWritePageAlternateFormatsCreatesAllSidecarFiles(): void
    {
        $this->assertTrue($this->site->writePageAlternateFormats($this->homePage()));
        $pageDir = $this->siteDir() . '/pages/item-page-1';
        $this->assertFileExists($pageDir . '/index.md');
        $this->assertFileExists($pageDir . '/index.json');
        $this->assertFileExists($pageDir . '/index.yaml');
        $this->assertFileExists($pageDir . '/index.xml');
    }

    public function testWritePageAlternateFormatsMarkdownMirrorsPageContent(): void
    {
        $this->site->writePageAlternateFormats($this->homePage());
        $this->assertSame(
            'Home content',
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.md')
        );
    }

    public function testWritePageAlternateFormatsJsonPayloadContainsPageAndContent(): void
    {
        $this->site->writePageAlternateFormats($this->homePage());
        $payload = json_decode(
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.json'),
            true
        );
        $this->assertSame('Home', $payload['title']);
        $this->assertSame('pages/item-page-1/index.json', $payload['location']);
        $this->assertSame('<p>Home content</p>', $payload['content']);
    }

    public function testWritePageAlternateFormatsExplicitHtmlContentOverridesPageFile(): void
    {
        $this->assertTrue(
            $this->site->writePageAlternateFormats($this->homePage(), '<h1>Override</h1>')
        );
        $this->assertSame(
            '# Override',
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.md')
        );
        $payload = json_decode(
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.json'),
            true
        );
        $this->assertSame('<h1>Override</h1>', $payload['content']);
    }

    public function testWritePageAlternateFormatsEmptyHtmlContentFallsBackToPageFile(): void
    {
        $this->site->writePageAlternateFormats($this->homePage(), '');
        $this->assertSame(
            'Home content',
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.md')
        );
    }

    public function testWritePageAlternateFormatsNonStringHtmlContentFallsBackToPageFile(): void
    {
        $this->site->writePageAlternateFormats($this->homePage(), array('not-a-string'));
        $this->assertSame(
            'Home content',
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.md')
        );
    }

    public function testWritePageAlternateFormatsEmptyPageContentUsesParagraphPlaceholder(): void
    {
        mkdir($this->siteDir() . '/pages/item-page-2', 0777, true);
        touch($this->siteDir() . '/pages/item-page-2/index.html');
        $bare = new stdClass();
        $bare->location = 'pages/item-page-2/index.html';
        $this->assertTrue($this->site->writePageAlternateFormats($bare));
        // htmlToMarkdown('<p></p>') trims down to an empty markdown document.
        $this->assertSame('', file_get_contents($this->siteDir() . '/pages/item-page-2/index.md'));
        $payload = json_decode(
            file_get_contents($this->siteDir() . '/pages/item-page-2/index.json'),
            true
        );
        $this->assertSame('<p></p>', $payload['content']);
    }

    public function testWritePageAlternateFormatsWorksForBarePageObject(): void
    {
        // Serving-side page objects are plain stdClass; only location is required.
        $bare = new stdClass();
        $bare->location = 'pages/item-page-1/index.html';
        $bare->title = 'Bare';
        $this->assertTrue($this->site->writePageAlternateFormats($bare, '<p>custom</p>'));
        $payload = json_decode(
            file_get_contents($this->siteDir() . '/pages/item-page-1/index.json'),
            true
        );
        $this->assertSame('Bare', $payload['title']);
        $this->assertSame('<p>custom</p>', $payload['content']);
    }

    public function testWritePageAlternateFormatsReturnsFalseForNullPage(): void
    {
        $this->assertFalse($this->site->writePageAlternateFormats(null));
    }

    public function testWritePageAlternateFormatsReturnsFalseForPageWithoutLocation(): void
    {
        $this->assertFalse($this->site->writePageAlternateFormats(new JSONOutlineSchemaItem()));
    }

    public function testWritePageAlternateFormatsYamlSidecarRoundTrips(): void
    {
        $this->site->writePageAlternateFormats($this->homePage());
        $yamlPath = $this->siteDir() . '/pages/item-page-1/index.yaml';
        $this->assertFileExists($yamlPath);
        $yaml = file_get_contents($yamlPath);
        $this->assertNotSame('', $yaml);
        $decoded = json_decode($yaml, true);
        if ($decoded !== null) {
            // JSON fallback encoding (valid YAML 1.2) used when ext-yaml is absent.
            $this->assertSame('pages/item-page-1/index.yaml', $decoded['location']);
            $this->assertSame('<p>Home content</p>', $decoded['content']);
        } else {
            // Native yaml_emit encoding.
            $this->assertStringStartsWith('---', $yaml);
        }
    }

    public function testWritePageAlternateFormatsXmlSidecarParses(): void
    {
        $this->site->writePageAlternateFormats($this->homePage());
        $xmlPath = $this->siteDir() . '/pages/item-page-1/index.xml';
        $this->assertFileExists($xmlPath);
        $parsed = simplexml_load_string(file_get_contents($xmlPath));
        $this->assertNotFalse($parsed);
        $this->assertSame('Home', (string) $parsed->title);
        $this->assertSame('pages/item-page-1/index.xml', (string) $parsed->location);
        $this->assertSame('<p>Home content</p>', (string) $parsed->content);
    }

    // =========================================================================
    // getPageAlternatePayload (private, via reflection)
    // =========================================================================

    public function testGetPageAlternatePayloadMergesPageDataWithLocationAndContent(): void
    {
        $payload = $this->invokePrivate(
            'getPageAlternatePayload',
            [$this->homePage(), '<p>body</p>', 'yaml']
        );
        $this->assertIsArray($payload);
        $this->assertSame('pages/item-page-1/index.yaml', $payload['location']);
        $this->assertSame('<p>body</p>', $payload['content']);
        $this->assertSame('Home', $payload['title']);
        $this->assertSame('item-page-1', $payload['id']);
    }

    public function testGetPageAlternatePayloadAcceptsBareObjects(): void
    {
        $bare = new stdClass();
        $bare->location = 'pages/x/index.html';
        $bare->title = 'Bare';
        $payload = $this->invokePrivate('getPageAlternatePayload', [$bare, 'body', 'json']);
        $this->assertSame('pages/x/index.json', $payload['location']);
        $this->assertSame('body', $payload['content']);
        $this->assertSame('Bare', $payload['title']);
    }

    // =========================================================================
    // htmlToMarkdown (private, via reflection)
    // =========================================================================

    public static function emptyHtmlProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'null' => [null],
        ];
    }

    #[DataProvider('emptyHtmlProvider')]
    public function testHtmlToMarkdownReturnsEmptyStringForEmptyInput($html): void
    {
        $this->assertSame('', $this->invokePrivate('htmlToMarkdown', [$html]));
    }

    public function testHtmlToMarkdownNormalizesCarriageReturns(): void
    {
        $this->assertSame(
            'a' . "\n" . 'b',
            $this->invokePrivate('htmlToMarkdown', ['<p>a' . "\r\n" . 'b</p>'])
        );
    }

    public function testHtmlToMarkdownFencesPreCodeBlocks(): void
    {
        $this->assertSame(
            "```\nx = 1;\n```",
            $this->invokePrivate('htmlToMarkdown', ['<pre><code>x = 1;</code></pre>'])
        );
    }

    public function testHtmlToMarkdownFencesPreCodeBlocksWithAttributes(): void
    {
        $this->assertSame(
            "```\necho 1;\n```",
            $this->invokePrivate(
                'htmlToMarkdown',
                ['<pre><code class="language-php">echo 1;</code></pre>']
            )
        );
    }

    public static function imageProvider(): array
    {
        return [
            'src before alt' => ['<img src="a.png" alt="an image">', '![an image](a.png)'],
            'alt before src' => ['<img alt="an image" src="a.png">', '![an image](a.png)'],
            'src only' => ['<img src="a.png">', '![](a.png)'],
        ];
    }

    #[DataProvider('imageProvider')]
    public function testHtmlToMarkdownConvertsImages(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('htmlToMarkdown', [$html]));
    }

    public function testHtmlToMarkdownConvertsAnchors(): void
    {
        $this->assertSame(
            '[link](https://example.com)',
            $this->invokePrivate('htmlToMarkdown', ['<a href="https://example.com">link</a>'])
        );
    }

    public function testHtmlToMarkdownConvertsAnchorsContainingMarkup(): void
    {
        $this->assertSame(
            '[**bold link**](/x)',
            $this->invokePrivate('htmlToMarkdown', ['<a href="/x"><strong>bold link</strong></a>'])
        );
    }

    public static function emphasisProvider(): array
    {
        return [
            'strong' => ['<strong>x</strong>', '**x**'],
            'b' => ['<b>x</b>', '**x**'],
            'em' => ['<em>x</em>', '*x*'],
            'i' => ['<i>x</i>', '*x*'],
        ];
    }

    #[DataProvider('emphasisProvider')]
    public function testHtmlToMarkdownConvertsEmphasis(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('htmlToMarkdown', [$html]));
    }

    public function testHtmlToMarkdownWrapsInlineCode(): void
    {
        $this->assertSame(
            '`$var = 1;`',
            $this->invokePrivate('htmlToMarkdown', ['<code>$var = 1;</code>'])
        );
    }

    public static function lineBreakProvider(): array
    {
        return [
            'br' => ['<p>a<br>b</p>'],
            'br with slash' => ['<p>a<br/>b</p>'],
            'br with spaced slash' => ['<p>a<br />b</p>'],
        ];
    }

    #[DataProvider('lineBreakProvider')]
    public function testHtmlToMarkdownConvertsLineBreaks(string $html): void
    {
        $this->assertSame('a' . "\n" . 'b', $this->invokePrivate('htmlToMarkdown', [$html]));
    }

    public function testHtmlToMarkdownConvertsHeadingsToHashLevels(): void
    {
        $html = '<h1>One</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6>';
        $md = $this->invokePrivate('htmlToMarkdown', [$html]);
        // Leading newline of the first heading is trimmed off the result.
        $this->assertStringStartsWith('# One' . "\n", $md);
        $this->assertStringContainsString("\n## Two\n", $md);
        $this->assertStringContainsString("\n### Three\n", $md);
        $this->assertStringContainsString("\n#### Four\n", $md);
        $this->assertStringContainsString("\n##### Five\n", $md);
        $this->assertStringEndsWith('###### Six', $md);
    }

    public function testHtmlToMarkdownConvertsListItems(): void
    {
        $this->assertSame(
            '- one' . "\n" . '- two',
            $this->invokePrivate('htmlToMarkdown', ['<ul><li>one</li><li>two</li></ul>'])
        );
    }

    public function testHtmlToMarkdownConvertsListItemsContainingMarkup(): void
    {
        $this->assertSame(
            '- **one**',
            $this->invokePrivate('htmlToMarkdown', ['<ul><li><strong>one</strong></li></ul>'])
        );
    }

    public function testHtmlToMarkdownSpacesParagraphs(): void
    {
        $this->assertSame(
            "One\n\nTwo",
            $this->invokePrivate('htmlToMarkdown', ['<p>One</p><p>Two</p>'])
        );
    }

    public function testHtmlToMarkdownUnwrapsBlockContainers(): void
    {
        $this->assertSame(
            "d\n\ns\n\na\n\nm",
            $this->invokePrivate(
                'htmlToMarkdown',
                ['<div>d</div><section>s</section><article>a</article><main>m</main>']
            )
        );
    }

    public function testHtmlToMarkdownStripsSpans(): void
    {
        $this->assertSame(
            'inner',
            $this->invokePrivate('htmlToMarkdown', ['<p><span class="x">inner</span></p>'])
        );
    }

    public function testHtmlToMarkdownPreservesCustomTagsVerbatim(): void
    {
        // Tolerant handling: tags outside the known set pass through untouched.
        $this->assertSame(
            '<my-element>kept</my-element>',
            $this->invokePrivate('htmlToMarkdown', ['<my-element>kept</my-element>'])
        );
    }

    public function testHtmlToMarkdownRichDocument(): void
    {
        $html = '<h1>Title</h1>'
            . '<p>Some <strong>bold</strong> and <em>italic</em> text</p>'
            . '<ul><li>one</li><li>two</li></ul>'
            . '<pre><code>x = 1;</code></pre>'
            . '<p><a href="https://example.com">link</a></p>'
            . '<img src="a.png" alt="an image">';
        $md = $this->invokePrivate('htmlToMarkdown', [$html]);
        $this->assertStringContainsString('# Title', $md);
        $this->assertStringContainsString('**bold**', $md);
        $this->assertStringContainsString('*italic* text', $md);
        $this->assertStringContainsString('- one', $md);
        $this->assertStringContainsString('- two', $md);
        $this->assertStringContainsString("```\nx = 1;\n```", $md);
        $this->assertStringContainsString('[link](https://example.com)', $md);
        $this->assertStringContainsString('![an image](a.png)', $md);
    }

    // =========================================================================
    // encodePageAlternateYaml (private, via reflection)
    // =========================================================================

    public function testEncodePageAlternateYamlReturnsRoundTrippableDocument(): void
    {
        $payload = array('title' => 'Home', 'content' => '<p>Home content</p>');
        $result = $this->invokePrivate('encodePageAlternateYaml', [$payload]);
        $this->assertIsString($result);
        $this->assertNotSame('', $result);
        if (str_starts_with($result, '---')) {
            // native ext-yaml encoding
            $this->assertStringContainsString('title', $result);
        } else {
            // JSON fallback (valid YAML 1.2)
            $this->assertSame($payload, json_decode($result, true));
        }
    }

    // =========================================================================
    // getPageAlternateXml / getPageAlternateXmlNode (private, via reflection)
    // =========================================================================

    public function testGetPageAlternateXmlWrapsPayloadInItemDocument(): void
    {
        $payload = array(
            'id' => 'item-page-1',
            'title' => 'Home',
            'slug' => 'home',
            'order' => 0,
            'parent' => null,
            'metadata' => array('created' => 1000, 'updated' => 2000),
            'location' => 'pages/item-page-1/index.xml',
            'content' => '<p>Home content</p>',
        );
        $xml = $this->invokePrivate('getPageAlternateXml', [$payload]);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<item>', $xml);
        $this->assertStringEndsWith("\n</item>\n", $xml);
        $this->assertStringContainsString('<title>Home</title>', $xml);
        $this->assertStringContainsString('<parent></parent>', $xml);
        $this->assertStringContainsString(
            "<metadata>\n<created>1000</created>\n<updated>2000</updated>\n</metadata>",
            $xml
        );
        $this->assertStringContainsString('<content><![CDATA[<p>Home content</p>]]></content>', $xml);
    }

    public function testGetPageAlternateXmlParsesWithSimplexml(): void
    {
        $payload = array(
            'title' => 'Home',
            'tags' => array('one', 'two'),
            'content' => '<p>Home content</p>',
        );
        $parsed = simplexml_load_string($this->invokePrivate('getPageAlternateXml', [$payload]));
        $this->assertNotFalse($parsed);
        $this->assertSame('Home', (string) $parsed->title);
        $this->assertSame('one', (string) $parsed->tags->item[0]);
        $this->assertSame('two', (string) $parsed->tags->item[1]);
        $this->assertSame('<p>Home content</p>', (string) $parsed->content);
    }

    public function testGetPageAlternateXmlNodeCdataEscapesContentKey(): void
    {
        $this->assertSame(
            "\n<content><![CDATA[a]]]]><![CDATA[>b]]></content>",
            $this->invokePrivate('getPageAlternateXmlNode', ['content', 'a]]>b'])
        );
    }

    public function testGetPageAlternateXmlNodeEscapesScalarValues(): void
    {
        $this->assertSame(
            "\n<title>&lt;b&gt;&amp;&quot;x</title>",
            $this->invokePrivate('getPageAlternateXmlNode', ['title', '<b>&"x'])
        );
    }

    public function testGetPageAlternateXmlNodeSerializesAssociativeArrays(): void
    {
        $this->assertSame(
            "\n<metadata>\n<created>1000</created>\n<updated>2000</updated>\n</metadata>",
            $this->invokePrivate(
                'getPageAlternateXmlNode',
                ['metadata', array('created' => 1000, 'updated' => 2000)]
            )
        );
    }

    public function testGetPageAlternateXmlNodeSerializesListArraysAsItems(): void
    {
        $this->assertSame(
            "\n<tags>\n<item>one</item>\n<item>two</item>\n</tags>",
            $this->invokePrivate('getPageAlternateXmlNode', ['tags', array('one', 'two')])
        );
    }

    public function testGetPageAlternateXmlNodeSerializesObjects(): void
    {
        $metadata = new stdClass();
        $metadata->created = 1000;
        $metadata->updated = 2000;
        $this->assertSame(
            "\n<metadata>\n<created>1000</created>\n<updated>2000</updated>\n</metadata>",
            $this->invokePrivate('getPageAlternateXmlNode', ['metadata', $metadata])
        );
    }

    public function testGetPageAlternateXmlNodeRendersNullAsEmptyElement(): void
    {
        $this->assertSame(
            "\n<parent></parent>",
            $this->invokePrivate('getPageAlternateXmlNode', ['parent', null])
        );
    }

    // =========================================================================
    // getSafeXmlTagName / getSafeXmlCdata (private, via reflection)
    // =========================================================================

    public static function safeXmlTagNameProvider(): array
    {
        return [
            'plain name unchanged' => ['title', 'title'],
            'invalid characters dashed' => ['my key!', 'my-key-'],
            'leading digit prefixed' => ['9lives', 'item-9lives'],
            'empty falls back to item' => ['', 'item'],
            'null falls back to item' => [null, 'item'],
            'already valid unchanged' => ['valid-tag_1', 'valid-tag_1'],
            'markup stripped to dashes' => ['a<b>c', 'a-b-c'],
        ];
    }

    #[DataProvider('safeXmlTagNameProvider')]
    public function testGetSafeXmlTagName($name, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('getSafeXmlTagName', [$name]));
    }

    public function testGetSafeXmlCdataEscapesClosingToken(): void
    {
        $this->assertSame(
            'before ]]]]><![CDATA[> after',
            $this->invokePrivate('getSafeXmlCdata', ['before ]]> after'])
        );
    }

    public function testGetSafeXmlCdataEscapesMultipleTokens(): void
    {
        $this->assertSame(
            'a]]]]><![CDATA[>b]]]]><![CDATA[>c',
            $this->invokePrivate('getSafeXmlCdata', ['a]]>b]]>c'])
        );
    }
}

/**
 * HAXCMSSite test subclass with no overrides: alternate-format generation is
 * exercised through the real implementation against the temp fixture.
 */
class HAXCMSAlternateFormatsTestSite extends HAXCMSSite
{
}

/**
 * Minimal HAXCMS collaborator mock; writePageAlternateFormats only needs
 * staticCache (via getPageContent), already provided by OperationsTestHaxcms.
 */
class HAXCMSAlternateFormatsTestHaxcms extends OperationsTestHaxcms
{
}
