<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Fake HAXCMS global for blocks.php branches that read config: the enabled
 * blocks settings file (configDirectory), the appStore autoloader list
 * (config->appStore->autoloader), and an optional wc-registry map returned
 * from getWCRegistryJson(). Distinct name from other per-file fake HAXCMS
 * classes since the Unit suite runs in one process.
 */
class SiteRoutesBlocksFakeHaxcms
{
    public $configDirectory = '';
    public $config;
    private $wcRegistry;

    public function __construct($wcRegistry = null)
    {
        $this->wcRegistry = $wcRegistry;
        $this->config = new stdClass();
    }

    public function getWCRegistryJson($site = null)
    {
        return $this->wcRegistry;
    }
}

/**
 * Route-level characterization tests for lib/siteRoutes/v1/blocks.php.
 * Without a HAXCMS global, getAutoloaderList() falls back to
 * array('grid-plate') and readEnabledBlocksSetting()/getWcMap() return
 * null/empty -- these tests characterize that reachable fallback path plus
 * custom-element-tag usage detection scanning item HTML content.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesBlocksTest extends TestCase
{
    private $tempDirs = array();

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS']);
        foreach ($this->tempDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        $this->tempDirs = array();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/haxcms-blocks-test-' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeDirRecursive($dir): void
    {
        if (!is_string($dir) || !is_dir($dir) || is_link($dir)) {
            return;
        }
        $entries = scandir($dir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path) && !is_link($path)) {
                    $this->removeDirRecursive($path);
                }
                else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }

    private function buildSiteWithBlockUsage(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('b', 'page-b', 'Page B', '', 2, 0, '', '', array('published' => false)),
        );
        $site->pageContentMap = array(
            'a' => '<my-block foo="bar">Hello</my-block><my-block foo="baz">World</my-block>',
            'b' => '<my-block foo="hidden">Should not count for anon</my-block>',
        );
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListAlwaysIncludesDefaultAutoloaderFallbackTag(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $tags = array_column($data['blocks'], 'tag');
        $this->assertContains('grid-plate', $tags);
    }

    public function testListIncludesTagsDetectedFromItemContentWithUsageCount(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        // Default authenticated context (see makeSiteRouteContext default):
        // applyItemFilters() only strips unpublished items for ANONYMOUS
        // requests, so this authenticated call still counts item 'b''s
        // usage too: 2 (item a) + 1 (item b) = 3.
        $context = makeSiteRouteContext($site, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $byTag = array();
        foreach ($data['blocks'] as $record) {
            $byTag[$record['tag']] = $record;
        }
        $this->assertArrayHasKey('my-block', $byTag);
        $this->assertSame(3, $byTag['my-block']['usageCount']);
        $this->assertTrue($byTag['my-block']['used']);
    }

    public function testListExcludesUnpublishedItemUsageForAnonymousCallers(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array(), 'v1/blocks', '/x/api', false);
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $byTag = array();
        foreach ($data['blocks'] as $record) {
            $byTag[$record['tag']] = $record;
        }
        // 'my-block' still detected as known (used on published item 'a'
        // twice), but the unpublished item 'b' usage must not count.
        $this->assertSame(2, $byTag['my-block']['usageCount']);
    }

    public function testFilterTagNarrowsList(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $_GET['filter.tag'] = 'my-block';
        $context = makeSiteRouteContext($site, array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $tags = array_column($data['blocks'], 'tag');
        $this->assertSame(array('my-block'), $tags);
    }

    public function testBlockDetailByTagReturnsUsageAndInstanceDetails(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        // Default authenticated context: both items a (2 usages) and b (1
        // usage) are counted since anon-visibility filtering only applies
        // to unauthenticated requests.
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-block'), 'v1/blocks/my-block');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('my-block', $data['tag']);
        $this->assertSame(3, $data['usageCount']);
        sort($data['usedIn']);
        $this->assertSame(array('a', 'b'), $data['usedIn']);
        $this->assertCount(2, $data['usedInDetails']);
    }

    public function testBlockDetailUnknownTagWithNoUsageReturns404(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'never-used'), 'v1/blocks/never-used');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testBlockDetailKnownViaAutoloaderFallbackTagSucceeds(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'grid-plate'), 'v1/blocks/grid-plate');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('grid-plate', $data['tag']);
        $this->assertSame(0, $data['usageCount']);
    }

    public function testUsageRouteWithoutWebcomponentNameReturns404(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array(), 'v1/blocks//usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testUsageRouteReturnsItemsWithUsageCount(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        // Default authenticated context: both items a and b appear (see
        // note above about anon-visibility filtering only applying to
        // unauthenticated requests). Default sort is -usageCount, so item
        // 'a' (2 usages) sorts ahead of item 'b' (1 usage).
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-block'), 'v1/blocks/my-block/usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('my-block', $data['block']);
        $this->assertSame(2, $data['count']);
        $this->assertSame('a', $data['items'][0]['id']);
        $this->assertSame(2, $data['items'][0]['usageCount']);
    }

    public function testUsageRouteUnknownTagReturns404(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'never-used'), 'v1/blocks/never-used/usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testInvalidWebcomponentNameFormatIsIgnoredAndTreatedAsList(): void
    {
        $site = $this->buildSiteWithBlockUsage();
        $context = makeSiteRouteContext(
            $site,
            array('webcomponentName' => 'bad"name'),
            'v1/blocks/bad%22name'
        );
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        // The malformed tag fails the security regex guard and is reset to
        // '', so this falls through to the list route rather than a detail
        // lookup or a crash.
        $this->assertArrayHasKey('blocks', $data);
    }

    // ------------------------------------------------------------------
    // HAXCMS config-driven branches (enabled blocks, autoloader, wc map)
    // ------------------------------------------------------------------

    private function detailTag($site, $tag)
    {
        $context = makeSiteRouteContext($site, array('webcomponentName' => $tag), 'v1/blocks/' . $tag);
        return invokeSiteRouteHandler('blocks.php', $context)['data'];
    }

    private function withEnabledBlocksFile($json): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/settings', 0777, true);
        file_put_contents($dir . '/settings/enabledBlocks.json', $json);
        $haxcms = new SiteRoutesBlocksFakeHaxcms();
        $haxcms->configDirectory = $dir;
        $GLOBALS['HAXCMS'] = $haxcms;
    }

    public function testEnabledBlocksSettingNormalizesLowercasesUniquesSorts(): void
    {
        // A tag present in the explicit list is enabled; a known tag absent
        // from it (grid-plate, still known via the autoloader fallback) is
        // disabled. The list is lowercased, trimmed, deduped and sorted.
        $this->withEnabledBlocksFile(
            '["Other-Block","my-block","my-block"]'
        );
        $site = $this->buildSiteWithBlockUsage();
        $this->assertTrue($this->detailTag($site, 'my-block')['data']['enabled']);
        $this->assertFalse($this->detailTag($site, 'grid-plate')['data']['enabled']);
    }

    public function testEnabledBlocksSettingNonArrayJsonIsRejected(): void
    {
        $this->withEnabledBlocksFile('"just a string"');
        // Rejected list means no explicit set, so the enabled default (true)
        // applies to every tag.
        $site = $this->buildSiteWithBlockUsage();
        $this->assertTrue($this->detailTag($site, 'grid-plate')['data']['enabled']);
    }

    public function testEnabledBlocksSettingNonStringEntryIsRejected(): void
    {
        $this->withEnabledBlocksFile('[1,2]');
        $site = $this->buildSiteWithBlockUsage();
        $this->assertTrue($this->detailTag($site, 'grid-plate')['data']['enabled']);
    }

    public function testEnabledBlocksSettingInvalidTagFormatIsRejected(): void
    {
        $this->withEnabledBlocksFile('["1bad-tag"]');
        $site = $this->buildSiteWithBlockUsage();
        $this->assertTrue($this->detailTag($site, 'grid-plate')['data']['enabled']);
    }

    public function testEnabledBlocksSettingMissingFileFallsBackToDefaultEnabled(): void
    {
        $dir = $this->makeTempDir();
        $haxcms = new SiteRoutesBlocksFakeHaxcms();
        $haxcms->configDirectory = $dir;
        $GLOBALS['HAXCMS'] = $haxcms;
        $site = $this->buildSiteWithBlockUsage();
        $this->assertTrue($this->detailTag($site, 'grid-plate')['data']['enabled']);
    }

    public function testEnabledBlocksSettingWithNonObjectHaxcmsFallsBackToDefaultEnabled(): void
    {
        $GLOBALS['HAXCMS'] = 'not-an-object';
        $site = $this->buildSiteWithBlockUsage();
        $this->assertTrue($this->detailTag($site, 'grid-plate')['data']['enabled']);
    }

    public function testAutoloaderListComesFromHaxcmsConfigWhenPresent(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms();
        $haxcms->config = new stdClass();
        $haxcms->config->appStore = new stdClass();
        $haxcms->config->appStore->autoloader = array('@haxtheweb/grid-plate', 'other-el', '');
        $GLOBALS['HAXCMS'] = $haxcms;
        $context = makeSiteRouteContext($this->buildSiteWithBlockUsage(), array(), 'v1/blocks');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $tags = array_column($result['data']['data']['blocks'], 'tag');
        $this->assertContains('@haxtheweb/grid-plate', $tags);
        $this->assertContains('other-el', $tags);
        $this->assertContains('my-block', $tags);
        $this->assertNotContains('', $tags);
        // A non-empty config autoloader REPLACES the default fallback rather
        // than merging with it.
        $this->assertNotContains('grid-plate', $tags);
    }

    public function testWcRegistryFromHaxcmsProvidesImportAndScopedPackage(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms(
            (object) array('my-block' => '@haxtheweb/my-block/my-block.js')
        );
        $GLOBALS['HAXCMS'] = $haxcms;
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertSame('@haxtheweb/my-block/my-block.js', $data['import']);
        $this->assertSame('@haxtheweb/my-block', $data['package']);
    }

    public function testWcRegistryObjectEntriesSupportPathAndImportKeys(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms(
            (object) array('my-block' => (object) array('path' => 'x.js'))
        );
        $GLOBALS['HAXCMS'] = $haxcms;
        $this->assertSame('x.js', $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data']['import']);

        $haxcms2 = new SiteRoutesBlocksFakeHaxcms(
            (object) array('my-block' => (object) array('import' => 'y.js'))
        );
        $GLOBALS['HAXCMS'] = $haxcms2;
        $this->assertSame('y.js', $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data']['import']);
    }

    public function testWcRegistryArrayValuedEntrySupportsPathAndImportKeys(): void
    {
        // The value stored under a tag may itself be an array even when the
        // wc map is an object.
        $haxcms = new SiteRoutesBlocksFakeHaxcms(
            (object) array('my-block' => array('path' => 'a.js'))
        );
        $GLOBALS['HAXCMS'] = $haxcms;
        $this->assertSame('a.js', $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data']['import']);

        $haxcms2 = new SiteRoutesBlocksFakeHaxcms(
            (object) array('my-block' => array('import' => 'b.js'))
        );
        $GLOBALS['HAXCMS'] = $haxcms2;
        $this->assertSame('b.js', $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data']['import']);
    }

    public function testWcRegistryUnsupportedImportShapeYieldsEmptyImportAndPackage(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms((object) array('my-block' => 5));
        $GLOBALS['HAXCMS'] = $haxcms;
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertSame('', $data['import']);
        $this->assertSame('', $data['package']);
    }

    public function testWcRegistryEmptyAndSlashOnlyImportsProduceEmptyPackage(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms((object) array('my-block' => '//'));
        $GLOBALS['HAXCMS'] = $haxcms;
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertSame('//', $data['import']);
        $this->assertSame('', $data['package']);
    }

    public function testWcRegistryNullFallsBackToEmptyMapWithDefaultImport(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms(null);
        $GLOBALS['HAXCMS'] = $haxcms;
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertSame('', $data['import']);
        $this->assertSame('', $data['package']);
        $this->assertSame(3, $data['usageCount']);
    }

    public function testWcRegistryFallsBackToSiteDirectoryJsonFile(): void
    {
        // The site-directory wc-registry.json fallback only engages when a
        // HAXCMS global exists but getWCRegistryJson() returns a non-object.
        $siteDir = $this->makeTempDir();
        file_put_contents(
            $siteDir . '/wc-registry.json',
            '{"my-block": {"import": "@hax/my-block.js"}}'
        );
        $GLOBALS['HAXCMS'] = new SiteRoutesBlocksFakeHaxcms(null);
        $site = $this->buildSiteWithBlockUsage();
        $site->siteDirectory = $siteDir;
        $data = $this->detailTag($site, 'my-block')['data'];
        $this->assertSame('@hax/my-block.js', $data['import']);
        $this->assertSame('@hax/my-block.js', $data['package']);
    }

    public function testWcRegistryScalarValueDoesNotBreakDetailLookup(): void
    {
        $haxcms = new SiteRoutesBlocksFakeHaxcms('garbage');
        $GLOBALS['HAXCMS'] = $haxcms;
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertSame('my-block', $data['tag']);
        $this->assertSame(3, $data['usageCount']);
        $this->assertSame('', $data['import']);
    }

    public function testDetailIncludeAddsSchemaFragments(): void
    {
        $_GET['include'] = 'haxProperties,haxSchema';
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertSame('my-block', $data['haxProperties']['gizmo']['tag']);
        $this->assertSame('icons:extension', $data['haxProperties']['gizmo']['icon']);
        $this->assertSame('1', $data['haxSchema']['api']);
        $this->assertArrayNotHasKey('haxElementSchema', $data);

        // Without the include param the fragments are omitted.
        unset($_GET['include']);
        $data = $this->detailTag($this->buildSiteWithBlockUsage(), 'my-block')['data'];
        $this->assertArrayNotHasKey('haxProperties', $data);
        $this->assertArrayNotHasKey('haxSchema', $data);
    }

    public function testItemsWithoutUsageOrContentAreSkipped(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
            makeSiteRouteItem('c', 'page-c', 'Page C', '', 3),
        );
        $site->pageContentMap = array(
            'a' => '<my-block>Once</my-block>',
            // 'c' has no content map entry -> getItemContent() returns ''
        );
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-block'), 'v1/blocks/my-block/usage');
        $result = invokeSiteRouteHandler('blocks.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('a', $data['items'][0]['id']);

        // Detail route: the empty-content item never reaches the DOM
        // extraction path and contributes nothing to usedInDetails.
        $detail = $this->detailTag($site, 'my-block')['data'];
        $this->assertSame(array('a'), $detail['usedIn']);
        $this->assertCount(1, $detail['usedInDetails']);
    }

    public function testBooleanStyleAttributeYieldsEmptyPropertyValue(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'page-a', 'Page A', '', 1),
        );
        $site->pageContentMap = array(
            'a' => '<my-block disabled flag="on">Hi</my-block>',
        );
        $detail = $this->detailTag($site, 'my-block')['data'];
        $properties = $detail['usedInDetails'][0]['instances'][0]['haxElementSchema']['properties'];
        // A value-less attribute gets an empty-string property; a valued one
        // keeps its value.
        $this->assertSame('', $properties['disabled']);
        $this->assertSame('on', $properties['flag']);
    }
}
