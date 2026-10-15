<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for HAXCMSSite's git wrapper methods
 * (gitCommit, gitFallbackIdentity, gitRevert, gitPush, gitSetRemote),
 * lib/HAXCMSSite.php:663-756.
 *
 * Unlike every other HAXCMSSite test in this suite, these tests do NOT stub
 * out the git collaborator — they exercise the REAL git wrapper methods
 * against a real temp git repository created in sys_get_temp_dir(), mirroring
 * the pattern established in GitRepoTest.php (git-binary-presence skip,
 * Git::set_bin pinning). None of the five methods under test reference
 * $GLOBALS['HAXCMS'], so no HAXCMS mock is required here.
 *
 * Expected values are derived from the git CLI's own output (log/show/remote)
 * read back independently, not by re-deriving them from the implementation.
 */
class GitWrapperTest extends TestCase
{
    private $tmpRoot;
    private $siteName = 'gitwrappersite';
    private $savedHome;
    private $savedNoSystem;

    protected function setUp(): void
    {
        $gitBin = trim((string) shell_exec('which git 2>/dev/null'));
        if ($gitBin === '') {
            $this->markTestSkipped('git binary not available');
        }
        Git::set_bin($gitBin);
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_gitwrapper_' . uniqid();
        mkdir($this->tmpRoot, 0777, true);
        $this->savedHome = getenv('HOME');
        $this->savedNoSystem = getenv('GIT_CONFIG_NOSYSTEM');
    }

