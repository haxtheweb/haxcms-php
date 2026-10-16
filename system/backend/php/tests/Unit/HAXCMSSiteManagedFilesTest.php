<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for HAXCMSSite's managed-file rebuild lifecycle,
 * lib/HAXCMSSite.php: getManagedTemplateFiles, maybeUpgradeLegacyBootstrap,
 * rebuildManagedFiles, rebuildAgentSkills, recurseDelete (private, exercised
 * indirectly through rebuildAgentSkills).
 *
 * Unlike HAXCMSSiteMutationsTest, these tests do NOT stub out
 * rebuildManagedFiles/rebuildAgentSkills — they exercise the REAL
 * implementation against the REAL system/boilerplate/site fixture (symlinked
 * into HAXCMS_ROOT by tests/phpunit-bootstrap.php), so the actual template
 * copy + twig render + agent-skills sync run end to end. gitCommit and
 * updateAlternateFormats are stubbed since those collaborators are already
 * covered elsewhere (GitWrapperTest, RSSFeedTest) and are irrelevant to the
 * managed-file contract under test here.
 */
class HAXCMSSiteManagedFilesTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $tmpRoot;
    private $siteName = 'managedfilessite';
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_managed_files_' . uniqid();
        $this->buildSiteFixture($this->siteName);
        $this->haxcms = new HAXCMSManagedFilesTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = new HAXCMSManagedFilesTestSite();
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
        mkdir($dir . '/files', 0777, true);
        file_put_contents($dir . '/pages/item-page-1/index.html', '<p>Home content</p>');
        $manifest = (object) array(
            'id' => 'site-managed-files-uuid',
            'title' => 'Managed Files Test Site',
            'author' => 'Tester',
            'description' => 'A test site for managed file rebuilds',
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

    // ---- getManagedTemplateFiles ----

    public function testGetManagedTemplateFilesReturnsExpectedKeyedList(): void
    {
        $templates = $this->site->getManagedTemplateFiles();
        $this->assertIsArray($templates);
        // spot check a handful of entries across the categories documented
        // in the method (core/PWA, seo, pwa, local dev, SCORM, files hardening)
        $this->assertSame('.htaccess', $templates['htaccess']);
        $this->assertSame('index.html', $templates['index']);
        $this->assertSame('llms.txt', $templates['llms']);
        $this->assertSame('manifest.json', $templates['manifest']);
        $this->assertSame('service-worker.js', $templates['sw']);
        $this->assertSame('package.json', $templates['package']);
        $this->assertSame('imsmanifest.xml', $templates['imsmanifest']);
        $this->assertSame('files/.htaccess', $templates['fileshtaccess']);
        // indexphp/configphp are NOT part of the base list; rebuildManagedFiles
        // adds them only for a full rebuild call.
        $this->assertArrayNotHasKey('indexphp', $templates);
        $this->assertArrayNotHasKey('configphp', $templates);
    }

    // ---- rebuildManagedFiles (full rebuild) ----

    public function testRebuildManagedFilesFullRebuildCopiesAllTemplatesAndIndexConfig(): void
    {
        $this->site->rebuildManagedFiles();
        // base managed templates landed on disk
        $this->assertFileExists($this->siteDir() . '/.htaccess');
        $this->assertFileExists($this->siteDir() . '/build.js');
        $this->assertFileExists($this->siteDir() . '/index.html');
        $this->assertFileExists($this->siteDir() . '/404.html');
        $this->assertFileExists($this->siteDir() . '/robots.txt');
        $this->assertFileExists($this->siteDir() . '/llms.txt');
        $this->assertFileExists($this->siteDir() . '/manifest.json');
        $this->assertFileExists($this->siteDir() . '/service-worker.js');
        $this->assertFileExists($this->siteDir() . '/files/.htaccess');
        // full rebuild (no explicit $templates arg) additionally copies the
        // dynamic index.php/config.php pair, not part of getManagedTemplateFiles()
        $this->assertFileExists($this->siteDir() . '/index.php');
        $this->assertFileExists($this->siteDir() . '/config.php');
    }

    public function testRebuildManagedFilesRendersTwigTemplatesWithSiteTitle(): void
    {
        $this->site->rebuildManagedFiles();
        // manifest.json is twig-templated; {{ title }} should resolve to the
        // real site title, proving the real (non-stubbed) twig render ran.
        $manifestJson = file_get_contents($this->siteDir() . '/manifest.json');
        $this->assertStringContainsString('Managed Files Test Site', $manifestJson);
        $this->assertStringNotContainsString('{{', $manifestJson);
    }

    public function testRebuildManagedFilesFullRebuildTriggersAgentSkillsSync(): void
    {
        // Full rebuild (isFullRebuild = count($templates) === 0) invokes
        // rebuildAgentSkills(), which populates .well-known/agent-skills.
        $this->site->rebuildManagedFiles();
        $this->assertFileExists($this->siteDir() . '/.well-known/agent-skills/index.json');
        $this->assertFileExists($this->siteDir() . '/.well-known/agent-skills/hax-site-structure/SKILL.md');
    }

    public function testRebuildManagedFilesPartialRebuildOnlyWritesRequestedFiles(): void
    {
        // Partial rebuild: pass an explicit, non-empty $templates list.
        // isFullRebuild becomes false, so only 'sw' => service-worker.js is
        // written, and agent-skills sync (full-rebuild only) must NOT run.
        $this->site->rebuildManagedFiles(array('sw' => 'service-worker.js'));
        $this->assertFileExists($this->siteDir() . '/service-worker.js');
        $this->assertFileDoesNotExist($this->siteDir() . '/.htaccess');
        $this->assertFileDoesNotExist($this->siteDir() . '/robots.txt');
        $this->assertDirectoryDoesNotExist($this->siteDir() . '/.well-known/agent-skills');
    }

    public function testRebuildManagedFilesUpdatesLlmsAlternateFormatWhenLlmsTemplateIncluded(): void
    {
        // When 'llms.txt' is among the rendered templates, rebuildManagedFiles
        // calls $this->updateAlternateFormats('llms'). The test subclass
        // records whether/with-what that call happened instead of exercising
        // the real llms serialization path (covered elsewhere).
        $this->site->rebuildManagedFiles();
        $this->assertSame(array('llms'), $this->site->updateAlternateFormatsCalls);
    }

    public function testRebuildManagedFilesSkipsLlmsAlternateFormatWhenLlmsTemplateExcluded(): void
    {
        $this->site->rebuildManagedFiles(array('sw' => 'service-worker.js'));
        $this->assertSame(array(), $this->site->updateAlternateFormatsCalls);
    }

    // ---- maybeUpgradeLegacyBootstrap ----

    public function testMaybeUpgradeLegacyBootstrapNoopsWhenIndexHtmlMissing(): void
    {
        // index.html does not exist yet (fixture never wrote one); the
        // early-return branch must not throw and must not rebuild anything.
        $this->site->maybeUpgradeLegacyBootstrap();
        $this->assertFileDoesNotExist($this->siteDir() . '/.htaccess');
        $this->assertSame(0, $this->site->gitCommitCallCount);
    }

    public function testMaybeUpgradeLegacyBootstrapNoopsWhenNoLegacyMarkerPresent(): void
    {
        file_put_contents($this->siteDir() . '/index.html', '<html><head></head><body>modern</body></html>');
        $this->site->maybeUpgradeLegacyBootstrap();
        // no @lrnwebcomponents marker: no rebuild, no commit
        $this->assertFileDoesNotExist($this->siteDir() . '/.htaccess');
        $this->assertSame(0, $this->site->gitCommitCallCount);
    }

    public function testMaybeUpgradeLegacyBootstrapRebuildsAndCommitsWhenLegacyMarkerPresent(): void
    {
        file_put_contents(
            $this->siteDir() . '/index.html',
            '<html><head><script src="./node_modules/@lrnwebcomponents/foo/foo.js"></script></head></html>'
        );
        $this->site->maybeUpgradeLegacyBootstrap();
        // rebuildManagedFiles ran for real: the modern boilerplate index.html
        // (no @lrnwebcomponents marker) replaced the legacy one.
        $newIndex = file_get_contents($this->siteDir() . '/index.html');
        $this->assertStringNotContainsString('@lrnwebcomponents', $newIndex);
        $this->assertFileExists($this->siteDir() . '/.htaccess');
        $this->assertSame(1, $this->site->gitCommitCallCount);
        $this->assertSame('Managed files upgraded: legacy bootstrap', $this->site->lastGitCommitMsg);
    }

    public function testMaybeUpgradeLegacyBootstrapSwallowsExceptionsFromRebuild(): void
    {
        // Contract: maybeUpgradeLegacyBootstrap wraps its body in try/catch
        // and silently no-ops on any Exception from its collaborators.
        file_put_contents(
            $this->siteDir() . '/index.html',
            '<html><head><script src="@lrnwebcomponents/foo"></script></head></html>'
        );
        $this->site->throwOnRebuildManagedFiles = true;
        // Must not throw.
        $this->site->maybeUpgradeLegacyBootstrap();
        $this->assertSame(0, $this->site->gitCommitCallCount);
    }

    // ---- rebuildAgentSkills ----

    public function testRebuildAgentSkillsCopiesIndexAndSkillMarkdownFiles(): void
    {
        $this->site->rebuildAgentSkills();
        $siteAS = $this->siteDir() . '/.well-known/agent-skills';
        $this->assertFileExists($siteAS . '/index.json');
        $this->assertFileExists($siteAS . '/.htaccess');
        $this->assertFileExists($siteAS . '/hax-site-structure/SKILL.md');
        $this->assertFileExists($siteAS . '/hax-managed-files/SKILL.md');
        // auxiliary reference files (nested under references/) are also synced
        $this->assertFileExists($siteAS . '/hax-design-system/references/ddd-tokens.md');
    }

    public function testRebuildAgentSkillsIndexContentMatchesBoilerplateVerbatim(): void
    {
        $this->site->rebuildAgentSkills();
        $boilerIndex = HAXCMS_ROOT . '/system/boilerplate/site/.well-known/agent-skills/index.json';
        $siteIndex = $this->siteDir() . '/.well-known/agent-skills/index.json';
        $this->assertSame(file_get_contents($boilerIndex), file_get_contents($siteIndex));
    }

    public function testRebuildAgentSkillsCleansStaleSiteSkillsDirBeforeResync(): void
    {
        // Pre-seed a stale skill directory + file that is NOT part of the
        // current boilerplate index; recurseDelete (private, invoked via
        // rebuildAgentSkills) must remove it as part of the clean-then-copy cycle.
        $siteAS = $this->siteDir() . '/.well-known/agent-skills';
        mkdir($siteAS . '/stale-skill', 0777, true);
        file_put_contents($siteAS . '/stale-skill/SKILL.md', 'stale content');
        $this->site->rebuildAgentSkills();
        $this->assertFileDoesNotExist($siteAS . '/stale-skill/SKILL.md');
        $this->assertDirectoryDoesNotExist($siteAS . '/stale-skill');
        // fresh content from the boilerplate is still present
        $this->assertFileExists($siteAS . '/hax-site-structure/SKILL.md');
    }

    public function testRebuildAgentSkillsIsNoopWhenBoilerplateIndexMissing(): void
    {
        // Point HAXCMS_ROOT-relative boilerplate lookup at a site with no
        // agent-skills index by using a fresh HAXCMS_ROOT-less scenario is not
        // possible (HAXCMS_ROOT is process-global), so instead verify the
        // silent-no-op contract directly: temporarily rename the real index
        // out of the way, call rebuildAgentSkills, then restore it.
        $boilerIndex = HAXCMS_ROOT . '/system/boilerplate/site/.well-known/agent-skills/index.json';
        $backupPath = $boilerIndex . '.bak-test';
        $this->assertFileExists($boilerIndex, 'precondition: real boilerplate index must exist');
        rename($boilerIndex, $backupPath);
        try {
            $this->site->rebuildAgentSkills();
            $this->assertDirectoryDoesNotExist($this->siteDir() . '/.well-known/agent-skills');
        } finally {
            rename($backupPath, $boilerIndex);
        }
    }
}

