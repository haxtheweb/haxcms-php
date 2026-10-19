<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

include_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteRouteUtils.php';
require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Characterization tests for the remaining SiteRouteUtils seams not covered
 * by SiteRouteUtilsTest (pure utilities) or
 * SiteRouteUtilsItemsSearchHelpersTest (search/navigation builders):
 *
 * - $_SERVER-derived request path / bearer-token helpers
 * - $_GET query coercion helpers (dotted keys, CSV arrays, number/bool)
 * - $GLOBALS['HAXCMS'] delegates (cleanSlug, getSiteBasePath, language, theme)
 * - manifest normalization / ordered items / id-or-slug lookup / item content
 * - response formatting: detectResponseFormat, serializePayload, toXmlNode,
 *   toYaml/parseYaml, sendFormattedResponse (rawByFormat, envelopes)
 * - yamlUsesAnchorsOrAliases tokenizer edges beyond YamlAliasGuardTest
 * - normalizeSecurityPolicy + OpenAPI route converters
 * - spec-driven auth policy readers/lookups + mutation-security guard
 * - itemToSummary, isAnonymousSiteApiRequest, filterOpenApiToPublicOperations
 * - applyItemFilters (anonymous visibility + filter.* query params)
 * - collectSiteFiles + custom-element usage collection
 *
 * Known-unreachable branches (verified dead in this environment, not tested):
 * - SiteRouteUtils.php:66 (preg_replace failure guard): the patterns are
 *   static and cannot fail.
 * - SiteRouteUtils.php:96-98 (getallheaders fallback): every polyfill /
 *   SAPI implementation derives its headers from $_SERVER HTTP_* keys, so a
 *   request that has an Authorization header is already consumed by the
 *   HTTP_AUTHORIZATION branch at line 80.
 * - SiteRouteUtils.php:873 / 880 / 1967-2012 (simpleYamlSerialize and the
 *   toYaml/parseYaml fallbacks): Symfony YAML is a hard dependency in
 *   vendor/, so class_exists() is always true under test.
 * - SiteRouteUtils.php:1148-1160 / 1208-1220 (missing/empty/unparseable spec
 *   guards): the real site-spec.yaml / system-spec.yaml always exist in the
 *   repo and the readers cannot be pointed elsewhere.
 * - SiteRouteUtils.php:1308-1311 (assertSiteApiMutationRoutesAreSecured
 *   error_log): every mutation operation in the real site-spec.yaml declares
 *   a non-public security policy, so no offender can ever be produced.
 * - SiteRouteUtils.php:1371-1373 (!class_exists DOMDocument): ext-dom is a
 *   required platform dependency of this codebase.
 */
