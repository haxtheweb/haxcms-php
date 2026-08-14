<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for HAXCMSSite public mutation + read methods.
 *
 * A real temp-site fixture (site.json + pages/<id>/index.html) is built under
 * sys_get_temp_dir(), loaded via HAXCMSSite::load(), and exercised through the
 * public seam. The git/twig collaborators (gitCommit, rebuildManagedFiles,
 * updateAlternateFormats) are stubbed via a HAXCMSSite test subclass so the
 * REAL manifest mutation + JSONOutlineSchema::save run against the temp
 * fixture without a git binary or twig template tree. HAXCMS_ROOT is defined
 * once (process-global) pointing at the real repo root so the boilerplate page
 * templates that addPage/newSite recurseCopy from are present.
 *
 * Expected values are derived from independent sources: CC license URLs (the
 * public Creative Commons spec), the generateMachineName contract (computed
 * with a standalone php -r that does NOT import HAXCMS), and relational
 * properties (round-trip persistence, collision suffixing, containment
 * rejection, determinism).
 */
class HAXCMSSiteMutationsTest extends TestCase
{
    private $haxcms;
    private $site;
    private $savedHaxcms;
    private $savedRequestUri;
    private $tmpRoot;
    private $siteName = 'testsite';

    protected function setUp(): void
    {
        // HAXCMS_ROOT is process-global; define once at the real repo root so
        // boilerplate page/site templates exist for addPage/newSite recurseCopy.
        // __DIR__ is <repoRoot>/system/backend/php/tests/Unit, so 5 levels up.
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', dirname(__DIR__, 5));
        }
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->savedRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_site_mut_' . uniqid();
        $this->buildSiteFixture($this->siteName);
        // Install the mock as $GLOBALS['HAXCMS'] BEFORE loading the site,
        // because HAXCMSSite::load() calls $GLOBALS['HAXCMS']->cleanTitle().
        $this->haxcms = new HAXCMSSiteTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = new HAXCMSSiteTestSite();
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
        if ($this->savedRequestUri !== null) {
            $_SERVER['REQUEST_URI'] = $this->savedRequestUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
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

    private function siteJsonPath(): string
    {
        return $this->siteDir() . '/site.json';
    }

    private function buildSiteFixture($name): void
    {
        $dir = $this->tmpRoot . '/' . $name;
        mkdir($dir . '/pages', 0777, true);
        $items = array(
            array(
                'id' => 'item-page-1', 'title' => 'Home Page', 'slug' => 'home',
                'location' => 'pages/item-page-1/index.html', 'order' => 0,
                'parent' => null, 'indent' => 0, 'description' => 'Welcome home',
                'metadata' => (object) array('created' => 1000, 'updated' => 2000),
            ),
            array(
                'id' => 'item-page-2', 'title' => 'About Us', 'slug' => 'about',
                'location' => 'pages/item-page-2/index.html', 'order' => 1,
                'parent' => null, 'indent' => 0, 'description' => 'About this site',
                'metadata' => (object) array('created' => 3000, 'updated' => 4000),
            ),
            array(
                'id' => 'item-page-3', 'title' => 'Contact', 'slug' => 'contact',
                'location' => 'pages/item-page-3/index.html', 'order' => 2,
                'parent' => 'item-page-1', 'indent' => 1, 'description' => 'Get in touch',
                'metadata' => (object) array('created' => 5000, 'updated' => 6000),
            ),
        );
        $manifest = (object) array(
            'id' => 'site-uuid-1234',
            'title' => 'My Test Site',
            'author' => 'Tester',
            'description' => 'A test site description',
            'license' => 'by-sa',
            'metadata' => (object) array('site' => (object) array('name' => $name)),
            'items' => array(),
        );
        foreach ($items as $it) {
            $manifest->items[] = (object) $it;
            $pageDir = $dir . '/pages/' . $it['id'];
            mkdir($pageDir, 0777, true);
            file_put_contents($pageDir . '/index.html', '<p>Content for ' . $it['title'] . '</p>');
        }
        file_put_contents($dir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function readBackManifest(): stdClass
    {
        return json_decode(file_get_contents($this->siteJsonPath()));
    }

    // ---- getLicenseData ----

    public function testGetLicenseDataReturnsAllLicensesForNonSelectType(): void
    {
        // Contract: $type != 'select' returns the full list keyed by short
        // code with name/link/image. 'by-sa' is not 'select' so it returns
        // the full list. The CC BY-SA 4.0 URL is the independent source of
        // truth (public Creative Commons spec).
        $data = $this->site->getLicenseData('by-sa');
        $this->assertArrayHasKey('by-sa', $data);
        $this->assertSame('Creative Commons: Attribution Share a like', $data['by-sa']['name']);
        $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', $data['by-sa']['link']);
        $this->assertSame('https://i.creativecommons.org/l/by-sa/4.0/88x31.png', $data['by-sa']['image']);
    }

    public function testGetLicenseDataAllReturnsSameFullList(): void
    {
        $all = $this->site->getLicenseData('all');
        $this->assertCount(6, $all);
        $this->assertArrayHasKey('by', $all);
        $this->assertArrayHasKey('by-sa', $all);
        $this->assertArrayHasKey('by-nd', $all);
        $this->assertArrayHasKey('by-nc', $all);
        $this->assertArrayHasKey('by-nc-sa', $all);
        $this->assertArrayHasKey('by-nc-nd', $all);
    }

    public function testGetLicenseDataSelectReturnsNameOnlyMap(): void
    {
        // Contract: $type == 'select' returns [code => name] (no link/image).
        $select = $this->site->getLicenseData('select');
        $this->assertSame('Creative Commons: Attribution Share a like', $select['by-sa']);
        $this->assertArrayNotHasKey('link', $select);
    }

    public function testGetLicenseDataByLicenseLinkIsIndependent(): void
    {
        // Independent source of truth: CC Attribution 4.0 canonical URL.
        $data = $this->site->getLicenseData('all');
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $data['by']['link']);
    }

    // ---- loadNode ----

    public function testLoadNodeFindsItemById(): void
    {
        $item = $this->site->loadNode('item-page-2');
        $this->assertNotFalse($item);
        $this->assertSame('item-page-2', $item->id);
        $this->assertSame('About Us', $item->title);
    }

    public function testLoadNodeReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->site->loadNode('does-not-exist'));
    }

