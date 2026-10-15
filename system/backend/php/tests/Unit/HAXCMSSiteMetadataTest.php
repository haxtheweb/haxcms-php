<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for HAXCMSSite::getSiteMetadata(), the rendered
 * <head> metadata blob (lib/HAXCMSSite.php): title/description fallbacks,
 * robots, canonical + prev/next + alternate links, modulepreload/content-tag
 * preload emission (and dedup), theme + license + social tags, and the
 * JSON-LD @graph structured data. The HAXCMS collaborator mock records
 * buildShellModulepreloadPaths / getContentTagPath / dispatchEvent calls.
 *
 * A real temp-site fixture provides two pages (second nested under home) so
 * manifest lookups, breadcrumbs and prev/next run against real data.
 */
class HAXCMSSiteMetadataTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $savedServer;
    private $tmpRoot;
    private $siteName = 'metasite';
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->savedServer = $_SERVER;
        unset($_SERVER['SCRIPT_NAME']);
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_metadata_' . uniqid();
        $this->buildSiteFixture($this->siteName);
        $this->haxcms = new HAXCMSMetadataTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = new HAXCMSSiteMetadataTestSite();
        $this->site->load($this->tmpRoot, '/', $this->siteName);
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_SERVER = $this->savedServer;
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir($dir): void
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

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function buildSiteFixture($name): void
    {
        $dir = $this->tmpRoot . '/' . $name;
        mkdir($dir . '/pages/item-page-1', 0777, true);
        mkdir($dir . '/pages/item-page-2', 0777, true);
        file_put_contents($dir . '/pages/item-page-1/index.html', '<p>Home content</p>');
        file_put_contents($dir . '/pages/item-page-2/index.html', '<p>Second content</p>');
        $manifest = (object) array(
            'id' => 'site-metadata-uuid',
            'title' => 'Meta Test Site',
            'author' => 'Tester',
            'description' => 'A site for metadata',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $name,
                    'domain' => 'https://meta.example.com',
                    'settings' => (object) array(
                        'lang' => 'en',
                        'private' => false,
                        'canonical' => true,
                    ),
                ),
                'theme' => (object) array(
                    'path' => '@haxtheweb/base-theme/haxcms-theme.js',
                    'variables' => (object) array(
                        'hexCode' => '#81adc4',
                        'image' => 'https://example.com/banner.jpg',
                    ),
                ),
            ),
            'items' => array(
                (object) array(
                    'id' => 'item-page-1', 'title' => 'Home', 'slug' => 'home',
                    'location' => 'pages/item-page-1/index.html', 'order' => 0,
                    'parent' => null, 'indent' => 0, 'description' => 'Home page',
                    'metadata' => (object) array('created' => 1000, 'updated' => 2000),
                ),
                (object) array(
                    'id' => 'item-page-2', 'title' => 'Second', 'slug' => 'second',
                    'location' => 'pages/item-page-2/index.html', 'order' => 1,
                    'parent' => 'item-page-1', 'indent' => 1, 'description' => 'Second page',
                    'metadata' => (object) array('created' => 3000, 'updated' => 4000),
                ),
            ),
        );
        file_put_contents($dir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function homePage()
    {
        return $this->site->loadNode('item-page-1');
    }

    private function extractJsonLd(string $metadata): array
    {
        $this->assertMatchesRegularExpression(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            $metadata
        );
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $metadata, $m);
        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://schema.org', $decoded['@context']);
        return $decoded['@graph'];
    }

    private function graphNodeByType(array $graph, string $type): array
    {
        foreach ($graph as $node) {
            if (isset($node['@type']) && $node['@type'] === $type) {
                return $node;
            }
        }
        $this->fail('No @graph node of type ' . $type);
    }

    // =========================================================================
    // Core head tags
    // =========================================================================

    public function testGetSiteMetadataReturnsCoreHeadTags(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertIsString($metadata);
        $this->assertStringContainsString('<meta charset="utf-8">', $metadata);
        $this->assertStringContainsString('<link rel="preload" href="./build.js" as="script" />', $metadata);
        $this->assertStringContainsString('<link rel="preload" href="./build-haxcms.js" as="script" />', $metadata);
        $this->assertStringContainsString('<meta name="generator" content="HAXcms" />', $metadata);
        $this->assertStringContainsString('<link rel="manifest" href="manifest.json" />', $metadata);
        $this->assertStringContainsString('<link rel="llms" href="llms.txt" title="LLM Content Map" />', $metadata);
        $this->assertStringContainsString('<link rel="alternate" type="text/markdown" href="llms.txt" title="Markdown Summary" />', $metadata);
        $this->assertStringContainsString('.well-known/agent-skills/index.json', $metadata);
    }

    public function testGetSiteMetadataTitleCombinesSiteAndPageTitle(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<title>Meta Test Site | Home</title>', $metadata);
        $this->assertStringContainsString('<meta name="og:sitename" property="og:sitename" content="Meta Test Site" />', $metadata);
        $this->assertStringContainsString('<meta name="og:title" property="og:title" content="Home" />', $metadata);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Home" />', $metadata);
    }

    public function testGetSiteMetadataDescriptionUsesPageDescription(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<meta name="description" content="Home page" />', $metadata);
        $this->assertStringContainsString('<meta name="og:description" property="og:description" content="Home page" />', $metadata);
    }

    public function testGetSiteMetadataRobotsDefaultsToIndexFollow(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<meta name="robots" content="index, follow" />', $metadata);
    }

    public function testGetSiteMetadataThemeColorFromThemeVariables(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<meta name="theme-color" content="#81adc4" />', $metadata);
        $this->assertStringContainsString('<meta name="msapplication-TileColor" content="#81adc4" />', $metadata);
    }

    public function testGetSiteMetadataDefaultIconPaths(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<link rel="icon" href="assets/icon-16x16.png" />', $metadata);
        $this->assertStringContainsString('href="assets/icon-192x192.png"', $metadata);
    }

    public function testGetSiteMetadataCanonicalFromSiteDomainAndSlug(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<link rel="canonical" href="https://meta.example.com/home" />', $metadata);
    }

    public function testGetSiteMetadataNextAndPrevLinks(): void
    {
        // Home is the first item: it only gets a next link.
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<link rel="next" href="second" />', $metadata);
        $this->assertStringNotContainsString('<link rel="prev"', $metadata);
        // Second gets a prev link instead.
        $metadata = $this->site->getSiteMetadata($this->site->loadNode('item-page-2'));
        $this->assertStringContainsString('<link rel="prev" href="home" />', $metadata);
        $this->assertStringNotContainsString('<link rel="next"', $metadata);
    }

    public function testGetSiteMetadataLicenseMeta(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString(
            '<meta rel="cc:license" href="https://creativecommons.org/licenses/by-sa/4.0/" content="License: Creative Commons: Attribution Share a like"/>',
            $metadata
        );
    }

    public function testGetSiteMetadataSocialImageFromThemeBanner(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<meta name="og:image" property="og:image" content="https://example.com/banner.jpg" />', $metadata);
        $this->assertStringContainsString('<meta name="twitter:image" property="twitter:image" content="https://example.com/banner.jpg" />', $metadata);
    }

    public function testGetSiteMetadataEmitsAlternateFormatLinkTags(): void
    {
        // Only index.html exists, so just the html alternate is emitted.
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<link rel="alternate" type="text/html" href="/home.html" />', $metadata);
        $this->assertStringNotContainsString('type="text/markdown" href="/home.md"', $metadata);
    }

    // =========================================================================
    // Modulepreload + content-tag preloads
    // =========================================================================

    public function testGetSiteMetadataShellModulepreloadEmission(): void
    {
        $this->haxcms->shellPaths = array('@haxtheweb/haxcms-elements/lib/base-haxcms.js');
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString(
            '  <link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/haxcms-elements/lib/base-haxcms.js" crossorigin="anonymous" />',
            $metadata
        );
    }

    public function testGetSiteMetadataContentTagPreloadFromPageMarkup(): void
    {
        file_put_contents(
            $this->siteDir() . '/pages/item-page-1/index.html',
            '<p>x</p><video-player></video-player>'
        );
        // Tag extraction quirks: '<video-player>' yields '<video-player', while
        // '</video-player>' loses its '<' entirely when '</' is stripped, yielding
        // 'video-player'. Both resolve to the same path so the preload is deduped.
        $this->haxcms->contentTagPaths['<video-player'] = '@haxtheweb/video-player/video-player.js';
        $this->haxcms->contentTagPaths['video-player'] = '@haxtheweb/video-player/video-player.js';
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertSame(array('<video-player', 'video-player'), $this->haxcms->contentTagRequests);
        $this->assertStringContainsString(
            '<link rel="preload" href="./build/es6/node_modules/@haxtheweb/video-player/video-player.js" as="script" crossorigin="anonymous" />',
            $metadata
        );
        $this->assertStringContainsString(
            '<link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/video-player/video-player.js" />',
            $metadata
        );
    }

    public function testGetSiteMetadataContentTagPreloadDedupedAgainstShellSet(): void
    {
        file_put_contents(
            $this->siteDir() . '/pages/item-page-1/index.html',
            '<video-player></video-player>'
        );
        $shared = '@haxtheweb/video-player/video-player.js';
        $this->haxcms->shellPaths = array($shared);
        $this->haxcms->contentTagPaths['<video-player'] = $shared;
        $metadata = $this->site->getSiteMetadata($this->homePage());
        // Only the shell modulepreload remains; no duplicate preload pair.
        $this->assertSame(1, substr_count($metadata, $shared));
    }

    public function testGetSiteMetadataDuplicateTagsEmittedOnce(): void
    {
        file_put_contents(
            $this->siteDir() . '/pages/item-page-1/index.html',
            '<video-player>one</video-player><video-player>two</video-player>'
        );
        $this->haxcms->contentTagPaths['<video-player'] = '@haxtheweb/video-player/video-player.js';
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertSame(1, substr_count($metadata, 'video-player/video-player.js" as="script"'));
    }

    // =========================================================================
    // Settings-driven variations
    // =========================================================================

    public function testGetSiteMetadataPrivateSiteNoindex(): void
    {
        $this->site->manifest->metadata->site->settings->private = true;
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<meta name="robots" content="none" />', $metadata);
    }

    public function testGetSiteMetadataCanonicalDisabledOmitsCanonical(): void
    {
        $this->site->manifest->metadata->site->settings->canonical = false;
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringNotContainsString('rel="canonical"', $metadata);
    }

    public function testGetSiteMetadataCustomThemePathEmitsDirectLinks(): void
    {
        $this->site->manifest->metadata->theme->path = './custom/build/custom.es6.js';
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString(
            '<link rel="modulepreload" href="./custom/build/custom.es6.js" crossorigin="anonymous" />',
            $metadata
        );
        $this->assertStringContainsString(
            '<link rel="preload" href="./custom/build/custom.es6.js" as="script" crossorigin="anonymous" />',
            $metadata
        );
        // Registry-prefixed preload for the old theme must not be emitted.
        $this->assertStringNotContainsString(
            'build/es6/node_modules/@haxtheweb/base-theme/haxcms-theme.js',
            $metadata
        );
    }

    public function testGetSiteMetadataNewPageTitleFallsBackToSiteTitle(): void
    {
        $page = $this->homePage();
        $page->title = 'New item';
        $metadata = $this->site->getSiteMetadata($page);
        $this->assertStringContainsString('<title>Meta Test Site</title>', $metadata);
        $this->assertStringContainsString('<meta name="og:title" property="og:title" content="Meta Test Site" />', $metadata);
    }

    public function testGetSiteMetadataNullPageUsesManifestDefaults(): void
    {
        $metadata = $this->site->getSiteMetadata();
        $this->assertStringContainsString('<title>Meta Test Site</title>', $metadata);
        $this->assertStringContainsString('<meta name="description" content="A site for metadata" />', $metadata);
    }

    public function testGetSiteMetadataCdnParameterOverridesBase(): void
    {
        $this->haxcms->shellPaths = array('@haxtheweb/haxcms-elements/lib/base-haxcms.js');
        $metadata = $this->site->getSiteMetadata($this->homePage(), null, 'https://cdn.example.com/');
        $this->assertStringContainsString('<link rel="preconnect" crossorigin href="https://cdn.example.com/">', $metadata);
        $this->assertStringContainsString('<link rel="preload" href="https://cdn.example.com/build.js" as="script" />', $metadata);
        $this->assertStringContainsString(
            'href="https://cdn.example.com/build/es6/node_modules/@haxtheweb/haxcms-elements/lib/base-haxcms.js"',
            $metadata
        );
    }

    public function testGetSiteMetadataExplicitDomainUsedForCanonical(): void
    {
        $this->site->manifest->metadata->site->domain = '';
        $metadata = $this->site->getSiteMetadata($this->homePage(), 'https://other.example.com');
        // Without a site domain, the canonical is the bare domain (no slug appended).
        $this->assertStringContainsString('<link rel="canonical" href="https://other.example.com" />', $metadata);
    }

    // =========================================================================
    // JSON-LD structured data
    // =========================================================================

    public function testGetSiteMetadataJsonLdGraphNodes(): void
    {
        $graph = $this->extractJsonLd($this->site->getSiteMetadata($this->homePage()));
        $types = array_map(function ($node) {
            return $node['@type'];
        }, $graph);
        $this->assertContains('Person', $types);
        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('WebPage', $types);
    }

    public function testGetSiteMetadataJsonLdWebPageNode(): void
    {
        $graph = $this->extractJsonLd($this->site->getSiteMetadata($this->homePage()));
        $webPage = $this->graphNodeByType($graph, 'WebPage');
        $this->assertSame('https://meta.example.com/home#webpage', $webPage['@id']);
        $this->assertSame('https://meta.example.com/home', $webPage['url']);
        $this->assertSame('Home', $webPage['name']);
        $this->assertSame('Home page', $webPage['description']);
        $this->assertSame('en', $webPage['inLanguage']);
        $this->assertSame('1970-01-01T00:16:40+00:00', $webPage['datePublished']);
        $this->assertSame('1970-01-01T00:33:20+00:00', $webPage['dateModified']);
        $this->assertSame('item-page-1', $webPage['identifier']);
        $this->assertSame('https://meta.example.com#website', $webPage['isPartOf']['@id']);
    }

    public function testGetSiteMetadataJsonLdWebSiteSearchAction(): void
    {
        $graph = $this->extractJsonLd($this->site->getSiteMetadata($this->homePage()));
        $webSite = $this->graphNodeByType($graph, 'WebSite');
        $this->assertSame('https://meta.example.com#website', $webSite['@id']);
        $this->assertSame('https://meta.example.com', $webSite['url']);
        $this->assertSame('Meta Test Site', $webSite['name']);
        $this->assertSame('en', $webSite['inLanguage']);
        $this->assertSame(
            'https://meta.example.com/x/search?search={search_term_string}',
            $webSite['potentialAction']['target']['urlTemplate']
        );
        $this->assertSame('required name=search_term_string', $webSite['potentialAction']['query-input']);
    }

    public function testGetSiteMetadataJsonLdBreadcrumbTrailForNestedPage(): void
    {
        $graph = $this->extractJsonLd($this->site->getSiteMetadata($this->site->loadNode('item-page-2')));
        $breadcrumb = $this->graphNodeByType($graph, 'BreadcrumbList');
        $this->assertSame('https://meta.example.com/second#breadcrumb', $breadcrumb['@id']);
        $this->assertSame('Meta Test Site', $breadcrumb['itemListElement'][0]['name']);
        $this->assertSame('Home', $breadcrumb['itemListElement'][1]['name']);
        $this->assertSame('https://meta.example.com/home', $breadcrumb['itemListElement'][1]['item']);
        $this->assertSame('Second', $breadcrumb['itemListElement'][2]['name']);
        $this->assertSame('https://meta.example.com/second', $breadcrumb['itemListElement'][2]['item']);
    }

    public function testGetSiteMetadataJsonLdAuthorFromObjectAuthor(): void
    {
        $this->site->manifest->author = (object) array(
            'name' => 'Test Author',
            'email' => 'author@example.com',
            'socialLink' => 'https://x.com/author',
            'image' => 'https://example.com/author.png',
        );
        $graph = $this->extractJsonLd($this->site->getSiteMetadata($this->homePage()));
        $author = $this->graphNodeByType($graph, 'Person');
        $this->assertSame('https://meta.example.com#author', $author['@id']);
        $this->assertSame('Test Author', $author['name']);
        $this->assertSame('author@example.com', $author['email']);
        $this->assertSame(array('https://x.com/author'), $author['sameAs']);
        $this->assertSame('https://example.com/author.png', $author['image']['url']);
    }

    public function testGetSiteMetadataJsonLdOmitsPersonWithoutAuthor(): void
    {
        $this->site->manifest->author = '';
        $graph = $this->extractJsonLd($this->site->getSiteMetadata($this->homePage()));
        $types = array_map(function ($node) {
            return $node['@type'];
        }, $graph);
        $this->assertNotContains('Person', $types);
    }

    public function testGetSiteMetadataTwitterCreatorFromTwitterSocialLink(): void
    {
        $this->site->manifest->metadata->author = (object) array(
            'socialLink' => 'https://twitter.com/tester',
        );
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertStringContainsString('<meta name="twitter:creator" content="@tester" />', $metadata);
    }

    // =========================================================================
    // Event dispatch
    // =========================================================================

    public function testGetSiteMetadataDispatchesEventWithResult(): void
    {
        $metadata = $this->site->getSiteMetadata($this->homePage());
        $this->assertCount(1, $this->haxcms->dispatchedEvents);
        $this->assertSame('haxcms-site-metadata', $this->haxcms->dispatchedEvents[0]['event']);
        $this->assertSame($metadata, $this->haxcms->dispatchedEvents[0]['value']);
    }
}

