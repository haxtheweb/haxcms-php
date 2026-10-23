<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the single-site fallback renderer
 * (system/boilerplate/site/config.php + index.php).
 *
 * The fallback's manifest is a plain stdClass, not a JSONOutlineSchema, so
 * getSiteMetadata() must not call methods on it. Rendering a page that has a
 * truthy id used to fatal on $this->manifest->getItemKeyById().
 *
 * The fallback only activates when no global HAXCMS exists, and it defines
 * classes at include time, so the scenario renders in a fresh PHP process
 * against a throwaway copy of the boilerplate.
 */
class FallbackRendererPrevNextTest extends TestCase
{
    private $workDir;
    private $siteDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/haxcms_fallback_nav_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->siteDir = $this->workDir . '/site';
        mkdir($this->siteDir . '/pages', 0777, true);
        // __DIR__ = <repo>/system/backend/php/tests/Unit
        $boilerplate = dirname(__DIR__, 4) . '/boilerplate/site';
        $this->assertFileExists($boilerplate . '/config.php');
        copy($boilerplate . '/config.php', $this->siteDir . '/config.php');
        copy($boilerplate . '/index.php', $this->siteDir . '/index.php');
        file_put_contents($this->siteDir . '/wc-registry.json', '{}');
        $items = array();
        foreach (array('one', 'two', 'three') as $order => $slug) {
            mkdir($this->siteDir . '/pages/' . $slug, 0777, true);
            file_put_contents($this->siteDir . '/pages/' . $slug . '/index.html', '<p>' . $slug . '</p>');
            $items[] = array(
                'id' => 'item-' . $slug, 'indent' => 0, 'location' => 'pages/' . $slug . '/index.html',
                'slug' => $slug, 'order' => $order, 'parent' => null,
                'title' => ucfirst($slug), 'description' => 'x', 'metadata' => new stdClass(),
            );
        }
        file_put_contents($this->siteDir . '/site.json', json_encode(array(
            'title' => 'Fallback nav test',
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

    /**
     * Render the <head> metadata for the item at $index. The fallback's own
     * request based page lookup is bypassed so every position in the outline
     * can be exercised, not only the first item.
     */
    private function metadataFor($index)
    {
        $code = '$_SERVER["REQUEST_URI"]="/"; include "config.php"; $c = new HAXSiteConfig(); echo "[START]" . $c->getSiteMetadata($c->manifest->items[' . (int) $index . '], "https://x.test/") . "[END]";';
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($code);
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $this->siteDir);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    public function testMiddlePageRendersWithPrevAndNextLinks(): void
    {
        $html = $this->metadataFor(1);
        $this->assertStringContainsString('[END]', $html, 'metadata rendered instead of a fatal error');
        $this->assertStringContainsString('<link rel="prev" href="one" />', $html);
        $this->assertStringContainsString('<link rel="next" href="three" />', $html);
    }

    public function testFirstPageHasNextButNoPrev(): void
    {
        $html = $this->metadataFor(0);
        $this->assertStringContainsString('[END]', $html);
        $this->assertStringNotContainsString('rel="prev"', $html);
        $this->assertStringContainsString('<link rel="next" href="two" />', $html);
    }

    public function testLastPageHasPrevButNoNext(): void
    {
        $html = $this->metadataFor(2);
        $this->assertStringContainsString('[END]', $html);
        $this->assertStringContainsString('<link rel="prev" href="two" />', $html);
        $this->assertStringNotContainsString('rel="next"', $html);
    }
}
