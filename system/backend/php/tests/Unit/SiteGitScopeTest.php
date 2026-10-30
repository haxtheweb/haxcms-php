<?php
use PHPUnit\Framework\TestCase;

/**
 * haxtheweb/issues#3201: a HAXsite kept inside a larger git repo (its own .git
 * removed so the parent repo tracks it) leaves versioning to that repo.
 * Previously Git::open($siteDir, true) ran `git init` in the site folder,
 * creating a nested repo the parent then tracked as a gitlink.
 *
 * Real git in temp folders, like GitWrapperTest. Mirrors
 * test/unit/site-git-scope.test.cjs in haxcms-nodejs.
 */
class SiteGitScopeTest extends TestCase
{
    private $tmpRoot;
    private $siteName = 'mysite';

    protected function setUp(): void
    {
        $gitBin = trim((string) shell_exec('which git 2>/dev/null'));
        if ($gitBin === '') {
            $this->markTestSkipped('git binary not available');
        }
        Git::set_bin($gitBin);
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_gitscope_' . uniqid();
        mkdir($this->tmpRoot . '/' . $this->siteName, 0777, true);
        file_put_contents($this->tmpRoot . '/' . $this->siteName . '/site.json', "{}\n");
    }

    protected function tearDown(): void
    {
        if ($this->tmpRoot && is_dir($this->tmpRoot)) {
            shell_exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        }
    }

    private function git(string $dir, string $args): string
    {
        return trim((string) shell_exec(
            'git -C ' . escapeshellarg($dir) .
            ' -c user.name=haxcmstest -c user.email=haxcmstest@example.com ' .
            $args . ' 2>&1'
        ));
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

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

    /** make tmpRoot a repo with one commit; the site folder has no .git */
    private function makeParentRepo(): string
    {
        file_put_contents($this->tmpRoot . '/README.md', "parent\n");
        $this->git($this->tmpRoot, 'init -q');
        $this->git($this->tmpRoot, 'add README.md');
        $this->git($this->tmpRoot, 'commit -q -m start');
        return $this->git($this->tmpRoot, 'rev-parse HEAD');
    }

    public function testScopeOwnParentNone(): void
    {
        $site = $this->buildSite();
        $this->assertSame('none', $site->gitScope());
        $this->makeParentRepo();
        $this->assertSame('parent', $site->gitScope());
        mkdir($this->siteDir() . '/.git');
        $this->assertSame('own', $site->gitScope());
        rmdir($this->siteDir() . '/.git');
        file_put_contents($this->siteDir() . '/.git', "gitdir: /somewhere/else\n");
        $this->assertSame('own', $site->gitScope());
    }

    public function testGitCommitLeavesParentRepoAloneAndMakesNoNestedRepo(): void
    {
        $head = $this->makeParentRepo();
        file_put_contents($this->siteDir() . '/page.html', '<p>new</p>');
        $site = $this->buildSite();

        $this->assertTrue($site->gitCommit('Page saved'));

        $this->assertSame($head, $this->git($this->tmpRoot, 'rev-parse HEAD'));
        $this->assertFalse(file_exists($this->siteDir() . '/.git'), 'no nested repo');
        $this->assertStringContainsString('mysite/', $this->git($this->tmpRoot, 'status --porcelain'));
    }

    public function testGitRevertNeverResetsParentRepo(): void
    {
        $this->makeParentRepo();
        file_put_contents($this->tmpRoot . '/README.md', "parent, second\n");
        $this->git($this->tmpRoot, 'commit -q -am second');
        $head = $this->git($this->tmpRoot, 'rev-parse HEAD');
        $site = $this->buildSite();

        $this->assertTrue($site->gitRevert(1));

        $this->assertSame($head, $this->git($this->tmpRoot, 'rev-parse HEAD'));
        $this->assertSame("parent, second\n", file_get_contents($this->tmpRoot . '/README.md'));
    }

    public function testGitPushAndSetRemoteLeaveParentRepoAlone(): void
    {
        $head = $this->makeParentRepo();
        file_put_contents($this->siteDir() . '/page.html', '<p>new</p>');
        $site = $this->buildSite();
        $details = new stdClass();
        $details->url = 'https://git.example.test/x/y.git';

        $this->assertTrue($site->gitPush());
        $this->assertFalse($site->gitSetRemote($details));

        $this->assertSame($head, $this->git($this->tmpRoot, 'rev-parse HEAD'));
        $this->assertSame('', $this->git($this->tmpRoot, 'remote'));
        $this->assertFalse(file_exists($this->siteDir() . '/.git'));
    }

    public function testSiteWithItsOwnRepoStillCommits(): void
    {
        $this->makeParentRepo();
        $this->git($this->siteDir(), 'init -q');
        $this->git($this->siteDir(), 'config user.name haxcmstest');
        $this->git($this->siteDir(), 'config user.email haxcmstest@example.com');
        file_put_contents($this->siteDir() . '/page.html', '<p>new</p>');
        $site = $this->buildSite();

        $this->assertTrue($site->gitCommit('Page saved'));

        $this->assertStringContainsString('Page saved', $this->git($this->siteDir(), 'log -1 --format=%s'));
    }
}
