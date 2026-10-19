<?php
/**
 * Characterization tests for lib/systemRoutes/v1/lifecycle.php.
 *
 * The returned route closure is exercised for every lifecycle route family:
 *   - v1/sites GET (real Operations::listSites over a temp _sites tree)
 *   - v1/sites POST (real Operations::createSite happy path -> the route's
 *     enrichment block computes id/slug/link on the created schema item)
 *   - v1/sites/:siteName GET/POST (site info envelope with links + metadata;
 *     both the literal ':siteName' route form and the resolved path form)
 *   - the 5 IDOR-gated write delegates (clone, archive, download,
 *     download-skeleton, save-as-template): route gate 403 plus a delegate
 *     invocation verified via the delegate's own invalid-token fast-fail, so
 *     no filesystem mutation is needed to prove the call happened
 *   - unknown route 404 and the __failed envelope
 *
 * $GLOBALS['HAXCMS'] is a configurable fake (LifecycleRouteFakeHaxcms) whose
 * loadSite returns a REAL HAXCMSSite (test subclass) loaded from a per-test
 * temp fixture under HAXCMS_ROOT. For createSite's git step, the fixture
 * ships an empty .git directory: GitRepo only checks that .git exists, and
 * git subcommand failures are swallowed downstream, so no git binary or
 * identity is required.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS in lifecycle.php (195/203 stmts):
 *   - 77 and 89-94 (slug fallbacks in the createSite enrichment) only run
 *     when the created site object lacks a slug; real createSite always
 *     assigns slug = location before returning.
 *   - 287 (non-array / status-less response rewrap) is defensive: every
 *     reachable $response in this route either carries __failed or status.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Configurable HAXCMS collaborator for the lifecycle route. Mirrors the
 * OperationsLifecycleTestHaxcms pattern: loadSite returns a real HAXCMSSite
 * loaded from the temp sites tree, and generateMachineName mirrors the
 * production normalization contract.
 */
class LifecycleRouteFakeHaxcms extends OperationsTestHaxcms
{
    public $publishedDirectory = '_published';
    public $systemRequestBase;
    public $outlineSchema;
    public $siteMissing = false;
    public $requestTokenUserName = 'testuser';
    public $tokenCalls = array();
    public $requestTokenCalls = array();

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function getRequestTokenUserName()
    {
        return $this->requestTokenUserName;
    }

    public function getRequestToken($name)
    {
        $this->requestTokenCalls[] = (string) $name;
        return 'request-token-for-' . $name;
    }

    public function generateMachineName($name)
    {
        // Mirrors HAXCMS::generateMachineName so machineName-derived
        // assertions reflect the real production normalization contract.
        $name = str_replace(chr(0), '', (string) $name);
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

    public function getThemes()
    {
        return array('clean-two' => array('name' => 'clean-two', 'element' => 'clean-two'));
    }

    public function validateRequestToken($token, $value)
    {
        $this->tokenCalls[] = array((string) $token, (string) $value);
        return parent::validateRequestToken($token, $value);
    }

    public function loadSite($name, $create = false, $domain = null, $build = null)
    {
        if ($this->siteMissing) {
            return false;
        }
        $tmpname = $this->cleanTitle(urldecode((string) $name), false);
        $siteDir = HAXCMS_ROOT . '/' . $this->sitesDirectory . '/' . $tmpname;
        if ($create && !is_dir($siteDir)) {
            $this->writeSiteFixture($tmpname, array(), true);
        }
        $site = new LifecycleRouteTestSite();
        $site->load(
            HAXCMS_ROOT . '/' . $this->sitesDirectory,
            $this->basePath . $this->sitesDirectory . '/',
            $tmpname
        );
        return $site;
    }

    /**
     * Write a minimal-but-valid site fixture into the sites tree and return
     * its directory. $withGitDir drops an empty .git so createSite's
     * GitRepo::open succeeds without a real git repository.
     */
    public function writeSiteFixture($name, array $items = array(), $withGitDir = false, $created = 1700000000, $updated = 1700000100)
    {
        $siteDir = HAXCMS_ROOT . '/' . $this->sitesDirectory . '/' . $name;
        @mkdir($siteDir . '/pages', 0777, true);
        $manifest = (object) array(
            'id' => 'manifest-' . $name,
            'title' => 'Title for ' . $name,
            'author' => '',
            'description' => 'A lifecycle test site',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $name,
                    'created' => $created,
                    'updated' => $updated,
                ),
            ),
            'items' => $items,
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        if ($withGitDir) {
            @mkdir($siteDir . '/.git', 0777, true);
        }
        return $siteDir;
    }
}

