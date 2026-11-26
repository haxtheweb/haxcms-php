<?php
use PHPUnit\Framework\TestCase;

/**
 * haxtheweb/issues#3431: the page starts the site builder and the active
 * theme loading while it is still being parsed (HAXCMSSite::bootImportsMarkup,
 * put in the managed head by getSiteMetadata), and the shells' safety net
 * only lifts the loading screen once the theme holds something. The expected
 * markup is the same string haxcms-nodejs test/unit/boot-imports.test.cjs
 * checks, so both backends emit identical pages; the safety net's behavior is
 * exercised there against the identical ghpages.html.
 */
class BootImportsTest extends TestCase
{
    private const BUILDER = '@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js';

    public function testRegistryThemeImportsTheBuilderAndTheTheme(): void
    {
        $this->assertSame(
            '  <script data-haxcms-boot>(function(){var c=(globalThis.__appCDN||"./")+"build/es6/node_modules/";["@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js","@haxtheweb/clean-two/clean-two.js"].forEach(function(p){import(c+p).catch(function(){});});})();</script>',
            HAXCMSSite::bootImportsMarkup('./', '@haxtheweb/clean-two/clean-two.js')
        );
    }

    public function testCustomThemeImportsTheSitesOwnBuild(): void
    {
        $this->assertSame(
            '  <script data-haxcms-boot>(function(){var c=(globalThis.__appCDN||"./")+"build/es6/node_modules/";["@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js"].forEach(function(p){import(c+p).catch(function(){});});if(!globalThis.__appCustomEnv){import("./custom/build/custom.es6.js").catch(function(){});}})();</script>',
            HAXCMSSite::bootImportsMarkup('./', './custom/build/custom.es6.js')
        );
    }

    public function testOldThemePathsLoadFromHaxtheweb(): void
    {
        $paths = HAXCMSSite::bootImportPaths('@lrnwebcomponents/clean-one/clean-one.js');
        $this->assertSame(array(self::BUILDER, '@haxtheweb/clean-one/clean-one.js'), $paths['registry']);
    }

    public function testAnythingElseFromSiteJsonIsDropped(): void
    {
        $bad = array(
            '../x.js',
            '@x/../y.js',
            './custom/build/../../x.js',
            'https://evil.example/x.js',
            'javascript:alert(1)',
            '@a/b".js',
            '@a/b<script>.js',
            '@a/b\\c.js',
            './custom/other.js',
            '',
        );
        foreach ($bad as $path) {
            $paths = HAXCMSSite::bootImportPaths($path);
            $this->assertSame(array(self::BUILDER), $paths['registry'], $path);
            $this->assertSame('', $paths['custom'], $path);
            if ($path !== '') {
                $this->assertStringNotContainsString($path, HAXCMSSite::bootImportsMarkup('./', $path));
            }
        }
    }

    public function testAnUnrecognizedBaseMeansNoScript(): void
    {
        foreach (array('"><script>', 'javascript:x/', '//cdn.example/', 'https://cdn.example', '') as $base) {
            $this->assertSame('', HAXCMSSite::bootImportsMarkup($base, ''), $base);
        }
        $this->assertStringContainsString(
            '(globalThis.__appCDN||"https://cdn.hax.cloud/cdn/")',
            HAXCMSSite::bootImportsMarkup('https://cdn.hax.cloud/cdn/', '')
        );
    }

    /**
     * the safety net is in every PHP shell, replacing the fixed 2.5 s lift
     */
    public function testShellsWaitForTheThemeBeforeTheSafetyNetLifts(): void
    {
        foreach (array('index.html', 'ghpages.html', 'index.php') as $file) {
            $html = file_get_contents(__DIR__ . '/../../../../boilerplate/site/' . $file);
            $this->assertNotFalse($html, $file);
            $this->assertStringContainsString('function hideWhenSettled(delay) {', $html, $file);
            $this->assertStringContainsString('hideWhenSettled(2500);', $html, $file);
            $this->assertStringContainsString("document.querySelector('haxcms-site-builder > .haxcms-theme-element')", $html, $file);
            $this->assertDoesNotMatchRegularExpression('/hideLoading\(\);\s*\}, 2500\);/', $html, $file);
        }
    }

    /**
     * every shell hides the theme under the loading screen (themes reveal
     * themselves with their own :host visibility) and shows the server's
     * page without JavaScript
     */
    public function testShellsHideTheThemeUnderTheLoadingScreen(): void
    {
        foreach (array('index.html', 'ghpages.html', 'index.php') as $file) {
            $html = file_get_contents(__DIR__ . '/../../../../boilerplate/site/' . $file);
            $this->assertStringContainsString('body:has(> #loading[aria-busy="true"]) haxcms-site-builder > .haxcms-theme-element {', $html, $file);
            $this->assertMatchesRegularExpression('/<noscript>[\s\S]*visibility: visible !important;[\s\S]*<\/noscript>/', $html, $file);
        }
    }
}
