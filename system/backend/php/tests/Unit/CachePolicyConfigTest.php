<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Browser cache policy, kept identical to haxcms-nodejs (app.js
 * BUILD_ASSET_CACHE_CONTROL / isCacheableBuildAssetPath, and page HTML via
 * setUncachedResponseHeaders):
 *
 * - build modules (build/es6/...) except the component registry, a site's
 *   custom/build output and uploads: public, max-age=3600. The paths carry no
 *   version, so nothing longer: a page could mix old and new modules after
 *   an upgrade.
 * - every other static file: public, max-age=0 (revalidate on each use).
 * - responses PHP answers keep their own Cache-Control (setifempty).
 * - a site's page HTML: private, no-cache, must-revalidate; no-store when the
 *   request carries credentials.
 *
 * The rules live in Apache config, so this test extracts the ap_expr
 * regexes from the shipped .htaccess files and runs them (PCRE) against
 * decoded request paths. A live Apache check is in the PR description.
 */
class CachePolicyConfigTest extends TestCase
{
    private static function read($relative)
    {
        $path = dirname(__DIR__, 5) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException('missing file: ' . $relative);
        }
        return file_get_contents($path);
    }

    public static function htaccessFiles()
    {
        return array(
            'root' => array('.htaccess'),
            'site boilerplate' => array('system/boilerplate/site/.htaccess'),
        );
    }

    #[DataProvider('htaccessFiles')]
    public function testNoLongLifetimeForUnversionedFiles($file)
    {
        $conf = self::read($file);
        // the old default cached every static file (site.json, page content,
        // build modules) for two weeks
        $this->assertStringNotContainsString('ExpiresDefault', $conf);
        $this->assertDoesNotMatchRegularExpression('/max-age=(?!0\b|3600\b)\d+/', $conf);
        $this->assertStringNotContainsString('immutable', $conf);
    }

    #[DataProvider('htaccessFiles')]
    public function testBuildModulesHourEverythingElseRevalidates($file)
    {
        $conf = self::read($file);
        $this->assertSame(
            1,
            preg_match('/<If "([^"]*build\/[^"]*)">\s*Header setifempty Cache-Control "public, max-age=3600"\s*<\/If>\s*<Else>\s*Header setifempty Cache-Control "public, max-age=0"\s*<\/Else>/s', $conf, $m),
            $file . ': build/everything-else cache rule'
        );
        // %{REQUEST_URI} =~ m#...# and !~ m#...# clauses joined by &&
        preg_match_all('/%\{REQUEST_URI\} (=~|!~) m#(.+?)#/', $m[1], $clauses, PREG_SET_ORDER);
        $this->assertCount(3, $clauses, $file . ': expected three REQUEST_URI clauses');
        $matches = function ($path) use ($clauses) {
            foreach ($clauses as $c) {
                $hit = preg_match('#' . $c[2] . '#', $path) === 1;
                if (($c[1] === '=~') !== $hit) {
                    return false;
                }
            }
            return true;
        };
        $hour = array(
            '/_sites/s/build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js',
            '/build/es6/node_modules/lit/index.js',
            '/_sites/s/build/es6/node_modules/@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js',
        );
        $revalidate = array(
            '/_sites/s/site.json',
            '/_sites/s/pages/item-1/index.html',
            '/_sites/s/wc-registry.json',
            '/_sites/s/build/es6/node_modules/@haxtheweb/haxcms-elements/wc-registry.json',
            '/_sites/s/build/wc-registry-graph.json',
            '/_sites/s/custom/build/custom.es6.js',
            '/_sites/s/files/build/report.js',
            '/_sites/s/build.js',
            '/_sites/s/build-haxcms.js',
            '/_sites/s/theme/theme.css',
            '/_sites/s/assets/favicon.ico',
        );
        foreach ($hour as $p) {
            $this->assertTrue($matches($p), $file . ': an hour for ' . $p);
        }
        foreach ($revalidate as $p) {
            $this->assertFalse($matches($p), $file . ': revalidate ' . $p);
        }
    }

    public function testSitePageHtmlUsesTheSharedUncachedPolicy()
    {
        $index = self::read('system/boilerplate/site/index.php');
        $this->assertStringContainsString(
            'HAXCMS::sendUncachedResponseHeaders(HAXCMS::requestCarriesCredentials())',
            $index
        );
        // headers go out before the page markup
        $this->assertLessThan(
            strpos($index, '<!DOCTYPE html>'),
            strpos($index, 'sendUncachedResponseHeaders')
        );
        $this->assertSame('private, no-cache, must-revalidate', HAXCMS::uncachedCacheControl(false));
        $this->assertStringStartsWith('no-store', HAXCMS::uncachedCacheControl(true));
    }
}
