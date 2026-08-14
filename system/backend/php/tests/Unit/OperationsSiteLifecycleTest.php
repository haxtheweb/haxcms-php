<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the Operations site-lifecycle route seam:
 * createSite, cloneSite, archiveSite, downloadSite, downloadSiteSkeleton,
 * saveSiteAsTemplate.
 *
 * The user_token + IDOR gate contract is pinned via the shared
 * OperationsTestHaxcms collaborator, which we subclass (OperationsLifecycle
 * TestHaxcms) to override loadSite so it returns a REAL HAXCMSSite (test
 * subclass) loaded from a per-test temp sites tree under HAXCMS_ROOT.
 * Observable mutations (cloned directory created + manifest rewritten, site
 * dir moved into _archived with collision suffixing, zip written, skeleton
 * json written) are verified by reading the filesystem back independently of
 * the production return value.
 *
 * cloneSite uses $GLOBALS['fileSystem'] (Symfony Filesystem) for the mirror;
 * downloadSite uses ZipArchive. git/twig collaborators (gitCommit,
 * rebuildManagedFiles, updateAlternateFormats) are no-op'd via the
 * HAXCMSSite test subclass so no git binary is required.
 *
 * HAXCMS_ROOT is define()'d once (process-global) at a stable temp base; each
 * test uses a unique site name and cleans _sites/_archived/_published in
 * tearDown so sequential tests stay isolated.
 */
class OperationsSiteLifecycleTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedFileSystem;
    private $savedServerSoftware;
    private $savedRequestUri;
    private $savedScriptName;
    private $savedErrorLog;
    private $siteName;
    private $configDir;

    protected function setUp(): void
    {
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_lifecycle_root');
        }
        if (!is_dir(HAXCMS_ROOT)) {
            @mkdir(HAXCMS_ROOT, 0777, true);
        }

        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($GLOBALS['fileSystem'])) {
            $this->savedFileSystem = $GLOBALS['fileSystem'];
        }
        $this->savedServerSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null;
        $this->savedRequestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        $this->savedScriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null;
        $this->savedErrorLog = @ini_get('error_log');

        // Force isCLI() false so any auth path that checks it runs for real.
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        // Redirect production error_log() calls (createSite emits debug logs)
        // into a file under the per-test config dir so they don't hit stderr.
        $this->configDir = HAXCMS_ROOT . '/_config_' . substr(uniqid(), -8);
        @mkdir($this->configDir . '/settings', 0777, true);
        @ini_set('error_log', $this->configDir . '/php-error.log');

        // Per-test site name: cleanTitle-idempotent (lowercase + hyphen + hex).
        $this->siteName = 'lc-' . substr(uniqid(), -8);

        $this->haxcms = new OperationsLifecycleTestHaxcms();
        $this->haxcms->sitesDirectory = '_sites';
        $this->haxcms->archivedDirectory = '_archived';
        $this->haxcms->publishedDirectory = '_published';
        $this->haxcms->systemRequestBase = 'system/api';
        $this->haxcms->basePath = '/';
        $this->haxcms->configDirectory = $this->configDir;
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $GLOBALS['fileSystem'] = new \Symfony\Component\Filesystem\Filesystem();

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedFileSystem)) {
            $GLOBALS['fileSystem'] = $this->savedFileSystem;
            $this->savedFileSystem = null;
        } else {
            unset($GLOBALS['fileSystem']);
        }
        if ($this->savedServerSoftware !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        if ($this->savedRequestUri !== null) {
            $_SERVER['REQUEST_URI'] = $this->savedRequestUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
        if ($this->savedScriptName !== null) {
            $_SERVER['SCRIPT_NAME'] = $this->savedScriptName;
        } else {
            unset($_SERVER['SCRIPT_NAME']);
        }
        @ini_set('error_log', $this->savedErrorLog);

        // Clean per-test filesystem artifacts under the shared HAXCMS_ROOT.
        $this->rrmdir(HAXCMS_ROOT . '/_sites');
        $this->rrmdir(HAXCMS_ROOT . '/_archived');
        $this->rrmdir(HAXCMS_ROOT . '/_published');
        $this->rrmdir($this->configDir);
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildMinimalSiteFixture(string $name): string
    {
        $siteDir = HAXCMS_ROOT . '/_sites/' . $name;
        @mkdir($siteDir . '/pages', 0777, true);
        $manifest = (object)array(
            'id' => 'orig-' . $name,
            'title' => 'Title for ' . $name,
            'author' => '',
            'description' => 'A test site',
            'license' => 'by-sa',
            'metadata' => (object)array('site' => (object)array('name' => $name)),
            'items' => array(),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        return $siteDir;
    }

    private function siteJsonPath(string $name): string
    {
        return HAXCMS_ROOT . '/_sites/' . $name . '/site.json';
    }

    private function cloneName(): string
    {
        // OperationsTestHaxcms::getUniqueName appends '-copy'.
        return $this->siteName . '-copy';
    }

    // =========================================================================
    // createSite
    // =========================================================================

    public function testCreateSiteFailsWhenNotSystemV1Request(): void
    {
        // Gate: hasValidCreateSiteRequestToken()/hasValidCreateSiteUserToken()
        // both delegate to isSystemV1Request(), which requires the request URI
        // to contain '/system/api/v1/'. A normal site request must be rejected
        // with 403 'invalid request token' before any site work happens.
        $_SERVER['REQUEST_URI'] = '/sites/' . $this->siteName;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->ops->params = array(
            'user_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->createSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testCreateSiteFailsWhenNoRequestUriAndNoSystemV1ScriptName(): void
    {
        // isSystemV1Request falls back to SCRIPT_NAME when REQUEST_URI is
        // absent; neither containing '/system/api/v1/' -> 403.
        unset($_SERVER['REQUEST_URI']);
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->ops->params = array(
            'user_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->createSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testCreateSiteGatePassesButRejectsUnknownTheme(): void
    {
        // When the request IS a system v1 request, the gate passes and the
        // method proceeds into site creation. With no theme supplied and an
        // empty theme registry, it must reject with 400 'Invalid theme
        // supplied' (HAXCMS_DEFAULT_THEME = 'clean-two'). Reaching this 400
        // proves the 403 gate opened; the deep creation flow (git, boilerplate
        // copy, branch creation) is out of scope and not exercised here.
        $_SERVER['REQUEST_URI'] = '/system/api/v1/createSite';
        $_SERVER['SCRIPT_NAME'] = '/system/api/v1/index.php';
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'user_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $result = $this->ops->createSite();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Invalid theme supplied for site creation', $result['__failed']['message']);
        $this->assertSame('clean-two', $result['__failed']['theme']);
    }

    // =========================================================================
    // cloneSite
    // =========================================================================

    public function testCloneSiteFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->cloneSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testCloneSiteFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->cloneSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    public function testCloneSiteHappyPathClonesDirectoryAndRewritesManifest(): void
    {
        $this->buildMinimalSiteFixture($this->siteName);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));

        $result = $this->ops->cloneSite();

        $this->assertSame(200, $result['status']);
        $this->assertSame($this->cloneName(), $result['data']['name']);
        $this->assertSame('/_sites/' . $this->cloneName(), $result['data']['detail']);

        // Observable mutation: a real cloned directory exists under _sites/,
        // and the original source directory is preserved (clone copies, does
        // not move).
        $this->assertTrue(is_dir(HAXCMS_ROOT . '/_sites/' . $this->cloneName()));
        $this->assertTrue(is_dir(HAXCMS_ROOT . '/_sites/' . $this->siteName));

        // Independent source of truth: read the cloned site.json back from
        // disk and verify the manifest was rewritten to the clone name with a
        // fresh id (distinct from the original).
        $cloned = json_decode(file_get_contents($this->siteJsonPath($this->cloneName())));
        $this->assertSame($this->cloneName(), $cloned->metadata->site->name);
        $this->assertNotSame('orig-' . $this->siteName, $cloned->id);
    }

    // =========================================================================
    // archiveSite
    // =========================================================================

    public function testArchiveSiteFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->archiveSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testArchiveSiteFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->archiveSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    public function testArchiveSiteHappyPathMovesSiteDirToArchived(): void
    {
        $this->buildMinimalSiteFixture($this->siteName);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));

        $result = $this->ops->archiveSite();

        $this->assertSame(200, $result['status']);
        $this->assertSame($this->siteName, $result['data']['name']);
        $this->assertSame($this->siteName, $result['data']['archivedName']);
        $this->assertSame('Site archived', $result['data']['detail']);

        // Observable mutation: the site directory was MOVED (renamed) out of
        // _sites/ into _archived/, carrying its site.json with it.
        $this->assertFalse(is_dir(HAXCMS_ROOT . '/_sites/' . $this->siteName));
        $this->assertTrue(is_dir(HAXCMS_ROOT . '/_archived/' . $this->siteName));
        $this->assertTrue(file_exists(HAXCMS_ROOT . '/_archived/' . $this->siteName . '/site.json'));
    }

    public function testArchiveSiteCollisionSuffixesArchiveName(): void
    {
        $this->buildMinimalSiteFixture($this->siteName);
        // Pre-seed an existing archived copy so the move must find a new slot.
        $existingArchive = HAXCMS_ROOT . '/_archived/' . $this->siteName;
        @mkdir($existingArchive, 0777, true);
        file_put_contents($existingArchive . '/marker.txt', 'pre-existing archive');

        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));

        $result = $this->ops->archiveSite();

        $this->assertSame(200, $result['status']);
        $this->assertSame($this->siteName, $result['data']['name']);
        $this->assertSame($this->siteName . '-1', $result['data']['archivedName']);
        $this->assertSame(
            'Site archived as ' . $this->siteName . '-1 because an archived copy already existed',
            $result['data']['detail']
        );

        // The live site moved to the suffixed slot; the pre-existing archive
        // is left untouched.
        $this->assertFalse(is_dir(HAXCMS_ROOT . '/_sites/' . $this->siteName));
        $this->assertTrue(is_dir(HAXCMS_ROOT . '/_archived/' . $this->siteName . '-1'));
        $this->assertTrue(file_exists(HAXCMS_ROOT . '/_archived/' . $this->siteName . '-1/site.json'));
        $this->assertSame('pre-existing archive', file_get_contents($existingArchive . '/marker.txt'));
    }

    // =========================================================================
    // downloadSite
    // =========================================================================

    public function testDownloadSiteFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->downloadSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testDownloadSiteFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->downloadSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    public function testDownloadSiteHappyPathWritesZip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $siteDir = $this->buildMinimalSiteFixture($this->siteName);
        file_put_contents($siteDir . '/index.html', '<html><body>hello</body></html>');
        @mkdir(HAXCMS_ROOT . '/_published', 0777, true);

        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));

        $result = $this->ops->downloadSite();

        $this->assertSame(200, $result['status']);
        $this->assertSame($this->siteName . '.zip', $result['data']['name']);
        // Observable mutation: a real zip archive was written to the
        // published directory and is non-empty.
        $zipPath = HAXCMS_ROOT . '/_published/' . $this->siteName . '.zip';
        $this->assertTrue(file_exists($zipPath));
        $this->assertGreaterThan(0, filesize($zipPath));
    }

    // =========================================================================
    // downloadSiteSkeleton
    // =========================================================================

    public function testDownloadSiteSkeletonFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->downloadSiteSkeleton();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testDownloadSiteSkeletonFailsWithMissingSiteName(): void
    {
        // This route validates site.name presence/format (400) BEFORE the IDOR
        // gate, a stricter pre-check than cloneSite/archiveSite/downloadSite.
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->downloadSiteSkeleton();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid site name', $result['__failed']['message']);
    }

    public function testDownloadSiteSkeletonFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->downloadSiteSkeleton();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    public function testDownloadSiteSkeletonFailsWhenSiteNotFound(): void
    {
        // loadSite returning false (no manifest) -> 404 'Site does not exist'.
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->haxcms->siteMissing = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->downloadSiteSkeleton();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Site does not exist', $result['__failed']['message']);
    }

    public function testDownloadSiteSkeletonHappyPathReturnsSkeleton(): void
    {
        $this->buildMinimalSiteFixture($this->siteName);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));

        $result = $this->ops->downloadSiteSkeleton();

        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['data']['skeleton']);
        $this->assertSame($this->siteName . '.json', $result['data']['filename']);
        // The skeleton's machineName is derived from the site's manifest
        // metadata.site.name (normalized), an independent structural property.
        $this->assertSame($this->siteName, $result['data']['skeleton']['meta']['machineName']);
        // build.type tags the payload as a skeleton; build.structure is
        // 'from-skeleton' so the payload can round-trip through the
        // from-skeleton creation path on re-import.
        $this->assertSame('skeleton', $result['data']['skeleton']['build']['type']);
        $this->assertSame('from-skeleton', $result['data']['skeleton']['build']['structure']);
    }

    // =========================================================================
    // saveSiteAsTemplate
    // =========================================================================

    public function testSaveSiteAsTemplateFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveSiteAsTemplate();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSaveSiteAsTemplateFailsWithMissingSiteName(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->saveSiteAsTemplate();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid site name', $result['__failed']['message']);
    }

    public function testSaveSiteAsTemplateFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveSiteAsTemplate();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    public function testSaveSiteAsTemplateFailsWhenSiteNotFound(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->haxcms->siteMissing = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveSiteAsTemplate();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Site does not exist', $result['__failed']['message']);
    }

    public function testSaveSiteAsTemplateHappyPathWritesSkeletonJson(): void
    {
        $this->buildMinimalSiteFixture($this->siteName);
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = true;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => $this->siteName));

        $result = $this->ops->saveSiteAsTemplate();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['saved']);
        $this->assertSame($this->siteName, $result['data']['name']);
        $this->assertSame($this->siteName . '.json', $result['data']['filename']);
        $this->assertSame(
            '/system/api/v1/skeletons/' . rawurlencode($this->siteName),
            $result['data']['link']
        );

        // Observable mutation: a real skeleton json file was written under
        // configDirectory/user/skeletons/. Independent read-back verifies the
        // persisted machineName.
        $targetPath = $this->configDir . '/user/skeletons/' . $this->siteName . '.json';
        $this->assertTrue(file_exists($targetPath));
        $written = json_decode(file_get_contents($targetPath), true);
        $this->assertIsArray($written);
        $this->assertSame($this->siteName, $written['meta']['machineName']);
    }
}