class SiteRouteUtilsHelpersTest extends TestCase
{
    private array $serverBackup = array();
    private $getBackup = null;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        unset($GLOBALS['HAXCMS']);
        putenv('HTTP_AUTHORIZATION');
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        unset($GLOBALS['HAXCMS']);
        putenv('HTTP_AUTHORIZATION');
        http_response_code(200);
    }

    // ------------------------------------------------------------------
    // Request path helpers ($_SERVER)
    // ------------------------------------------------------------------

    public function testGetRequestPathParsesUriAndStripsQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/x/api/v1/items?filter.tags=a,b&page=2';
        $this->assertSame('/x/api/v1/items', SiteRouteUtils::getRequestPath());
    }

    public function testGetRequestPathDefaultsToRootWhenUriMissingOrUnusable(): void
    {
        unset($_SERVER['REQUEST_URI']);
        $this->assertSame('/', SiteRouteUtils::getRequestPath());
        $_SERVER['REQUEST_URI'] = '';
        $this->assertSame('/', SiteRouteUtils::getRequestPath());
    }

    public function testGetRelativePathStripsScriptDirectory(): void
    {
        $_SERVER['REQUEST_URI'] = '/base/www/x/api/v1/items';
        $_SERVER['SCRIPT_NAME'] = '/base/www/index.php';
        $this->assertSame('/x/api/v1/items', SiteRouteUtils::getRelativePathFromScript());
    }

    public function testGetRelativePathIgnoresDotAndRootScriptDirectories(): void
    {
        $_SERVER['REQUEST_URI'] = '/x/api/v1/site';
        // dirname('index.php') === '.' and dirname('/index.php') === '/',
        // both of which are discarded rather than treated as a prefix.
        $_SERVER['SCRIPT_NAME'] = 'index.php';
        $this->assertSame('/x/api/v1/site', SiteRouteUtils::getRelativePathFromScript());
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->assertSame('/x/api/v1/site', SiteRouteUtils::getRelativePathFromScript());
    }

    public function testGetRelativePathFallsBackToRequestPathDefaultArg(): void
    {
        // No explicit argument and no SCRIPT_NAME: the request path is used
        // as-is (covers the getRequestPath() default-argument seam).
        $_SERVER['REQUEST_URI'] = '/x/api/v1/site';
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertSame('/x/api/v1/site', SiteRouteUtils::getRelativePathFromScript());
    }

    public function testGetRelativePathReturnsRootWhenScriptPrefixConsumesPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/base';
        $_SERVER['SCRIPT_NAME'] = '/base/index.php';
        $this->assertSame('/', SiteRouteUtils::getRelativePathFromScript());
    }

    public function testGetRelativePathPrependsMissingLeadingSlash(): void
    {
        // parse_url('foo/bar', PHP_URL_PATH) === 'foo/bar' (no leading slash).
        $_SERVER['REQUEST_URI'] = 'foo/bar';
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertSame('/foo/bar', SiteRouteUtils::getRelativePathFromScript());
    }

    public function testIsSiteApiPathDefaultsToDerivedRequestPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/x/api/v1/site';
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertTrue(SiteRouteUtils::isSiteApiPath());
        $_SERVER['REQUEST_URI'] = '/v1/site';
        $this->assertFalse(SiteRouteUtils::isSiteApiPath());
    }

    public function testIsSiteApiPathMatchesPrefixAndBoundaryOnly(): void
    {
        $this->assertTrue(SiteRouteUtils::isSiteApiPath('/x/api'));
        $this->assertTrue(SiteRouteUtils::isSiteApiPath('/x/api/v1/items'));
        // Boundary respected: /x/apifoo is not an API path.
        $this->assertFalse(SiteRouteUtils::isSiteApiPath('/x/apifoo'));
        $this->assertFalse(SiteRouteUtils::isSiteApiPath('/v1/items'));
        $this->assertFalse(SiteRouteUtils::isSiteApiPath('/'));
    }

    public function testGetApiBasePathFromRequestPathUsesLongestXApiPrefix(): void
    {
        $this->assertSame('/sub/x/api', SiteRouteUtils::getApiBasePathFromRequestPath('/sub/x/api/v1/items'));
        $this->assertSame('/x/api', SiteRouteUtils::getApiBasePathFromRequestPath('/x/api'));
        // No /x/api prefix anywhere: documented default.
        $this->assertSame('/x/api', SiteRouteUtils::getApiBasePathFromRequestPath('/v1/items'));
    }

    public function testGetApiBasePathFromRequestPathDefaultsToRequestUri(): void
    {
        $_SERVER['REQUEST_URI'] = '/base/x/api/v1/site';
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertSame('/base/x/api', SiteRouteUtils::getApiBasePathFromRequestPath());
    }

    public function testGetRouteSuffixFromRelativePathDerivesRouteKey(): void
    {
        $this->assertSame('v1/items', SiteRouteUtils::getRouteSuffixFromRelativePath('/x/api/v1/items'));
        $this->assertSame('v1/site', SiteRouteUtils::getRouteSuffixFromRelativePath('/x/api/v1/site/'));
        $this->assertSame('', SiteRouteUtils::getRouteSuffixFromRelativePath('/x/api/'));
        // Not an API path at all: null.
        $this->assertNull(SiteRouteUtils::getRouteSuffixFromRelativePath('/v1/items'));
        $this->assertNull(SiteRouteUtils::getRouteSuffixFromRelativePath('x/api/v1/items'));
    }

    public function testGetRouteSuffixFromRelativePathDefaultsToRequestPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/x/api/v1/content';
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertSame('v1/content', SiteRouteUtils::getRouteSuffixFromRelativePath());
    }

    public function testGetRequestMethodNormalizesCaseAndDefaultsToGet(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $this->assertSame('GET', SiteRouteUtils::getRequestMethod());
        $_SERVER['REQUEST_METHOD'] = 'post';
        $this->assertSame('POST', SiteRouteUtils::getRequestMethod());
    }

    // ------------------------------------------------------------------
    // Bearer token extraction
    // ------------------------------------------------------------------

    public function testGetBearerTokenFromAuthorizationHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
        $this->assertSame('abc123', SiteRouteUtils::getBearerTokenFromRequest());
        // Scheme comparison is case-insensitive.
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer tok';
        $this->assertSame('tok', SiteRouteUtils::getBearerTokenFromRequest());
    }

    public function testGetBearerTokenRejectsNonBearerAndMalformedHeaders(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $this->assertNull(SiteRouteUtils::getBearerTokenFromRequest());
        // 'Bearer' with no token collapses to a single part.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $this->assertNull(SiteRouteUtils::getBearerTokenFromRequest());
        // Empty header string is treated as absent.
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        $this->assertNull(SiteRouteUtils::getBearerTokenFromRequest());
        // Three space-separated segments do not parse as Bearer.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer a b';
        $this->assertNull(SiteRouteUtils::getBearerTokenFromRequest());
    }

    public function testGetBearerTokenFallsBackToRedirectHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';
        $this->assertSame('redirect-token', SiteRouteUtils::getBearerTokenFromRequest());
    }

    public function testGetBearerTokenFallsBackToEnvironmentVariable(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        putenv('HTTP_AUTHORIZATION=Bearer env-token');
        $this->assertSame('env-token', SiteRouteUtils::getBearerTokenFromRequest());
    }

    public function testGetBearerTokenReturnsNullWhenNoSourceHasToken(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        putenv('HTTP_AUTHORIZATION');
        $this->assertNull(SiteRouteUtils::getBearerTokenFromRequest());
    }

    // ------------------------------------------------------------------
    // Query helpers ($_GET)
    // ------------------------------------------------------------------

    public function testGetQueryValueFallsBackWhenGetUnavailable(): void
    {
        $_GET = null;
        $this->assertSame('fb', SiteRouteUtils::getQueryValue('anything', 'fb'));
    }

    public function testGetQueryValueDottedKeyFallsBackToUnderscoreVariant(): void
    {
        $_GET = array('page_limit' => '5');
        $this->assertSame('5', SiteRouteUtils::getQueryValue('page.limit', 'none'));
        $this->assertSame('none', SiteRouteUtils::getQueryValue('page.size', 'none'));
        // Literal key wins over the underscore variant.
        $_GET = array('page.limit' => 'literal', 'page_limit' => 'converted');
        $this->assertSame('literal', SiteRouteUtils::getQueryValue('page.limit', 'none'));
    }

    public function testGetCsvQueryAcceptsArrayValues(): void
    {
        $_GET = array('t' => array(' a ', '', 'b'));
        $this->assertSame(array('a', 'b'), SiteRouteUtils::getCsvQuery('t'));
    }

    public function testGetCsvQuerySplitsAndTrimsStrings(): void
    {
        $_GET = array('t' => ' a ,, b ');
        $this->assertSame(array('a', 'b'), SiteRouteUtils::getCsvQuery('t'));
        $_GET = array('t' => '');
        $this->assertSame(array(), SiteRouteUtils::getCsvQuery('t'));
        $_GET = array();
        $this->assertSame(array(), SiteRouteUtils::getCsvQuery('missing'));
    }

    public function testGetNumberQueryClampsToMinAndMax(): void
    {
        $_GET = array('n' => '1');
        $this->assertSame(5, SiteRouteUtils::getNumberQuery('n', 25, 5, 200));
        $_GET = array('n' => '999');
        $this->assertSame(200, SiteRouteUtils::getNumberQuery('n', 25, 5, 200));
        $_GET = array('n' => '42');
        $this->assertSame(42, SiteRouteUtils::getNumberQuery('n', 25, 5, 200));
        // No clamps supplied: value passes through.
        $_GET = array('n' => '-7');
        $this->assertSame(-7, SiteRouteUtils::getNumberQuery('n', 25));
    }

    public function testGetNumberQueryFallsBackOnMissingOrNonNumeric(): void
    {
        $_GET = array();
        $this->assertSame(25, SiteRouteUtils::getNumberQuery('n', 25));
        // The fallback value itself is subject to the clamps.
        $this->assertSame(10, SiteRouteUtils::getNumberQuery('n', 25, 1, 10));
        $_GET = array('n' => 'abc');
        // Non-numeric input returns the fallback BEFORE clamping.
        $this->assertSame(25, SiteRouteUtils::getNumberQuery('n', 25, 1, 10));
    }

    public static function booleanQueryProvider(): array
    {
        return array(
            'bool true passes through' => array(true, 'fb', true),
            'numeric one is true' => array(1, 'fb', true),
            'numeric zero is false' => array(0, 'fb', false),
            'string 1' => array('1', 'fb', true),
            'string true' => array('true', 'fb', true),
            'string yes' => array('yes', 'fb', true),
            'string on' => array('on', 'fb', true),
            'uppercase padded' => array(' ON ', 'fb', true),
            'string 0' => array('0', 'fb', false),
            'string false' => array('false', 'fb', false),
            'string no' => array('no', 'fb', false),
            'string off' => array('off', 'fb', false),
            'unrecognized string returns fallback' => array('maybe', 'fb', 'fb'),
        );
    }

    #[DataProvider('booleanQueryProvider')]
    public function testGetBooleanQueryCoercion(mixed $raw, mixed $fallback, mixed $expected): void
    {
        $_GET = array('flag' => $raw);
        $this->assertSame($expected, SiteRouteUtils::getBooleanQuery('flag', $fallback));
    }

    public function testGetBooleanQueryFallsBackWhenAbsent(): void
    {
        $_GET = array();
        $this->assertNull(SiteRouteUtils::getBooleanQuery('flag', null));
        $this->assertFalse(SiteRouteUtils::getBooleanQuery('flag', false));
    }

    // ------------------------------------------------------------------
    // $GLOBALS['HAXCMS'] delegates
    // ------------------------------------------------------------------

    public function testCleanSlugDelegatesToGlobalHaxcms(): void
    {
        $GLOBALS['HAXCMS'] = new SiteRouteUtilsHelpersFakeHaxcms();
        $this->assertSame('haxcms-cleaned', SiteRouteUtils::cleanSlug('whatever'));
    }

    public function testGetVersionDelegatesAndFallsBack(): void
    {
        $this->assertSame('0.0.0', SiteRouteUtils::getVersion());
        $GLOBALS['HAXCMS'] = new SiteRouteUtilsHelpersFakeHaxcms();
        $this->assertSame('9.9.9-test', SiteRouteUtils::getVersion());
    }

    public function testValidateSiteTokenFailsClosedWithoutHaxcms(): void
    {
        // No $GLOBALS['HAXCMS'] (or missing validateRequestToken): fail closed.
        $this->assertFalse(SiteRouteUtils::validateSiteToken('testsite', 'tok'));
    }

    public function testGenerateUUIDIsRFC4122V4(): void
    {
        $uuid = SiteRouteUtils::generateUUID();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
        $this->assertNotSame($uuid, SiteRouteUtils::generateUUID());
    }

    public function testGetSiteBasePathReturnsRootWithoutManifestMetadata(): void
    {
        $this->assertSame('/', SiteRouteUtils::getSiteBasePath(null));
        $this->assertSame('/', SiteRouteUtils::getSiteBasePath((object) array()));
        $this->assertSame('/', SiteRouteUtils::getSiteBasePath((object) array('manifest' => new stdClass())));
    }

    public function testGetSiteBasePathUsesSitesDirectoryFromSiteBasePath(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->basePath = '/var/www/_sites/testsite';
        $this->assertSame('/_sites/testsite/', SiteRouteUtils::getSiteBasePath($site));
        // Without the sites-directory marker the name is appended directly.
        $site2 = new SiteRoutesFakeSite();
        $this->assertSame('/testsite/', SiteRouteUtils::getSiteBasePath($site2));
    }

    public function testGetSiteBasePathHonorsGlobalBasePathAndSitesDirectory(): void
    {
        $GLOBALS['HAXCMS'] = (object) array(
            'basePath' => '/sub',
            'sitesDirectory' => 'custom-sites',
        );
        $site = new SiteRoutesFakeSite();
        $site->basePath = '/x/custom-sites/testsite';
        $this->assertSame('/sub/custom-sites/testsite/', SiteRouteUtils::getSiteBasePath($site));
    }

    public function testGetSiteLanguagePrefersManifestThenSiteThenDefault(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->metadata->site->settings = (object) array('lang' => 'fr');
        $this->assertSame('fr', SiteRouteUtils::getSiteLanguage($site));
        $site2 = new SiteRoutesFakeSite();
        $site2->language = 'de';
        $this->assertSame('de', SiteRouteUtils::getSiteLanguage($site2));
        // Empty site language falls through to the 'en' default.
        $empty = new SiteRoutesFakeSite();
        $empty->language = '';
        $this->assertSame('en', SiteRouteUtils::getSiteLanguage($empty));
    }

    public function testGetSiteThemeReadsManifestThemeElement(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->metadata->theme = (object) array('element' => 'clean-two');
        $this->assertSame('clean-two', SiteRouteUtils::getSiteTheme($site));
        $this->assertNull(SiteRouteUtils::getSiteTheme(new SiteRoutesFakeSite()));
    }

    public function testGetSiteDirectoryPrefersSiteDirectoryThenDirectoryPlusName(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->siteDirectory = '/tmp/sites/testsite/';
        $this->assertSame('/tmp/sites/testsite', SiteRouteUtils::getSiteDirectory($site));
        // No siteDirectory: directory + manifest site name.
        $site2 = new SiteRoutesFakeSite();
        $site2->directory = '/var/www/_sites/';
        $this->assertSame('/var/www/_sites/testsite', SiteRouteUtils::getSiteDirectory($site2));
        // No usable path data at all: empty string.
        $this->assertSame('', SiteRouteUtils::getSiteDirectory((object) array('manifest' => new SiteRoutesFakeManifest())));
        $this->assertSame('', SiteRouteUtils::getSiteDirectory(null));
    }

    // ------------------------------------------------------------------
    // Manifest helpers
    // ------------------------------------------------------------------

    public function testNormalizeManifestItemsHandlesMissingAndScalarItems(): void
    {
        $this->assertSame(array(), SiteRouteUtils::normalizeManifestItems(null));
        $site = new SiteRoutesFakeSite();
        unset($site->manifest->items);
        $this->assertSame(array(), SiteRouteUtils::normalizeManifestItems($site));
        $site2 = new SiteRoutesFakeSite();
        $site2->manifest->items = 'not-a-collection';
        $this->assertSame(array(), SiteRouteUtils::normalizeManifestItems($site2));
    }

    public function testNormalizeManifestItemsAcceptsObjectMaps(): void
    {
        $site = new SiteRoutesFakeSite();
        $itemA = makeSiteRouteItem('a', 'a', 'A');
        $itemB = makeSiteRouteItem('b', 'b', 'B');
        $site->manifest->items = (object) array('first' => $itemA, 'second' => $itemB);
        $this->assertSame(array($itemA, $itemB), SiteRouteUtils::normalizeManifestItems($site));
    }

    public function testNormalizeManifestItemsDropsFalsyArrayEntries(): void
    {
        $site = new SiteRoutesFakeSite();
        $item = makeSiteRouteItem('a', 'a', 'A');
        $site->manifest->items = array(null, false, $item);
        $this->assertSame(array($item), SiteRouteUtils::normalizeManifestItems($site));
    }

    public function testGetOrderedItemsReturnsItemsWhenOrderTreeThrows(): void
    {
        $site = (object) array('manifest' => new SiteRouteUtilsHelpersThrowingOrderManifest());
        $items = SiteRouteUtils::getOrderedItems($site);
        $this->assertCount(1, $items);
        $this->assertSame('thrown-order-marker', $items[0]->id);
    }

    public function testGetOrderedItemsReturnsOrderTreeResult(): void
    {
        $site = new SiteRoutesFakeSite();
        $a = makeSiteRouteItem('a', 'a', 'A', '', 2);
        $b = makeSiteRouteItem('b', 'b', 'B', '', 1);
        $site->manifest->items = array($a, $b);
        $ordered = SiteRouteUtils::getOrderedItems($site);
        $this->assertSame(array('b', 'a'), array_column($ordered, 'id'));
    }

    public function testFindItemByIdOrSlugReturnsNullForEmptyOrMissingManifest(): void
    {
        $this->assertNull(SiteRouteUtils::findItemByIdOrSlug(new SiteRoutesFakeSite(), ''));
        $this->assertNull(SiteRouteUtils::findItemByIdOrSlug(null, 'x'));
        $this->assertNull(SiteRouteUtils::findItemByIdOrSlug((object) array(), 'x'));
    }

    public function testFindItemByIdOrSlugUsesGetPropertyManifestSeam(): void
    {
        $site = (object) array('manifest' => new SiteRouteUtilsHelpersPropertyManifest());
        $found = SiteRouteUtils::findItemByIdOrSlug($site, 'wanted-slug');
        $this->assertNotNull($found);
        $this->assertSame('wanted-slug', $found->slug);
        $this->assertNull(SiteRouteUtils::findItemByIdOrSlug($site, 'nope'));
    }

    public function testFindItemByIdOrSlugLinearScanFallback(): void
    {
        // Manifest without getItemById/getItemByProperty: linear scan over items.
        $manifest = new stdClass();
        $bySlug = makeSiteRouteItem('id-1', 'wanted-slug', 'One');
        $byId = makeSiteRouteItem('wanted-id', 'other-slug', 'Two');
        $manifest->items = array($bySlug, $byId);
        $site = (object) array('manifest' => $manifest);
        $this->assertSame($bySlug, SiteRouteUtils::findItemByIdOrSlug($site, 'wanted-slug'));
        $this->assertSame($byId, SiteRouteUtils::findItemByIdOrSlug($site, 'wanted-id'));
        $this->assertNull(SiteRouteUtils::findItemByIdOrSlug($site, 'missing'));
    }

    // ------------------------------------------------------------------
    // getItemContent
    // ------------------------------------------------------------------

    public function testGetItemContentReturnsEmptyWithoutSiteOrPageContentMethod(): void
    {
        $item = makeSiteRouteItem('a', 'a', 'A');
        $this->assertSame('', SiteRouteUtils::getItemContent(null, $item));
        $this->assertSame('', SiteRouteUtils::getItemContent(new stdClass(), $item));
        $this->assertSame('', SiteRouteUtils::getItemContent(null, null));
    }

    public function testGetItemContentReadsPageContentDirectlyWithoutLoadNode(): void
    {
        $site = new SiteRouteUtilsHelpersDirectContentSite();
        $item = makeSiteRouteItem('a', 'a', 'A');
        $this->assertSame('direct-content', SiteRouteUtils::getItemContent($site, $item));
        // Non-string page content is discarded.
        $site2 = new SiteRouteUtilsHelpersNonStringContentSite();
        $this->assertSame('', SiteRouteUtils::getItemContent($site2, $item));
    }

    public function testGetItemContentFallsBackToDirectReadWhenLoadNodeMisses(): void
    {
        // loadNode returns null: the handler falls through to getPageContent($item).
        $site = new SiteRouteUtilsHelpersNullLoadNodeSite();
        $item = makeSiteRouteItem('a', 'a', 'A');
        $this->assertSame('fallback-content', SiteRouteUtils::getItemContent($site, $item));
    }

    // ------------------------------------------------------------------
    // setValueByPath
    // ------------------------------------------------------------------

    public function testSetValueByPathIgnoresEmptyPath(): void
    {
        $target = array();
        SiteRouteUtils::setValueByPath($target, '', 'value');
        $this->assertSame(array(), $target);
    }

    public function testSetValueByPathCreatesNestedArrays(): void
    {
        $target = array();
        SiteRouteUtils::setValueByPath($target, 'a.b.c', 1);
        $this->assertSame(array('a' => array('b' => array('c' => 1))), $target);
        // Existing scalars along the path are replaced by arrays.
        SiteRouteUtils::setValueByPath($target, 'a', 'scalar');
        SiteRouteUtils::setValueByPath($target, 'a.deeper', 2);
        $this->assertSame(array('a' => array('deeper' => 2)), $target);
    }

    public function testNormalizeSortTokensUsesDefaultSortWhenEmpty(): void
    {
        $this->assertSame(array(array('key' => 'order', 'desc' => false)), SiteRouteUtils::normalizeSortTokens('', 'order'));
    }

    public function testSortRecordsTreatsEqualKeysAsTies(): void
    {
        $records = array(
            array('id' => 'a', 'order' => 1),
            array('id' => 'b', 'order' => 1),
        );
        $sorted = SiteRouteUtils::sortRecords($records, 'order');
        // Stable sort keeps input order for ties.
        $this->assertSame(array('a', 'b'), array_column($sorted, 'id'));
    }

    public function testPaginateRecordsUsesQueryAndDefaults(): void
    {
        $_GET = array('page.limit' => '2', 'page.offset' => '1');
        $records = array(
            array('id' => 'a'),
            array('id' => 'b'),
            array('id' => 'c'),
            array('id' => 'd'),
        );
        $result = SiteRouteUtils::paginateRecords($records);
        $this->assertSame(2, $result['page']['limit']);
        $this->assertSame(1, $result['page']['offset']);
        $this->assertSame(4, $result['page']['total']);
        $this->assertSame(array(array('id' => 'b'), array('id' => 'c')), $result['records']);
        // Without query params the defaults apply.
        $_GET = array();
        $result2 = SiteRouteUtils::paginateRecords($records, 3, 5);
        $this->assertSame(3, $result2['page']['limit']);
        $this->assertSame(0, $result2['page']['offset']);
        $this->assertSame(array('a', 'b', 'c'), array_column($result2['records'], 'id'));
    }

    // ------------------------------------------------------------------
    // Response format detection + serialization
    // ------------------------------------------------------------------

    public function testDetectResponseFormatSuffixBeatsEverything(): void
    {
        $this->assertSame('yaml', SiteRouteUtils::detectResponseFormat(array('json'), 'json', 'v1/site.yaml'));
        $this->assertSame('json', SiteRouteUtils::detectResponseFormat(array('json'), 'json', 'v1/site.json'));
    }

    public function testDetectResponseFormatHonorsQueryParamWhenAllowed(): void
    {
        $_GET = array('format' => 'md');
        $this->assertSame('md', SiteRouteUtils::detectResponseFormat(array('json', 'md'), 'json', ''));
        $_GET = array('format' => 'yaml');
        $this->assertSame('json', SiteRouteUtils::detectResponseFormat(array('json'), 'json', ''));
    }

    public function testDetectResponseFormatFallsBackToFirstAllowedFormat(): void
    {
        // Default format not allowed and no query param: first allowed wins.
        $this->assertSame('yaml', SiteRouteUtils::detectResponseFormat(array('yaml', 'md'), 'xml', ''));
    }

    public function testNormalizeAllowedFormatsDedupesAndDefaults(): void
    {
        $this->assertSame(array('json', 'yaml'), SiteRouteUtils::normalizeAllowedFormats(array('json', 'JSON', 'yaml', 'bogus')));
        $this->assertSame(array('json'), SiteRouteUtils::normalizeAllowedFormats(array()));
    }

    public function testGetFormatMimeTypeFallsBackToPlainText(): void
    {
        $this->assertSame('text/plain; charset=utf-8', SiteRouteUtils::getFormatMimeType('csv'));
        $this->assertSame('application/json; charset=utf-8', SiteRouteUtils::getFormatMimeType('json'));
    }

    public function testSerializePayloadFormats(): void
    {
        $this->assertSame('[]', SiteRouteUtils::serializePayload(array(), 'json'));
        $this->assertSame("a: 1\n", SiteRouteUtils::serializePayload(array('a' => 1), 'yaml'));
        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<response><root><x>1</x></root></response>',
            SiteRouteUtils::serializePayload(array('root' => array('x' => 1)), 'xml')
        );
        // md: strings pass through; arrays fall back to JSON.
        $this->assertSame('# Raw Markdown', SiteRouteUtils::serializePayload('# Raw Markdown', 'md'));
        $this->assertStringContainsString('"x": 1', SiteRouteUtils::serializePayload(array('x' => 1), 'md'));
        // html: strings pass through; arrays are wrapped in an escaped <pre>.
        $this->assertSame('<b>hi</b>', SiteRouteUtils::serializePayload('<b>hi</b>', 'html'));
        $html = SiteRouteUtils::serializePayload(array('x' => 1), 'html');
        $this->assertStringStartsWith('<pre>', $html);
        $this->assertStringEndsWith('</pre>', $html);
        $this->assertStringContainsString('{', $html);
        // Unknown format: raw string cast.
        $this->assertSame('raw-value', SiteRouteUtils::serializePayload('raw-value', 'csv'));
    }

    public function testToXmlNodeVariants(): void
    {
        $this->assertSame('<a></a>', SiteRouteUtils::toXmlNode('a', null));
        $this->assertSame('<a><b>1</b></a>', SiteRouteUtils::toXmlNode('a', array('b' => 1)));
        $this->assertSame('<a><item>1</item><item>2</item></a>', SiteRouteUtils::toXmlNode('a', array(1, 2)));
        $this->assertSame('<a><b>1</b></a>', SiteRouteUtils::toXmlNode('a', (object) array('b' => 1)));
        $this->assertSame('<a>x&lt;y</a>', SiteRouteUtils::toXmlNode('a', 'x<y'));
    }

    public function testGetSafeXmlTagSanitizesAndPrefixesNumericTags(): void
    {
        $this->assertSame('valid-tag_1', SiteRouteUtils::getSafeXmlTag('valid-tag_1'));
        $this->assertSame('item-1bad', SiteRouteUtils::getSafeXmlTag('1bad'));
        $this->assertSame('a-b-c-', SiteRouteUtils::getSafeXmlTag('a b<c>'));
    }

    public function testEscapeHelpers(): void
    {
        // ENT_HTML401 encodes apostrophes as &#039; while ENT_XML1 uses &apos;.
        $this->assertSame('a&amp;b&quot;c&#039;d&lt;e&gt;', SiteRouteUtils::escapeHtmlValue('a&b"c\'d<e>'));
        $this->assertSame('a&amp;b&quot;c&apos;d&lt;e&gt;', SiteRouteUtils::escapeXmlValue('a&b"c\'d<e>'));
    }

    public function testToYamlAndParseYamlRoundTrip(): void
    {
        $yaml = SiteRouteUtils::toYaml(array('a' => 1, 'b' => array('c' => 'x')));
        $this->assertStringContainsString('a: 1', $yaml);
        $parsed = SiteRouteUtils::parseYaml($yaml);
        $this->assertSame(array('a' => 1, 'b' => array('c' => 'x')), $parsed);
    }

    private function captureResponse($data, $options = array(), $routeSuffix = '', $apiBasePath = '/x/api'): array
    {
        ob_start();
        SiteRouteUtils::sendFormattedResponse($data, $options, $routeSuffix, $apiBasePath);
        $raw = ob_get_clean();
        return array('raw' => $raw, 'status' => http_response_code());
    }

    public function testSendFormattedResponseEnvelopesByDefault(): void
    {
        $res = $this->captureResponse(array('a' => 1));
        $this->assertSame(200, $res['status']);
        $this->assertSame(array('status' => 200, 'data' => array('a' => 1)), json_decode($res['raw'], true));
    }

    public function testSendFormattedResponseHonorsStatusCode(): void
    {
        $res = $this->captureResponse(array('error' => 'nope'), array('statusCode' => 404));
        $this->assertSame(404, $res['status']);
        $this->assertSame(404, json_decode($res['raw'], true)['status']);
    }

    public function testSendFormattedResponsePassesThroughEnvelopedData(): void
    {
        $envelope = array('status' => 201, 'data' => array('x' => 1));
        $res = $this->captureResponse($envelope);
        // Body is the exact envelope; HTTP status stays the configured 200.
        $this->assertSame($envelope, json_decode($res['raw'], true));
        $this->assertSame(200, $res['status']);
    }

    public function testSendFormattedResponseWithoutEnvelopeOutputsRawData(): void
    {
        $res = $this->captureResponse(array('a' => 1), array('envelope' => false));
        $this->assertSame(array('a' => 1), json_decode($res['raw'], true));
    }

    public function testSendFormattedResponseTreatsNonArrayAllowedFormatsAsDefault(): void
    {
        // allowedFormats must be an array; a scalar is ignored so json wins.
        $res = $this->captureResponse(array('a' => 1), array('allowedFormats' => 'yaml'));
        $this->assertSame(array('status' => 200, 'data' => array('a' => 1)), json_decode($res['raw'], true));
    }

    public function testSendFormattedResponseRawByFormatString(): void
    {
        $res = $this->captureResponse(
            array('a' => 1),
            array('allowedFormats' => array('md'), 'rawByFormat' => array('md' => '# Raw Markdown'))
        );
        $this->assertSame('# Raw Markdown', $res['raw']);
    }

    public function testSendFormattedResponseRawByFormatSerializedValue(): void
    {
        $res = $this->captureResponse(
            array('a' => 1),
            array('allowedFormats' => array('md'), 'rawByFormat' => array('md' => array('x' => 1)))
        );
        $this->assertStringContainsString('"x": 1', $res['raw']);
    }

    public function testGetRepresentationPathStripsAndAppendsExtension(): void
    {
        $this->assertSame('/x/api/v1/site.yaml', SiteRouteUtils::getRepresentationPath('/x/api/v1/site.json', 'yaml'));
        $this->assertSame('/x/api/v1/site.md', SiteRouteUtils::getRepresentationPath('/x/api/v1/site', 'md'));
        $this->assertSame('/x/api/v1/site.markdown', SiteRouteUtils::getRepresentationPath('/x/api/v1/site.md', 'markdown'));
    }

    public function testSetRepresentationHeadersIsCallableInCli(): void
    {
        // header() is a no-op in the CLI SAPI; the call just needs to execute
        // (covers the alternates loop) without error.
        SiteRouteUtils::setRepresentationHeaders('/x/api/v1/site', array('json', 'yaml'), 'yaml');
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // yamlUsesAnchorsOrAliases tokenizer edges beyond YamlAliasGuardTest
    // ------------------------------------------------------------------

    public static function tokenizerEdgeProvider(): array
    {
        return array(
            'plain scalar continuation line starting with alias is fail-closed' => array("a: b\n  *c", true),
            'escaped quote inside double-quoted scalar is skipped' => array("a: \"x \\\" *y\"", false),
            'document marker without trailing space is plain scalar' => array("---&x\n", false),
            'ampersand directly followed by flow comma is not a node property' => array("[&, 1]", false),
            'tab after block indicator opens a node position' => array("a:\t*x", true),
            'block scalar content starting with ampersand is skipped' => array("desc: |\n  &not-a-node\n", false),
            'flow mapping with alias value' => array("{a: 1, b: *x}", true),
            'nested flow with anchor' => array("a: [{b: &x 1}]", true),
            'text without ampersand or asterisk short-circuits' => array("plain: text", false),
            'flow close bracket at a node position' => array("[ ]\nkey: *x", true),
            'flow comma at a node position' => array("[ , ]\nkey: *x", true),
        );
    }

    #[DataProvider('tokenizerEdgeProvider')]
    public function testTokenizerEdges(string $yaml, bool $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::yamlUsesAnchorsOrAliases($yaml));
    }

    // ------------------------------------------------------------------
    // normalizeSecurityPolicy
    // ------------------------------------------------------------------

    public static function securityPolicyProvider(): array
    {
        return array(
            'null config' => array(null, 'public'),
            'empty config' => array(array(), 'public'),
            'non-array requirement skipped' => array(array('bogus'), 'public'),
            'empty requirement is public' => array(array(array()), 'public'),
            'site token with bearer' => array(array(array('siteTokenHeader' => array(), 'bearerAuth' => array())), 'authenticated-site'),
            'site token only' => array(array(array('siteTokenHeader' => array())), 'site-token-only'),
            'user token' => array(array(array('userTokenHeader' => array())), 'authenticated-user'),
            'bearer only' => array(array(array('bearerAuth' => array())), 'authenticated'),
            'unrecognized scheme' => array(array(array('oauth' => array())), 'public'),
            'user token wins across requirements' => array(array(array('userTokenHeader' => array()), array('bearerAuth' => array())), 'authenticated-user'),
        );
    }

    #[DataProvider('securityPolicyProvider')]
    public function testNormalizeSecurityPolicy($config, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::normalizeSecurityPolicy($config));
    }

    // ------------------------------------------------------------------
    // OpenAPI path converters
    // ------------------------------------------------------------------

    public static function siteRouteConverterProvider(): array
    {
        return array(
            'with params' => array('/x/api/v1/items/{idOrSlug}/export/{format}', 'v1/items/:idOrSlug/export/:format'),
            'bare prefix' => array('/x/api', ''),
            'trailing slash' => array('/x/api/', ''),
            'not a site api path' => array('/system/api/v1/x', ''),
        );
    }

    #[DataProvider('siteRouteConverterProvider')]
    public function testConvertOpenApiPathToSiteRoute(string $input, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::convertOpenApiPathToSiteRoute($input));
    }

    public static function systemRouteConverterProvider(): array
    {
        return array(
            'with params' => array('/system/api/v1/sites/{siteName}/clone', 'v1/sites/:siteName/clone'),
            'bare v1' => array('/system/api/v1', 'v1'),
            'not a system api path' => array('/x/api/v1/items', ''),
        );
    }

    #[DataProvider('systemRouteConverterProvider')]
    public function testConvertOpenApiPathToSystemRoute(string $input, string $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::convertOpenApiPathToSystemRoute($input));
    }

    // ------------------------------------------------------------------
    // Spec-driven auth policies
    // ------------------------------------------------------------------

    public function testReadSiteApiAuthPoliciesFromRealSpec(): void
    {
        $policies = SiteRouteUtils::readSiteApiAuthPoliciesFromOpenApiSpec();
        $this->assertIsArray($policies);
        $this->assertGreaterThan(0, count($policies));
        // Public read endpoint declared in site-spec.yaml.
        $this->assertSame('public', $policies['get:v1/items']);
        // Mutation endpoints carry an authenticated-site policy.
        $this->assertSame('authenticated-site', $policies['patch:v1/site']);
        $this->assertSame('authenticated-site', $policies['post:v1/site/updateAlternativeFormats']);
    }

    public function testReadSystemApiAuthPoliciesFromRealSpec(): void
    {
        $policies = SiteRouteUtils::readSystemApiAuthPoliciesFromOpenApiSpec();
        $this->assertIsArray($policies);
        $this->assertGreaterThan(0, count($policies));
        $this->assertSame('public', $policies['get:v1']);
        $this->assertSame('public', $policies['post:v1/session/login']);
    }

    public function testGetSiteApiRouteAuthPolicyFailsClosed(): void
    {
        $this->assertSame('public', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/items', 'GET'));
        // POST /v1/items (create) is a declared mutation: authenticated-site.
        $this->assertSame('authenticated-site', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/items', 'POST'));
        // Route entirely absent from the spec: fails closed.
        $this->assertSame('authenticated', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/undeclared', 'GET'));
        // Method case is normalized on lookup.
        $this->assertSame('authenticated-site', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/site', 'PATCH'));
    }

    public function testGetSystemApiRouteAuthPolicyFailsClosed(): void
    {
        $this->assertSame('public', SiteRouteUtils::getSystemApiRouteAuthPolicy('v1/session/refresh', 'GET'));
        $this->assertSame('authenticated', SiteRouteUtils::getSystemApiRouteAuthPolicy('v1/undeclared', 'GET'));
    }

    public function testAssertSiteApiMutationRoutesAreSecuredFailsClosedQuietly(): void
    {
        // GET methods are skipped; non-array route maps are skipped; unknown
        // POST routes fail closed to 'authenticated' (never 'public'), so no
        // offender is reported.
        $routesMap = array(
            'GET' => array('v1/items' => array()),
            'POST' => array('v1/undeclared-route' => array()),
            'PATCH' => 'not-an-array',
        );
        $this->assertSame(array(), SiteRouteUtils::assertSiteApiMutationRoutesAreSecured($routesMap));
        // Null (or any non-array) registry is treated as empty.
        $this->assertSame(array(), SiteRouteUtils::assertSiteApiMutationRoutesAreSecured(null));
    }

    // ------------------------------------------------------------------
    // buildHaxElementSchemaFromHtml (real parse path)
    // ------------------------------------------------------------------

    public function testBuildHaxElementSchemaFromHtmlParsesElements(): void
    {
        $schema = SiteRouteUtils::buildHaxElementSchemaFromHtml('<x-foo data-foo="v" bool><span>hi</span></x-foo><p>plain</p>');
        $this->assertCount(2, $schema);
        $this->assertSame('x-foo', $schema[0]['tag']);
        $this->assertSame('v', $schema[0]['properties']['data-foo']);
        // libxml gives valueless attributes an empty string value (not null),
        // so the property is present with '' rather than true.
        $this->assertArrayHasKey('bool', $schema[0]['properties']);
        $this->assertSame('<span>hi</span>', $schema[0]['content']);
        $this->assertSame('p', $schema[1]['tag']);
        $this->assertSame(array(), $schema[1]['properties']);
        $this->assertSame('plain', $schema[1]['content']);
        // Text nodes at the fragment root are skipped, not schema entries.
        $this->assertSame(array(), SiteRouteUtils::buildHaxElementSchemaFromHtml('just text, no elements'));
    }

    public function testBuildHaxElementSchemaFromHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame(array(), SiteRouteUtils::buildHaxElementSchemaFromHtml(''));
        $this->assertSame(array(), SiteRouteUtils::buildHaxElementSchemaFromHtml('   '));
    }

    // ------------------------------------------------------------------
    // Search field / lookup value / findMatch seams
    // ------------------------------------------------------------------

    public function testGetSearchFieldValueSlugAndLocationFields(): void
    {
        $item = makeSiteRouteItem('id-1', 'my-slug', 'Title', '', 0, 0, 'pages/id-1/index.html');
        $this->assertSame('my-slug', SiteRouteUtils::getSearchFieldValue('slug', $item));
        $this->assertSame('pages/id-1/index.html', SiteRouteUtils::getSearchFieldValue('location', $item));
        $this->assertSame('', SiteRouteUtils::getSearchFieldValue('unknown-field', $item));
    }

    public function testGetItemLookupValuePrefersSlugThenId(): void
    {
        $item = makeSiteRouteItem('id-1', 'my-slug', 'Title');
        $this->assertSame('my-slug', SiteRouteUtils::getItemLookupValue($item));
        $idOnly = new stdClass();
        $idOnly->id = 'just-id';
        $this->assertSame('just-id', SiteRouteUtils::getItemLookupValue($idOnly));
        $this->assertSame('', SiteRouteUtils::getItemLookupValue(null));
        $this->assertSame('', SiteRouteUtils::getItemLookupValue(new stdClass()));
    }

    public function testFindMatchReturnsNullForEmptySourceAndMisses(): void
    {
        $this->assertNull(SiteRouteUtils::findMatch('', 'needle'));
        $this->assertNull(SiteRouteUtils::findMatch('abc', 'zzz'));
    }

    public function testFindMatchReturnsIndexLengthAndSnippet(): void
    {
        $match = SiteRouteUtils::findMatch('hello world needle tail', 'needle');
        $this->assertSame(12, $match['index']);
        $this->assertSame(6, $match['length']);
        // Snippet window is +/-60 chars, clamped to the string bounds.
        $this->assertSame('hello world needle tail', $match['snippet']);
    }

    // ------------------------------------------------------------------
    // itemToSummary
    // ------------------------------------------------------------------

    public function testItemToSummaryBuildsFullRecord(): void
    {
        $item = makeSiteRouteItem(
            'id-1',
            'slug-one',
            'Title One',
            'parent-1',
            2,
            1,
            'pages/id-1/index.html',
            'Description',
            array('tags' => array('A', 'B'), 'region' => 'header', 'published' => true)
        );
        $summary = SiteRouteUtils::itemToSummary($item, '/x/api');
        $this->assertSame('id-1', $summary['id']);
        $this->assertSame('Title One', $summary['title']);
        $this->assertSame('slug-one', $summary['slug']);
        $this->assertSame('parent-1', $summary['parent']);
        $this->assertSame(1, $summary['indent']);
        $this->assertSame(2, $summary['order']);
        $this->assertSame('pages/id-1/index.html', $summary['location']);
        $this->assertSame('Description', $summary['description']);
        $this->assertSame('header', $summary['region']);
        $this->assertSame(array('A', 'B'), $summary['tags']);
        $this->assertTrue($summary['published']);
        $this->assertSame('/x/api/v1/items/slug-one', $summary['links']['self']);
        $this->assertSame('/x/api/v1/content/slug-one', $summary['links']['content']);
        $this->assertSame('/x/api/v1/items/parent-1', $summary['links']['parent']);
        $this->assertSame('/x/api/v1/items?filter.parent=id-1', $summary['links']['children']);
        $this->assertCount(3, $summary['related']);
        $this->assertSame('/x/api/v1/entities#item', $summary['related'][0]['href']);
        $this->assertSame('/x/api/v1/schemas?filter.kind=jsonOutlineSchema', $summary['related'][1]['href']);
        $this->assertSame('/x/api/v1/schemas?filter.kind=jsonOutlineSchemaItem', $summary['related'][2]['href']);
    }

    public function testItemToSummaryDefaultsForBareItem(): void
    {
        // A bare object with no metadata/parent/id properties at all.
        $summary = SiteRouteUtils::itemToSummary(new stdClass());
        $this->assertNull($summary['id']);
        $this->assertSame('', $summary['slug']);
        $this->assertNull($summary['parent']);
        $this->assertSame(0, $summary['indent']);
        $this->assertSame(0, $summary['order']);
        $this->assertSame('', $summary['location']);
        $this->assertSame(array(), $summary['metadata']);
        $this->assertNull($summary['region']);
        $this->assertSame(array(), $summary['tags']);
        $this->assertTrue($summary['published']);
        $this->assertNull($summary['links']['parent']);
        $this->assertNull($summary['links']['children']);
        $this->assertSame('/x/api/v1/items/', $summary['links']['self']);
    }

    public function testContentToRecord(): void
    {
        $item = makeSiteRouteItem('id-1', 'my-slug', 'Title');
        $this->assertSame(array(
            'id' => 'id-1',
            'slug' => 'my-slug',
            'title' => 'Title',
            'format' => 'html',
            'mode' => 'bundle',
            'body' => '<p>Body</p>',
        ), SiteRouteUtils::contentToRecord($item, '<p>Body</p>'));
        // Non-string body is normalized to an empty string.
        $this->assertSame('', SiteRouteUtils::contentToRecord($item, 42)['body']);
    }

    public function testItemVisibilityHelpersDefaultWithoutMetadata(): void
    {
        $bare = new stdClass();
        $this->assertTrue(SiteRouteUtils::isItemPublished($bare));
        $this->assertFalse(SiteRouteUtils::isItemHiddenFromMenu($bare));
        $this->assertTrue(SiteRouteUtils::isItemVisibleToAnonymous($bare));
        $this->assertTrue(SiteRouteUtils::isItemPublished(null));
    }

    // ------------------------------------------------------------------
    // isAnonymousSiteApiRequest
    // ------------------------------------------------------------------

    public function testIsAnonymousSiteApiRequestReadsAuthContext(): void
    {
        $authenticated = new stdClass();
        $authenticated->auth = array('authenticated' => true);
        $this->assertFalse(SiteRouteUtils::isAnonymousSiteApiRequest($authenticated));

        $anonymous = new stdClass();
        $anonymous->auth = array('authenticated' => false);
        $this->assertTrue(SiteRouteUtils::isAnonymousSiteApiRequest($anonymous));

        // auth array without the authenticated key falls back to token sniffing.
        $partial = new stdClass();
        $partial->auth = array('userName' => 'x');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok';
        $this->assertFalse(SiteRouteUtils::isAnonymousSiteApiRequest($partial));
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertTrue(SiteRouteUtils::isAnonymousSiteApiRequest($partial));
    }

    // ------------------------------------------------------------------
    // filterOpenApiToPublicOperations
    // ------------------------------------------------------------------

    public function testFilterOpenApiToPublicOperationsReturnsInputWithoutPaths(): void
    {
        $this->assertSame('not-an-array', SiteRouteUtils::filterOpenApiToPublicOperations('not-an-array'));
        $noPaths = array('info' => array('title' => 'T'));
        $this->assertSame($noPaths, SiteRouteUtils::filterOpenApiToPublicOperations($noPaths));
    }

    public function testFilterOpenApiToPublicOperationsKeepsOnlyAnonymousOperations(): void
    {
        $doc = array(
            'openapi' => '3.0.0',
            'info' => array('title' => 'Site API', 'description' => 'Original.'),
            'security' => array(array('bearerAuth' => array())),
            'paths' => array(
                '/public' => array(
                    'get' => array('security' => array(), 'summary' => 'pub'),
                    'post' => array('summary' => 'inherited private'),
                ),
                '/private' => array('get' => array('summary' => 'priv')),
                '/paramonly' => array('parameters' => array(array('name' => 'x'))),
                '/boguspath' => 'not-an-array',
            ),
        );
        $out = SiteRouteUtils::filterOpenApiToPublicOperations($doc);
        $this->assertSame(array('/public'), array_keys($out['paths']));
        // The private POST is dropped but the public GET is kept intact.
        $this->assertSame(array('get'), array_keys($out['paths']['/public']));
        $this->assertSame('pub', $out['paths']['/public']['get']['summary']);
        // Profile annotation + description note appended to the original.
        $this->assertSame('public', $out['x-haxcms-profile']);
        $this->assertStringStartsWith("Original.\n\n", $out['info']['description']);
        $this->assertStringContainsString('public profile', $out['info']['description']);
    }

    public function testFilterOpenApiToPublicOperationsCreatesMissingInfo(): void
    {
        $out = SiteRouteUtils::filterOpenApiToPublicOperations(array('paths' => array()));
        $this->assertSame(array(), $out['paths']);
        $this->assertSame('public', $out['x-haxcms-profile']);
        $this->assertStringStartsWith('This is the public profile:', $out['info']['description']);
    }

    // ------------------------------------------------------------------
    // applyItemFilters
    // ------------------------------------------------------------------

    public function testApplyItemFiltersRemovesInvisibleItemsForAnonymousRequests(): void
    {
        $site = new SiteRoutesFakeSite();
        $visible = makeSiteRouteItem('v', 'v', 'Visible', '', 0, 0, '', '', array());
        $unpublished = makeSiteRouteItem('u', 'u', 'Unpublished', '', 0, 0, '', '', array('published' => false));
        $hidden = makeSiteRouteItem('h', 'h', 'Hidden', '', 0, 0, '', '', array('hideInMenu' => true));
        $context = makeSiteRouteContext($site, array(), '', '/x/api', false);
        $out = SiteRouteUtils::applyItemFilters(array($visible, $unpublished, $hidden), $site, $context);
        $this->assertSame(array('v', 'v'), array($out[0]->id, $out[0]->slug));
        $this->assertCount(1, $out);
        $this->assertSame('v', $out[0]->id);
    }

    public function testApplyItemFiltersKeepsInvisibleItemsForAuthenticatedRequests(): void
    {
        $site = new SiteRoutesFakeSite();
        $unpublished = makeSiteRouteItem('u', 'u', 'Unpublished', '', 0, 0, '', '', array('published' => false));
        $context = makeSiteRouteContext($site, array(), '', '/x/api', true);
        $out = SiteRouteUtils::applyItemFilters(array($unpublished), $site, $context);
        $this->assertCount(1, $out);
        $this->assertSame('u', $out[0]->id);
    }

    public function testApplyItemFiltersAncestorFilterUsesFindBranch(): void
    {
        $site = new SiteRoutesFakeSite();
        $root = makeSiteRouteItem('root', 'root', 'Root');
        $child = makeSiteRouteItem('c1', 'c1', 'Child', 'root');
        $other = makeSiteRouteItem('o2', 'o2', 'Other', 'root');
        $site->manifest->items = array($root, $child, $other);
        $_GET = array('filter.ancestor' => 'root');
        // 'standalone' is not in the manifest branch rooted at 'root'.
        $standalone = makeSiteRouteItem('standalone', 'standalone', 'Standalone');
        $out = SiteRouteUtils::applyItemFilters(array($child, $standalone), $site, null);
        $this->assertCount(1, $out);
        $this->assertSame('c1', $out[0]->id);
    }

    public function testApplyItemFiltersSurvivesThrowingFindBranch(): void
    {
        $site = (object) array('manifest' => new SiteRouteUtilsHelpersThrowingBranchManifest());
        $_GET = array('filter.ancestor' => 'root');
        $item = makeSiteRouteItem('a', 'a', 'A');
        $out = SiteRouteUtils::applyItemFilters(array($item), $site, null);
        $this->assertCount(1, $out);
        $this->assertSame('a', $out[0]->id);
    }

    public function testApplyItemFiltersParentFilter(): void
    {
        $_GET = array('filter.parent' => 'p1');
        $kept = makeSiteRouteItem('a', 'a', 'A', 'p1');
        $dropped = makeSiteRouteItem('b', 'b', 'B', 'p2');
        $noParent = makeSiteRouteItem('c', 'c', 'C');
        $out = SiteRouteUtils::applyItemFilters(array($kept, $dropped, $noParent), null, null);
        $this->assertSame(array('a'), array_column($out, 'id'));
    }

    public function testApplyItemFiltersDepthFilter(): void
    {
        $site = new SiteRoutesFakeSite();
        $root = makeSiteRouteItem('root', 'root', 'Root', '', 0, 0);
        $child = makeSiteRouteItem('c1', 'c1', 'Child', 'root', 1, 1);
        $grand = makeSiteRouteItem('g1', 'g1', 'Grand', 'c1', 2, 2);
        $site->manifest->items = array($root, $child, $grand);
        $_GET = array('filter.ancestor' => 'root', 'filter.depth' => '1');
        $out = SiteRouteUtils::applyItemFilters(array($root, $child, $grand), $site, null);
        $this->assertSame(array('root', 'c1'), array_column($out, 'id'));
    }

    public function testApplyItemFiltersTagFilterIsCaseInsensitive(): void
    {
        $_GET = array('filter.tags' => 'Alpha,Beta');
        $alpha = makeSiteRouteItem('a', 'a', 'A', '', 0, 0, '', '', array('tags' => array('alpha')));
        $gamma = makeSiteRouteItem('g', 'g', 'G', '', 0, 0, '', '', array('tags' => array('gamma')));
        $none = makeSiteRouteItem('n', 'n', 'N');
        $out = SiteRouteUtils::applyItemFilters(array($alpha, $gamma, $none), null, null);
        $this->assertSame(array('a'), array_column($out, 'id'));
    }

    public function testApplyItemFiltersPublishedFilter(): void
    {
        $_GET = array('filter.published' => 'false');
        $published = makeSiteRouteItem('p', 'p', 'P');
        $unpublished = makeSiteRouteItem('u', 'u', 'U', '', 0, 0, '', '', array('published' => false));
        $out = SiteRouteUtils::applyItemFilters(array($published, $unpublished), null, null);
        $this->assertSame(array('u'), array_column($out, 'id'));
    }

    public function testApplyItemFiltersPageTypeFilter(): void
    {
        $_GET = array('filter.pageType' => 'landing');
        $landing = makeSiteRouteItem('l', 'l', 'L', '', 0, 0, '', '', array('pageType' => 'landing'));
        $other = makeSiteRouteItem('o', 'o', 'O', '', 0, 0, '', '', array('pageType' => 'other'));
        $none = makeSiteRouteItem('n', 'n', 'N');
        $out = SiteRouteUtils::applyItemFilters(array($landing, $other, $none), null, null);
        $this->assertSame(array('l'), array_column($out, 'id'));
    }

    public function testApplyItemFiltersRegionFilter(): void
    {
        $_GET = array('filter.region' => 'header');
        $header = makeSiteRouteItem('h', 'h', 'H', '', 0, 0, '', '', array('region' => 'header'));
        $footer = makeSiteRouteItem('f', 'f', 'F', '', 0, 0, '', '', array('region' => 'footer'));
        $none = makeSiteRouteItem('n', 'n', 'N');
        $out = SiteRouteUtils::applyItemFilters(array($header, $footer, $none), null, null);
        $this->assertSame(array('h'), array_column($out, 'id'));
    }

    // ------------------------------------------------------------------
    // collectSiteFiles
    // ------------------------------------------------------------------

    private static function rrmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        $entries = is_array($entries) ? $entries : array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::rrmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testCollectSiteFilesReturnsEmptyForMissingDirectory(): void
    {
        $this->assertSame(array(), SiteRouteUtils::collectSiteFiles((object) array(), '/nonexistent-dir-xyz'));
    }

    public function testCollectSiteFilesWalksAndFilters(): void
    {
        $base = sys_get_temp_dir() . '/sru-collect-' . uniqid();
        mkdir($base . '/haxcms-managed', 0777, true);
        mkdir($base . '/sub', 0777, true);
        file_put_contents($base . '/a.md', 'a');
        file_put_contents($base . '/.gitkeep', '');
        file_put_contents($base . '/sub/b.md', 'b');
        file_put_contents($base . '/haxcms-managed/secret.txt', 's');
        symlink($base . '/a.md', $base . '/link.md');
        try {
            $files = SiteRouteUtils::collectSiteFiles((object) array(), $base);
            $this->assertSame(
                array('a.md', 'sub/b.md'),
                array_column($files, 'relativePath')
            );
            $this->assertSame($base . '/a.md', $files[0]['absolutePath']);
            $this->assertIsArray($files[0]['stats']);
            // Search matches case-insensitively against path and filename.
            $searched = SiteRouteUtils::collectSiteFiles((object) array(), $base, 'B.MD');
            $this->assertSame(array('sub/b.md'), array_column($searched, 'relativePath'));
        } finally {
            self::rrmdirRecursive($base);
        }
    }

    public function testCollectSiteFilesHandlesUnreadableDirectoryAndManagedFile(): void
    {
        $base = sys_get_temp_dir() . '/sru-collect2-' . uniqid();
        mkdir($base, 0777, true);
        mkdir($base . '/noperm', 0000);
        file_put_contents($base . '/haxcms-managed', 'x');
        file_put_contents($base . '/ok.txt', 'ok');
        try {
            $files = SiteRouteUtils::collectSiteFiles((object) array(), $base);
            // The unreadable directory contributes no entries and a FILE named
            // 'haxcms-managed' is filtered out by name.
            $this->assertSame(array('ok.txt'), array_column($files, 'relativePath'));
        } finally {
            chmod($base . '/noperm', 0700);
            self::rrmdirRecursive($base);
        }
    }

    // ------------------------------------------------------------------
    // Custom element usage collection
    // ------------------------------------------------------------------

    public function testExtractCustomElementTagsFromHtmlCountsAndLowercases(): void
    {
        $this->assertSame(array(), SiteRouteUtils::extractCustomElementTagsFromHtml(''));
        $usage = SiteRouteUtils::extractCustomElementTagsFromHtml(
            '<X-foo></x-FOO><x-foo><x-bar/></x-foo><p>hi</p><x-baz attr="1">'
        );
        $this->assertSame(array('x-foo' => 2, 'x-bar' => 1, 'x-baz' => 1), $usage);
    }

    public function testCollectCustomElementUsageAggregatesAcrossItems(): void
    {
        $site = new SiteRoutesFakeSite();
        $site->pageContentMap = array(
            'id1' => '<x-foo><x-bar></x-bar></x-foo>',
            'id2' => '<x-foo>',
        );
        $items = array(
            makeSiteRouteItem('id1', 'one', 'One'),
            makeSiteRouteItem('id2', 'two', 'Two'),
        );
        $usage = SiteRouteUtils::collectCustomElementUsage($site, $items);
        $this->assertSame(array('x-foo' => 2, 'x-bar' => 1), $usage);
    }
}

/**
 * Unique per-file fakes (the full suite runs in one process, so class names
 * must not collide with other test files' fakes).
 */
class SiteRouteUtilsHelpersFakeHaxcms
{
    public function cleanTitle($value)
    {
        return 'haxcms-cleaned';
    }

    public function getHAXCMSVersion()
    {
        return '9.9.9-test';
    }
}

class SiteRouteUtilsHelpersThrowingOrderManifest
{
    public $items;

    public function __construct()
    {
        $item = new stdClass();
        $item->id = 'thrown-order-marker';
        $this->items = array($item);
    }

    public function orderTree($items)
    {
        throw new RuntimeException('orderTree exploded');
    }
}

class SiteRouteUtilsHelpersPropertyManifest
{
    public $items = array();
    private $wanted;

    public function __construct()
    {
        $item = new stdClass();
        $item->slug = 'wanted-slug';
        $item->id = 'wanted-id';
        $this->wanted = $item;
    }

    public function getItemById($id)
    {
        return false;
    }

    public function getItemByProperty($property, $value)
    {
        if ($property === 'slug' && $value === 'wanted-slug') {
            return $this->wanted;
        }
        return false;
    }
}

class SiteRouteUtilsHelpersThrowingBranchManifest
{
    public $items = array();

    public function findBranch($ancestorId)
    {
        throw new RuntimeException('findBranch exploded');
    }
}

class SiteRouteUtilsHelpersDirectContentSite
{
    public function getPageContent($page)
    {
        return 'direct-content';
    }
}

class SiteRouteUtilsHelpersNonStringContentSite
{
    public function getPageContent($page)
    {
        return 42;
    }
}

class SiteRouteUtilsHelpersNullLoadNodeSite
{
    public function loadNode($id)
    {
        return null;
    }

    public function getPageContent($page)
    {
        return 'fallback-content';
    }
}
