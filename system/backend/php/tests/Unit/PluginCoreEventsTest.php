<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/PluginOutlineHooksTest.php';
require_once __DIR__ . '/../../lib/plugins/HAXCMSPluginSystem.php';

/**
 * DRAFT plugin platform: files, site lifecycle, sign-in and git commits fire
 * their hooks from core (spec/06). Mirrors "files, site lifecycle, sign-in
 * and git commits fire their hooks" in haxcms-nodejs
 * test/unit/plugin-platform.test.cjs.
 *
 * A before hook can refuse, an after hook is told, and with plugins off
 * nothing fires. The fixture (fx-core-events) logs every hook and vetoes a
 * subject whose name, title or message contains "forbidden".
 */
class PluginCoreEventsTest extends TestCase
{
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'plugin-events';
    private $spec;

    protected function setUp(): void
    {
        $this->spec = HAXCMSPluginEngine::defaultSpecDir();
        if (!is_dir($this->spec . '/conformance/fixtures/fx-core-events')) {
            $this->markTestSkipped('plugin spec with fx-core-events not found (set HAXCMS_PLUGIN_SPEC_DIR)');
        }
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $this->fixtureDirName = 'fixtures_plugin_events_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        putenv('HAXCMS_PLUGINS');
        putenv('HAXCMS_PLUGIN_DIRS=' . $this->spec . '/conformance/fixtures/fx-core-events');
        HAXCMSPluginSystem::reset();
    }

    protected function tearDown(): void
    {
        putenv('HAXCMS_PLUGINS');
        putenv('HAXCMS_PLUGIN_DIRS');
        foreach (array('AUTHOR', 'COMMITTER') as $k) {
            putenv('GIT_' . $k . '_NAME');
            putenv('GIT_' . $k . '_EMAIL');
        }
        HAXCMSPluginSystem::reset();
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
        if ($this->tmpRoot && is_dir($this->tmpRoot)) {
            $this->rrmdir($this->tmpRoot);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** A site on disk with two files; $realGit keeps HAXCMSSite's own gitCommit. */
    private function buildSite(bool $realGit = false)
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/a', 0777, true);
        mkdir($siteDir . '/files', 0777, true);
        file_put_contents($siteDir . '/pages/a/index.html', '<p>A</p>');
        file_put_contents($siteDir . '/files/plain.txt', 'data');
        file_put_contents($siteDir . '/files/forbidden.txt', 'data');
        file_put_contents($siteDir . '/site.json', json_encode((object) array(
            'id' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4d',
            'title' => 'Events',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array('name' => $this->siteName, 'settings' => (object) array('pathauto' => false)),
                'platform' => (object) array('features' => (object) array()),
                'theme' => (object) array('element' => 'clean-one'),
            ),
            'items' => array((object) array(
                'id' => 'a', 'indent' => 0, 'location' => 'pages/a/index.html', 'slug' => 'a', 'order' => 0,
                'parent' => null, 'title' => 'A', 'description' => '', 'metadata' => (object) array(),
            )),
        ), JSON_PRETTY_PRINT));
        $haxcms = new PluginOutlineTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        @mkdir($haxcms->configDirectory, 0777, true);
        $haxcms->validRequestToken = true;
        $haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $haxcms;
        $site = $realGit ? new PluginEventsGitSite() : new PluginOutlineTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $haxcms->loadedSite = $site;
        $this->ops = new Operations();
        return $site;
    }

    private function heard($site, string $hook): int
    {
        $h = $site ? HAXCMSPluginSystem::host($site) : HAXCMSPluginSystem::systemHost();
        $n = 0;
        foreach ($h->log as $l) {
            if (($l['message'] ?? '') === 'fx-core-events:' . $hook) {
                $n++;
            }
        }
        return $n;
    }