/**
 * HAXCMSSite test subclass that no-ops the git/twig collaborators while
 * keeping the real manifest load + JSONOutlineSchema::save.
 */
class LifecycleRouteTestSite extends HAXCMSSite
{
    public $gitCommits = array();
    public $rebuiltManagedFiles = false;
    public $updatedAlternateFormats = false;

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        $this->rebuiltManagedFiles = true;
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->updatedAlternateFormats = true;
        return null;
    }
}

class SystemRoutesLifecycleTest extends TestCase
{
    private $savedHaxcms;
    private $savedFileSystem;
    private $savedServer;
    private $savedErrorLog;
    private $configDir;
    private $lifecyclePath;
    private $siteName;

    protected function setUp(): void
    {
        $this->lifecyclePath = dirname(__DIR__, 2) . '/lib/systemRoutes/v1/lifecycle.php';
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedFileSystem = isset($GLOBALS['fileSystem']) ? $GLOBALS['fileSystem'] : null;
        $this->savedServer = $_SERVER;
        $this->savedErrorLog = @ini_get('error_log');
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']);
        unset($_SERVER['REQUEST_URI']);
        unset($_SERVER['SCRIPT_NAME']);
        http_response_code(200);
        // Redirect createSite's production error_log() debug output away from
        // the test runner's stderr.
        $this->configDir = sys_get_temp_dir() . '/haxcms_lifecycle_route_' . uniqid();
        @mkdir($this->configDir, 0777, true);
        @ini_set('error_log', $this->configDir . '/php-error.log');
        $this->siteName = 'lc-' . substr(uniqid(), -8);
        // Defensive: clear the shared per-process sites tree so listSites sees
        // exactly the fixtures each test writes.
        $this->rrmdir(HAXCMS_ROOT . '/_sites');
        $GLOBALS['HAXCMS'] = new LifecycleRouteFakeHaxcms();
        $GLOBALS['HAXCMS']->sitesDirectory = '_sites';
        $GLOBALS['HAXCMS']->archivedDirectory = '_archived';
        $GLOBALS['HAXCMS']->basePath = '/';
        $GLOBALS['HAXCMS']->configDirectory = $this->configDir;
        $GLOBALS['fileSystem'] = new \Symfony\Component\Filesystem\Filesystem();
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if ($this->savedFileSystem !== null) {
            $GLOBALS['fileSystem'] = $this->savedFileSystem;
        } else {
            unset($GLOBALS['fileSystem']);
        }
        $_SERVER = $this->savedServer;
        @ini_set('error_log', $this->savedErrorLog);
        $this->rrmdir(HAXCMS_ROOT . '/_sites');
        $this->rrmdir(HAXCMS_ROOT . '/_archived');
        $this->rrmdir($this->configDir);
        http_response_code(200);
    }

