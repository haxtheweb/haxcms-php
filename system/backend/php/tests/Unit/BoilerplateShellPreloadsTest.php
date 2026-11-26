<?php
use PHPUnit\Framework\TestCase;

/**
 * haxtheweb/issues#3428: the static shell (ghpages.html) chooses where build/
 * is served from (this site or the CDN) with a check at the top of <head>.
 * Its preload hints carry their path in data-haxcms-cdn-href, not href, and a
 * script after the managed head gives them their address; with an href in
 * the markup the browser fetched ./build/ at once and a host without build/
 * answered every hint with a 404. The behavior is exercised in haxcms-nodejs
 * test/unit/static-pages.test.cjs against the identical template; this guards
 * that the PHP copy carries it.
 */
class BoilerplateShellPreloadsTest extends TestCase
{
    private function shell(): string
    {
        $html = file_get_contents(__DIR__ . '/../../../../boilerplate/site/ghpages.html');
        $this->assertNotFalse($html, 'ghpages.html exists');
        return $html;
    }

    public function testShellPreloadsHaveNoHrefInTheMarkup(): void
    {
        $html = $this->shell();
        $this->assertStringNotContainsString(' href="./build/', $html);
        $this->assertStringNotContainsString(' href="./wc-registry.json"', $html);
        $this->assertStringContainsString(
            '<link rel="modulepreload" data-haxcms-cdn-href="./build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js"',
            $html
        );
    }

    public function testTheAddressScriptRunsAfterTheManagedHead(): void
    {
        $html = $this->shell();
        $apply = strpos($html, '<script data-haxcms-cdn-preloads>');
        $this->assertNotFalse($apply);
        // the managed head ends at the first <style>; the script must come
        // after it so it is not moved ahead of the hints it addresses
        $firstStyle = strpos($html, '<style>');
        $this->assertNotFalse($firstStyle);
        $this->assertGreaterThan($firstStyle, $apply);
        $this->assertGreaterThan(strrpos($html, 'data-haxcms-cdn-href='), $apply);
        $this->assertLessThan(strpos($html, '</head>'), $apply);
        $this->assertStringContainsString('globalThis.__appCDN', substr($html, $apply, 1500));
    }

    // haxtheweb/issues#3428: the import map is written by the build/ check,
    // so custom/build/ imports @haxtheweb/* from the place core comes from
    public function testImportMapFollowsTheBuildCheck(): void
    {
        $html = $this->shell();
        $this->assertStringNotContainsString('<script type="importmap">', $html);
        $probe = strpos($html, 'var cdn = "./";');
        $map = strpos($html, 'importmap.type = "importmap"');
        $this->assertNotFalse($probe);
        $this->assertNotFalse($map);
        $this->assertGreaterThan($probe, $map);
        $this->assertStringContainsString('cdn + "build/es6/node_modules/@haxtheweb/"', $html);
        // written before any module hint
        $this->assertLessThan(strpos($html, 'rel="modulepreload"'), $map);
    }

    // haxtheweb/issues#3428: hidden under the loading screen, shown without JS
    public function testRenderedPageHiddenWhileLoadingAndShownWithoutJs(): void
    {
        $html = $this->shell();
        $this->assertStringContainsString('body:has(> #loading[aria-busy="true"]) haxcms-site-builder {', $html);
        $this->assertMatchesRegularExpression('/<noscript>[\s\S]*#loading \{\s*display: none !important;[\s\S]*visibility: visible !important;[\s\S]*<\/noscript>/', $html);
    }
}
