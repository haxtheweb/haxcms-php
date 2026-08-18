<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the FeedMe class (lib/RSS.php).
 *
 * Tests RSS 2.0 and Atom feed generation, including:
 *   - getRSSFeed: domain fallback, XML structure, categories, copyright
 *   - rssItems: item generation, content truncation, limit trimming,
 *     category elements, secure path resolution
 *   - getAtomFeed: Atom XML structure, author resolution
 *   - atomItems: entry generation, CDATA content, tags
 *   - resolveSiteLocationPath: null byte, traversal, non-existent,
 *     symlink escape rejection (via reflection)
 *
 * Uses a real temp site fixture with site.json and page HTML files
 * loaded via a HAXCMSSite subclass with stubbed collaborators.
 */
class RSSFeedTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'rss-site';
    private $siteRoot;
    private $feed;
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_rss_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_rss_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixture();

        $this->haxcms = new RSSTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $this->site = new RSSTestSite();
        $this->site->load($this->tmpRoot, '/', $this->siteName);

        $this->feed = new FeedMe();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildSiteFixture(): void
    {
        mkdir($this->siteRoot . '/pages/item-a', 0777, true);
        mkdir($this->siteRoot . '/pages/item-b', 0777, true);

        file_put_contents(
            $this->siteRoot . '/pages/item-a/index.html',
            '<p>Content for item A with <strong>HTML</strong> tags.</p>'
        );
        file_put_contents(
            $this->siteRoot . '/pages/item-b/index.html',
            '<p>Content for item B.</p>'
        );

        $manifest = (object)array(
            'id' => 'site-rss-uuid',
            'title' => 'RSS Test Site',
            'author' => 'Test Author',
            'description' => 'A test site for RSS generation',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'domain' => 'https://example.com',
                    'settings' => (object)array(
                        'pathauto' => false,
                        'lang' => 'en-US',
                    ),
                    'created' => 1000000,
                    'updated' => 2000000,
                ),
                'tags' => array('education', 'OER & open content'),
                'platform' => (object)array(
                    'features' => (object)array(),
                ),
            ),
            'items' => array(
                (object)array(
                    'id' => 'item-a',
                    'indent' => 0,
                    'location' => 'pages/item-a/index.html',
                    'slug' => 'item-a',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Item A',
                    'description' => 'First item description',
                    'metadata' => (object)array(
                        'created' => 1000000,
                        'updated' => 1100000,
                        'tags' => array('tag1', 'tag2 & special'),
                    ),
                ),
                (object)array(
                    'id' => 'item-b',
                    'indent' => 0,
                    'location' => 'pages/item-b/index.html',
                    'slug' => 'item-b',
                    'order' => 1,
                    'parent' => null,
                    'title' => 'Item B',
                    'description' => 'Second item description',
                    'metadata' => (object)array(
                        'created' => 2000000,
                        'updated' => 2100000,
                    ),
                ),
            ),
        );
        file_put_contents(
            $this->siteRoot . '/site.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod('FeedMe', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->feed, $args);
    }

    // =========================================================================
    // getRSSFeed — structure and domain fallback
    // =========================================================================

    public function testGetRSSFeedProducesValidXmlStructure(): void
    {
        $xml = $this->feed->getRSSFeed($this->site, 'https://example.com');
        $this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $xml);
        $this->assertStringContainsString('<rss', $xml);
        $this->assertStringContainsString('<channel>', $xml);
        $this->assertStringContainsString('<title>RSS Test Site</title>', $xml);
        $this->assertStringContainsString('<link>https://example.com/</link>', $xml);
        $this->assertStringContainsString('<description>A test site for RSS generation</description>', $xml);
        $this->assertStringContainsString('<language>en-US</language>', $xml);
        $this->assertStringContainsString('<generator>HAXcms PHP</generator>', $xml);
        $this->assertStringContainsString('</channel>', $xml);
        $this->assertStringContainsString('</rss>', $xml);
    }

    public function testGetRSSFeedUsesDomainFromManifestWhenEmpty(): void
    {
        $xml = $this->feed->getRSSFeed($this->site, '');
        $this->assertStringContainsString('https://example.com/', $xml);
    }

    public function testGetRSSFeedFallsBackToSitesPathWhenNoDomain(): void
    {
        // Remove domain from manifest
        unset($this->site->manifest->metadata->site->domain);
        $this->haxcms->domainValue = '';
        $xml = $this->feed->getRSSFeed($this->site, '');
        $this->assertStringContainsString('/sites/' . $this->siteName . '/', $xml);
    }

    public function testGetRSSFeedIncludesCopyrightWhenDomainPresent(): void
    {
        $xml = $this->feed->getRSSFeed($this->site, 'https://example.com');
        $this->assertStringContainsString('<copyright>Copyright (C)', $xml);
        $this->assertStringContainsString('example.com', $xml);
    }

    public function testGetRSSFeedIncludesSiteCategoriesFromTags(): void
    {
        $xml = $this->feed->getRSSFeed($this->site, 'https://example.com');
        $this->assertStringContainsString('<category>education</category>', $xml);
        $this->assertStringContainsString('OER &amp; open content', $xml);
    }

    public function testGetRSSFeedIncludesAtomSelfLink(): void
    {
        $xml = $this->feed->getRSSFeed($this->site, 'https://example.com');
        $this->assertStringContainsString('atom:link', $xml);
        $this->assertStringContainsString('href="https://example.com/rss.xml"', $xml);
        $this->assertStringContainsString('rel="self"', $xml);
    }

    public function testGetRSSFeedEscapesSpecialCharactersInTitle(): void
    {
        $this->site->manifest->title = 'Test <script> & "quotes"';
        $xml = $this->feed->getRSSFeed($this->site, 'https://example.com');
        $this->assertStringContainsString('Test &lt;script&gt; &amp; &quot;quotes&quot;', $xml);
        $this->assertStringNotContainsString('<script>', $xml);
    }

    // =========================================================================
    // rssItems — item generation
    // =========================================================================

    public function testRssItemsGeneratesItemElements(): void
    {
        $items = $this->feed->rssItems($this->site, 'https://example.com');
        $this->assertStringContainsString('<item>', $items);
        $this->assertStringContainsString('<title>Item A</title>', $items);
        $this->assertStringContainsString('<title>Item B</title>', $items);
        $this->assertStringContainsString('<link>https://example.com/item-a</link>', $items);
        $this->assertStringContainsString('<link>https://example.com/item-b</link>', $items);
    }

    public function testRssItemsIncludesItemCategories(): void
    {
        $items = $this->feed->rssItems($this->site, 'https://example.com');
        $this->assertStringContainsString('<category>tag1</category>', $items);
        $this->assertStringContainsString('tag2 &amp; special', $items);
    }

    public function testRssItemsStripsHtmlFromContentDescription(): void
    {
        $items = $this->feed->rssItems($this->site, 'https://example.com');
        // Content should be stripped of HTML tags
        $this->assertStringContainsString('Content for item A', $items);
        $this->assertStringNotContainsString('<strong>HTML</strong>', $items);
    }

    public function testRssItemsRespectsLimit(): void
    {
        $items = $this->feed->rssItems($this->site, 'https://example.com', 1);
        // Only one <item> should be present
        $this->assertSame(1, substr_count($items, '<item>'));
    }

    public function testRssItemsTruncatesLongContent(): void
    {
        // Create a page with very long content
        $longContent = '<p>' . str_repeat('Lorem ipsum dolor sit amet. ', 50) . '</p>';
        file_put_contents(
            $this->siteRoot . '/pages/item-a/index.html',
            $longContent
        );

        $items = $this->feed->rssItems($this->site, 'https://example.com');
        // The description should contain the truncation marker
        $this->assertStringContainsString('...', $items);
    }

    public function testRssItemsIncludesPubDate(): void
    {
        $items = $this->feed->rssItems($this->site, 'https://example.com');
        $this->assertStringContainsString('<pubDate>', $items);
        $this->assertStringContainsString('</pubDate>', $items);
    }

    // =========================================================================
    // getAtomFeed — structure
    // =========================================================================

    public function testGetAtomFeedProducesValidXmlStructure(): void
    {
        $xml = $this->feed->getAtomFeed($this->site, 'https://example.com');
        $this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $xml);
        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);
        $this->assertStringContainsString('<title>RSS Test Site</title>', $xml);
        $this->assertStringContainsString('<subtitle>A test site for RSS generation</subtitle>', $xml);
        $this->assertStringContainsString('<author>', $xml);
        $this->assertStringContainsString('<name>Test Author</name>', $xml);
        $this->assertStringContainsString('</feed>', $xml);
    }

    public function testGetAtomFeedResolvesAuthorFromMetadataWhenAuthorEmpty(): void
    {
        $this->site->manifest->author = '';
        $this->site->manifest->metadata->author = new stdClass();
        $this->site->manifest->metadata->author->name = 'Metadata Author';
        $xml = $this->feed->getAtomFeed($this->site, 'https://example.com');
        $this->assertStringContainsString('<name>Metadata Author</name>', $xml);
    }

    // =========================================================================
    // atomItems — entry generation
    // =========================================================================

    public function testAtomItemsGeneratesEntryElements(): void
    {
        $items = $this->feed->atomItems($this->site, 'https://example.com');
        $this->assertStringContainsString('<entry>', $items);
        $this->assertStringContainsString('<title>Item A</title>', $items);
        $this->assertStringContainsString('<title>Item B</title>', $items);
        $this->assertStringContainsString('<summary>First item description</summary>', $items);
    }

    public function testAtomItemsIncludesCDataContent(): void
    {
        $items = $this->feed->atomItems($this->site, 'https://example.com');
        $this->assertStringContainsString('<content type="html">', $items);
        $this->assertStringContainsString('<![CDATA[', $items);
        $this->assertStringContainsString('Content for item A', $items);
    }

    public function testAtomItemsIncludesCategoryTags(): void
    {
        $items = $this->feed->atomItems($this->site, 'https://example.com');
        $this->assertStringContainsString('<category term="tag1"', $items);
        $this->assertStringContainsString('tag2 &amp; special', $items);
    }

    public function testAtomItemsRespectsLimit(): void
    {
        $items = $this->feed->atomItems($this->site, 'https://example.com', 1);
        $this->assertSame(1, substr_count($items, '<entry>'));
    }

    public function testAtomItemsEscapesCDataClosingTag(): void
    {
        // Write content that contains ]]>
        file_put_contents(
            $this->siteRoot . '/pages/item-a/index.html',
            '<p>Content with ]]> attempt</p>'
        );
        $items = $this->feed->atomItems($this->site, 'https://example.com');
        // The ]]> should be split to prevent CDATA injection
        $this->assertStringContainsString(']]]]><![CDATA[>', $items);
    }

    // =========================================================================
    // resolveSiteLocationPath — security tests (via reflection)
    // =========================================================================

    public function testResolveSiteLocationPathEmptyLocationReturnsFalse(): void
    {
        $result = $this->invokePrivate('resolveSiteLocationPath', [
            $this->siteRoot, '',
        ]);
        $this->assertFalse($result);
    }

    public function testResolveSiteLocationPathNullByteReturnsFalse(): void
    {
        $result = $this->invokePrivate('resolveSiteLocationPath', [
            $this->siteRoot, "pages/item-a\0/index.html",
        ]);
        $this->assertFalse($result);
    }

    public function testResolveSiteLocationPathTraversalReturnsFalse(): void
    {
        $result = $this->invokePrivate('resolveSiteLocationPath', [
            $this->siteRoot, '../../../etc/passwd',
        ]);
        $this->assertFalse($result);
    }

    public function testResolveSiteLocationPathNonExistentFileReturnsFalse(): void
    {
        $result = $this->invokePrivate('resolveSiteLocationPath', [
            $this->siteRoot, 'pages/nonexistent/index.html',
        ]);
        $this->assertFalse($result);
    }

    public function testResolveSiteLocationPathValidFileReturnsPath(): void
    {
        $result = $this->invokePrivate('resolveSiteLocationPath', [
            $this->siteRoot, 'pages/item-a/index.html',
        ]);
        $this->assertNotFalse($result);
        $this->assertStringContainsString('pages/item-a/index.html', $result);
    }

    public function testResolveSiteLocationPathSymlinkEscapeReturnsFalse(): void
    {
        // Create a symlink inside the site that points outside
        $outsideDir = sys_get_temp_dir() . '/haxcms_rss_outside_' . uniqid();
        mkdir($outsideDir);
        file_put_contents($outsideDir . '/secret.txt', 'secret data');
        @symlink($outsideDir, $this->siteRoot . '/pages/escape-link');

        $result = $this->invokePrivate('resolveSiteLocationPath', [
            $this->siteRoot, 'pages/escape-link/secret.txt',
        ]);
        // Should return false because the resolved path is outside the site root
        $this->assertFalse($result);

        @unlink($this->siteRoot . '/pages/escape-link');
        $this->rrmdir($outsideDir);
    }
}

/**
 * HAXCMS mock for RSS feed tests. Adds getDomain() which is required
 * by FeedMe::getRSSFeed for domain fallback.
 */
class RSSTestHaxcms extends OperationsTestHaxcms
{
    public $domainValue = 'https://iam.example.com';

    public function getDomain()
    {
        return $this->domainValue;
    }
}

/**
 * HAXCMSSite test subclass for RSS feed tests. No git/twig stubs needed
 * since FeedMe only reads from the site (no mutations).
 */
class RSSTestSite extends HAXCMSSite
{
    // Uses real HAXCMSSite methods (load, sortItems, getLanguage, etc.)
    // with no overrides needed for read-only feed generation.
}
