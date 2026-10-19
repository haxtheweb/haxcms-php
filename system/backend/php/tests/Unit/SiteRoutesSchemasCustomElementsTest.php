<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Minimal stand-in for the $GLOBALS['HAXCMS'] global, exposing only the
 * getWCRegistryJson($site) method that customElements.php/schemas.php call
 * via method_exists() guards.
 */
class SiteRoutesFakeHaxcmsWcRegistry
{
    private $wcMap;

    public function __construct($wcMap)
    {
        $this->wcMap = $wcMap;
    }

    public function getWCRegistryJson($site)
    {
        return $this->wcMap;
    }
}

/**
 * Route-level characterization tests for lib/siteRoutes/v1/schemas.php and
 * lib/siteRoutes/v1/customElements.php.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesSchemasCustomElementsTest extends TestCase
{
    private $tmpDirs = array();

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
        $this->tmpDirs = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        unset($GLOBALS['HAXCMS']);
    }

    private function removeDirRecursive($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            }
            else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function buildSiteWithWcRegistryFile($wcMapArray)
    {
        $site = new SiteRoutesFakeSite();
        $dir = sys_get_temp_dir() . '/site-routes-wc-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        file_put_contents($dir . '/wc-registry.json', json_encode($wcMapArray));
        $site->siteDirectory = $dir;
        return $site;
    }

    /**
     * customElements.php's getWcMap() initializes $wcMap = new stdClass()
     * (already an object) BEFORE checking $GLOBALS['HAXCMS'], so its
     * `if (!is_object($wcMap))` filesystem-fallback branch that reads
     * wc-registry.json directly from the site directory is unreachable
     * whenever no HAXCMS global is present. In production the HAXCMS global
     * is always set, so this fake mirrors the realistic/reachable code path:
     * $GLOBALS['HAXCMS']->getWCRegistryJson($site) returning a decoded map.
     */
    private function buildSiteWithWcRegistryViaHaxcmsGlobal($wcMapArray)
    {
        $site = new SiteRoutesFakeSite();
        $wcMap = json_decode(json_encode($wcMapArray));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry($wcMap);
        return $site;
    }

    // --- schemas.php ---

    public function testSchemasMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testSchemasListReturnsAllDescriptorsWithLinks(): void
    {
        $site = new SiteRoutesFakeSite();
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $data = $result['data']['data'];
        $ids = array_column($data['schemas'], 'id');
        $this->assertContains('json-outline-schema', $ids);
        $this->assertContains('hax-properties', $ids);
        $this->assertSame('/x/api/v1/schemas', $data['links']['self']);
        $this->assertSame(count($ids), $data['count']);
    }

    public function testSchemasFilterKindNarrowsToMatchingDescriptor(): void
    {
        $site = new SiteRoutesFakeSite();
        $_GET['filter.kind'] = 'xapi';
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $data = $result['data']['data'];
        $this->assertCount(1, $data['schemas']);
        $this->assertSame('xapi-statement-schema', $data['schemas'][0]['id']);
    }

    public function testSchemasFilterWebcomponentNameWithoutRegistryUsesFallbackShape(): void
    {
        $site = new SiteRoutesFakeSite();
        $_GET['filter.webcomponentName'] = 'my-element';
        $_GET['filter.kind'] = 'haxSchema';
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        $result = invokeSiteRouteHandler('schemas.php', $context);
        $data = $result['data']['data'];
        $this->assertCount(1, $data['schemas']);
        $this->assertSame('my-element', $data['schemas'][0]['schema']['tag']);
    }

    // --- schemas.php haxProperties loading pipeline ---

    private function buildSiteWithNodeModules()
    {
        $site = new SiteRoutesFakeSite();
        $dir = sys_get_temp_dir() . '/site-routes-schemas-' . bin2hex(random_bytes(6));
        mkdir($dir . '/node_modules', 0777, true);
        $this->tmpDirs[] = $dir;
        $site->siteDirectory = $dir;
        return $site;
    }

    private function writeHaxPropertiesFixture($siteDirectory, $relativePath, $props)
    {
        $fullPath = $siteDirectory . '/node_modules/' . $relativePath;
        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0777, true);
        }
        file_put_contents($fullPath, json_encode($props));
        return $fullPath;
    }

    private function schemasDataWithFilters($site, array $get)
    {
        foreach ($get as $key => $value) {
            $_GET[$key] = $value;
        }
        $context = makeSiteRouteContext($site, array(), 'v1/schemas');
        return invokeSiteRouteHandler('schemas.php', $context)['data']['data'];
    }

    private function schemaById(array $data, $id)
    {
        foreach ($data['schemas'] as $schema) {
            if ($schema['id'] === $id) {
                return $schema['schema'];
            }
        }
        $this->fail('Schema descriptor not found: ' . $id);
    }

    public function testSchemasLoadsHaxPropertiesFromSiteNodeModules(): void
    {
        $site = $this->buildSiteWithNodeModules();
        $this->writeHaxPropertiesFixture($site->siteDirectory, 'my-element/my-element.haxProperties.json', array(
            'api' => '2',
            'canScale' => false,
            'customKey' => array('nested' => true),
        ));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
            json_decode(json_encode(array('my-element' => 'my-element/my-element.js')))
        );
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'my-element'));
        $haxPropertiesSchema = $this->schemaById($data, 'hax-properties');
        $this->assertSame('my-element', $haxPropertiesSchema['tag']);
        $this->assertSame('2', $haxPropertiesSchema['api']);
        $this->assertSame(array('nested' => true), $haxPropertiesSchema['customKey']);
        $haxSchema = $this->schemaById($data, 'hax-schema');
        $this->assertSame('my-element', $haxSchema['tag']);
        $this->assertSame('2', $haxSchema['api']);
        $this->assertFalse($haxSchema['canScale']);
        $this->assertTrue($haxSchema['canPosition']);
        $this->assertTrue($haxSchema['canEditSource']);
    }

    public function testSchemasHaxPropertiesCaseInsensitiveObjectRegistryKey(): void
    {
        $site = $this->buildSiteWithNodeModules();
        $this->writeHaxPropertiesFixture($site->siteDirectory, 'upper-el/upper-el.haxProperties.json', array('api' => '3'));
        // Registry key casing differs from the requested tag: the exact-key
        // isset() misses and the normalized-key loop resolves the import.
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
            json_decode(json_encode(array('Upper-El' => 'upper-el/upper-el.js')))
        );
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'upper-el'));
        $this->assertSame('3', $this->schemaById($data, 'hax-properties')['api']);
    }

    public function testSchemasHaxPropertiesFromArrayRegistry(): void
    {
        $site = $this->buildSiteWithNodeModules();
        $this->writeHaxPropertiesFixture($site->siteDirectory, 'arr-el/arr-el.haxProperties.json', array('api' => '4'));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(array('arr-el' => 'arr-el/arr-el.js'));
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'arr-el'));
        $this->assertSame('4', $this->schemaById($data, 'hax-properties')['api']);
    }

    public function testSchemasHaxPropertiesFromArrayRegistryCaseInsensitiveKey(): void
    {
        $site = $this->buildSiteWithNodeModules();
        $this->writeHaxPropertiesFixture($site->siteDirectory, 'arr-el2/arr-el2.haxProperties.json', array('api' => '5'));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(array('Arr-El2' => 'arr-el2/arr-el2.js'));
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'arr-el2'));
        $this->assertSame('5', $this->schemaById($data, 'hax-properties')['api']);
    }

    public function testSchemasHaxPropertiesResolvedViaPackageLibCandidate(): void
    {
        $site = $this->buildSiteWithNodeModules();
        // Import lives in dist/ but the haxProperties file only exists at the
        // package-root lib/ candidate, so earlier candidates is_file-miss.
        $this->writeHaxPropertiesFixture($site->siteDirectory, 'pkg-b/lib/scoped-el.haxProperties.json', array('api' => '6'));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
            json_decode(json_encode(array('scoped-el' => 'pkg-b/dist/scoped-el.js')))
        );
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'scoped-el'));
        $this->assertSame('6', $this->schemaById($data, 'hax-properties')['api']);
    }

    public function testSchemasHaxPropertiesScopedPackageNameResolvesPackageRoot(): void
    {
        $site = $this->buildSiteWithNodeModules();
        // '@scope/el/x.js' parses to package '@scope/el'; the file exists at
        // the <packageRoot>/lib/<tag>.haxProperties.json candidate.
        $this->writeHaxPropertiesFixture($site->siteDirectory, '@scope/el/lib/el.haxProperties.json', array('api' => '7'));
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
            json_decode(json_encode(array('el' => '@scope/el/x.js')))
        );
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'el'));
        $this->assertSame('7', $this->schemaById($data, 'hax-properties')['api']);
    }

    public function testSchemasHaxPropertiesImportPathValueVariants(): void
    {
        $site = $this->buildSiteWithNodeModules();
        foreach (array('el-a', 'el-b', 'el-c', 'el-d') as $tag) {
            $this->writeHaxPropertiesFixture($site->siteDirectory, 'pkg-v/' . $tag . '.haxProperties.json', array('api' => $tag));
        }
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry((object) array(
            'el-a' => (object) array('path' => 'pkg-v/dist/el-a.js'),
            'el-b' => (object) array('import' => 'pkg-v/dist/el-b.js'),
            'el-c' => array('path' => 'pkg-v/dist/el-c.js'),
            'el-d' => array('import' => 'pkg-v/dist/el-d.js'),
        ));
        foreach (array('el-a', 'el-b', 'el-c', 'el-d') as $tag) {
            $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => $tag));
            $this->assertSame($tag, $this->schemaById($data, 'hax-properties')['api'], $tag);
        }
    }

    public function testSchemasHaxPropertiesNonStringImportValueFallsBackToDefaults(): void
    {
        $site = $this->buildSiteWithNodeModules();
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
            json_decode(json_encode(array('el-e' => 42)))
        );
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'el-e'));
        $haxPropertiesSchema = $this->schemaById($data, 'hax-properties');
        $this->assertSame('object', $haxPropertiesSchema['type']);
        $this->assertSame('el-e', $haxPropertiesSchema['tag']);
    }

    public function testSchemasHaxPropertiesSlashImportPathYieldsNoPackage(): void
    {
        $site = $this->buildSiteWithNodeModules();
        // '/' explodes to zero path parts, so parsePackageName returns '' and
        // no package-root candidates are generated; nothing resolves on disk.
        $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
            json_decode(json_encode(array('el-slash' => '/')))
        );
        $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'el-slash'));
        $this->assertSame('object', $this->schemaById($data, 'hax-properties')['type']);
    }

    public function testSchemasSearchRootsDeduplicateIdenticalCandidates(): void
    {
        // Run against a temp CWD and derive siteDirectory from it: the
        // site-derived node_modules candidate then string-equals the
        // getcwd()-derived one AND exists on disk, so the first occurrence is
        // added to the roots and the duplicate trips the in_array() continue.
        $originalCwd = getcwd();
        $tmp = sys_get_temp_dir() . '/site-routes-schemas-cwd-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/src/public/build/es6/node_modules', 0777, true);
        $this->tmpDirs[] = $tmp;
        try {
            chdir($tmp);
            $site = new SiteRoutesFakeSite();
            $site->siteDirectory = $tmp . '/src/public/build/es6';
            $GLOBALS['HAXCMS'] = new SiteRoutesFakeHaxcmsWcRegistry(
                json_decode(json_encode(array('dedupe-el' => 'dedupe-el/dedupe-el.js')))
            );
            $data = $this->schemasDataWithFilters($site, array('filter.webcomponentName' => 'dedupe-el'));
        } finally {
            chdir($originalCwd);
        }
        $haxPropertiesSchema = $this->schemaById($data, 'hax-properties');
        $this->assertSame('object', $haxPropertiesSchema['type']);
        $this->assertSame('dedupe-el', $haxPropertiesSchema['tag']);
    }

    // --- customElements.php ---

    public function testCustomElementsMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testCustomElementsEmptyRegistryReturnsEmptyList(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array());
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(0, $data['count']);
        $this->assertSame(array(), $data['customElements']);
    }

    public function testCustomElementsListFromWcRegistryFile(): void
    {
        $site = $this->buildSiteWithWcRegistryFile(array(
            'my-element' => 'my-element/my-element.js',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        // getWcMap() initializes $wcMap = null before checking the HAXCMS
        // global, so when no HAXCMS global is set the is_object() guard on
        // the filesystem fallback is reachable and the on-disk
        // wc-registry.json IS read.
        $this->assertSame(1, $data['count']);
        $this->assertSame('my-element', $data['customElements'][0]['tag']);
    }

    public function testCustomElementsListFromHaxcmsGlobalRegistry(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
            'other-element' => '@scope/other-element/other-element.js',
        ));
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(2, $data['count']);
        $tags = array_column($data['customElements'], 'tag');
        sort($tags);
        $this->assertSame(array('my-element', 'other-element'), $tags);
    }

    public function testCustomElementsDetailByTagIncludesPackageAndLinks(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
        ));
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-element'), 'v1/custom-elements/my-element');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('my-element', $data['tag']);
        $this->assertSame('my-element/my-element.js', $data['import']);
        $this->assertSame('my-element', $data['package']);
        $this->assertSame('/x/api/v1/custom-elements/my-element', $data['links']['self']);
    }

    public function testCustomElementsDetailUnknownTagReturns404(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array());
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'nope'), 'v1/custom-elements/nope');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testCustomElementsIncludeAddsSchemaFragments(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
        ));
        $_GET['include'] = 'haxProperties,haxSchema,haxElementSchema';
        $context = makeSiteRouteContext($site, array('webcomponentName' => 'my-element'), 'v1/custom-elements/my-element');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertArrayHasKey('haxProperties', $data);
        $this->assertArrayHasKey('haxSchema', $data);
        $this->assertArrayHasKey('haxElementSchema', $data);
        $this->assertSame('my-element', $data['haxElementSchema']['tag']);
    }

    public function testCustomElementsFilterTagNarrowsList(): void
    {
        $site = $this->buildSiteWithWcRegistryViaHaxcmsGlobal(array(
            'my-element' => 'my-element/my-element.js',
            'other-thing' => 'other-thing/other-thing.js',
        ));
        $_GET['filter.tag'] = 'my-';
        $context = makeSiteRouteContext($site, array(), 'v1/custom-elements');
        $result = invokeSiteRouteHandler('customElements.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame('my-element', $data['customElements'][0]['tag']);
    }
}