    private function fileOp(string $operation, string $path, array $extra = array())
    {
        $this->ops->params = array_merge(array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => $operation,
            'path' => $path,
        ), $extra);
        return $this->ops->fileOperation();
    }

    public function testAFileOperationFiresTheFileHooksAndAVetoLeavesTheFileAlone(): void
    {
        $site = $this->buildSite();
        $dir = $this->tmpRoot . '/' . $this->siteName;
        $no = $this->fileOp('delete', 'files/forbidden.txt');
        $this->assertSame(409, $no['__failed']['status'] ?? null, json_encode($no));
        $this->assertSame('fx-core-events: forbidden', $no['__failed']['message']);
        $this->assertFileExists($dir . '/files/forbidden.txt');
        $this->assertSame(0, $this->heard($site, 'file.after.delete'));

        $ok = $this->fileOp('delete', 'files/plain.txt');
        $this->assertArrayNotHasKey('__failed', $ok, json_encode($ok));
        $this->assertFileDoesNotExist($dir . '/files/plain.txt');
        $this->assertSame(2, $this->heard($site, 'file.before.delete'));
        $this->assertSame(1, $this->heard($site, 'file.after.delete'));

        // a change to a file that stays is a save
        file_put_contents($dir . '/files/before.txt', 'data');
        $renamed = $this->fileOp('rename', 'files/before.txt', array('newName' => 'after'));
        $this->assertArrayNotHasKey('__failed', $renamed, json_encode($renamed));
        $this->assertSame(1, $this->heard($site, 'file.before.save'));
        $this->assertSame(1, $this->heard($site, 'file.after.save'));
    }

    public function testAFailedFileOperationFiresNoAfterHook(): void
    {
        $site = $this->buildSite();
        $missing = $this->fileOp('delete', 'files/not-there.txt');
        $this->assertArrayHasKey('__failed', $missing);
        $this->assertSame(0, $this->heard($site, 'file.before.delete'));
        $this->assertSame(0, $this->heard($site, 'file.after.delete'));
    }

    public function testAGitCommitFiresItsHooksAndAVetoLeavesTheWorkUncommitted(): void
    {
        foreach (array('AUTHOR', 'COMMITTER') as $k) {
            putenv('GIT_' . $k . '_NAME=Test');
            putenv('GIT_' . $k . '_EMAIL=test@example.invalid');
        }
        $site = $this->buildSite(true);
        $dir = $this->tmpRoot . '/' . $this->siteName;
        $commits = function () use ($dir) {
            return (int) trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-list --count HEAD 2>/dev/null'));
        };
        $site->gitCommit('Page added');
        $this->assertSame(1, $commits());
        $this->assertSame(1, $this->heard($site, 'git.before.commit'));
        $this->assertSame(1, $this->heard($site, 'git.after.commit'));
        file_put_contents($dir . '/files/more.txt', 'more');
        $site->gitCommit('forbidden');
        $this->assertSame(1, $commits(), 'a vetoed commit was made');
        $this->assertSame(2, $this->heard($site, 'git.before.commit'));
        $this->assertSame(1, $this->heard($site, 'git.after.commit'));
    }

    public function testSiteEventsReachTheSiteEngineAndSystemEventsTheSystemEngine(): void
    {
        $site = $this->buildSite();
        $summary = HAXCMSPluginSystem::siteSummary($site);
        $this->assertSame('plugin-events', $summary['name']);
        $this->assertSame('Events', $summary['title']);
        $this->assertSame('clean-one', $summary['theme']);
        $this->assertNull(HAXCMSPluginSystem::event($site, 'site.before.export', $summary, array('extra' => array('format' => 'zip')))['veto']);
        $this->assertSame(1, $this->heard($site, 'site.before.export'));
        $this->assertSame(0, $this->heard(null, 'site.before.export'));

        $this->assertNull(HAXCMSPluginSystem::event(null, 'site.before.create', array('name' => 'fine', 'title' => 'Fine'))['veto']);
        $refused = HAXCMSPluginSystem::event(null, 'site.before.create', array('name' => 'forbidden', 'title' => 'No'));
        $this->assertSame('fx-core-events: forbidden', $refused['veto']['message']);
        $this->assertSame(2, $this->heard(null, 'site.before.create'));
        // an after hook is told and cannot refuse
        $this->assertNull(HAXCMSPluginSystem::event(null, 'site.after.archive', array('name' => 'forbidden'))['veto']);
        $this->assertSame(1, $this->heard(null, 'site.after.archive'));
    }

    public function testSignOutEndsTheSessionAndThenTellsPlugins(): void
    {
        $this->buildSite();
        $GLOBALS['HAXCMS']->revoked = array();
        $_COOKIE['haxcms_refresh_token'] = 'r';
        try {
            $out = $this->ops->logout();
        } finally {
            unset($_COOKIE['haxcms_refresh_token']);
        }
        $this->assertSame('loggedout', $out['data']);
        $this->assertSame(array('ada'), $GLOBALS['HAXCMS']->revoked);
        $this->assertSame(1, $this->heard(null, 'user.after.logout'));
    }

    public function testTheLogReachesALogSinkFromPluginsAndFromCore(): void
    {
        $this->buildSite();
        $got = function () {
            $out = array();
            foreach (HAXCMSPluginSystem::systemHost()->log as $l) {
                if (strpos((string) ($l['message'] ?? ''), 'fx-log-sink got') === 0) {
                    $out[] = $l['message'];
                }
            }
            return $out;
        };
        // no sink on: the entry is taken and goes nowhere
        $this->assertTrue(HAXCMSPluginSystem::log(null, 'warn', 'nobody is listening'));
        $this->assertSame(array(), $got());

        putenv('HAXCMS_PLUGIN_DIRS=' . implode(PATH_SEPARATOR, array(
            $this->spec . '/conformance/fixtures/fx-core-events',
            $this->spec . '/conformance/fixtures/fx-log-sink',
        )));
        HAXCMSPluginSystem::reset();
        $this->assertTrue(HAXCMSPluginSystem::log(null, 'error', 'site.json could not be written'));
        HAXCMSPluginSystem::event(null, 'site.before.create', array('name' => 'logged'));
        $lines = $got();
        $this->assertContains('fx-log-sink got [error] core: site.json could not be written', $lines);
        $this->assertContains('fx-log-sink got [info] fx-core-events: fx-core-events:site.before.create', $lines);
        foreach ($lines as $l) {
            $this->assertStringNotContainsString('] fx-log-sink:', $l, 'the sink received its own entry');
        }

        putenv('HAXCMS_PLUGINS=off');
        HAXCMSPluginSystem::reset();
        $this->assertFalse(HAXCMSPluginSystem::log(null, 'info', 'x'));
    }

    public function testWithPluginsOffNothingFiresAndNothingIsRefused(): void
    {
        $this->buildSite();
        putenv('HAXCMS_PLUGINS=off');
        HAXCMSPluginSystem::reset();
        $this->assertNull(HAXCMSPluginSystem::event(null, 'site.before.create', array('name' => 'forbidden'))['veto']);
        $ok = $this->fileOp('delete', 'files/forbidden.txt');
        $this->assertArrayNotHasKey('__failed', $ok, json_encode($ok));
    }
}

/** HAXCMSSite with its own gitCommit; the generated-file rebuilds are stubbed */
class PluginEventsGitSite extends HAXCMSSite
{
    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }
}
