<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
// HAXCMSSiteTestHaxcms: the HAXCMS collaborator mock
require_once __DIR__ . '/HAXCMSSiteMutationsTest.php';

/**
 * The sitemap is always two plain files, sitemap.xml and sitemap-index.xml:
 * the names haxcms-nodejs writes, the site API links to and the site folder
 * contract lists. Whatever the site's domain looks like, a rebuild writes
 * both, writes no compressed copy and removes one left by an earlier version.
 */
class SitemapFilesTest extends TestCase
{
    private $savedHaxcms;
    private $tmpRoot;
    private $siteName = 'sitemapsite';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_sitemap_' . uniqid();
        $GLOBALS['HAXCMS'] = new HAXCMSSiteTestHaxcms();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        foreach (glob($this->tmpRoot . '/' . $this->siteName . '/*') ?: array() as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->tmpRoot . '/' . $this->siteName);
        @rmdir($this->tmpRoot);
    }

    private function siteWithDomain($domain): SitemapFilesTestSite
    {
        $dir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($dir, 0777, true);
        $site = (object) array('name' => $this->siteName);
        if ($domain !== null) {
            $site->domain = $domain;
        }
        $manifest = (object) array(
            'id' => 'site-uuid-sitemap',
            'title' => 'Sitemap Site',
            'author' => 'Tester',
            'description' => 'A site',
            'license' => 'by-sa',
            'metadata' => (object) array('site' => $site),
            'items' => array(
                (object) array(
                    'id' => 'page-1', 'title' => 'Home', 'slug' => 'home',
                    'location' => 'pages/page-1/index.html', 'order' => 0,
                    'parent' => null, 'indent' => 0, 'description' => 'Home',
                    'metadata' => (object) array('created' => 1000, 'updated' => 86400),
                ),
                (object) array(
                    'id' => 'page-2', 'title' => 'Fish & chips', 'slug' => 'home/fish&chips',
                    'location' => 'pages/page-2/index.html', 'order' => 0,
                    'parent' => 'page-1', 'indent' => 1, 'description' => 'Fish',
                    'metadata' => (object) array('created' => 2000, 'updated' => 172800),
                ),
                (object) array(
                    'id' => 'page-3', 'title' => 'Deep', 'slug' => 'home/fish/deep',
                    'location' => 'pages/page-3/index.html', 'order' => 0,
                    'parent' => 'page-2', 'indent' => 2, 'description' => 'Deep',
                    'metadata' => (object) array('created' => 3000, 'updated' => 259200),
                ),
            ),
        );
        file_put_contents($dir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $loaded = new SitemapFilesTestSite();
        $loaded->load($this->tmpRoot, '/', $this->siteName);
        return $loaded;
    }

    private function sitePath($file): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/' . $file;
    }

    public function testAbsoluteDomainWritesBothPlainFilesAndNoCompressedCopy(): void
    {
        $site = $this->siteWithDomain('https://example.com/course/');
        $site->updateAlternateFormats('sitemap');
        $this->assertFileExists($this->sitePath('sitemap.xml'));
        $this->assertFileExists($this->sitePath('sitemap-index.xml'));
        $this->assertFileDoesNotExist($this->sitePath('sitemap.xml.gz'));
    }

    public function testSiteWithNoDomainWritesTheSameTwoFiles(): void
    {
        $site = $this->siteWithDomain(null);
        $site->updateAlternateFormats('sitemap');
        $this->assertFileExists($this->sitePath('sitemap.xml'));
        $this->assertFileExists($this->sitePath('sitemap-index.xml'));
        $this->assertFileDoesNotExist($this->sitePath('sitemap.xml.gz'));
    }

    public function testCompressedCopyFromAnEarlierVersionIsRemoved(): void
    {
        $site = $this->siteWithDomain('https://example.com/course/');
        file_put_contents($this->sitePath('sitemap.xml.gz'), 'stale');
        $site->updateAlternateFormats('sitemap');
        $this->assertFileDoesNotExist($this->sitePath('sitemap.xml.gz'));
        $this->assertFileExists($this->sitePath('sitemap.xml'));
    }

    public function testSitemapListsEveryPageWithTheFieldsBothBackendsWrite(): void
    {
        $site = $this->siteWithDomain('https://example.com/course/');
        $site->updateAlternateFormats('sitemap');
        $xml = simplexml_load_string(file_get_contents($this->sitePath('sitemap.xml')));
        $this->assertNotFalse($xml, 'sitemap.xml is well-formed');
        $this->assertSame('urlset', $xml->getName());
        $this->assertCount(3, $xml->url);
        $this->assertSame('https://example.com/course/home', (string) $xml->url[0]->loc);
        // a character XML reserves is escaped in the file and reads back whole
        $this->assertSame('https://example.com/course/home/fish&chips', (string) $xml->url[1]->loc);
        $this->assertSame('1970-01-02T00:00:00.000Z', (string) $xml->url[0]->lastmod);
        foreach ($xml->url as $url) {
            $this->assertSame('daily', (string) $url->changefreq);
        }
        // top level, child, and a page at indent 2
        $this->assertSame('1.0', (string) $xml->url[0]->priority);
        $this->assertSame('0.5', (string) $xml->url[1]->priority);
        $this->assertSame('0.7', (string) $xml->url[2]->priority);
    }

    public function testSitemapIndexPointsAtSitemapXml(): void
    {
        $site = $this->siteWithDomain('https://example.com/course/');
        $site->updateAlternateFormats('sitemap');
        $xml = simplexml_load_string(file_get_contents($this->sitePath('sitemap-index.xml')));
        $this->assertNotFalse($xml, 'sitemap-index.xml is well-formed');
        $this->assertSame('sitemapindex', $xml->getName());
        $this->assertSame('https://example.com/course/sitemap.xml', (string) $xml->sitemap[0]->loc);
    }
}

/** the real alternate formats rebuild, without git or the managed file copy */
class SitemapFilesTestSite extends HAXCMSSite
{
    public function gitCommit($msg = 'Committed changes')
    {
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }
}