/**
 * HAXCMSSite test subclass with no overrides: metadata rendering is exercised
 * through the real implementation against the temp fixture.
 */
class HAXCMSSiteMetadataTestSite extends HAXCMSSite
{
}

/**
 * HAXCMS collaborator mock for metadata rendering: configurable URI/CDN,
 * recorded shell + content-tag path lookups, and recorded dispatchEvent calls.
 */
class HAXCMSMetadataTestHaxcms extends OperationsTestHaxcms
{
    public $cdn = './';
    public $uri = 'https://meta.example.com';
    public $shellPaths = array('@haxtheweb/haxcms-elements/lib/base-haxcms.js');
    public $contentTagPaths = array();
    public $contentTagRequests = array();
    public $dispatchedEvents = array();

    public function getURI()
    {
        return $this->uri;
    }

    public function buildShellModulepreloadPaths($site, $base = './', $themePath = '', $cap = 12)
    {
        return $this->shellPaths;
    }

    public function getContentTagPath($site, $base, $tag)
    {
        $this->contentTagRequests[] = $tag;
        return array_key_exists($tag, $this->contentTagPaths) ? $this->contentTagPaths[$tag] : false;
    }

    public function dispatchEvent($event, &$value = null)
    {
        $this->dispatchedEvents[] = array('event' => $event, 'value' => $value);
        return $value;
    }
}
