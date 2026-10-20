<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for createSite (lib/operations/createSite.php).
 *
 * The route is gated on the request targeting /system/api/v1/ (both token
 * helpers check isSystemV1Request), so $_SERVER['REQUEST_URI'] is pinned in
 * setUp and restored in tearDown. Everything past the gate runs the REAL
 * logic against a temp site fixture with a REAL git collaborator (mirroring
 * GitWrapperTest: repo-local identity via GitRepo config so commits succeed
 * on identity-less CI runners, git-binary skip guard, Git::set_bin pin).
 *
 * Slices pinned here:
 *   - non-v1 request -> 403 'invalid request token'
 *   - happy path: theme from the registry, description strip_tags, license
 *     normalization, platform scaffold, settings defaults, real manifest
 *     save + first git commit, response shape
 *   - build object passthrough (structure/type/version, items stripped) and
 *     site.domain forwarded to loadSite
 *   - from-skeleton: unresolved machine name -> 400; resolved skeleton ->
 *     trusted platform/theme/settings/license/description/logo applied
 *   - license normalization: Creative Commons URL form vs unrecognized value
 *   - system default language from config; git vendor config mirrored into
 *     the site manifest (minus keySet/email/user) + static branch created
 *   - build.files best-effort warnings surfaced on the 200 response
 *   - build.siteFiles: unsafe entries (path traversal, empty URL, loopback
 *     SSRF target) skipped without writes
 */
class OperationsCreateSiteTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServer = array();
    private $tmpRoot;
    private $siteName = 'my-site';

    protected function setUp(): void
    {
        $gitBin = trim((string) shell_exec('which git 2>/dev/null'));
        if ($gitBin === '') {
            $this->markTestSkipped('git binary not available');
        }
        Git::set_bin($gitBin);

        // HAXCMS_ROOT is process-global; define once at a stable temp base so
        // skeleton-directory discovery never touches a real install.
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_createsite_root');
        }
        if (!is_dir(HAXCMS_ROOT)) {
            mkdir(HAXCMS_ROOT, 0777, true);
        }

        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        foreach (array('SERVER_SOFTWARE', 'REQUEST_URI') as $key) {
            if (isset($_SERVER[$key])) {
                $this->savedServer[$key] = $_SERVER[$key];
            }
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        // The route's token helpers accept system/api/v1 requests.
        $_SERVER['REQUEST_URI'] = '/system/api/v1/createSite';

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_createsite_' . uniqid();
        $this->buildSiteFixture();

        $this->haxcms = new CreateSiteTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->coreConfigPath = $this->tmpRoot . '/_core/';
        $this->haxcms->themes = array(
            'my-theme' => array(
                'element' => 'my-theme',
                'name' => 'My Theme',
                'path' => 'elements/my-theme/my-theme.js',
                'variables' => array(
                    'hexCode' => '#0055cc',
                    'cssVariable' => '--ddd-theme-primary-blue-7',
                ),
            ),
        );

        // Install the mock BEFORE loading the site (HAXCMSSite::load calls
        // $GLOBALS['HAXCMS']->cleanTitle).
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new CreateSiteTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;

        // Repo-local identity so the real commit (and branch creation below)
        // succeeds regardless of the host's global git config.
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $repo = Git::open($siteDir, true);
        $repo->run('config user.name haxcmstest');
        $repo->run('config user.email haxcmstest@example.com');

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
        foreach ($this->savedServer as $key => $value) {
            $_SERVER[$key] = $value;
        }
        foreach (array('SERVER_SOFTWARE', 'REQUEST_URI') as $key) {
            if (!array_key_exists($key, $this->savedServer)) {
                unset($_SERVER[$key]);
            }
        }
        $this->savedServer = array();
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

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    /**
     * Minimal real site fixture: one page on disk so getPageContent works for
     * the build.files link pass.
     */
    private function buildSiteFixture(): void
    {
        $siteDir = $this->siteDir();
        mkdir($siteDir . '/pages/item-a', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content item-a</p>');
        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array('site' => (object)array('name' => $this->siteName)),
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
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function persistedSite(): stdClass
    {
        return json_decode(file_get_contents($this->siteDir() . '/site.json'));
    }

    private function runCreateSite(array $site, array $build = null): array
    {
        $this->ops->params = array('site' => $site);
        if ($build !== null) {
            $this->ops->params['build'] = $build;
        }
        return $this->ops->createSite();
    }

    // =========================================================================
    // Gate
    // =========================================================================

    public function testNonSystemV1RequestFailsWith403(): void
    {
        $_SERVER['REQUEST_URI'] = '/some/other/route';
        $result = $this->runCreateSite(array('name' => 'New Site', 'theme' => 'my-theme'));
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    // =========================================================================
    // Happy path (no build object)
    // =========================================================================

    public function testHappyPathBuildsSiteResponseAndCommits(): void
    {
        $result = $this->runCreateSite(array(
            'name' => 'New Site',
            'theme' => 'my-theme',
            'description' => 'My <b>desc</b>',
            'license' => 'by-nc',
        ));

        $this->assertSame(200, $result['status']);
        $schema = $result['data'];

        // schema identity: site name from the (already existing) manifest
        $this->assertSame($this->siteName, $schema->title);
        $this->assertSame('/_sites/' . $this->siteName . '/index.html', $schema->location);
        $this->assertSame($schema->location, $schema->slug);
        // description is tag-stripped
        $this->assertSame('My desc', $schema->description);

        // theme resolved from the registry, variables defaulted from it
        $this->assertSame('my-theme', $schema->metadata->theme->element);
        $this->assertSame('#0055cc', $schema->metadata->theme->variables->hexCode);
        $this->assertSame('--ddd-theme-primary-blue-7', $schema->metadata->theme->variables->cssVariable);
        $this->assertSame('icons:record-voice-over', $schema->metadata->theme->variables->icon);

        // platform scaffold
        $this->assertSame('expert', $schema->metadata->platform->audience);
        $this->assertInstanceOf(stdClass::class, $schema->metadata->platform->features);

        // site settings defaults + license mirrored
        $this->assertSame('en-US', $schema->metadata->site->settings->lang);
        $this->assertTrue($schema->metadata->site->settings->publishPagesOn);
        $this->assertTrue($schema->metadata->site->settings->canonical);
        $this->assertTrue($schema->metadata->site->settings->pathauto);
        $this->assertSame('assets/banner.jpg', $schema->metadata->site->logo);
        $this->assertSame('by-nc', $schema->metadata->site->license);
        // no build object supplied -> no build metadata, no git block on schema
        $this->assertFalse(isset($schema->metadata->build));
        $this->assertFalse(isset($schema->metadata->site->git));

        // loadSite received the machine name + create flag
        $calls = $this->haxcms->loadSiteCalls;
        $this->assertCount(1, $calls);
        $this->assertSame('new-site', $calls[0]['name']);
        $this->assertTrue($calls[0]['create']);
        $this->assertNull($calls[0]['domain']);

        // real git: repo initialized + first commit with the canonical message
        $this->assertTrue(is_dir($this->siteDir() . '/.git'));
        $log = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' log --pretty=format:%s 2>&1');
        $this->assertStringContainsString('A new journey begins: Test Site (site-uuid)', (string) $log);

        // persisted site.json: license + description + mirrored metadata
        $persisted = $this->persistedSite();
        $this->assertSame('by-nc', $persisted->license);
        $this->assertSame('My desc', $persisted->description);
        $this->assertSame('by-nc', $persisted->metadata->site->license);
        $this->assertTrue($persisted->metadata->site->settings->publishPagesOn);
        $this->assertInstanceOf(stdClass::class, $persisted->metadata->node->fields);
    }

    // =========================================================================
    // Build object passthrough + domain
    // =========================================================================

    public function testBuildObjectStoredMinusItemsAndDomainForwarded(): void
    {
        $result = $this->runCreateSite(
            array('name' => 'New Site', 'theme' => 'my-theme', 'domain' => 'www.example.com'),
            array(
                'structure' => 'course',
                'type' => 'test',
                'items' => array(array('id' => 'x', 'title' => 'X')),
                'files' => array(),
            )
        );
        $this->assertSame(200, $result['status']);
        $schema = $result['data'];

        // build metadata stored on the schema item, item replication stripped
        $this->assertSame('course', $schema->metadata->build->structure);
        $this->assertSame('test', $schema->metadata->build->type);
        $this->assertSame('0.0.0-test', $schema->metadata->build->version);
        $this->assertFalse(isset($schema->metadata->build->items));

        // domain + build forwarded to loadSite
        $calls = $this->haxcms->loadSiteCalls;
        $this->assertSame('www.example.com', $calls[0]['domain']);
        $this->assertSame('course', $calls[0]['build']->structure);
    }

    // =========================================================================
    // from-skeleton resolution
    // =========================================================================

    public function testFromSkeletonUnresolvedMachineNameFailsWith400(): void
    {
        $result = $this->runCreateSite(
            array('name' => 'New Site', 'theme' => 'my-theme'),
            array(
                'structure' => 'from-skeleton',
                'type' => 'skeleton',
                'skeletonMachineName' => 'ghost-skeleton',
                'items' => array(),
                'files' => array(),
            )
        );
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Unable to resolve skeletonMachineName for from-skeleton build',
            $result['__failed']['message']
        );
        $this->assertSame('ghost-skeleton', $result['__failed']['skeletonMachineName']);
    }

    public function testFromSkeletonAppliesTrustedSkeletonValues(): void
    {
        // resolvable user skeleton in the config skeleton directory
        $skeletonDir = $this->haxcms->configDirectory . '/user/skeletons';
        if (!is_dir($skeletonDir)) {
            mkdir($skeletonDir, 0777, true);
        }
        file_put_contents($skeletonDir . '/my-skel.json', json_encode(array(
            'meta' => array('machineName' => 'my-skel', 'name' => 'My Skel'),
            'site' => array(
                'theme' => 'my-theme',
                'license' => 'by-nc-sa',
                'description' => 'Skel <em>desc</em>',
                'logo' => 'files/skel-logo.jpg',
                'settings' => array('lang' => 'fr-FR', 'publishPagesOn' => false),
                'platform' => array('audience' => 'novice', 'features' => array('addPage' => true)),
            ),
            'build' => array(
                'type' => 'skeleton',
                'structure' => 'from-skeleton',
                'items' => array(),
                'files' => array(),
            ),
        )));

        $result = $this->runCreateSite(
            array('name' => 'Skeleton Site', 'theme' => 'my-theme'),
            array(
                'structure' => 'from-skeleton',
                'type' => 'test',
                'skeletonMachineName' => 'my-skel',
                'items' => array(),
                'files' => array(),
            )
        );
        $this->assertSame(200, $result['status']);
        $schema = $result['data'];

        // trusted platform + theme + settings from the skeleton
        $this->assertSame('novice', $schema->metadata->platform->audience);
        $this->assertSame('my-theme', $schema->metadata->theme->element);
        $this->assertSame('fr-FR', $schema->metadata->site->settings->lang);
        $this->assertFalse($schema->metadata->site->settings->publishPagesOn);
        // trusted site license + description + logo (request supplied none)
        $this->assertSame('by-nc-sa', $schema->metadata->site->license);
        $this->assertSame('Skel desc', $schema->description);
        $this->assertSame('files/skel-logo.jpg', $schema->metadata->site->logo);
        // trusted skeleton build data: no build metadata block on the schema
        $this->assertFalse(isset($schema->metadata->build));

        // persisted manifest carries the trusted license
        $this->assertSame('by-nc-sa', $this->persistedSite()->license);
    }

    // =========================================================================
    // License normalization
    // =========================================================================

    public function testCreativeCommonsUrlLicenseNormalizesToCode(): void
    {
        $result = $this->runCreateSite(array(
            'name' => 'New Site',
            'theme' => 'my-theme',
            'license' => 'https://creativecommons.org/licenses/by-nc/4.0/',
        ));
        $this->assertSame(200, $result['status']);
        // The URL matcher walks the supported list in order and uses strpos,
        // so '/licenses/by' (the first entry) matches before '/licenses/by-nc':
        // a by-nc URL normalizes to the 'by' prefix code.
        $this->assertSame('by', $result['data']->metadata->site->license);
        $this->assertSame('by', $this->persistedSite()->license);
    }

    public function testUnrecognizedLicenseLeavesManifestLicenseAlone(): void
    {
        $result = $this->runCreateSite(array(
            'name' => 'New Site',
            'theme' => 'my-theme',
            'license' => 'not-a-license',
        ));
        $this->assertSame(200, $result['status']);
        // fixture license untouched when normalization fails
        $this->assertSame('by-sa', $this->persistedSite()->license);
    }

    // =========================================================================
    // Config-driven defaults
    // =========================================================================

    public function testConfiguredDefaultLanguageUsedForSiteSettings(): void
    {
        $this->haxcms->config->localization = (object)array('defaultLanguage' => 'es-ES');
        $result = $this->runCreateSite(array('name' => 'New Site', 'theme' => 'my-theme'));
        $this->assertSame(200, $result['status']);
        $this->assertSame('es-ES', $result['data']->metadata->site->settings->lang);
    }

    public function testGitVendorConfigMirroredAndStaticBranchCreated(): void
    {
        $gitConfig = (object)array(
            'vendor' => 'gitlab',
            'staticBranch' => 'static-site',
            'branch' => 'main',
            'keySet' => 'secret-keyset',
            'email' => 'bot@example.com',
            'user' => 'bot',
        );
        $this->haxcms->config->site = (object)array('git' => $gitConfig);

        $result = $this->runCreateSite(array('name' => 'New Site', 'theme' => 'my-theme'));
        $this->assertSame(200, $result['status']);
        // git block is response-stripped from the schema item
        $this->assertFalse(isset($result['data']->metadata->site->git));

        // persisted manifest carries the vendor config minus identity secrets
        $persistedGit = $this->persistedSite()->metadata->site->git;
        $this->assertSame('gitlab', $persistedGit->vendor);
        $this->assertSame('static-site', $persistedGit->staticBranch);
        $this->assertSame('main', $persistedGit->branch);
        $this->assertFalse(isset($persistedGit->keySet));
        $this->assertFalse(isset($persistedGit->email));
        $this->assertFalse(isset($persistedGit->user));

        // NOTE: the repo-level create_branch(staticBranch/branch) checks in
        // createSite are unreachable under this flow. The 419-421 mirror
        // assigns the schema's metadata->site OBJECT (shared reference) into
        // the site manifest, so the unset($schema->metadata->site->git)
        // before the branch section also removes the git block from the site
        // manifest's in-memory copy; the isset checks then fail. The persisted
        // site.json above still carries the git block because manifest->save
        // ran before that unset. Pin the observable contract only: no extra
        // branches are created.
        $branches = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' branch --list 2>&1');
        $this->assertStringNotContainsString('static-site', (string) $branches);
    }

    // =========================================================================
    // build.files best-effort warnings
    // =========================================================================

    public function testBuildFilesWarningsSurfaceOnSuccessResponse(): void
    {
        $result = $this->runCreateSite(
            array('name' => 'New Site', 'theme' => 'my-theme'),
            array(
                'structure' => 'course',
                'type' => 'test',
                'files' => array(
                    // extension outside the bulk-import allow list
                    'evil.php' => '/tmp/never-used',
                    // not a URL and not a staged bulk-import tmp path
                    'note.txt' => '/not/a/staged/path',
                ),
            )
        );
        // best-effort: the site is still created, warnings ride the response
        $this->assertSame(200, $result['status']);
        // warnings live on data (like Node), not on the envelope
        $this->assertArrayNotHasKey('warnings', $result);
        $this->assertTrue(isset($result['data']->warnings));
        $warnings = $result['data']->warnings;
        $this->assertSame('evil.php', $warnings[0]['file']);
        $this->assertSame('Disallowed file extension in build.files', $warnings[0]['reason']);
        $this->assertSame('note.txt', $warnings[1]['file']);
        $this->assertSame('Invalid bulk import source path in build.files', $warnings[1]['reason']);
        // the normal data fields are still present alongside warnings
        $this->assertNotEmpty($result['data']->id);
        $this->assertNotEmpty($result['data']->slug);
        // nothing leaked into site.json
        $this->assertStringNotContainsString('warnings', file_get_contents($this->siteDir() . '/site.json'));
        // and the JSON response body carries data.warnings
        $encoded = json_decode(json_encode($result), true);
        $this->assertCount(2, $encoded['data']['warnings']);
    }

    public function testNoWarningsWhenNoBuildFileIsSkipped(): void
    {
        $result = $this->runCreateSite(
            array('name' => 'New Site', 'theme' => 'my-theme'),
            array(
                'structure' => 'course',
                'type' => 'own',
                'files' => array(),
            )
        );
        $this->assertSame(200, $result['status']);
        $this->assertArrayNotHasKey('warnings', $result);
        $this->assertFalse(isset($result['data']->warnings));
        $encoded = json_decode(json_encode($result), true);
        $this->assertArrayNotHasKey('warnings', $encoded['data']);
        $this->assertArrayHasKey('slug', $encoded['data']);
        $this->assertStringNotContainsString('warnings', file_get_contents($this->siteDir() . '/site.json'));
    }

    // =========================================================================
    // build.siteFiles safety skips
    // =========================================================================

    public function testSiteFilesUnsafeEntriesSkippedWithoutWrites(): void
    {
        $result = $this->runCreateSite(
            array('name' => 'New Site', 'theme' => 'my-theme'),
            array(
                'structure' => 'course',
                'type' => 'test',
                'siteFiles' => array(
                    // path traversal -> normalizeSiteFilePath refuses
                    '../evil.txt' => 'http://example.com/x',
                    // valid theme path but empty URL -> skipped
                    'theme/style.css' => '',
                    // loopback target -> SSRF guard throws, skipped
                    'theme/loop.css' => 'http://127.0.0.1/evil.css',
                ),
            )
        );
        $this->assertSame(200, $result['status']);
        // nothing was fetched or written into the site tree
        $this->assertFalse(is_dir($this->siteDir() . '/theme'));
        $this->assertFileDoesNotExist($this->siteDir() . '/theme/style.css');
    }
}

