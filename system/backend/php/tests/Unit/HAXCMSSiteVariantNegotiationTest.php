<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for HAXCMSSite's alternate-page-format serving and
 * negotiation seam, lib/HAXCMSSite.php: respondWithRequestedPageVariant,
 * sendPageAlternateHeaders, getManifestItemBySlug, getCanonicalPagePathForSlug,
 * getAlternateFilePathForPageFormat, and the private request-parsing helpers
 * (getRequestedVariantInfo, getNegotiatedVariantFormat,
 * getRequestedVariantContentType, getPageAlternateLinkTags) exercised via
 * reflection where there is no public seam.
 *
 * A real temp-site fixture provides sidecar files (pages/<id>/index.md,
 * .json, .yaml, .xml) directly on disk -- getAlternateFilePathForPageFormat
 * only checks file_exists, so content correctness of those sidecar files is
 * covered separately by HAXCMSSiteAlternateFormatsTest (writePageAlternateFormats).
 *
 * header()/http_response_code() calls in respondWithRequestedPageVariant are
 * observable here because PHPUnit's own output buffering (ob_start per test)
 * keeps "headers" from actually being "sent" under the CLI SAPI, so
 * header()/http_response_code() behave normally instead of warning.
 */
class HAXCMSSiteVariantNegotiationTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $savedServer;
    private $tmpRoot;
    private $siteName = 'variantsite';
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->savedServer = $_SERVER;
        // SCRIPT_NAME defaults to the phpunit binary's own invocation path
        // (e.g. vendor/bin/phpunit) under the CLI SAPI; clear it so
        // getCanonicalPagePathForSlug's script-directory detection starts
        // from a clean slate for each test (tests that need a specific
        // SCRIPT_NAME set it explicitly).
        unset($_SERVER['SCRIPT_NAME']);
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_variant_' . uniqid();
        $this->buildSiteFixture($this->siteName);
        $this->haxcms = new HAXCMSVariantTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = new HAXCMSVariantTestSite();
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
        file_put_contents($dir . '/pages/item-page-1/index.html', '<p>Home content</p>');
        file_put_contents($dir . '/pages/item-page-1/index.md', '# Home content');
        file_put_contents($dir . '/pages/item-page-1/index.json', '{"title":"Home"}');
        $manifest = (object) array(
            'id' => 'site-variant-uuid',
            'title' => 'Variant Test Site',
            'author' => 'Tester',
            'description' => 'A test site for variant negotiation',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array('name' => $name),
            ),
            'items' => array(
                (object) array(
                    'id' => 'item-page-1', 'title' => 'Home', 'slug' => 'home',
                    'location' => 'pages/item-page-1/index.html', 'order' => 0,
                    'parent' => null, 'indent' => 0, 'description' => 'Home page',
                    'metadata' => (object) array('created' => 1000, 'updated' => 2000),
                ),
            ),
        );
        file_put_contents($dir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod(HAXCMSSite::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->site, $args);
    }

    // =========================================================================
    // getManifestItemBySlug
    // =========================================================================

    public function testGetManifestItemBySlugFindsExistingItem(): void
    {
        $item = $this->invokePrivate('getManifestItemBySlug', ['home']);
        $this->assertNotNull($item);
        $this->assertSame('item-page-1', $item->id);
    }

    public function testGetManifestItemBySlugReturnsNullForUnknownSlug(): void
    {
        $this->assertNull($this->invokePrivate('getManifestItemBySlug', ['does-not-exist']));
    }

    public function testGetManifestItemBySlugReturnsNullForEmptySlug(): void
    {
        $this->assertNull($this->invokePrivate('getManifestItemBySlug', ['']));
    }

    // =========================================================================
    // getCanonicalPagePathForSlug
    // =========================================================================

    public function testGetCanonicalPagePathForSlugNoScriptDirectory(): void
    {
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertSame('/home', $this->invokePrivate('getCanonicalPagePathForSlug', ['home']));
    }

    public function testGetCanonicalPagePathForSlugEmptySlugNoScriptDirectoryReturnsRoot(): void
    {
        unset($_SERVER['SCRIPT_NAME']);
        $this->assertSame('/', $this->invokePrivate('getCanonicalPagePathForSlug', ['']));
    }

    public function testGetCanonicalPagePathForSlugWithScriptDirectory(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/sites/variantsite/index.php';
        $this->assertSame('/sites/variantsite/home', $this->invokePrivate('getCanonicalPagePathForSlug', ['home']));
    }

    public function testGetCanonicalPagePathForSlugEmptySlugWithScriptDirectoryReturnsScriptDirectory(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/sites/variantsite/index.php';
        $this->assertSame('/sites/variantsite', $this->invokePrivate('getCanonicalPagePathForSlug', ['']));
    }

    // =========================================================================
    // getRequestedVariantInfo (private, via reflection)
    // =========================================================================

    public static function requestedVariantInfoProvider(): array
    {
        return [
            'html extension' => ['/home.html', 'html', '/home'],
            'md extension' => ['/home.md', 'md', '/home'],
            'json extension' => ['/home.json', 'json', '/home'],
            'yaml extension' => ['/home.yaml', 'yaml', '/home'],
            'yml normalizes to yaml' => ['/home.yml', 'yaml', '/home'],
            'xml extension' => ['/home.xml', 'xml', '/home'],
            'uppercase extension lowercased' => ['/home.JSON', 'json', '/home'],
            'no extension' => ['/home', null, '/home'],
            'unrelated extension left alone' => ['/home.php', null, '/home.php'],
        ];
    }

    #[DataProvider('requestedVariantInfoProvider')]
    public function testGetRequestedVariantInfo(string $path, $expectedFormat, string $expectedBasePath): void
    {
        $info = $this->invokePrivate('getRequestedVariantInfo', [$path]);
        $this->assertSame($expectedFormat, $info['format']);
        $this->assertSame($expectedBasePath, $info['basePath']);
    }

    // =========================================================================
    // getRequestedVariantContentType (private, via reflection)
    // =========================================================================

    public static function variantContentTypeProvider(): array
    {
        return [
            'html' => ['html', 'text/html; charset=utf-8'],
            'md' => ['md', 'text/markdown; charset=utf-8'],
            'json' => ['json', 'application/json; charset=utf-8'],
            'yaml' => ['yaml', 'application/yaml; charset=utf-8'],
            'xml' => ['xml', 'application/xml; charset=utf-8'],
            'unknown falls back to text/plain' => ['bogus', 'text/plain; charset=utf-8'],
        ];
    }

    #[DataProvider('variantContentTypeProvider')]
    public function testGetRequestedVariantContentType(string $format, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('getRequestedVariantContentType', [$format]));
    }

    // =========================================================================
    // getNegotiatedVariantFormat (private, via reflection)
    // =========================================================================

    public static function negotiatedVariantFormatProvider(): array
    {
        return [
            'no accept header -> null' => [null, null],
            'text/html present -> null (prefer html)' => ['text/html', null],
            'application/xhtml+xml present -> null' => ['application/xhtml+xml', null],
            'text/markdown -> md' => ['text/markdown', 'md'],
            'application/yaml -> yaml' => ['application/yaml', 'yaml'],
            'application/x-yaml -> yaml' => ['application/x-yaml', 'yaml'],
            'text/yaml -> yaml' => ['text/yaml', 'yaml'],
            'application/xml -> xml' => ['application/xml', 'xml'],
            'text/xml -> xml' => ['text/xml', 'xml'],
            'application/json alone -> json' => ['application/json', 'json'],
            'application/json with text/html -> null' => ['application/json, text/html', null],
            'unrecognized -> null' => ['application/octet-stream', null],
        ];
    }

    #[DataProvider('negotiatedVariantFormatProvider')]
    public function testGetNegotiatedVariantFormat($acceptHeader, $expected): void
    {
        if ($acceptHeader === null) {
            unset($_SERVER['HTTP_ACCEPT']);
        } else {
            $_SERVER['HTTP_ACCEPT'] = $acceptHeader;
        }
        $this->assertSame($expected, $this->invokePrivate('getNegotiatedVariantFormat'));
    }

    // =========================================================================
    // getAlternateFilePathForPageFormat (private, via reflection)
    // =========================================================================

    public function testGetAlternateFilePathForPageFormatReturnsPathWhenFileExists(): void
    {
        $page = $this->site->loadNode('item-page-1');
        $path = $this->invokePrivate('getAlternateFilePathForPageFormat', [$page, 'md']);
        $this->assertSame($this->siteDir() . '/pages/item-page-1/index.md', $path);
    }

    public function testGetAlternateFilePathForPageFormatReturnsNullWhenFileMissing(): void
    {
        $page = $this->site->loadNode('item-page-1');
        $this->assertNull($this->invokePrivate('getAlternateFilePathForPageFormat', [$page, 'xml']));
    }

    public function testGetAlternateFilePathForPageFormatReturnsNullForPageWithNoLocation(): void
    {
        $page = new JSONOutlineSchemaItem();
        $this->assertNull($this->invokePrivate('getAlternateFilePathForPageFormat', [$page, 'md']));
    }

    public function testGetAlternateFilePathForPageFormatReturnsNullForNullPage(): void
    {
        $this->assertNull($this->invokePrivate('getAlternateFilePathForPageFormat', [null, 'md']));
    }

    // =========================================================================
    // respondWithRequestedPageVariant — public entrypoint
    // =========================================================================

    private function callRespond(): array
    {
        ob_start();
        $handled = $this->site->respondWithRequestedPageVariant();
        $body = ob_get_clean();
        return array($handled, $body);
    }

    public function testRespondReturnsFalseForInternalApiPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/x/search';
        unset($_SERVER['SCRIPT_NAME']);
        list($handled, $body) = $this->callRespond();
        $this->assertFalse($handled);
        $this->assertSame('', $body);
    }

    public function testRespondReturnsFalseForRootPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['SCRIPT_NAME']);
        list($handled, $body) = $this->callRespond();
        $this->assertFalse($handled);
    }

    public function testRespondReturns404ForExplicitFormatOnUnknownSlug(): void
    {
        $_SERVER['REQUEST_URI'] = '/no-such-page.json';
        unset($_SERVER['SCRIPT_NAME']);
        list($handled, $body) = $this->callRespond();
        $this->assertTrue($handled);
        $this->assertSame(404, http_response_code());
        $this->assertSame('Not found', $body);
    }

    public function testRespondReturnsFalseForUnknownSlugWithoutExplicitFormat(): void
    {
        $_SERVER['REQUEST_URI'] = '/no-such-page';
        unset($_SERVER['SCRIPT_NAME']);
        list($handled, $body) = $this->callRespond();
        $this->assertFalse($handled);
    }

    public function testRespondServesExplicitMdFormatForKnownSlug(): void
    {
        $_SERVER['REQUEST_URI'] = '/home.md';
        unset($_SERVER['SCRIPT_NAME']);
        list($handled, $body) = $this->callRespond();
        $this->assertTrue($handled);
        $this->assertSame('# Home content', $body);
    }

    public function testRespondReturns404ForExplicitFormatWithNoSidecarFile(): void
    {
        // 'home' exists, but no index.xml sidecar was created in the fixture.
        $_SERVER['REQUEST_URI'] = '/home.xml';
        unset($_SERVER['SCRIPT_NAME']);
        list($handled, $body) = $this->callRespond();
        $this->assertTrue($handled);
        $this->assertSame(404, http_response_code());
        $this->assertSame('Not found', $body);
    }

    public function testRespondServesNegotiatedFormatViaAcceptHeader(): void
    {
        $_SERVER['REQUEST_URI'] = '/home';
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        list($handled, $body) = $this->callRespond();
        $this->assertTrue($handled);
        $this->assertSame('{"title":"Home"}', $body);
    }

    public function testRespondReturnsFalseWhenNegotiatedFormatHasNoSidecarFile(): void
    {
        // Accept header negotiates xml, but there is no index.xml sidecar;
        // contract is a silent fall-through to normal HTML rendering (no 404).
        $_SERVER['REQUEST_URI'] = '/home';
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['HTTP_ACCEPT'] = 'application/xml';
        list($handled, $body) = $this->callRespond();
        $this->assertFalse($handled);
        $this->assertSame('', $body);
    }

    public function testRespondPrefersHtmlAcceptOverNegotiation(): void
    {
        $_SERVER['REQUEST_URI'] = '/home';
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/json';
        list($handled, $body) = $this->callRespond();
        $this->assertFalse($handled);
    }

    // =========================================================================
    // sendPageAlternateHeaders / getPageAlternateLinkTags
    // =========================================================================

    public function testSendPageAlternateHeadersNoopsForPageWithoutSlug(): void
    {
        ob_start();
        $this->site->sendPageAlternateHeaders(new stdClass());
        $out = ob_get_clean();
        $this->assertSame('', $out);
    }

    public function testSendPageAlternateHeadersEmitsLinkHeaderForAvailableFormats(): void
    {
        $page = $this->site->loadNode('item-page-1');
        ob_start();
        $this->site->sendPageAlternateHeaders($page);
        ob_end_clean();
        // md and json sidecars exist in the fixture; confirm via the
        // equivalent getPageAlternateLinkTags HTML (same availability check).
        $tags = $this->invokePrivate('getPageAlternateLinkTags', [$page]);
        $this->assertStringContainsString('type="text/markdown"', $tags);
        $this->assertStringContainsString('href="/home.md"', $tags);
        $this->assertStringContainsString('type="application/json"', $tags);
        $this->assertStringNotContainsString('type="application/xml"', $tags);
    }

    public function testGetPageAlternateLinkTagsReturnsEmptyForPageWithoutSlug(): void
    {
        $this->assertSame('', $this->invokePrivate('getPageAlternateLinkTags', [new stdClass()]));
    }

    public function testGetPageAlternateLinkTagsOnlyIncludesHtmlWhenNoOtherSidecarFiles(): void
    {
        // The page's own location (index.html) always satisfies the 'html'
        // format lookup (it maps to itself), so 'html' is always present;
        // only the non-html sidecar formats are conditional on real files.
        mkdir($this->siteDir() . '/pages/item-page-2', 0777, true);
        file_put_contents($this->siteDir() . '/pages/item-page-2/index.html', '<p>no sidecars</p>');
        $bare = new stdClass();
        $bare->slug = 'bare';
        $bare->location = 'pages/item-page-2/index.html';
        $tags = $this->invokePrivate('getPageAlternateLinkTags', [$bare]);
        $this->assertStringContainsString('type="text/html"', $tags);
        $this->assertStringNotContainsString('type="text/markdown"', $tags);
        $this->assertStringNotContainsString('type="application/json"', $tags);
        $this->assertStringNotContainsString('type="application/yaml"', $tags);
        $this->assertStringNotContainsString('type="application/xml"', $tags);
    }
}

/**
 * HAXCMSSite test subclass with no overrides: variant negotiation reads are
 * exercised through the real implementation against the temp fixture.
 */
class HAXCMSVariantTestSite extends HAXCMSSite
{
}

/**
 * Minimal HAXCMS collaborator mock; variant negotiation methods under test
 * do not read any $GLOBALS['HAXCMS'] collaborators directly (only
 * HAXCMSSite::load() needs cleanTitle, already provided by the base mock).
 */
class HAXCMSVariantTestHaxcms extends OperationsTestHaxcms
{
}
