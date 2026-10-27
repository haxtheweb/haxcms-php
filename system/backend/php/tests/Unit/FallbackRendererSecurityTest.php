<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the single-site fallback renderer
 * (system/boilerplate/site/config.php + index.php):
 *  - N6  manifest-derived values are encoded for their output context
 *  - N7  getPageContent only reads files contained in the site directory
 *
 * The fallback only activates when no global HAXCMS exists, and it defines
 * classes at include time, so each scenario renders in a fresh PHP process
 * against a throwaway copy of the boilerplate.
 */
class FallbackRendererSecurityTest extends TestCase
{
    private $workDir;
    private $siteDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/haxcms_fallback_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->siteDir = $this->workDir . '/site';
        mkdir($this->siteDir . '/pages/home', 0777, true);
        // __DIR__ = <repo>/system/backend/php/tests/Unit
        $boilerplate = dirname(__DIR__, 4) . '/boilerplate/site';
        $this->assertFileExists($boilerplate . '/config.php');
        copy($boilerplate . '/config.php', $this->siteDir . '/config.php');
        copy($boilerplate . '/index.php', $this->siteDir . '/index.php');
        file_put_contents($this->siteDir . '/wc-registry.json', '{}');
        file_put_contents($this->siteDir . '/pages/home/index.html', '<p>home page</p>');
        // a file that lives OUTSIDE the site directory
        file_put_contents($this->workDir . '/secret.txt', 'TOP-SECRET-OUTSIDE-SITE');
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

    /**
     * @param array $site metadata.site overrides merged over defaults
     */
    private function writeManifest(array $metadata = array(), array $topLevel = array())
    {
        $manifest = array_merge(array(
            'title' => 'Fallback test',
            'description' => 'desc',
            'metadata' => array_replace_recursive(array(
                'site' => array('name' => 'fallback-test'),
                'theme' => array('variables' => array('cssVariable' => '--simple-colors-default-theme-blue-7')),
            ), $metadata),
            'items' => array(array(
                'id' => '0', 'indent' => 0, 'location' => 'pages/home/index.html',
                'slug' => 'home', 'order' => 0, 'parent' => null,
                'title' => 'Home', 'description' => 'x', 'metadata' => new stdClass(),
            )),
        ), $topLevel);
        file_put_contents($this->siteDir . '/site.json', json_encode($manifest));
    }

    private function runPhp($code)
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($code);
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $this->siteDir);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private function render()
    {
        return $this->runPhp('$_SERVER["REQUEST_URI"]="/site/"; $_SERVER["SCRIPT_NAME"]="/site/index.php"; $_SERVER["SERVER_NAME"]="x.test"; $_SERVER["SCRIPT_URI"]="https://x.test/"; include "index.php";');
    }

    private function pageContent($location)
    {
        $code = 'include "config.php"; $c = new HAXSiteConfig(); echo "[" . $c->getPageContent((object) array("location" => ' . var_export($location, true) . ')) . "]";';
        return $this->runPhp($code);
    }

    public function testHostileManifestValuesAreEncodedInTheShell(): void
    {
        $xss = '"><script>alert(1)</script>';
        $this->writeManifest(array(
            'site' => array(
                'name' => '</h1><script>alert(1)</script>',
                'dynamicCDN' => '";alert(1);//"></script><script>alert(1)</script>',
                'settings' => array('lang' => 'en' . $xss, 'canonical' => true),
                'domain' => 'javascript:alert(1)//',
            ),
            'theme' => array(
                'path' => 'x' . $xss,
                'variables' => array('cssVariable' => 'red;}</style><script>alert(1)</script>'),
            ),
            'author' => array('socialLink' => 'https://twitter.com/' . $xss),
        ), array('title' => 'T</title><script>alert(1)</script>'));
        $html = $this->render();

        $this->assertStringContainsString('<haxcms-site-builder', $html, 'shell rendered');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('</style><script>', $html);
        $this->assertStringNotContainsString('javascript:', strtolower((string) preg_replace('/<script type="application\/ld\+json">.*?<\/script>/s', '', $html)));
        // color falls back instead of reaching <style>
        $this->assertStringContainsString('--ld-accent: #FF2222;', $html);
        // unsafe CDN is replaced by the local default
        $this->assertStringContainsString('<script src="./build.js"></script>', $html);
        $this->assertStringContainsString('__appCDN="./";', $html);
        // unsafe canonical is dropped, not emitted empty or as javascript:
        $this->assertStringNotContainsString('name="canonical"', $html);
    }

    public function testLegitimateManifestValuesStillWork(): void
    {
        $this->writeManifest(array(
            'site' => array(
                'name' => 'fallback-test',
                'dynamicCDN' => 'https://cdn.example.com/hax/',
                'settings' => array('lang' => 'fr-CA', 'canonical' => true),
                'domain' => 'https://example.com',
            ),
        ));
        $html = $this->render();

        $this->assertStringContainsString('<html lang="fr-CA">', $html);
        $this->assertStringContainsString('--ld-accent: var(--simple-colors-default-theme-blue-7, #FF2222);', $html);
        $this->assertStringContainsString('<script src="https://cdn.example.com/hax/build-haxcms.js"></script>', $html);
        $this->assertStringContainsString('__appCDN="https://cdn.example.com/hax/";', $html);
        $this->assertStringContainsString('<link name="canonical" href="https://example.com/home" />', $html);
        $this->assertStringContainsString('<h1>Loading fallback-test...</h1>', $html);
        $this->assertStringContainsString('<p>home page</p>', $html);
    }

    public function testRelativeCdnPrefixesAreAccepted(): void
    {
        foreach (array('../../', './', '../') as $cdn) {
            $this->writeManifest(array('site' => array('dynamicCDN' => $cdn)));
            $html = $this->render();
            $this->assertStringContainsString('<script src="' . $cdn . 'build.js"></script>', $html, $cdn);
        }
    }

    public function testPageContentReadsOnlyInsideTheSiteDirectory(): void
    {
        $this->writeManifest();
        $this->assertSame('[<p>home page</p>]', $this->pageContent('pages/home/index.html'));
        foreach (array(
            '../secret.txt',
            'pages/../../secret.txt',
            'pages/..//..//secret.txt',
            '....//secret.txt',
            '/etc/hostname',
            "pages/home/index.html\0../../secret.txt",
        ) as $location) {
            $out = $this->pageContent($location);
            $this->assertStringNotContainsString('TOP-SECRET', $out, $location);
            $this->assertSame('[]', $out, $location);
        }
    }

    public function testPageContentRejectsSymlinkEscape(): void
    {
        $this->writeManifest();
        if (!@symlink($this->workDir . '/secret.txt', $this->siteDir . '/pages/home/link.html')) {
            $this->markTestSkipped('symlinks unavailable');
        }
        $this->assertSame('[]', $this->pageContent('pages/home/link.html'));
    }
}