    private function haxcms()
    {
        return $GLOBALS['HAXCMS'];
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_link BEFORE is_dir: is_dir() follows symlinks.
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

    private function outlineItem($slug)
    {
        return (object) array(
            'id' => 'item-' . $slug,
            'indent' => 0,
            'location' => 'pages/' . $slug . '/index.html',
            'slug' => $slug,
            'order' => 0,
            'parent' => null,
            'title' => ucfirst($slug),
            'description' => '',
            'metadata' => (object) array(),
        );
    }

    /**
     * Include the route file fresh and invoke the closure with a reflection
     * context; returns raw output, decoded JSON, and the response code.
     * $serverOverrides keys starting with 'unset:' remove the $_SERVER entry.
     */
    private function runHandler($routeSuffix, $method = 'GET', $body = array(), $params = array(), array $serverOverrides = array())
    {
        foreach ($serverOverrides as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $context = (new ReflectionClass('SystemApiRequestContext'))->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = $routeSuffix;
        $context->method = $method;
        $context->body = $body;
        $context->params = $params;
        $handler = require $this->lifecyclePath;
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        return array(
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'code' => http_response_code(),
        );
    }

    // ------------------------------------------------------------------
    // v1/sites GET: real listSites over the temp sites tree
    // ------------------------------------------------------------------

    public function testSitesGetListsValidSitesFromSitesDirectory(): void
    {
        $this->haxcms()->writeSiteFixture(
            $this->siteName,
            array($this->outlineItem('welcome'), $this->outlineItem('page-two'))
        );
        // Strays that must be filtered out: a dir without site.json and a file.
        @mkdir(HAXCMS_ROOT . '/_sites/not-a-site', 0777, true);
        file_put_contents(HAXCMS_ROOT . '/_sites/loose.txt', 'not a site');

        $result = $this->runHandler('v1/sites', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('My sites', $result['data']['data']['title']);
        $items = $result['data']['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('manifest-' . $this->siteName, $items[0]['id']);
        $this->assertSame('/_sites/' . $this->siteName . '/', $items[0]['location']);
        $this->assertSame('/_sites/' . $this->siteName . '/', $items[0]['slug']);
        $this->assertSame(2, $items[0]['metadata']['pageCount']);
        $this->assertArrayNotHasKey('items', $items[0]);
        // Canonical system READ routes feed the client X-HAXCMS-User-Token
        // header into the handler's token validation.
        $this->assertContains(array('client-token', 'testuser'), $this->haxcms()->tokenCalls);
    }

    public function testSitesGetFailsWithInvalidClientToken(): void
    {
        $this->haxcms()->validRequestToken = false;

        $result = $this->runHandler('v1/sites', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'bogus-token',
        ));

        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('invalid request token', $result['data']['data']['message']);
    }

    public function testSitesGetFallsBackToActiveUserNameWhenTokenUserNameEmpty(): void
    {
        $this->haxcms()->requestTokenUserName = '';
        $this->haxcms()->validRequestToken = false;

        $result = $this->runHandler('v1/sites', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        // The server token was minted for the ACTIVE user, proving the
        // empty-string getRequestTokenUserName() fallback ran.
        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('invalid request token', $result['data']['data']['message']);
        $this->assertSame('testuser', $this->haxcms()->requestTokenCalls[0]);
    }

    // ------------------------------------------------------------------
    // v1/sites POST: real createSite + the route's enrichment block
    // ------------------------------------------------------------------

    public function testSitesPostCreateSiteEnrichesResponseWithIdSlugLink(): void
    {
        // isSystemV1Request() gates createSite on the request URI.
        $result = $this->runHandler('v1/sites', 'POST', array(
            'site' => array('name' => $this->siteName),
        ), array(), array(
            'REQUEST_URI' => '/system/api/v1/sites',
            'SCRIPT_NAME' => '/system/api/v1/index.php',
        ));

        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        // The enrichment block promotes id/slug/link to the response top level.
        $this->assertSame('/_sites/' . $this->siteName . '/index.html', $result['data']['data']['location']);
        $this->assertSame('/_sites/' . $this->siteName . '/index.html', $result['data']['slug']);
        $this->assertSame('/_sites/' . $this->siteName . '/', $result['data']['link']);
        $this->assertNotEmpty($result['data']['id']);
        $this->assertSame($result['data']['id'], $result['data']['data']['id']);
        $this->assertSame($this->siteName, $result['data']['data']['title']);
        // Observable mutation: the site really was created in the sites tree.
        $this->assertTrue(is_dir(HAXCMS_ROOT . '/_sites/' . $this->siteName));
        $this->assertFileExists(HAXCMS_ROOT . '/_sites/' . $this->siteName . '/site.json');
    }

    public function testSitesPostFailsOutsideSystemV1Requests(): void
    {
        $result = $this->runHandler('v1/sites', 'POST', array(
            'site' => array('name' => $this->siteName),
        ), array(), array(
            'REQUEST_URI' => '/sites',
        ));

        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('invalid request token', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // v1/sites/:siteName GET/POST: site info envelope
    // ------------------------------------------------------------------

    public function testSiteInfoGetReturnsDetailsLinksAndIsoMetadata(): void
    {
        $this->haxcms()->writeSiteFixture(
            $this->siteName,
            array($this->outlineItem('welcome'), $this->outlineItem('page-two'))
        );
        // A trailing slash on systemRequestBase lets the handler append 'v1'
        // cleanly (basePath . '/' . systemRequestBase . 'v1').
        $this->haxcms()->systemRequestBase = 'system/api/';
        $encoded = rawurlencode($this->siteName);

        $result = $this->runHandler('v1/sites/' . $this->siteName, 'GET', array(), array(
            'siteName' => $this->siteName,
        ), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('manifest-' . $this->siteName, $data['id']);
        $this->assertSame($this->siteName, $data['name']);
        $this->assertSame('Title for ' . $this->siteName, $data['title']);
        $this->assertSame('A lifecycle test site', $data['description']);
        // The handler concatenates normalizedBasePath ('/' for a root install)
        // with another '/' before the sites dir, so the leading slash doubles.
        $this->assertSame('//_sites/' . $encoded . '/', $data['location']);
        $this->assertSame(2, $data['metadata']['pageCount']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s.000\Z', 1700000000), $data['metadata']['created']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s.000\Z', 1700000100), $data['metadata']['updated']);
        $this->assertSame('/system/api/v1/sites/' . $encoded, $data['links']['self']);
        $this->assertSame('/system/api/v1/sites/' . $encoded . '/clone', $data['links']['clone']);
        $this->assertSame('/system/api/v1/sites/' . $encoded . '/archive', $data['links']['archive']);
        $this->assertSame('/system/api/v1/sites/' . $encoded . '/download', $data['links']['download']);
        $this->assertSame('/system/api/v1/sites/' . $encoded . '/download-skeleton', $data['links']['downloadSkeleton']);
        $this->assertSame('/system/api/v1/sites/' . $encoded . '/save-as-template', $data['links']['saveAsTemplate']);
        $this->assertSame('//_sites/' . $encoded . '/x/api', $data['links']['siteApi']);
    }

    public function testSiteInfoGetUsesDefaultSystemRequestBaseWhenUnset(): void
    {
        $this->haxcms()->writeSiteFixture($this->siteName, array($this->outlineItem('welcome')));
        $encoded = rawurlencode($this->siteName);

        $result = $this->runHandler('v1/sites/' . $this->siteName, 'GET', array(), array(
            'siteName' => $this->siteName,
        ), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('/system/api/v1/sites/' . $encoded, $result['data']['data']['links']['self']);
    }

    public function testSiteInfoGetNormalizesSubdirectoryBasePath(): void
    {
        $this->haxcms()->writeSiteFixture($this->siteName, array($this->outlineItem('welcome')));
        // A basePath without a leading slash (and with a trailing one) forces
        // normalizePath to both prepend the missing '/' and trim the trailing
        // one for the normalizedBasePath input.
        $this->haxcms()->basePath = 'subdir/';
        $this->haxcms()->systemRequestBase = 'system/api/';
        $encoded = rawurlencode($this->siteName);

        $result = $this->runHandler('v1/sites/' . $this->siteName, 'GET', array(), array(
            'siteName' => $this->siteName,
        ), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('/subdir/_sites/' . $encoded . '/', $data['location']);
        $this->assertSame('/subdir/system/api/v1/sites/' . $encoded, $data['links']['self']);
        $this->assertSame('/subdir/_sites/' . $encoded . '/x/api', $data['links']['siteApi']);
    }

    public function testSiteInfoGetHandlesEmptyBasePath(): void
    {
        $this->haxcms()->writeSiteFixture($this->siteName, array($this->outlineItem('welcome')));
        // An empty basePath hits normalizePath's empty-input branch, which
        // yields '/' for the normalized base.
        $this->haxcms()->basePath = '';
        $this->haxcms()->systemRequestBase = 'system/api/';
        $encoded = rawurlencode($this->siteName);

        $result = $this->runHandler('v1/sites/' . $this->siteName, 'GET', array(), array(
            'siteName' => $this->siteName,
        ), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('//_sites/' . $encoded . '/', $data['location']);
        $this->assertSame('/system/api/v1/sites/' . $encoded, $data['links']['self']);
    }

    public function testSiteInfoGetReturnsNullForNonNumericTimestamps(): void
    {
        // created/updated present but not usable integers -> toIsoDate null.
        $this->haxcms()->writeSiteFixture($this->siteName, array($this->outlineItem('welcome')), false, 0, 'not-a-date');

        $result = $this->runHandler('v1/sites/' . $this->siteName, 'GET', array(), array(
            'siteName' => $this->siteName,
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertNull($result['data']['data']['metadata']['created']);
        $this->assertNull($result['data']['data']['metadata']['updated']);
    }

    public function testSiteInfoGetReturns404WhenSiteMissing(): void
    {
        $this->haxcms()->siteMissing = true;

        $result = $this->runHandler('v1/sites/' . $this->siteName, 'GET', array(), array(
            'siteName' => $this->siteName,
        ));

        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Site not found', $result['data']['data']['message']);
    }

    public function testSiteInfoPostMergesBodyIntoSiteParams(): void
    {
        $this->haxcms()->writeSiteFixture($this->siteName, array($this->outlineItem('welcome')));

        // Literal :siteName route form + POST body merged into params['site'].
        $result = $this->runHandler('v1/sites/:siteName', 'POST', array(
            'title' => 'Renamed site',
        ), array('siteName' => $this->siteName));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame($this->siteName, $result['data']['data']['name']);
    }

    public function testSiteInfoUnsupportedMethodReturns405(): void
    {
        $result = $this->runHandler('v1/sites/' . $this->siteName, 'DELETE', array(), array(
            'siteName' => $this->siteName,
        ));

        $this->assertSame(405, $result['data']['status']);
        $this->assertSame('Method not allowed', $result['data']['data']);
    }

    // ------------------------------------------------------------------
    // IDOR-gated write delegates: route gate + delegate invocation
    // ------------------------------------------------------------------

    /**
     * Route gate failure: userCanAccessSite false -> 403 'Access denied to
     * site' envelope, and the delegate is never invoked.
     */
    private function assertRouteGateDenies($action): void
    {
        $this->haxcms()->canAccessSite = false;
        $result = $this->runHandler('v1/sites/' . $this->siteName . '/' . $action, 'POST', array(), array(
            'siteName' => $this->siteName,
        ));

        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('Access denied to site', $result['data']['data']['message']);
    }

    /**
     * Delegate invocation: the gate passes and the delegate call line runs.
     * The delegate's own token validation is forced to fail so it 403s fast
     * (before any filesystem work), proving the call happened without a
     * full clone/archive/download fixture.
     */
    private function assertDelegateInvoked($action): void
    {
        $this->haxcms()->canAccessSite = true;
        $this->haxcms()->validRequestToken = false;
        $result = $this->runHandler('v1/sites/:siteName/' . $action, 'POST', array(), array(
            'siteName' => $this->siteName,
        ));

        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('invalid request token', $result['data']['data']['message']);
    }

    public function testCloneRouteDeniesWhenUserCannotAccessSite(): void
    {
        $this->assertRouteGateDenies('clone');
    }

    public function testCloneRouteInvokesCloneSiteDelegate(): void
    {
        $this->assertDelegateInvoked('clone');
    }

    public function testArchiveRouteDeniesWhenUserCannotAccessSite(): void
    {
        $this->assertRouteGateDenies('archive');
    }

    public function testArchiveRouteInvokesArchiveSiteDelegate(): void
    {
        $this->assertDelegateInvoked('archive');
    }

    public function testDownloadRouteDeniesWhenUserCannotAccessSite(): void
    {
        $this->assertRouteGateDenies('download');
    }

    public function testDownloadRouteInvokesDownloadSiteDelegate(): void
    {
        $this->assertDelegateInvoked('download');
    }

    public function testDownloadSkeletonRouteDeniesWhenUserCannotAccessSite(): void
    {
        $this->assertRouteGateDenies('download-skeleton');
    }

    public function testDownloadSkeletonRouteInvokesDownloadSiteSkeletonDelegate(): void
    {
        $this->assertDelegateInvoked('download-skeleton');
    }

    public function testSaveAsTemplateRouteDeniesWhenUserCannotAccessSite(): void
    {
        $this->assertRouteGateDenies('save-as-template');
    }

    public function testSaveAsTemplateRouteInvokesSaveSiteAsTemplateDelegate(): void
    {
        $this->assertDelegateInvoked('save-as-template');
    }

    // ------------------------------------------------------------------
    // Unknown route + envelopes
    // ------------------------------------------------------------------

    public function testUnknownLifecycleRouteReturns404(): void
    {
        $result = $this->runHandler('v1/definitely-not-a-lifecycle-route', 'GET');

        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Unknown lifecycle route', $result['data']['data']['message']);
    }
}
