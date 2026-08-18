<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the 5 string-based format converters in
 * lib/systemRoutes/v1/:
 *   convertHtmlToMd, convertJsonToYaml, convertYamlToJson,
 *   convertMdToHtml, convertPrettyHtml
 *
 * Each converter file returns a closure that accepts a $context (stdClass
 * with body, routeSuffix, apiBasePath) and calls SiteRouteUtils::
 * sendFormattedResponse(), which sets http_response_code() and print's JSON.
 * We capture the output buffer and parse the JSON.
 *
 * Covers:
 *  - Missing-param validation (400)
 *  - Direct transform happy paths (no network)
 *  - GET-param fallback for html/md
 *  - type=link with SSRF-rejected URL (private IP → no network needed)
 *  - type=link genuine happy path → markTestSkipped (needs network)
 *  - Invalid JSON / YAML string parse errors (400)
 *
 * Expected values are derived from each transform's spec/contract, not
 * copied from the implementation.
 */
class FormatConvertersTest extends TestCase
{
    private $converterDir;
    private $savedGet;
    private $savedFiles;
    private $tmpFiles = array();

    protected function setUp(): void
    {
        $this->converterDir = dirname(__DIR__, 2) . '/lib/systemRoutes/v1';
        $this->savedGet = $_GET;
        $this->savedFiles = $_FILES;
        $_GET = array();
        $_FILES = array();
        $this->tmpFiles = array();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_FILES = $this->savedFiles;
        foreach ($this->tmpFiles as $f) {
            if (is_string($f) && file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = array();
        http_response_code(200);
    }

    /**
     * Load a converter closure from its file. Uses include (not require_once)
     * so the file re-executes and returns a fresh closure on each call. This
     * is safe for all 5 string-based converters because none declare top-level
     * functions/classes — they only `return function ($context) { ... }`.
     */
    private function loadConverter(string $name)
    {
        $path = $this->converterDir . '/' . $name . '.php';
        $this->assertFileExists($path);
        return include $path;
    }

    /**
     * Invoke a converter closure and return [statusCode, decodedJson|null, rawOutput].
     */
    private function invokeConverter(string $name, array $body = array(), string $routeSuffix = 'v1/actions/html-to-md')
    {
        $closure = $this->loadConverter($name);
        $this->assertIsCallable($closure);
        $context = new stdClass();
        $context->body = $body;
        $context->routeSuffix = $routeSuffix;
        $context->apiBasePath = '/system/api';
        ob_start();
        call_user_func($closure, $context);
        $raw = ob_get_clean();
        $status = http_response_code();
        $decoded = json_decode($raw, true);
        return array($status, $decoded, $raw);
    }

    // ------------------------------------------------------------------
    // convertHtmlToMd
    // ------------------------------------------------------------------

    public function testHtmlToMdMissingHtmlParamReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array());
        $this->assertSame(400, $status);
        $this->assertSame(400, $json['status']);
        $this->assertSame('missing `html` param', $json['data']['error']);
        $this->assertSame('', $json['data']['contents']);
    }

