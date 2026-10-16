<?php
use PHPUnit\Framework\TestCase;

/**
 * Security (SEC-UPLOAD): regression guard for the "force-download uploaded
 * markup under /files/" web-server rules.
 *
 * These rules live in web-server config (Apache .htaccess, nginx), not PHP,
 * so this test extracts the regexes from the shipped config files and runs
 * them (all are PCRE) against decoded request paths / on-disk file names.
 * The web servers apply them to the DECODED path (Apache ap_expr
 * %{REQUEST_URI} is r->uri after percent-decoding; FilesMatch tests the file
 * name on disk; nginx location regexes test the normalised $uri), so the
 * samples here are decoded paths. Live curl checks against apache2/nginx are
 * documented in the PR description.
 *
 * Extension list must stay identical to haxcms-nodejs app.js:
 * html, htm, xhtml, xml, svg (case-insensitive).
 */
class FilesMarkupDownloadGuardConfigTest extends TestCase
{
    private static function repoRoot()
    {
        return dirname(__DIR__, 5);
    }

    private static function read($relative)
    {
        $path = self::repoRoot() . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException('missing config file: ' . $relative);
        }
        return file_get_contents($path);
    }

    /** decoded URL paths that must be forced to download */
    private static function blockedPaths()
    {
        return array(
            '/_sites/s/files/x.html',
            '/_sites/s/files/X.HTML',
            '/_sites/s/files/y.htm',
            '/_sites/s/files/z.xhtml',
            '/_sites/s/files/a.xml',
            '/_sites/s/files/b.svg',
            '/_sites/s/files/B.SvG',
            '/_sites/s/files/sub/dir/c.Html',
            '/system/user/files/u.html',
            '/files/x.html',
        );
    }

    /** decoded URL paths that must be left alone */
    private static function allowedPaths()
    {
        return array(
            '/_sites/s/files/ok.png',
            '/_sites/s/files/ok.txt',
            '/_sites/s/files/doc.pdf',
            '/_sites/s/files/x.html.png',
            '/_sites/s/pages/p.html',
            '/_sites/s/index.html',
            '/_sites/s/offline.html',
            '/_sites/s/sitemap.xml',
            '/notfiles.html',
            '/_sites/s/myfiles.html',
        );
    }

    private function assertGuardRegex($pcre, $label)
    {
        $this->assertNotFalse(@preg_match($pcre, ''), $label . ': regex does not compile: ' . $pcre);
        foreach (self::blockedPaths() as $p) {
            $this->assertSame(1, preg_match($pcre, $p), $label . ' must force-download ' . $p);
        }
        foreach (self::allowedPaths() as $p) {
            $this->assertSame(0, preg_match($pcre, $p), $label . ' must not force-download ' . $p);
        }
    }

    public function testRootHtaccessUsesDecodedRequestUriIfRule()
    {
        $conf = self::read('.htaccess');
        $this->assertSame(
            1,
            preg_match('/<If "%\{REQUEST_URI\} =~ m#(.+?)#i">(.*?)<\/If>/s', $conf, $m),
            'root .htaccess must contain the <If %{REQUEST_URI} ...> download guard'
        );
        $this->assertGuardRegex('#' . $m[1] . '#i', 'root .htaccess <If>');
        $this->assertStringContainsString('Header always set Content-Disposition "attachment"', $m[2]);
        $this->assertStringContainsString('Header always set X-Content-Type-Options nosniff', $m[2]);
        // the old html-only raw-URI rule must be gone
        $this->assertStringNotContainsString('"/files/.*\.html?$"', $conf);
    }

    public function testRootHtaccessApache22FallbackCoversSameExtensions()
    {
        $conf = self::read('.htaccess');
        $this->assertSame(
            1,
            preg_match('/SetEnvIfNoCase Request_URI "([^"]+)" force_html_download=1/', $conf, $m)
        );
        $this->assertGuardRegex('#' . $m[1] . '#i', 'root .htaccess 2.2 fallback');
        $this->assertStringContainsString('Header always set Content-Disposition "attachment" env=force_html_download', $conf);
    }

    public function testSiteFilesHtaccessFilesMatchOnDiskName()
    {
        $conf = self::read('system/boilerplate/site/files/.htaccess');
        $this->assertSame(
            1,
            preg_match('/<FilesMatch "(\(\?i\)[^"]*(?:html|svg)[^"]*)">(.*?)<\/FilesMatch>/s', $conf, $m),
            'site files/.htaccess must contain the markup FilesMatch'
        );
        $pcre = '#' . $m[1] . '#';
        foreach (array('x.html', 'X.HTML', 'y.htm', 'z.xhtml', 'a.xml', 'b.svg', 'c.Html') as $f) {
            $this->assertSame(1, preg_match($pcre, $f), 'files/.htaccess must force-download ' . $f);
        }
        foreach (array('ok.png', 'ok.txt', 'x.html.png', 'doc.pdf', 'htmlnotes.md') as $f) {
            $this->assertSame(0, preg_match($pcre, $f), 'files/.htaccess must not force-download ' . $f);
        }
        $this->assertStringContainsString('Header always set Content-Disposition "attachment"', $m[2]);
        $this->assertStringContainsString('Header always set X-Content-Type-Options nosniff', $m[2]);
        // pre-existing execution hardening must remain
        $this->assertStringContainsString('Require all denied', $conf);
    }

    public function testNginxReferenceSnippetLocationRule()
    {
        $this->assertNginxLocationRule('scripts/nginx/haxcms-nginx.conf');
    }

    public function testDdevNginxLocationRule()
    {
        $this->assertNginxLocationRule('.ddev/nginx-site.conf');
    }

    private function assertNginxLocationRule($file)
    {
        $conf = self::read($file);
        $this->assertSame(
            1,
            preg_match('/location ~\* (\/files\/\S+) \{(.*?)\}/s', $conf, $m),
            $file . ' must contain the /files/ markup location'
        );
        $this->assertGuardRegex('#' . $m[1] . '#i', $file);
        $this->assertStringContainsString('add_header Content-Disposition attachment always;', $m[2]);
        // add_header in a location disables inheritance of server-level ones
        $this->assertStringContainsString('add_header X-Content-Type-Options nosniff always;', $m[2]);
    }

    public function testDdevFilesLocationPrecedesStaticSvgLocation()
    {
        // nginx picks the FIRST matching regex location; the static-asset
        // block also matches .svg, so the guard must come before it.
        $conf = self::read('.ddev/nginx-site.conf');
        $guard = strpos($conf, 'location ~* /files/');
        $svg = strpos($conf, 'svg|svgz');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($svg);
        $this->assertLessThan($svg, $guard);
    }
}