/**
 * HAXCMS collaborator mock subclass that returns a REAL HAXCMSSite (test
 * subclass) from loadSite, loaded from the temp sites tree under HAXCMS_ROOT.
 * Adds generateMachineName (needed by normalizeTemplateMachineName /
 * buildSiteTemplateSkeleton) and an outlineSchema + the published/system
 * request base properties the parent mock does not declare.
 */
class OperationsLifecycleTestHaxcms extends OperationsTestHaxcms
{
    public $publishedDirectory = '_published';
    public $systemRequestBase = 'system/api';
    public $outlineSchema;
    public $siteMissing = false;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function generateMachineName($name)
    {
        // Mirrors HAXCMS::generateMachineName exactly so machineName-derived
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

    public function loadSite($name, $create = false, $domain = null, $build = null)
    {
        if ($this->siteMissing) {
            return false;
        }
        $tmpname = $this->cleanTitle(urldecode((string) $name), false);
        $siteDir = HAXCMS_ROOT . '/' . $this->sitesDirectory . '/' . $tmpname;
        if ($create && !is_dir($siteDir)) {
            @mkdir($siteDir . '/pages', 0777, true);
            $manifest = (object)array(
                'id' => $this->generateUUID(),
                'title' => (string) $name,
                'author' => '',
                'description' => '',
                'license' => 'by-sa',
                'metadata' => (object)array('site' => (object)array('name' => $tmpname)),
                'items' => array(),
            );
            file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
        }
        $site = new OperationsLifecycleTestSite();
        $site->load(
            HAXCMS_ROOT . '/' . $this->sitesDirectory,
            $this->basePath . $this->sitesDirectory . '/',
            $tmpname
        );
        return $site;
    }
}

/**
 * HAXCMSSite test subclass that no-ops the git/twig collaborators while
 * keeping the real manifest load + JSONOutlineSchema::save. This lets the
 * lifecycle route mutation+persistence logic run for real against a temp
 * fixture without a git binary or twig template tree.
 */
class OperationsLifecycleTestSite extends HAXCMSSite
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