    protected function tearDown(): void
    {
        if ($this->savedHome !== false) {
            putenv('HOME=' . $this->savedHome);
        } else {
            putenv('HOME');
        }
        if ($this->savedNoSystem !== false) {
            putenv('GIT_CONFIG_NOSYSTEM=' . $this->savedNoSystem);
        } else {
            putenv('GIT_CONFIG_NOSYSTEM');
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

    /**
     * Build a minimal HAXCMSSite whose only requirements for the git wrapper
     * methods are satisfied: ->directory and ->manifest->metadata->site->name.
     * Neither property needs to be a real JSONOutlineSchema for these methods.
     */
    private function buildSite(): HAXCMSSite
    {
        $site = new HAXCMSSite();
        $site->directory = $this->tmpRoot;
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = $this->siteName;
        return $site;
    }

    /**
     * Isolate the process from any discoverable git identity (global/system
     * config) so gitFallbackIdentity()'s "no identity configured" branch is
     * actually reachable, regardless of the host's ~/.gitconfig.
     */
    private function suppressAmbientGitIdentity(): void
    {
        $fakeHome = sys_get_temp_dir() . '/haxcms_gitwrapper_fakehome_' . uniqid();
        @mkdir($fakeHome, 0777, true);
        putenv('HOME=' . $fakeHome);
        putenv('GIT_CONFIG_NOSYSTEM=1');
    }

    // ---- gitCommit ----

    public function testGitCommitCreatesRealCommitWithStagedFile(): void
    {
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/page.html', '<p>hello</p>');
        $site = $this->buildSite();

        $result = $site->gitCommit('my test commit message');

        $this->assertTrue($result);
        $this->assertTrue(is_dir($this->siteDir() . '/.git'));
        $log = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' log --pretty=format:%s 2>&1');
        $this->assertStringContainsString('my test commit message', $log);
    }

    public function testGitCommitInitializesRepoWhenNoneExists(): void
    {
        // gitCommit opens with $create_new = true, so a fresh (non-git)
        // directory becomes a git repo as a side effect of the first commit.
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/page.html', 'content');
        $this->assertFalse(is_dir($this->siteDir() . '/.git'));

        $site = $this->buildSite();
        $site->gitCommit('init via commit');

        $this->assertTrue(is_dir($this->siteDir() . '/.git'));
    }

    public function testGitCommitStagesAllChangesIncludingNewFiles(): void
    {
        // gitCommit calls $repo->add('.') before committing, so untracked
        // files are included even without an explicit add call from the caller.
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/first.html', 'first');
        $site = $this->buildSite();
        $site->gitCommit('first commit');

        file_put_contents($this->siteDir() . '/second.html', 'second');
        $site->gitCommit('second commit');

        $log = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' log --pretty=format:%s 2>&1');
        $this->assertStringContainsString('first commit', $log);
        $this->assertStringContainsString('second commit', $log);
        $status = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' status --porcelain 2>&1');
        $this->assertSame('', trim((string) $status));
    }

    // ---- gitFallbackIdentity ----

    public function testGitFallbackIdentityReturnsEmptyArrayWhenIdentityAlreadyConfigured(): void
    {
        mkdir($this->siteDir(), 0777, true);
        $site = $this->buildSite();
        $repo = Git::open($this->siteDir(), true);
        $repo->run('config user.email identity@example.com');
        $repo->run('config user.name "Identity Already Set"');

        $keys = $site->gitFallbackIdentity($repo);

        $this->assertSame(array(), $keys);
    }

    public function testGitFallbackIdentitySuppliesPlaceholderWhenNoneConfigured(): void
    {
        $this->suppressAmbientGitIdentity();
        mkdir($this->siteDir(), 0777, true);
        $site = $this->buildSite();
        $repo = Git::open($this->siteDir(), true);

        $keys = $site->gitFallbackIdentity($repo);

        sort($keys);
        $this->assertSame(
            array('GIT_AUTHOR_EMAIL', 'GIT_AUTHOR_NAME', 'GIT_COMMITTER_EMAIL', 'GIT_COMMITTER_NAME'),
            $keys
        );
    }

    public function testGitCommitWithFallbackFlagCommitsAsHaxcmsPlaceholderIdentity(): void
    {
        $this->suppressAmbientGitIdentity();
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/page.html', 'content');
        $site = $this->buildSite();
        $site->gitIdentityFallback = true;

        $result = $site->gitCommit('commit with fallback identity');

        $this->assertTrue($result);
        $author = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' log -1 --pretty=format:"%an <%ae>" 2>&1');
        $this->assertStringContainsString('HAXcms <haxcms@localhost>', $author);
    }

    public function testGitCommitWithoutFallbackFlagFailsWhenNoIdentityConfigured(): void
    {
        // Without gitIdentityFallback set, gitFallbackIdentity() is never
        // invoked, so a repo with no discoverable identity has no author to
        // commit with. git itself still swallows the failure per the
        // resilient-default contract (GitRepo::run_command's $skip_fail
        // default), so gitCommit() still returns true, but no commit is
        // actually created.
        $this->suppressAmbientGitIdentity();
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/page.html', 'content');
        $site = $this->buildSite();

        $result = $site->gitCommit('should not succeed without identity');

        $this->assertTrue($result);
        $log = trim((string) shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' log --oneline 2>/dev/null'));
        $this->assertSame('', $log);
    }

    // ---- gitRevert ----

    public function testGitRevertRewindsLastCommit(): void
    {
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/page.html', 'v1');
        $site = $this->buildSite();
        $site->gitCommit('first commit');
        $repo = Git::open($this->siteDir(), true);
        $firstSHA = $repo->currentSHA();

        file_put_contents($this->siteDir() . '/page.html', 'v2');
        $site->gitCommit('second commit');
        $this->assertNotSame($firstSHA, $repo->currentSHA());

        $result = $site->gitRevert(1);

        $this->assertTrue($result);
        $this->assertSame($firstSHA, $repo->currentSHA());
        $this->assertSame('v1', file_get_contents($this->siteDir() . '/page.html'));
    }

    // ---- gitPush ----

    public function testGitPushAddsAndCommitsWithForcedMessage(): void
    {
        mkdir($this->siteDir(), 0777, true);
        file_put_contents($this->siteDir() . '/page.html', 'content');
        $site = $this->buildSite();

        $result = $site->gitPush();

        $this->assertTrue($result);
        $log = shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' log --pretty=format:%s 2>&1');
        $this->assertStringContainsString('commit forced', $log);
    }

    // ---- gitSetRemote ----

    public function testGitSetRemoteConfiguresOriginUrl(): void
    {
        mkdir($this->siteDir(), 0777, true);
        $site = $this->buildSite();
        $gitDetails = (object) array('url' => 'https://example.com/some/repo.git');

        $result = $site->gitSetRemote($gitDetails);

        $this->assertTrue($result);
        $remoteUrl = trim((string) shell_exec(
            'git -C ' . escapeshellarg($this->siteDir()) . ' remote get-url origin 2>&1'
        ));
        $this->assertSame('https://example.com/some/repo.git', $remoteUrl);
    }
}
