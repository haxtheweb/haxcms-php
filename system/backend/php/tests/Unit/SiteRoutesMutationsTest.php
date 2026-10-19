<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/OperationsNodeOpsTest.php';

/**
 * SiteApiRequestContext subclass with an injected body + headers so route
 * handlers see a controlled getBody()/getHeader(). php://input is empty in
 * the PHPUnit CLI, so the base class would always return array()/null.
 */
class SiteMutationRouteTestContext extends SiteApiRequestContext
{
    public $injectedBody = array();
    public $injectedHeaders = array();

    public function getBody()
    {
        return is_array($this->injectedBody) ? $this->injectedBody : array();
    }

    public function getHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (array_key_exists($key, $this->injectedHeaders)) {
            return $this->injectedHeaders[$key];
        }
        return parent::getHeader($name);
    }
}

/**
 * Recording mock: captures validateRequestToken/validateSiteToken calls so
 * tests can assert the X-HAXCMS-Site-Token header value + 'user:site' scope
 * actually reached the operation layer. Adds getRequestTokenUserName (used by
 * SiteRouteUtils::validateSiteToken) which the base mock does not implement.
 */
class SiteMutationRecordingHaxcms extends OperationsNodeTestHaxcms
{
    public $tokenCalls = array();

    public function validateRequestToken($token, $value)
    {
        $this->tokenCalls[] = array('request', (string) $token, (string) $value);
        return parent::validateRequestToken($token, $value);
    }

    public function validateSiteToken($siteName, $token)
    {
        $this->tokenCalls[] = array('site', (string) $siteName, (string) $token);
        return parent::validateSiteToken($siteName, $token);
    }

    public function getRequestTokenUserName()
    {
        return $this->getActiveUserName();
    }
}

/**
 * Site subclass whose updateAlternateFormats throws, to exercise the
 * alternative-formats route 500 path.
 */
class SiteMutationThrowingFormatSite extends OperationsNodeTestSite
{
    public function updateAlternateFormats($format = null)
    {
        throw new Exception('format generation exploded');
    }
}

/**
 * Tests for the 7 site-scoped v1 mutation route handlers:
 * itemsMutation, contentMutation, siteMutation, exportsMutation,
 * revisionsMutation, siteAlternativeFormatsMutation, filesMutation.
 *
 * Each handler is invoked through invokeSiteRouteHandler() with a
 * SiteMutationRouteTestContext bound to a real temp-site fixture
 * (OperationsNodeTestSite), so the REAL Operations methods
 * (createNode/saveNodeDetails/deleteNode/saveNode/siteSearch/saveFile/
 * fileOperation/restoreNodeRevision/save*Settings) execute against disk and
 * persistence is verified from the written site.json / page files.
 */
class SiteRoutesMutationsTest extends TestCase
{
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'my-site';

    /** @var SiteMutationRecordingHaxcms */
    private $haxcms;

    /** @var OperationsNodeTestSite */
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