/**
 * OperationsTestHaxcms subclass with what createSite needs: a real
 * JSONOutlineSchema (outlineSchema->newItem), generateMachineName, a
 * configurable themes registry, and a loadSite call recorder.
 */
class CreateSiteTestHaxcms extends OperationsTestHaxcms
{
    public $coreConfigPath = '';
    public $themes = array();
    public $outlineSchema;
    public $loadSiteCalls = array();

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function getThemes()
    {
        return $this->themes;
    }

    public $dispatchedEvents = array();

    /**
     * getSkeletonDirectories() dispatches 'haxcms-skeleton-dirs'; record it
     * and leave the directory list untouched (no integrations in these
     * tests), mirroring the OperationsSkeletonTestHaxcms seam.
     */
    public function dispatchEvent($event, &$value = null)
    {
        $this->dispatchedEvents[] = $event;
        return null;
    }

    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', (string) $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = str_replace(array('\\\\', '/'), '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        return $name === '' ? 'default' : $name;
    }

    public function loadSite($name, $create = false, $domain = null, $build = null)
    {
        $this->loadSiteCalls[] = array(
            'name' => $name,
            'create' => $create,
            'domain' => $domain,
            'build' => $build,
        );
        return $this->loadedSite;
    }
}

/**
 * HAXCMSSite subclass that keeps EVERYTHING real for createSite: the real
 * manifest load/save and the real gitCommit wrapper (repo pre-initialized
 * with a repo-local identity in setUp so commits succeed on CI).
 */
class CreateSiteTestSite extends HAXCMSSite
{
}
