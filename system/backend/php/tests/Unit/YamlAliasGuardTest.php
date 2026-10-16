<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

include_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteRouteUtils.php';

/**
 * Security (SEC-SSRF / YAML alias amplification): untrusted YAML is capped at
 * 256 KiB and documents using anchors/aliases are refused with HTTP 400
 * "YAML anchors and aliases are not supported" (same limit + message as
 * haxcms-nodejs). Covers the detector and the convertYamlToJson route.
 */
class YamlAliasGuardTest extends TestCase
{
    protected function setUp(): void
    {
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        http_response_code(200);
    }

    public static function aliasProvider(): array
    {
        return array(
            'scalar anchor + alias' => array("a: &x 1\nb: *x"),
            'merge key' => array("base: &b {x: 1}\nother:\n  <<: *b"),
            'sequence item' => array("- &a [1, 2]\n- *a"),
            'flow mapping' => array("{a: &x 1, b: *x}"),
            'flow sequence alias only' => array("[*x]"),
            'flow sequence anchor' => array("a: [1, &x 2]"),
            'document marker anchor' => array("--- &root\na: 1"),
            'tag then anchor' => array("key: !!str &a val"),
            'complex key anchor' => array("? &k key\n: v"),
            'alias as key' => array("*k : v"),
            'nested indent' => array("a:\n  b:\n    - &deep x\n    - *deep"),
            'billion laughs' => array(self::billionLaughs()),
        );
    }

    #[DataProvider('aliasProvider')]
    public function testDetectsAnchorsAndAliases(string $yaml): void
    {
        $this->assertTrue(SiteRouteUtils::yamlUsesAnchorsOrAliases($yaml));
    }

    public static function plainProvider(): array
    {
        return array(
            'ampersand and asterisk in plain scalar' => array("title: R&D * notes"),
            'single quoted' => array("title: 'it''s *not* &alias'"),
            'double quoted' => array("title: \"*quoted* &x\""),
            'multi-line double quoted' => array("multi: \"line one\n  *still quoted*\"\nb: 1"),
            'literal block scalar' => array("desc: |\n  *emphasis* line\n  &amp; more\nnext: ok"),
            'folded block scalar' => array("desc: >-\n  * bullet\n  *bold*\n"),
            'comment' => array("a: b # *comment &x"),
            'comment line' => array("k: v\n# &x comment\n"),
            'math' => array("math: 2*3&4"),
            'url query' => array("url: http://x.com/?a=1&b=2"),
            'front matter' => array("title: Hello\ntags: [a, b]\ndescription: Fish & chips"),
        );
    }

    #[DataProvider('plainProvider')]
    public function testDoesNotFlagOrdinaryYaml(string $yaml): void
    {
        $this->assertFalse(SiteRouteUtils::yamlUsesAnchorsOrAliases($yaml));
        // and it still parses
        \Symfony\Component\Yaml\Yaml::parse($yaml);
    }

    public function testParityConstants(): void
    {
        $this->assertSame(262144, SiteRouteUtils::YAML_MAX_INPUT_BYTES);
        $this->assertSame('YAML anchors and aliases are not supported', SiteRouteUtils::YAML_ANCHOR_ALIAS_ERROR);
    }

    private static function billionLaughs()
    {
        $y = "a: &a [\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\"]\n";
        $prev = 'a';
        foreach (array('b', 'c', 'd', 'e', 'f', 'g', 'h', 'i') as $n) {
            $y .= $n . ': &' . $n . ' [' . implode(',', array_fill(0, 9, '*' . $prev)) . "]\n";
            $prev = $n;
        }
        return $y;
    }

    private function invoke(array $body)
    {
        $closure = include dirname(__DIR__, 2) . '/lib/systemRoutes/v1/convertYamlToJson.php';
        $context = new stdClass();
        $context->body = $body;
        $context->routeSuffix = 'v1/actions/yaml-to-json';
        $context->apiBasePath = '/system/api';
        ob_start();
        call_user_func($closure, $context);
        $raw = ob_get_clean();
        return array(http_response_code(), json_decode($raw, true));
    }

    public function testRouteRejectsBillionLaughs(): void
    {
        list($status, $json) = $this->invoke(array('yaml' => self::billionLaughs()));
        $this->assertSame(400, $status);
        $this->assertSame('YAML anchors and aliases are not supported', $json['data']['error']);
        $this->assertSame('', $json['data']['contents']);
    }

    public function testRouteRejectsOversizedInput(): void
    {
        $yaml = 'k: "' . str_repeat('x', 262144) . '"';
        list($status, $json) = $this->invoke(array('yaml' => $yaml));
        $this->assertSame(400, $status);
        $this->assertStringContainsString('262144', $json['data']['error']);
    }

    public function testRouteStillConvertsOrdinaryYaml(): void
    {
        list($status, $json) = $this->invoke(array('yaml' => "title: R&D\nlist: [a, b]"));
        $this->assertSame(200, $status);
        $decoded = json_decode($json['data']['contents'], true);
        $this->assertSame('R&D', $decoded['title']);
        $this->assertSame(array('a', 'b'), $decoded['list']);
    }
}