/**
 * HAXCMSSite test subclass that no-ops gitCommit/updateAlternateFormats
 * (recording calls for assertions) while keeping the REAL
 * rebuildManagedFiles/rebuildAgentSkills/maybeUpgradeLegacyBootstrap/
 * recurseDelete implementations, so this test exercises the actual managed
 * file lifecycle end to end against the real system/boilerplate/site fixture.
 */
class HAXCMSManagedFilesTestSite extends HAXCMSSite
{
    public $gitCommitCallCount = 0;
    public $lastGitCommitMsg = null;
    public $updateAlternateFormatsCalls = array();
    public $throwOnRebuildManagedFiles = false;

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommitCallCount++;
        $this->lastGitCommitMsg = $msg;
        return true;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->updateAlternateFormatsCalls[] = $format;
        return null;
    }

    public function rebuildManagedFiles($templates = array())
    {
        if ($this->throwOnRebuildManagedFiles) {
            throw new Exception('forced failure for maybeUpgradeLegacyBootstrap test');
        }
        return parent::rebuildManagedFiles($templates);
    }
}

/**
 * HAXCMS collaborator mock providing the methods HAXCMSSite's managed-file
 * rebuild path reads: generateMachineName, staticCache (by-reference),
 * getDomain, getHAXCMSVersion (from OperationsTestHaxcms).
 */
class HAXCMSManagedFilesTestHaxcms extends OperationsTestHaxcms
{
    // rebuildManagedFiles() calls the real (un-stubbed) getSiteMetadata(),
    // which unconditionally reads/calls these collaborators on $GLOBALS['HAXCMS'].
    public $cdn = './';
    // getServiceWorkerScript() (invoked via getTemplateVars during rebuild)
    // reads this directly off $GLOBALS['HAXCMS'].
    public $developerMode = false;
    private $cacheData = array();

    public function getURI()
    {
        return null;
    }

    public function buildShellModulepreloadPaths($site, $base = './', $themePath = '', $cap = 12)
    {
        return array();
    }

    public function getContentTagPath($site, $base, $tag)
    {
        return false;
    }

    public function dispatchEvent($event, &$value = null)
    {
        return $value;
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
