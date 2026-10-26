<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the single-site fallback renderer
 * (system/boilerplate/site/config.php + index.php): the page matching the
 * requested URL must drive the server-rendered title / metadata, not always
 * the first item.
 *
 * The fallback defines classes at include time, so each request renders in a
 * fresh PHP process against a throwaway copy of the boilerplate.
 */
class FallbackRendererPageLookupTest extends TestCase
{
    private $workDir;
    private $siteDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/haxcms_fallback_lookup_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->siteDir = $this->workDir . '/site';
        mkdir($this->siteDir . '/pages', 0777, true);
        // __DIR__ = <repo>/system/backend/php/tests/Unit
        $boilerplate = dirname(__DIR__, 4) . '/boilerplate/site';
        $this->assertFileExists($boilerplate . '/config.php');
        copy($boilerplate . '/config.php', $this->siteDir . '/config.php');
        copy($boilerplate . '/index.php', $this->siteDir . '/index.php');
        file_put_contents($this->siteDir . '/wc-registry.json', '{}');
        $items = array();
        foreach (array('one' => 'One', 'two' => 'Two', 'two/child' => 'Child') as $slug => $title) {
            mkdir($this->siteDir . '/pages/' . $slug, 0777, true);
            file_put_contents($this->siteDir . '/pages/' . $slug . '/index.html', '<p>body of ' . $slug . '</p>');
            $items[] = array(
                'id' => 'item-' . str_replace('/', '-', $slug), 'indent' => 0,
                'location' => 'pages/' . $slug . '/index.html',
                'slug' => $slug, 'order' => count($items), 'parent' => null,
                'title' => $title, 'description' => 'about ' . $slug, 'metadata' => new stdClass(),
            );
        }
        file_put_contents($this->siteDir . '/site.json', json_encode(array(
            'title' => 'Lookup test',
            'description' => 'desc',
            'metadata' => array(
                'site' => array('name' => 'fallback-test'),
                'theme' => array('variables' => array('cssVariable' => '--simple-colors-default-theme-blue-7')),
            ),
            'items' => $items,
        )));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->workDir);
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function render($requestUri, $scriptName, $env = null)
    {
        $code = '$_SERVER["REQUEST_URI"]=' . var_export($requestUri, true)
            . '; $_SERVER["SCRIPT_NAME"]=' . var_export($scriptName, true)
            . '; $_SERVER["SERVER_NAME"]="x.test"; $_SERVER["SCRIPT_URI"]="https://x.test/"; include "index.php"; echo "\n[STATUS:" . http_response_code() . "]";';
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($code);
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $this->siteDir, $env);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    public static function pageRequests(): array
    {
        return array(
            'web root'                => array('/two', '/index.php', 'Two'),
            'web root trailing slash' => array('/two/', '/index.php', 'Two'),
            'sub-directory'           => array('/site/two', '/site/index.php', 'Two'),
            'query string ignored'    => array('/site/two?x=1', '/site/index.php', 'Two'),
            'path-info style'         => array('/site/index.php/two', '/site/index.php', 'Two'),
            'nested slug'             => array('/site/two/child', '/site/index.php', 'Child'),
            'url encoded'             => array('/site/%74wo', '/site/index.php', 'Two'),
        );
    }

    #[DataProvider('pageRequests')]
    public function testRequestedPageDrivesTheRenderedShell($uri, $script, $expectedTitle): void
    {
        $html = $this->render($uri, $script);
        $this->assertStringContainsString('<haxcms-site-builder', $html, 'shell rendered');
        $this->assertStringContainsString('<title>Lookup test | ' . $expectedTitle . '</title>', $html);
    }

    public static function homeRequests(): array
    {
        return array(
            'site root'          => array('/site/', '/site/index.php'),
            'site root no slash' => array('/site', '/site/index.php'),
            'index.php itself'   => array('/site/index.php', '/site/index.php'),
        );
    }

    #[DataProvider('homeRequests')]
    public function testHomeRequestsRenderTheFirstPage($uri, $script): void
    {
        $html = $this->render($uri, $script);
        $this->assertStringContainsString('<haxcms-site-builder', $html);
        $this->assertStringContainsString('<title>Lookup test | One</title>', $html);
        $this->assertStringNotContainsString('haxcms-page-miss', $html);
        $this->assertStringNotContainsString('[STATUS:404]', $html);
    }

    public static function unknownRequests(): array
    {
        return array(
            'unknown slug'      => array('/site/nope', '/site/index.php'),
            'unknown nested'    => array('/site/two/nope', '/site/index.php'),
            'unknown web root'  => array('/nope', '/index.php'),
            'traversal attempt' => array('/site/../../etc/passwd', '/site/index.php'),
            'real dir, no slug' => array('/site/pages', '/site/index.php'),
        );
    }

    #[DataProvider('unknownRequests')]
    public function testUnknownSlugIsA404WithThePageMissShell($uri, $script): void
    {
        $html = $this->render($uri, $script);
        $this->assertStringContainsString('[STATUS:404]', $html, 'HTTP status is 404');
        $this->assertStringContainsString('<haxcms-site-builder', $html, 'shell still renders');
        $this->assertStringContainsString('haxcms-page-miss', $html, 'page miss markup shown');
        $this->assertStringContainsString('Page not found', $html);
        // site title only, never another page's metadata or body
        $this->assertStringContainsString('<title>Lookup test</title>', $html);
        $this->assertStringNotContainsString('| One', $html);
        $this->assertStringNotContainsString('body of one', $html);
        $this->assertStringNotContainsString('rel="prev"', $html);
        $this->assertStringNotContainsString('rel="next"', $html);
    }

    public function testKnownPageIsNot404(): void
    {
        $html = $this->render('/site/two', '/site/index.php');
        $this->assertStringNotContainsString('[STATUS:404]', $html);
        $this->assertStringNotContainsString('haxcms-page-miss', $html);
        $this->assertStringContainsString('body of two', $html);
    }

    public function testProxyPathPrefixCanBeNamedInHaxsiteBaseUrl(): void
    {
        $env = array('HAXSITE_BASE_URL' => 'https://example.com/proxy/');
        $known = $this->render('/proxy/two', '/index.php', $env);
        $this->assertStringContainsString('<title>Lookup test | Two</title>', $known);
        $this->assertStringNotContainsString('[STATUS:404]', $known);
        $home = $this->render('/proxy/', '/index.php', $env);
        $this->assertStringContainsString('<title>Lookup test | One</title>', $home);
        $unknown = $this->render('/proxy/nope', '/index.php', $env);
        $this->assertStringContainsString('[STATUS:404]', $unknown);
    }
}