    public function testHtmlToMdEmptyStringHtmlReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array('html' => ''));
        $this->assertSame(400, $status);
        $this->assertSame('missing `html` param', $json['data']['error']);
    }

    public function testHtmlToMdConvertsParagraphToMarkdown(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array('html' => '<p>Hello World</p>'));
        $this->assertSame(200, $status);
        $this->assertSame(200, $json['status']);
        $this->assertStringContainsString('Hello World', $json['data']['contents']);
    }

    public function testHtmlToMdConvertsHeadingToAtxStyle(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array('html' => '<h1>Title</h1>'));
        $this->assertSame(200, $status);
        // atx style = # prefix
        $this->assertStringContainsString('# Title', $json['data']['contents']);
    }

    public function testHtmlToMdConvertsBoldAndItalic(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array('html' => '<p><strong>bold</strong> and <em>italic</em></p>'));
        $this->assertSame(200, $status);
        $contents = $json['data']['contents'];
        $this->assertStringContainsString('**bold**', $contents);
        $this->assertStringContainsString('_italic_', $contents);
    }

    public function testHtmlToMdConvertsListItemWithDashStyle(): void
    {
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array('html' => '<ul><li>one</li><li>two</li></ul>'));
        $this->assertSame(200, $status);
        $contents = $json['data']['contents'];
        $this->assertStringContainsString('- one', $contents);
        $this->assertStringContainsString('- two', $contents);
    }

    public function testHtmlToMdFallsBackToGetParamWhenBodyEmpty(): void
    {
        $_GET['html'] = '<p>From GET</p>';
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array());
        $this->assertSame(200, $status);
        $this->assertStringContainsString('From GET', $json['data']['contents']);
    }

    public function testHtmlToMdGetParamIgnoredWhenBodyPresent(): void
    {
        $_GET['html'] = '<p>From GET</p>';
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array('html' => '<p>From body</p>'));
        $this->assertSame(200, $status);
        $this->assertStringContainsString('From body', $json['data']['contents']);
        $this->assertStringNotContainsString('From GET', $json['data']['contents']);
    }

    public function testHtmlToMdTypeLinkWithPrivateUrlSilentlyProducesEmpty(): void
    {
        // SsrfGuard throws on private IP; the catch sets html='' and the
        // converter proceeds to convert empty string → empty markdown.
        // No network access needed — the guard rejects before any fetch.
        list($status, $json) = $this->invokeConverter('convertHtmlToMd', array(
            'html' => 'http://127.0.0.1/',
            'type' => 'link',
        ));
        $this->assertSame(200, $status);
        $this->assertSame(200, $json['status']);
        $this->assertSame('', $json['data']['contents']);
    }

    public function testHtmlToMdTypeLinkHappyPathNeedsNetwork(): void
    {
        $this->markTestSkipped('Genuine type=link happy path requires network access to a public URL');
    }

    // ------------------------------------------------------------------
    // convertJsonToYaml
    // ------------------------------------------------------------------

    public function testJsonToYamlMissingJsonParamReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array(), 'v1/actions/json-to-yaml');
        $this->assertSame(400, $status);
        $this->assertSame('missing `json` param', $json['data']['error']);
    }

    public function testJsonToYamlNullJsonReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array('json' => null), 'v1/actions/json-to-yaml');
        $this->assertSame(400, $status);
        $this->assertSame('missing `json` param', $json['data']['error']);
    }

    public function testJsonToYamlConvertsJsonStringToYaml(): void
    {
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array('json' => '{"name":"test","value":42}'), 'v1/actions/json-to-yaml');
        $this->assertSame(200, $status);
        $yaml = $json['data']['contents'];
        $this->assertStringContainsString('name: test', $yaml);
        $this->assertStringContainsString('value: 42', $yaml);
    }

    public function testJsonToYamlConvertsArrayDirectly(): void
    {
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array('json' => array('a' => 1, 'b' => 2)), 'v1/actions/json-to-yaml');
        $this->assertSame(200, $status);
        $yaml = $json['data']['contents'];
        $this->assertStringContainsString('a: 1', $yaml);
        $this->assertStringContainsString('b: 2', $yaml);
    }

    public function testJsonToYamlInvalidJsonStringReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array('json' => '{invalid json'), 'v1/actions/json-to-yaml');
        $this->assertSame(400, $status);
        $this->assertSame('Invalid JSON string provided', $json['data']['error']);
    }

    public function testJsonToYamlNestedArrayProducesNestedYaml(): void
    {
        $data = array('outer' => array('inner' => 'value'));
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array('json' => $data), 'v1/actions/json-to-yaml');
        $this->assertSame(200, $status);
        $yaml = $json['data']['contents'];
        $this->assertStringContainsString('outer:', $yaml);
        $this->assertStringContainsString('inner: value', $yaml);
    }

    public function testJsonToYamlTypeLinkWithPrivateUrlReturns400(): void
    {
        // SsrfGuard throws on private IP; the catch sends 400 with fetch error.
        list($status, $json) = $this->invokeConverter('convertJsonToYaml', array(
            'json' => 'http://10.0.0.1/data.json',
            'type' => 'link',
        ), 'v1/actions/json-to-yaml');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Failed to fetch JSON from link', $json['data']['error']);
    }

    public function testJsonToYamlTypeLinkHappyPathNeedsNetwork(): void
    {
        $this->markTestSkipped('Genuine type=link happy path requires network access to a public URL');
    }

    // ------------------------------------------------------------------
    // convertYamlToJson
    // ------------------------------------------------------------------

    public function testYamlToJsonMissingYamlParamReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array(), 'v1/actions/yaml-to-json');
        $this->assertSame(400, $status);
        $this->assertSame('missing `yaml` param', $json['data']['error']);
    }

    public function testYamlToJsonEmptyYamlReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array('yaml' => ''), 'v1/actions/yaml-to-json');
        $this->assertSame(400, $status);
        $this->assertSame('missing `yaml` param', $json['data']['error']);
    }

    public function testYamlToJsonWhitespaceOnlyYamlReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array('yaml' => '   '), 'v1/actions/yaml-to-json');
        $this->assertSame(400, $status);
        // The initial param check uses trim() so whitespace-only input is
        // caught as 'missing `yaml` param', not 'Invalid or empty YAML content'
        // (the latter only fires after a type=link fetch returns empty body).
        $this->assertSame('missing `yaml` param', $json['data']['error']);
    }

    public function testYamlToJsonConvertsYamlToJsonString(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array('yaml' => "name: test\nvalue: 42"), 'v1/actions/yaml-to-json');
        $this->assertSame(200, $status);
        $contents = $json['data']['contents'];
        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded);
        $this->assertSame('test', $decoded['name']);
        $this->assertSame(42, $decoded['value']);
    }

    public function testYamlToJsonNestedYamlProducesNestedJson(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array('yaml' => "outer:\n  inner: value"), 'v1/actions/yaml-to-json');
        $this->assertSame(200, $status);
        $decoded = json_decode($json['data']['contents'], true);
        $this->assertSame('value', $decoded['outer']['inner']);
    }

    public function testYamlToJsonPreservesUnicodeAndSlashes(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array('yaml' => "path: a/b/c\ngreeting: héllo"), 'v1/actions/yaml-to-json');
        $this->assertSame(200, $status);
        $contents = $json['data']['contents'];
        // JSON_UNESCAPED_SLASHES → no backslash-escaped forward slashes
        $this->assertStringContainsString('a/b/c', $contents);
        // JSON_UNESCAPED_UNICODE → no \uXXXX for multibyte
        $this->assertStringContainsString('héllo', $contents);
    }

    public function testYamlToJsonTypeLinkWithPrivateUrlReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertYamlToJson', array(
            'yaml' => 'http://169.254.169.254/data.yaml',
            'type' => 'link',
        ), 'v1/actions/yaml-to-json');
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Failed to fetch YAML from link', $json['data']['error']);
    }

    public function testYamlToJsonTypeLinkHappyPathNeedsNetwork(): void
    {
        $this->markTestSkipped('Genuine type=link happy path requires network access to a public URL');
    }

    // ------------------------------------------------------------------
    // convertMdToHtml
    // ------------------------------------------------------------------

    public function testMdToHtmlMissingMdParamReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array(), 'v1/actions/md-to-html');
        $this->assertSame(400, $status);
        $this->assertSame('missing `md` param', $json['data']['error']);
    }

    public function testMdToHtmlEmptyStringMdReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array('md' => ''), 'v1/actions/md-to-html');
        $this->assertSame(400, $status);
        $this->assertSame('missing `md` param', $json['data']['error']);
    }

    public function testMdToHtmlConvertsAtxHeadingToH1(): void
    {
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array('md' => '# Title'), 'v1/actions/md-to-html');
        $this->assertSame(200, $status);
        $html = $json['data']['contents'];
        $this->assertStringContainsString('<h1>Title</h1>', $html);
    }

    public function testMdToHtmlConvertsParagraph(): void
    {
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array('md' => 'Hello World'), 'v1/actions/md-to-html');
        $this->assertSame(200, $status);
        $html = $json['data']['contents'];
        $this->assertStringContainsString('Hello World', $html);
        $this->assertStringContainsString('<p>', $html);
    }

    public function testMdToHtmlConvertsEmphasisAndStrong(): void
    {
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array('md' => '*italic* and **bold**'), 'v1/actions/md-to-html');
        $this->assertSame(200, $status);
        $html = $json['data']['contents'];
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testMdToHtmlFallsBackToGetParam(): void
    {
        $_GET['md'] = '# From GET';
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array(), 'v1/actions/md-to-html');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<h1>From GET</h1>', $json['data']['contents']);
    }

    public function testMdToHtmlTypeLinkWithPrivateUrlProducesEmptyHtml(): void
    {
        // SsrfGuard throws → catch sets mdText='' → Markdown::defaultTransform('')
        // → empty HTML string, returned as 200.
        list($status, $json) = $this->invokeConverter('convertMdToHtml', array(
            'md' => 'http://127.0.0.1/readme.md',
            'type' => 'link',
        ), 'v1/actions/md-to-html');
        $this->assertSame(200, $status);
        // Empty markdown produces empty (or whitespace-only) HTML
        $this->assertSame('', trim($json['data']['contents']));
    }

    public function testMdToHtmlTypeLinkHappyPathNeedsNetwork(): void
    {
        $this->markTestSkipped('Genuine type=link happy path requires network access to a public URL');
    }

    // ------------------------------------------------------------------
    // convertPrettyHtml
    // ------------------------------------------------------------------

    public function testPrettyHtmlMissingHtmlParamReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array(), 'v1/actions/pretty-html');
        $this->assertSame(400, $status);
        $this->assertSame('missing `html` param', $json['data']['error']);
    }

    public function testPrettyHtmlEmptyStringReturns400(): void
    {
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array('html' => ''), 'v1/actions/pretty-html');
        $this->assertSame(400, $status);
        $this->assertSame('missing `html` param', $json['data']['error']);
    }

    public function testPrettyHtmlFormatsMinifiedHtml(): void
    {
        $minified = '<div><p>Hello</p><p>World</p></div>';
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array('html' => $minified), 'v1/actions/pretty-html');
        $this->assertSame(200, $status);
        $pretty = $json['data']['contents'];
        // Pretty-printed output should contain newlines/indentation
        $this->assertNotSame($minified, $pretty);
        $this->assertStringContainsString('Hello', $pretty);
        $this->assertStringContainsString('World', $pretty);
    }

    public function testPrettyHtmlPreservesContentStructure(): void
    {
        $html = '<ul><li>one</li><li>two</li></ul>';
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array('html' => $html), 'v1/actions/pretty-html');
        $this->assertSame(200, $status);
        $pretty = $json['data']['contents'];
        $this->assertStringContainsString('<li>one</li>', $pretty);
        $this->assertStringContainsString('<li>two</li>', $pretty);
    }

    public function testPrettyHtmlStripsXmlEncodingDeclaration(): void
    {
        $html = '<p>test</p>';
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array('html' => $html), 'v1/actions/pretty-html');
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('<?xml encoding="UTF-8">', $json['data']['contents']);
    }

    public function testPrettyHtmlFallsBackToGetParam(): void
    {
        $_GET['html'] = '<p>From GET</p>';
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array(), 'v1/actions/pretty-html');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('From GET', $json['data']['contents']);
    }

    public function testPrettyHtmlTypeLinkWithPrivateUrlProducesEmpty(): void
    {
        list($status, $json) = $this->invokeConverter('convertPrettyHtml', array(
            'html' => 'http://10.0.0.1/page.html',
            'type' => 'link',
        ), 'v1/actions/pretty-html');
        $this->assertSame(200, $status);
        // SsrfGuard throws → catch sets html='' → DOMDocument loads empty →
        // saveHTML produces minimal output (trimmed to empty or DOCTYPE)
        $contents = $json['data']['contents'];
        $this->assertTrue(trim($contents) === '' || strpos($contents, '<!DOCTYPE') !== false || strpos($contents, '<html') !== false);
    }

    public function testPrettyHtmlTypeLinkHappyPathNeedsNetwork(): void
    {
        $this->markTestSkipped('Genuine type=link happy path requires network access to a public URL');
    }
}