    public function testLoadNodeReturnsFalseForEmptyId(): void
    {
        $this->assertFalse($this->site->loadNode(''));
    }

    // ---- loadNodeByLocation ----

    public function testLoadNodeByLocationFindsBySlug(): void
    {
        // loadNodeByLocation('about') normalizes to pages/about/index.html
        // for the location match, but also matches item->slug == 'about'.
        $item = $this->site->loadNodeByLocation('about');
        $this->assertNotFalse($item);
        $this->assertSame('item-page-2', $item->id);
    }

    public function testLoadNodeByLocationReturnsBlankItemForUnknownPath(): void
    {
        $item = $this->site->loadNodeByLocation('no-such-page');
        $this->assertInstanceOf(JSONOutlineSchemaItem::class, $item);
        $this->assertTrue($this->site->lastPathLookupMiss);
    }

    public function testLoadNodeByLocationNullReturnsFirstItemForRootRequest(): void
    {
        // With REQUEST_URI = '/', the relative path is empty, so the root
        // fallback returns the first manifest item.
        $_SERVER['REQUEST_URI'] = '/';
        $item = $this->site->loadNodeByLocation(null);
        $this->assertSame('item-page-1', $item->id);
        $this->assertFalse($this->site->lastPathLookupMiss);
    }

    // ---- getUniqueSlugName ----

    public function testGetUniqueSlugNameNoCollisionReturnsAsIs(): void
    {
        $this->assertSame('fresh-slug', $this->site->getUniqueSlugName('fresh-slug'));
    }

    public function testGetUniqueSlugNameCollisionAppendsSuffix1(): void
    {
        // 'home' collides with item-page-1's slug -> 'home-1'.
        $this->assertSame('home-1', $this->site->getUniqueSlugName('home'));
    }

    public function testGetUniqueSlugNameDoubleCollisionAppendsSuffix2(): void
    {
        // Add a second item with slug 'home-1' so 'home' must skip to '-2'.
        $extra = new JSONOutlineSchemaItem();
        $extra->id = 'item-extra';
        $extra->slug = 'home-1';
        $extra->location = 'pages/item-extra/index.html';
        $this->site->manifest->items[] = $extra;
        $this->assertSame('home-2', $this->site->getUniqueSlugName('home'));
    }