        // HAXCMS_ROOT is process-global; the phpunit bootstrap defines it at a
        // temp root with the real boilerplate symlinked under system/.
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_node_root');
        }
        $this->ensureBoilerplate();

        // Several operations (saveNode/createNode writeLocation, fileOperation)
        // resolve writes via HAXCMS_ROOT/<sitesDirectory>/<siteName>/ rather
        // than $site->directory, so the fixture must live under HAXCMS_ROOT
        // and the mock's sitesDirectory must point at the fixture folder
        // (pattern from OperationsNodeOpsTest::setUpBodyFixture).
        $this->tmpRoot = HAXCMS_ROOT . '/site_mutations_' . uniqid();
        $this->buildSiteFixture();

        // Install the recording mock BEFORE loading the site, because
        // HAXCMSSite::load() calls $GLOBALS['HAXCMS']->cleanTitle().
        $this->haxcms = new SiteMutationRecordingHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->sitesDirectory = basename($this->tmpRoot);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new OperationsNodeTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->site = $site;

        $_FILES = array();
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
        $_FILES = array();
        $this->rrmdir($this->tmpRoot);
    }

    // ------------------------------------------------------------------
    // Fixture + helpers (pattern mirrored from OperationsNodeOpsTest)
    // ------------------------------------------------------------------

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
            // is_link BEFORE is_dir to avoid recursing through symlinks.
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

    private function ensureBoilerplate(): void
    {
        // HAXCMS_ROOT/system is normally a symlink to the real system tree
        // (see tests/phpunit-bootstrap.php); recurseCopy then finds the real
        // templates. Only seed a placeholder when that tree is absent so we
        // never write into the repository through the symlink.
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
    }

    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/item-a', 0777, true);
        mkdir($siteDir . '/pages/item-b', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content A</p>');
        file_put_contents($siteDir . '/pages/item-b/index.html', '<p>Content B</p>');

        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => false),
                ),
                'platform' => (object)array(
                    'features' => (object)array(),
                ),
                // Real site.json manifests always carry a theme block;
                // saveManifest writes into theme->variables unguarded.
                'theme' => (object)array(
                    'element' => 'haxcms-demo-theme',
                    'variables' => (object)array(),
                    'regions' => (object)array(),
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
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
                (object)array(
                    'id' => 'item-b',
                    'indent' => 0,
                    'location' => 'pages/item-b/index.html',
                    'slug' => 'item-b',
                    'order' => 1,
                    'parent' => null,
                    'title' => 'Item B',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJsonPath(): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/site.json';
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    /**
     * Read the persisted site.json and return the decoded items as a flat
     * list (handles both array and object encodings).
     */
    private function readPersistedItems(): array
    {
        $raw = file_get_contents($this->siteJsonPath());
        $decoded = json_decode($raw);
        $items = array();
        if (isset($decoded->items)) {
            foreach ($decoded->items as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function readPersistedItem(string $id): ?object
    {
        foreach ($this->readPersistedItems() as $item) {
            if (isset($item->id) && $item->id === $id) {
                return $item;
            }
        }
        return null;
    }

    private function readPersistedManifest(): object
    {
        return json_decode(file_get_contents($this->siteJsonPath()));
    }

    private function makeContext(
        $body = array(),
        $method = 'POST',
        $params = array(),
        $routeSuffix = 'items',
        $headers = array()
    ) {
        $context = new SiteMutationRouteTestContext($this->site);
        $context->method = $method;
        $context->routeSuffix = $routeSuffix;
        $context->apiBasePath = '/x/api';
        $context->setRouteParams($params);
        $context->injectedBody = $body;
        $context->injectedHeaders = $headers;
        return $context;
    }

    private function invoke($handlerFile, $context)
    {
        $path = dirname(__DIR__, 2) . '/lib/siteRoutes/v1/' . $handlerFile;
        $handler = include $path;
        ob_start();
        try {
            $handler($context);
        } finally {
            $raw = ob_get_clean();
        }
        return array('raw' => $raw, 'data' => json_decode($raw, true));
    }

    private function siteTokenHeader(): array
    {
        return array('HTTP_X_HAXCMS_SITE_TOKEN' => 'tok123');
    }

    /**
     * Assert the recorded validateRequestToken call for the site token
     * header value 'tok123' scoped to 'testuser:my-site' happened.
     */
    private function assertSiteTokenPropagated(): void
    {
        foreach ($this->haxcms->tokenCalls as $call) {
            if ($call[0] === 'request' && $call[1] === 'tok123' && $call[2] === 'testuser:my-site') {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail('validateRequestToken was not called with ("tok123", "testuser:my-site"): ' . var_export($this->haxcms->tokenCalls, true));
    }

    /**
     * Deterministic file uuid used by filesMutation for a site file. Mirrors
     * haxcmsSiteDeterministicFileUuid: sha256('site:files/<path>:<size>').
     */
    private function deterministicFileUuid(string $apiPath, int $size): string
    {
        $hash = strtolower(hash('sha256', $this->siteName . ':' . $apiPath . ':' . $size));
        return substr($hash, 0, 8) . '-' .
            substr($hash, 8, 4) . '-' .
            substr($hash, 12, 4) . '-' .
            substr($hash, 16, 4) . '-' .
            substr($hash, 20, 12);
    }

    // ==================================================================
    // itemsMutation.php
    // ==================================================================

    public function testItemsMutationPostWithoutPayloadReturns400(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(array(), 'POST'));
        $this->assertSame('Node payload is required', $res['data']['data']['message']);
    }

    public function testItemsMutationPostCreatesNode(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            array('node' => array('title' => 'Fresh Page')),
            'POST',
            array(),
            'items',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $this->assertSame('Fresh Page', $res['data']['data']['title']);
        // Real persistence: manifest now has 3 items and the page file exists
        // at the item's location.
        $items = $this->readPersistedItems();
        $this->assertCount(3, $items);
        $location = $res['data']['data']['location'];
        $this->assertNotSame('', $location);
        $this->assertFileExists($this->siteDir() . '/' . $location);
        // X-HAXCMS-Site-Token header propagated into the operation token gate.
        $this->assertSiteTokenPropagated();
    }

    public function testItemsMutationPostSkipsDeletedItemsInBulk(): void
    {
        $body = array(
            'items' => array(
                array('title' => 'Alpha Two'),
                array('title' => 'Skip Me', 'delete' => true),
            ),
        );
        $res = $this->invoke('itemsMutation.php', $this->makeContext($body, 'POST'));
        $this->assertSame(200, $res['data']['status']);
        $items = $this->readPersistedItems();
        $this->assertCount(3, $items);
        $titles = array_map(function ($item) {
            return $item->title;
        }, $items);
        $this->assertContains('Alpha Two', $titles);
        $this->assertNotContains('Skip Me', $titles);
    }

    public function testItemsMutationPostForcesSiteNameDespiteClientOverride(): void
    {
        // SEC-26: a client-supplied site.name must not redirect the write.
        $body = array(
            'node' => array('title' => 'Sneaky Page'),
            'site' => array('name' => 'evil-site'),
        );
        $res = $this->invoke('itemsMutation.php', $this->makeContext($body, 'POST'));
        $this->assertSame(200, $res['data']['status']);
        $manifest = $this->readPersistedManifest();
        $this->assertSame('my-site', $manifest->metadata->site->name);
        $this->assertCount(3, $this->readPersistedItems());
    }

    public function testItemsMutationPatchRequiresOperation(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            array('title' => 'X'),
            'PATCH',
            array('idOrSlug' => 'item-a')
        ));
        $this->assertSame('Operation is required', $res['data']['data']['message']);
    }

    public function testItemsMutationPatchSetTitlePersistsAndProjectsLinks(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            array('operation' => 'setTitle', 'title' => 'Renamed A'),
            'PATCH',
            array('idOrSlug' => 'item-a'),
            'items',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $record = $res['data']['data'];
        // D50 projection: summary + navigation links.
        $this->assertSame('item-a', $record['id']);
        $this->assertSame('Renamed A', $record['title']);
        $this->assertSame('/x/api/v1/items?filter.parent=item-a', $record['links']['children']);
        // Real persistence.
        $this->assertSame('Renamed A', $this->readPersistedItem('item-a')->title);
        $this->assertSiteTokenPropagated();
    }

    public function testItemsMutationPatchIgnoresLegacyNodeDetails(): void
    {
        // E10: legacy nested node.details.* must be reset, not honored.
        $body = array(
            'operation' => 'setTitle',
            'title' => 'Legit Title',
            'node' => array(
                'details' => array('title' => 'Injected Title'),
            ),
        );
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            $body,
            'PATCH',
            array('idOrSlug' => 'item-a'),
            'items',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $this->assertSame('Legit Title', $this->readPersistedItem('item-a')->title);
        $this->assertNotSame('Injected Title', $this->readPersistedItem('item-a')->title);
    }

    public function testItemsMutationPatchUnknownSlugReturns404(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            array('operation' => 'setTitle', 'title' => 'X'),
            'PATCH',
            array('idOrSlug' => 'nope')
        ));
        $this->assertSame('Item not found for idOrSlug "nope"', $res['data']['data']['message']);
    }

    public function testItemsMutationDeleteRemovesNode(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            array(),
            'DELETE',
            array('idOrSlug' => 'item-b'),
            'items',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $items = $this->readPersistedItems();
        $this->assertCount(1, $items);
        $this->assertSame('item-a', $items[0]->id);
    }

    public function testItemsMutationDeleteUnknownSlugReturns404(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(
            array(),
            'DELETE',
            array('idOrSlug' => 'ghost')
        ));
        $this->assertSame('Item not found for idOrSlug "ghost"', $res['data']['data']['message']);
    }

    public function testItemsMutationGetUnsupportedReturns405(): void
    {
        $res = $this->invoke('itemsMutation.php', $this->makeContext(array(), 'GET'));
        $this->assertSame('Unsupported method for /v1/items', $res['data']['data']['message']);
    }

    // ==================================================================
    // contentMutation.php
    // ==================================================================

    public function testContentMutationSearchDelegationDefaultsToReplaceOperation(): void
    {
        // No idOrSlug => delegation to Operations::siteSearch; the handler
        // defaults body.operation to 'replace', so a missing search term
        // surfaces siteSearch's own 400 (proves delegation + token gate ran).
        $res = $this->invoke('contentMutation.php', $this->makeContext(
            array(),
            'PATCH',
            array(),
            'content',
            $this->siteTokenHeader()
        ));
        $this->assertSame('Search query is required', $res['data']['data']['message']);
        $this->assertSiteTokenPropagated();
    }

    public function testContentMutationSearchDelegationRejectsBadToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $res = $this->invoke('contentMutation.php', $this->makeContext(
            array('search' => 'findme'),
            'PATCH',
            array(),
            'content',
            $this->siteTokenHeader()
        ));
        $this->assertSame('Authentication required', $res['data']['data']['message']);
    }

    public function testContentMutationUnknownSlugReturns404(): void
    {
        $res = $this->invoke('contentMutation.php', $this->makeContext(
            array('body' => '<p>x</p>'),
            'PATCH',
            array('idOrSlug' => 'nope')
        ));
        $this->assertSame('Content not found for idOrSlug "nope"', $res['data']['data']['message']);
    }

    public function testContentMutationRequiresBody(): void
    {
        $res = $this->invoke('contentMutation.php', $this->makeContext(
            array(),
            'PATCH',
            array('idOrSlug' => 'item-a')
        ));
        $this->assertSame('Content body is required', $res['data']['data']['message']);
    }

    public function testContentMutationWritesBodyToPage(): void
    {
        // saveNode only saves content following a <page-break> element.
        $res = $this->invoke('contentMutation.php', $this->makeContext(
            array('body' => '<page-break title="Item A" item-id="item-a" slug="item-a"></page-break><p>Updated content</p>'),
            'PATCH',
            array('idOrSlug' => 'item-a'),
            'content',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $html = file_get_contents($this->siteDir() . '/pages/item-a/index.html');
        $this->assertStringContainsString('Updated content', $html);
    }

    public function testContentMutationAcceptsContentFallbackField(): void
    {
        $res = $this->invoke('contentMutation.php', $this->makeContext(
            array('content' => '<page-break title="Item B" item-id="item-b" slug="item-b"></page-break><p>From content field</p>'),
            'PATCH',
            array('idOrSlug' => 'item-b'),
            'content',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $html = file_get_contents($this->siteDir() . '/pages/item-b/index.html');
        $this->assertStringContainsString('From content field', $html);
    }

    // ==================================================================
    // siteMutation.php
    // ==================================================================

    public function testSiteMutationDefaultSuffixSavesManifest(): void
    {
        // saveManifest reads these manifest.site/manifest.theme keys
        // unconditionally, mirroring the HAXcms frontend manifest form. The
        // form id/token pair is required for the standard manifest-save path;
        // without it the payload is treated as a scoped Details update and
        // theme variables are not processed.
        $body = array(
            'haxcms_form_id' => 'site-manifest',
            'haxcms_form_token' => 'tok123',
            'manifest' => array(
                'site' => array(
                    'manifest-title' => 'Test Site',
                    'manifest-description' => '',
                    'manifest-metadata-site-domain' => '',
                    'manifest-metadata-site-tags' => '',
                ),
                'theme' => array(
                    'manifest-metadata-theme-element' => 'haxcms-demo-theme',
                    'manifest-metadata-theme-variables-cssVariable' => 'grey',
                    'manifest-metadata-theme-variables-icon' => 'icons:editor',
                ),
            ),
        );
        $res = $this->invoke('siteMutation.php', $this->makeContext(
            $body,
            'PATCH',
            array(),
            'site',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        // site.json still valid and items intact after a manifest save.
        $manifest = $this->readPersistedManifest();
        $this->assertSame('Test Site', $manifest->title);
        $this->assertCount(2, $manifest->items);
        $this->assertSame(
            '--simple-colors-default-theme-grey-7',
            $manifest->metadata->theme->variables->cssVariable
        );
        $this->assertSiteTokenPropagated();
    }

    public function testSiteMutationScopedDetailsPayloadSavesTitle(): void
    {
        // A manifest payload carrying manifest-title but no haxcms_form_id/
        // haxcms_form_token is detected as a scoped Details update: the title
        // is persisted directly while standard form fields (theme variables)
        // are deliberately not processed.
        $body = array(
            'manifest' => array(
                'site' => array(
                    'manifest-title' => 'Scoped Title',
                ),
            ),
        );
        $res = $this->invoke('siteMutation.php', $this->makeContext(
            $body,
            'PATCH',
            array(),
            'site',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $manifest = $this->readPersistedManifest();
        $this->assertSame('Scoped Title', $manifest->title);
        $this->assertArrayNotHasKey(
            'cssVariable',
            (array) $manifest->metadata->theme->variables
        );
    }

    public function testSiteMutationAppearanceSuffixPersistsPalette(): void
    {
        $body = array(
            'manifest' => array(
                'theme' => array(
                    'manifest-metadata-theme-variables-palette' => 'grey',
                ),
            ),
        );
        $res = $this->invoke('siteMutation.php', $this->makeContext(
            $body,
            'PATCH',
            array(),
            'site/appearance',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $this->assertTrue($res['data']['data']['saved']);
        $manifest = $this->readPersistedManifest();
        $this->assertSame('grey', $manifest->metadata->theme->variables->palette);
    }

    public function testSiteMutationPlatformSuffixPersistsFeatures(): void
    {
        $body = array(
            'platform' => array(
                'features' => array('addPage' => false),
            ),
        );
        $res = $this->invoke('siteMutation.php', $this->makeContext(
            $body,
            'PATCH',
            array(),
            'site/platform',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $manifest = $this->readPersistedManifest();
        $this->assertFalse($manifest->metadata->platform->features->addPage);
    }

    public function testSiteMutationSeoSuffixPersistsDescription(): void
    {
        $body = array(
            'seo' => array(
                'description' => 'SEO description here',
                'lang' => 'en',
            ),
        );
        $res = $this->invoke('siteMutation.php', $this->makeContext(
            $body,
            'PATCH',
            array(),
            'site/seo',
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status']);
        $manifest = $this->readPersistedManifest();
        $this->assertSame('SEO description here', $manifest->description);
        $this->assertSame('en', $manifest->metadata->site->settings->lang);
    }

    // ==================================================================
    // exportsMutation.php
    // ==================================================================

    public function testExportsMutationUnsupportedFormatReturns400WithSupportedList(): void
    {
        $res = $this->invoke('exportsMutation.php', $this->makeContext(
            array(),
            'GET',
            array('format' => 'exe'),
            'site/export/exe'
        ));
        $this->assertSame('Unsupported site export format "exe"', $res['data']['data']['message']);
        $this->assertSame(
            array('zip', 'markdown', 'pdf', 'docx', 'epub', 'html', 'skeleton'),
            $res['data']['data']['supportedFormats']
        );
    }

    public function testExportsMutationZipDescriptorUsesSystemApiBasePath(): void
    {
        $res = $this->invoke('exportsMutation.php', $this->makeContext(
            array(),
            'GET',
            array('format' => 'zip'),
            'site/export/zip'
        ));
        $this->assertSame('zip', $res['data']['data']['format']);
        $this->assertSame(
            '/system/api/v1/sites/my-site/download',
            $res['data']['data']['export']['href']
        );
        $this->assertSame('POST', $res['data']['data']['export']['method']);
        $this->assertSame('/x/api/v1/site/export/zip', $res['data']['data']['links']['self']);
    }

    public function testExportsMutationMarkdownDescriptorUsesApiBasePath(): void
    {
        $res = $this->invoke('exportsMutation.php', $this->makeContext(
            array(),
            'GET',
            array('format' => 'markdown'),
            'site/export/markdown'
        ));
        $this->assertSame('markdown', $res['data']['data']['format']);
        $this->assertSame(
            '/x/api/v1/content?mode=concat&format=md',
            $res['data']['data']['export']['href']
        );
        $this->assertSame('text/markdown', $res['data']['data']['export']['mediaType']);
        $this->assertSame('/x/api/v1/site/export/markdown', $res['data']['data']['links']['self']);
    }

    // ==================================================================
    // revisionsMutation.php
    // ==================================================================

    public function testRevisionsMutationUnknownSlugReturns404(): void
    {
        $res = $this->invoke('revisionsMutation.php', $this->makeContext(
            array(),
            'POST',
            array('idOrSlug' => 'nope', 'revisionId' => 'abc1234'),
            'items/nope/revisions/abc1234',
            $this->siteTokenHeader()
        ));
        $this->assertSame('Item not found for idOrSlug "nope"', $res['data']['data']['message']);
    }

    public function testRevisionsMutationInvalidHashReturns400(): void
    {
        $res = $this->invoke('revisionsMutation.php', $this->makeContext(
            array(),
            'POST',
            array('idOrSlug' => 'item-a', 'revisionId' => 'not-a-hash!'),
            'items/item-a/revisions/bad',
            $this->siteTokenHeader()
        ));
        $this->assertSame('Invalid revision hash', $res['data']['data']['message']);
    }

    public function testRevisionsMutationMissingRevisionFailsPropagated(): void
    {
        // Valid-format hash that does not exist; the temp site has no git
        // history, so restoreNodeRevision fails and the handler propagates
        // the __failed status (404 when git reports a missing object/unknown
        // revision, 500 for other git failures).
        $res = $this->invoke('revisionsMutation.php', $this->makeContext(
            array(),
            'POST',
            array('idOrSlug' => 'item-a', 'revisionId' => 'deadbeef'),
            'items/item-a/revisions/deadbeef',
            $this->siteTokenHeader()
        ));
        $this->assertContains($res['data']['status'], array(404, 500));
        $this->assertSiteTokenPropagated();
    }

    // ==================================================================
    // siteAlternativeFormatsMutation.php
    // ==================================================================

    public function testAltFormatsMissingSiteTokenReturns403(): void
    {
        $res = $this->invoke('siteAlternativeFormatsMutation.php', $this->makeContext(
            array(),
            'POST',
            array(),
            'site/updateAlternativeFormats'
        ));
        $this->assertSame(
            'X-HAXCMS-Site-Token header is required for this endpoint',
            $res['data']['data']['message']
        );
    }

    public function testAltFormatsInvalidSiteTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $res = $this->invoke('siteAlternativeFormatsMutation.php', $this->makeContext(
            array(),
            'POST',
            array(),
            'site/updateAlternativeFormats',
            $this->siteTokenHeader()
        ));
        $this->assertSame(
            'X-HAXCMS-Site-Token header is required for this endpoint',
            $res['data']['data']['message']
        );
    }

    public function testAltFormatsInvalidFormatReturns400(): void
    {
        $res = $this->invoke('siteAlternativeFormatsMutation.php', $this->makeContext(
            array('format' => 'exe'),
            'POST',
            array(),
            'site/updateAlternativeFormats',
            $this->siteTokenHeader()
        ));
        $this->assertSame('Invalid format requested for alternative formats update', $res['data']['data']['message']);
    }

    public function testAltFormatsRssReturnsFormatRss(): void
    {
        $res = $this->invoke('siteAlternativeFormatsMutation.php', $this->makeContext(
            array('format' => 'rss'),
            'POST',
            array(),
            'site/updateAlternativeFormats',
            $this->siteTokenHeader()
        ));
        $this->assertTrue($res['data']['data']['updated']);
        $this->assertSame('my-site', $res['data']['data']['site']['name']);
        $this->assertSame('rss', $res['data']['data']['format']);
    }

    public function testAltFormatsNullFormatReturnsUpdatedTrue(): void
    {
        $res = $this->invoke('siteAlternativeFormatsMutation.php', $this->makeContext(
            array(),
            'POST',
            array(),
            'site/updateAlternativeFormats',
            $this->siteTokenHeader()
        ));
        $this->assertTrue($res['data']['data']['updated']);
        $this->assertNull($res['data']['data']['format']);
    }

    public function testAltFormatsExceptionReturns500(): void
    {
        $throwing = new SiteMutationThrowingFormatSite();
        $throwing->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $throwing;

        $context = $this->makeContext(
            array(),
            'POST',
            array(),
            'site/updateAlternativeFormats',
            $this->siteTokenHeader()
        );
        $context->site = $throwing;
        $res = $this->invoke('siteAlternativeFormatsMutation.php', $context);
        $this->assertSame('Unable to update alternative formats for this site', $res['data']['data']['message']);
    }

    // ==================================================================
    // filesMutation.php
    // ==================================================================

    public function testFilesMutationGetUnsupportedReturns405(): void
    {
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'GET',
            array('fileUuid' => 'abc')
        ));
        $this->assertSame('Unsupported method for /v1/files', $res['data']['data']['message']);
    }

    public function testFilesMutationPostWithoutUploadReturns400(): void
    {
        // No $_FILES => saveFile fails with its own 400, propagated.
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'POST',
            array(),
            'files',
            $this->siteTokenHeader()
        ));
        $this->assertSame('Missing file upload', $res['data']['data']['message']);
    }

    public function testFilesMutationPatchRejectsDeleteOperation(): void
    {
        // D1: PATCH {operation:'delete'} must redirect clients to DELETE.
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array('operation' => 'delete'),
            'PATCH',
            array('fileUuid' => 'aabbccdd-1122-3344-5566-77889900aabb')
        ));
        $this->assertSame(
            'Use DELETE /x/api/v1/files/{fileUuid} for file deletion',
            $res['data']['data']['message']
        );
    }

    public function testFilesMutationPatchRequiresUuid(): void
    {
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'PATCH',
            array('fileUuid' => '')
        ));
        $this->assertSame('File uuid is required', $res['data']['data']['message']);
    }

    public function testFilesMutationPatchRejectsNonUuidToken(): void
    {
        // D52: strict UUID only — raw file paths are rejected.
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'PATCH',
            array('fileUuid' => 'files/hello.txt')
        ));
        $this->assertSame(
            'File uuid is required and must be a valid UUID',
            $res['data']['data']['message']
        );
    }

    public function testFilesMutationPatchUnknownUuidReturns404(): void
    {
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'PATCH',
            array('fileUuid' => '00000000-0000-4000-8000-000000000000'),
            'files/00000000-0000-4000-8000-000000000000',
            $this->siteTokenHeader()
        ));
        $this->assertSame('File not found for fileUuid', $res['data']['data']['message']);
    }

    public function testFilesMutationDeleteUnknownUuidReturns404(): void
    {
        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'DELETE',
            array('fileUuid' => '00000000-0000-4000-8000-000000000000'),
            'files/00000000-0000-4000-8000-000000000000',
            $this->siteTokenHeader()
        ));
        $this->assertSame('File not found for fileUuid', $res['data']['data']['message']);
    }

    public function testFilesMutationDeleteRemovesFileAndDataStoreRecord(): void
    {
        // Seed a real file in the site files dir; the files.json datastore
        // auto-builds from disk when files.json is missing.
        $filesDir = $this->siteDir() . '/files';
        if (!is_dir($filesDir)) {
            mkdir($filesDir, 0777, true);
        }
        $content = 'hello world';
        file_put_contents($filesDir . '/hello.txt', $content);
        $uuid = $this->deterministicFileUuid('files/hello.txt', strlen($content));

        $res = $this->invoke('filesMutation.php', $this->makeContext(
            array(),
            'DELETE',
            array('fileUuid' => $uuid),
            'files/' . $uuid,
            $this->siteTokenHeader()
        ));
        $this->assertSame(200, $res['data']['status'], 'DELETE response: ' . var_export($res, true));
        // Disk file removed by fileOperation(delete).
        $this->assertFileDoesNotExist($filesDir . '/hello.txt');
        // files.json datastore record scrubbed by FileStorage::delete (the
        // envelope file may still exist with the record removed).
        if (file_exists($filesDir . '/files.json')) {
            $envelope = json_decode(file_get_contents($filesDir . '/files.json'), true);
            $remaining = array();
            foreach (($envelope['files'] ?? array()) as $record) {
                if (($record['uuid'] ?? '') !== $uuid) {
                    $remaining[] = $record;
                }
            }
            $this->assertCount(0, $remaining);
        }
    }
}