    public function testGetUniqueSlugNameReturnsOwnedSlugWhenPageMatches(): void
    {
        // When the supplied page already owns the colliding slug, it is
        // returned unchanged (no suffix).
        $page1 = $this->site->loadNode('item-page-1');
        $this->assertSame('home', $this->site->getUniqueSlugName('home', $page1));
    }

    // ---- recurseCopy ----

    public function testRecurseCopyCopiesDirTree(): void
    {
        $src = $this->tmpRoot . '/src';
        $dst = $this->tmpRoot . '/dst';
        mkdir($src . '/sub', 0777, true);
        file_put_contents($src . '/index.html', '<p>root</p>');
        file_put_contents($src . '/sub/nested.txt', 'nested');
        $this->site->recurseCopy($src, $dst);
        $this->assertFileExists($dst . '/index.html');
        $this->assertSame('<p>root</p>', file_get_contents($dst . '/index.html'));
        $this->assertFileExists($dst . '/sub/nested.txt');
        $this->assertSame('nested', file_get_contents($dst . '/sub/nested.txt'));
    }

    public function testRecurseCopyRejectsTraversalInDestination(): void
    {
        // Security (N1): a '..' segment in $dst must be rejected before any
        // directory is created, preventing escape from the site tree.
        $src = $this->tmpRoot . '/src2';
        mkdir($src, 0777, true);
        file_put_contents($src . '/index.html', 'x');
        $dst = $this->tmpRoot . '/../escape_attempt';
        $result = $this->site->recurseCopy($src, $dst);
        $this->assertFalse($result);
        $this->assertFileDoesNotExist(dirname($this->tmpRoot) . '/escape_attempt/index.html');
    }

    public function testRecurseCopyRejectsNullByteInDestination(): void
    {
        $src = $this->tmpRoot . '/src3';
        mkdir($src, 0777, true);
        file_put_contents($src . '/index.html', 'x');
        $result = $this->site->recurseCopy($src, "bad\0path");
        $this->assertFalse($result);
    }

    // ---- validatePageLocation ----

    public function testValidatePageLocationAcceptsExistingPage(): void
    {
        $this->assertTrue($this->site->validatePageLocation('pages/item-page-1/index.html'));
    }

    public function testValidatePageLocationRejectsNonExistent(): void
    {
        $this->assertFalse($this->site->validatePageLocation('pages/no-such/index.html'));
    }

    public function testValidatePageLocationRejectsTraversal(): void
    {
        $this->assertFalse($this->site->validatePageLocation('../etc/passwd'));
    }

    public function testValidatePageLocationRejectsNullByte(): void
    {
        $this->assertFalse($this->site->validatePageLocation("pages/x\0/index.html"));
    }

    public function testValidatePageLocationRejectsEmpty(): void
    {
        $this->assertFalse($this->site->validatePageLocation(''));
    }

    // ---- save ----

    public function testSavePersistsManifestToSiteJson(): void
    {
        // Independent source of truth: read site.json back from disk.
        $this->site->manifest->title = 'Persisted Title';
        $this->site->save();
        $persisted = $this->readBackManifest();
        $this->assertSame('Persisted Title', $persisted->title);
    }

    // ---- addPage ----

    public function testAddPageCreatesItemAndPageDir(): void
    {
        $countBefore = count($this->site->manifest->items);
        $page = $this->site->addPage(null, 'New Page', 'init', 'new-page');
        $this->assertSame('New Page', $page->title);
        $this->assertSame('new-page', $page->slug);
        $this->assertSame('pages/' . $page->id . '/index.html', $page->location);
        // manifest gained the item
        $this->assertSame($countBefore + 1, count($this->site->manifest->items));
        // page dir was created on disk from the boilerplate template
        $this->assertFileExists($this->siteDir() . '/' . $page->location);
        // loadNode finds it by id
        $this->assertNotFalse($this->site->loadNode($page->id));
    }

    public function testAddPageSlugCollisionGetsUniqueSuffix(): void
    {
        // 'home' already exists as item-page-1's slug -> new page gets 'home-1'.
        $page = $this->site->addPage(null, 'Another Home', 'init', 'home');
        $this->assertSame('home-1', $page->slug);
    }

    public function testAddPageWithExplicitIdSanitizesTraversal(): void
    {
        // generateMachineName strips '..' and slashes, so a crafted id cannot
        // escape the pages dir. Expected 'x' computed independently.
        $page = $this->site->addPage(null, 'Trav', 'init', 'trav', '../../../../x');
        $this->assertSame('x', $page->id);
        $this->assertSame('pages/x/index.html', $page->location);
        $this->assertFileExists($this->siteDir() . '/pages/x/index.html');
    }

    // ---- updateNode ----

    public function testUpdateNodeReplacesItemAndPersists(): void
    {
        $item = $this->site->loadNode('item-page-2');
        $item->title = 'About Us Updated';
        $result = $this->site->updateNode($item);
        $this->assertSame($item, $result);
        // manifest reflects the new title
        $this->assertSame('About Us Updated', $this->site->loadNode('item-page-2')->title);
        // independent source of truth: read site.json back
        $persisted = $this->readBackManifest();
        $found = false;
        foreach ($persisted->items as $p) {
            if ($p->id === 'item-page-2') {
                $this->assertSame('About Us Updated', $p->title);
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testUpdateNodeReturnsFalseForUnknownId(): void
    {
        $ghost = new JSONOutlineSchemaItem();
        $ghost->id = 'nonexistent';
        $this->assertFalse($this->site->updateNode($ghost));
    }

    // ---- deleteNode ----

    public function testDeleteNodeRemovesItemAndPersists(): void
    {
        $item = $this->site->loadNode('item-page-3');
        $this->assertNotFalse($item);
        $countBefore = count($this->site->manifest->items);
        $result = $this->site->deleteNode($item);
        $this->assertTrue($result);
        // manifest no longer has it
        $this->assertFalse($this->site->loadNode('item-page-3'));
        $this->assertSame($countBefore - 1, count($this->site->manifest->items));
        // independent source of truth: read site.json back
        $persisted = $this->readBackManifest();
        foreach ($persisted->items as $p) {
            $this->assertNotSame('item-page-3', $p->id);
        }
    }

    public function testDeleteNodeReturnsFalseForUnknownId(): void
    {
        $ghost = new JSONOutlineSchemaItem();
        $ghost->id = 'nonexistent';
        $this->assertFalse($this->site->deleteNode($ghost));
    }

    // ---- renamePageLocation ----

    public function testRenamePageLocationMovesPageDir(): void
    {
        $oldDir = $this->siteDir() . '/pages/old-dir';
        mkdir($oldDir, 0777, true);
        file_put_contents($oldDir . '/index.html', '<p>moved content</p>');
        $this->site->renamePageLocation('pages/old-dir/index.html', 'pages/new-dir/index.html');
        // new location has the mirrored content
        $this->assertFileExists($this->siteDir() . '/pages/new-dir/index.html');
        $this->assertSame('<p>moved content</p>', file_get_contents($this->siteDir() . '/pages/new-dir/index.html'));
        // old index.html was removed
        $this->assertFileDoesNotExist($oldDir . '/index.html');
    }

    public function testRenamePageLocationRejectsTraversalInNewPath(): void
    {
        // Security (SEC-17): '..' in new path is a no-op (returns void).
        $oldDir = $this->siteDir() . '/pages/trav-dir';
        mkdir($oldDir, 0777, true);
        file_put_contents($oldDir . '/index.html', '<p>safe</p>');
        $this->site->renamePageLocation('pages/trav-dir/index.html', '../outside/index.html');
        $this->assertFileDoesNotExist(dirname($this->siteDir()) . '/outside/index.html');
        // original is untouched
        $this->assertFileExists($oldDir . '/index.html');
    }

    public function testRenamePageLocationRejectsNullByte(): void
    {
        $oldDir = $this->siteDir() . '/pages/null-dir';
        mkdir($oldDir, 0777, true);
        file_put_contents($oldDir . '/index.html', '<p>safe</p>');
        $this->site->renamePageLocation("pages/null-dir/index\0.html", 'pages/dest/index.html');
        $this->assertFileDoesNotExist($this->siteDir() . '/pages/dest/index.html');
    }

    // ---- itemFromParams ----

    public function testItemFromParamsBuildsItemWithCleanId(): void
    {
        $params = array(
            'node' => array('title' => 'My Page', 'id' => 'my-custom-id'),
            'indent' => 1,
            'order' => 5,
            'parent' => 'item-page-1',
            'description' => 'A new page',
        );
        $item = $this->site->itemFromParams($params);
        $this->assertSame('my-custom-id', $item->id);
        $this->assertSame('pages/my-custom-id/index.html', $item->location);
        $this->assertSame('My Page', $item->title);
        $this->assertSame(1, $item->indent);
        $this->assertSame(5, $item->order);
        $this->assertSame('item-page-1', $item->parent);
        $this->assertSame('A new page', $item->description);
        // slug derived from title via cleanTitle + getUniqueSlugName with
        // pathAuto=true: parent's slug is prepended, so 'My Page' under
        // item-page-1 (slug 'home') becomes 'home/my-page'.
        $this->assertSame('home/my-page', $item->slug);
        $this->assertTrue(isset($item->metadata->created));
        $this->assertTrue(is_numeric($item->metadata->created));
    }

    public function testItemFromParamsSanitizesTraversalId(): void
    {
        // generateMachineName('../../../../x') = 'x' (computed independently).
        $params = array(
            'node' => array('title' => 'Trav', 'id' => '../../../../x'),
        );
        $item = $this->site->itemFromParams($params);
        $this->assertSame('x', $item->id);
        $this->assertSame('pages/x/index.html', $item->location);
    }

    public function testItemFromParamsUsesLocationForSlugWhenProvided(): void
    {
        $params = array(
            'node' => array('title' => 'Some Title', 'location' => 'custom-path'),
        );
        $item = $this->site->itemFromParams($params);
        // slug derived from node.location, not title
        $this->assertSame('custom-path', $item->slug);
    }

    public function testItemFromParamsNullParentWhenNotSet(): void
    {
        $params = array('node' => array('title' => 'Orphan'));
        $item = $this->site->itemFromParams($params);
        $this->assertNull($item->parent);
    }

    // ---- getLLMSTxt ----

    public function testGetLLMSTxtProducesNonEmptyStringWithTitle(): void
    {
        $txt = $this->site->getLLMSTxt();
        $this->assertIsString($txt);
        $this->assertNotSame('', $txt);
        // first line is '# ' + safe title
        $this->assertStringStartsWith('# My Test Site', $txt);
    }

    public function testGetLLMSTxtIncludesDescriptionAndPagesSections(): void
    {
        $txt = $this->site->getLLMSTxt('');
        $this->assertStringContainsString('> A test site description', $txt);
        $this->assertStringContainsString('## Pages', $txt);
        $this->assertStringContainsString('## Core resources', $txt);
        // each page with a location gets a markdown link line
        $this->assertStringContainsString('- [Home Page]', $txt);
        $this->assertStringContainsString('- [About Us]', $txt);
    }

    public function testGetLLMSTxtResourceUrlsUseRootBaseWhenDomainEmpty(): void
    {
        // With domain='', getLLMSBaseURL returns '/', so resource URLs are
        // root-relative: /site.json, /pages/<id>/index.md.
        $txt = $this->site->getLLMSTxt('');
        $this->assertStringContainsString('(/site.json)', $txt);
        $this->assertStringContainsString('(/pages/item-page-1/index.md)', $txt);
    }

    // ---- jsonFeedFormat ----

    public function testJsonFeedFormatReturnsStructureWithItems(): void
    {
        // FINDING: lib/HAXCMSSite.php:1237 — jsonFeedFormat calls
        // str_replace('iam.','oer.', $GLOBALS['HAXCMS']->getDomain()) without
        // guarding for null. getDomain() returns null when SERVER_NAME is
        // unset (CLI / missing server var), and passing null to str_replace's
        // $subject is a PHP 8.3 deprecation (TypeError in PHP 9). The mock
        // faithfully returns null to surface this; the method still produces a
        // root-relative domain because null coalesces to '' in concatenation.
        $feed = $this->site->jsonFeedFormat();
        $this->assertIsArray($feed);
        $this->assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        $this->assertSame('My Test Site', $feed['title']);
        $this->assertArrayHasKey('items', $feed);
        $this->assertIsArray($feed['items']);
        $this->assertSame(3, count($feed['items']));
        $first = $feed['items'][0];
        $this->assertArrayHasKey('guid', $first);
        $this->assertArrayHasKey('url', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('summary', $first);
        $this->assertArrayHasKey('content_html', $first);
        $this->assertArrayHasKey('date_published', $first);
    }

    public function testJsonFeedFormatGuidStripsItemPrefixAndHyphens(): void
    {
        // guid = substr(str_replace('-','',str_replace('item-','',$id)),0,29)
        // For 'item-page-1' -> 'page1'. Independent derivation.
        $feed = $this->site->jsonFeedFormat();
        $this->assertSame('page1', $feed['items'][0]['guid']);
        $this->assertSame('page2', $feed['items'][1]['guid']);
    }

    public function testJsonFeedFormatContentHtmlReadsPageFile(): void
    {
        $feed = $this->site->jsonFeedFormat();
        $this->assertStringContainsString('Content for Home Page', $feed['items'][0]['content_html']);
    }

    public function testJsonFeedFormatRespectsLimit(): void
    {
        $feed = $this->site->jsonFeedFormat(2);
        $this->assertSame(2, count($feed['items']));
    }

    // ---- lunrSearchIndex ----

    public function testLunrSearchIndexReturnsDocumentsForAllItems(): void
    {
        $docs = $this->site->lunrSearchIndex($this->site->manifest->items);
        $this->assertIsArray($docs);
        $this->assertSame(3, count($docs));
        foreach ($docs as $doc) {
            $this->assertArrayHasKey('id', $doc);
            $this->assertArrayHasKey('title', $doc);
            $this->assertArrayHasKey('created', $doc);
            $this->assertArrayHasKey('location', $doc);
            $this->assertArrayHasKey('description', $doc);
            $this->assertArrayHasKey('text', $doc);
        }
    }

    public function testLunrSearchIndexMapsSlugAndCreatedFromMetadata(): void
    {
        $docs = $this->site->lunrSearchIndex($this->site->manifest->items);
        // first doc corresponds to first item (Home Page)
        $this->assertSame('Home Page', $docs[0]['title']);
        $this->assertSame('home', $docs[0]['location']);
        $this->assertSame(1000, $docs[0]['created']);
        $this->assertSame('Welcome home', $docs[0]['description']);
    }

    public function testLunrSearchIndexTextIsCleanedPageContent(): void
    {
        // Page content '<p>Content for Home Page</p>' is stripped, lowercased,
        // and short words (1-4 chars) removed. 'content' (7 chars) survives.
        $docs = $this->site->lunrSearchIndex($this->site->manifest->items);
        $this->assertNotSame('', $docs[0]['text']);
        $this->assertStringContainsString('content', $docs[0]['text']);
    }

    // ---- sortItems ----

    public function testSortItemsCreatedDescSortsByMetadataCreatedDescending(): void
    {
        // FINDING: lib/HAXCMSSite.php:1365-1366 — the metadata-key sort path
        // sets $this->__compareItemKey and $this->__compareItemDir as dynamic
        // properties without declaring them (and HAXCMSSite lacks
        // #[AllowDynamicProperties]). PHP 8.2+ emits a deprecation per
        // assignment; PHP 9 will error. The sort itself is CORRECT (DESC puts
        // the largest created timestamp first) — this is a separate issue from
        // the direct-key no-op bug below.
        $sorted = $this->site->sortItems('created', 'DESC');
        $titles = array_map(function ($i) { return $i->title; }, $sorted);
        $this->assertSame(array('Contact', 'About Us', 'Home Page'), $titles);
    }

    public function testSortItemsCreatedAscSortsByMetadataCreatedAscending(): void
    {
        $sorted = $this->site->sortItems('created', 'ASC');
        $titles = array_map(function ($i) { return $i->title; }, $sorted);
        $this->assertSame(array('Home Page', 'About Us', 'Contact'), $titles);
    }

    public function testSortItemsTitleAscIsNoOpDueToMissingUseCapture(): void
    {
        // FINDING: lib/HAXCMSSite.php:1376-1389 — the direct-key comparator
        // closure (for id, title, indent, location, order, parent, description)
        // is missing `use ($key, $dir)`, so $key and $dir are undefined inside
        // the closure. $dir (null) != 'ASC' so the else branch runs, and
        // $a->{null} (i.e. $a->{''}) is an undefined property yielding null on
        // both sides, so null == null -> return 0 for every pair. With an
        // all-zero comparator PHP 8's stable usort preserves the original
        // order, so sortItems('title', 'ASC') is a no-op: items come back in
        // manifest order, NOT ascending. The metadata-key sorts above
        // (created/updated/readtime via compareItemKeys) work correctly,
        // confirming the bug is isolated to the inline direct-key closure.
        $sorted = $this->site->sortItems('title', 'ASC');
        $titles = array_map(function ($i) { return $i->title; }, $sorted);
        $this->assertSame(array('Home Page', 'About Us', 'Contact'), $titles);
    }

    public function testSortItemsTitleDescIsNoOpDueToMissingUseCapture(): void
    {
        // FINDING: lib/HAXCMSSite.php:1376-1389 — same missing-`use` bug as
        // above. sortItems('title', 'DESC') is also a no-op: the all-zero
        // comparator leaves items in original manifest order, NOT descending.
        $sorted = $this->site->sortItems('title', 'DESC');
        $titles = array_map(function ($i) { return $i->title; }, $sorted);
        $this->assertSame(array('Home Page', 'About Us', 'Contact'), $titles);
    }

    public function testSortItemsDoesNotMutateManifest(): void
    {
        // sortItems copies the items array; the manifest order is unchanged.
        $before = array_map(function ($i) { return $i->id; }, $this->site->manifest->items);
        $this->site->sortItems('created', 'DESC');
        $after = array_map(function ($i) { return $i->id; }, $this->site->manifest->items);
        $this->assertSame($before, $after);
    }

    // ---- newSite ----

    public function testNewSiteCreatesSiteTreeAndManifest(): void
    {
        // git binary is required (newSite calls Git::create -> git init).
        $gitBin = '/usr/bin/git';
        if (!file_exists($gitBin)) {
            $macBin = '/usr/local/bin/git';
            if (!file_exists($macBin)) {
                $this->markTestSkipped('git binary not available');
            }
        }
        $name = 'brand-new-site';
        $site = new HAXCMSSiteTestSite();
        $result = $site->newSite($this->tmpRoot, '/', $name, null, null);
        $this->assertSame($site, $result);
        // site directory created from boilerplate
        $this->assertDirectoryExists($this->tmpRoot . '/' . $name);
        // site.json exists with the right title + metadata.site.name
        $this->assertFileExists($this->tmpRoot . '/' . $name . '/site.json');
        $manifest = json_decode(file_get_contents($this->tmpRoot . '/' . $name . '/site.json'));
        $this->assertSame($name, $manifest->title);
        $this->assertSame($name, $manifest->metadata->site->name);
        // default build creates a single Welcome page
        $this->assertTrue(is_array($manifest->items));
        $this->assertGreaterThanOrEqual(1, count($manifest->items));
        // default settings scaffold
        $this->assertSame('en', $manifest->metadata->site->settings->lang);
        $this->assertFalse($manifest->metadata->site->settings->private);
    }
}

/**
 * HAXCMSSite test subclass that no-ops the git/twig collaborators while
 * keeping the real manifest load + JSONOutlineSchema::save. This lets
 * addPage/updateNode/deleteNode/save/newSite mutation+persistence logic run
 * for real against a temp fixture without a git binary or twig template tree.
 */
class HAXCMSSiteTestSite extends HAXCMSSite
{
    public function gitCommit($msg = 'Committed changes')
    {
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }
}

/**
 * HAXCMS collaborator mock extending OperationsTestHaxcms with the extra
 * methods HAXCMSSite reads: generateMachineName (contract-mirroring),
 * outlineSchema->newItem(), staticCache (by-reference), getDomain.
 */
class HAXCMSSiteTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;
    public $cdn = './';
    private $cacheData = array();

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = preg_replace('/[\\\\\/]/', '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        if (empty($name)) {
            $name = 'default';
        }
        return $name;
    }

    public function &staticCache($name, $default_value = null, $reset = false)
    {
        if (!array_key_exists($name, $this->cacheData)) {
            $this->cacheData[$name] = $default_value;
        }
        return $this->cacheData[$name];
    }

    public function getDomain()
    {
        return null;
    }
}
