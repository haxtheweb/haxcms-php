<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the Git / GitRepo seam (lib/Git.php).
 *
 * These tests exercise the PUBLIC API of Git and GitRepo against real temp
 * git repositories created in sys_get_temp_dir(). The git binary is required;
 * the whole class is skipped if `which git` is empty.
 *
 * Expected values come from the git CLI contract (SHA-1 40-char hex format,
 * branch listing format, status output) and from relational properties
 * (round-trip create/list/delete, clone-history identity, reset determinism),
 * NOT by copying the implementation.
 *
 * NOTE on command strings: GitRepo::run() splits the command on whitespace and
 * run_args() PREPENDS the git binary, so the command string must NOT include a
 * leading "git" token (that would yield "git git <subcommand>"). Identity is
 * set via run('config user.email ...') not run('git config ...').
 */
class GitRepoTest extends TestCase
{
    private $repoPath;
    private $repo;
    private $pathsToClean = array();

    protected function setUp(): void
    {
        $gitBin = trim((string) shell_exec('which git 2>/dev/null'));
        if ($gitBin === '') {
            $this->markTestSkipped('git binary not available');
        }
        // Pin the binary path explicitly so tests work regardless of where git
        // is installed (Git::__construct would auto-detect /usr/bin/git or
        // /usr/local/bin/git, but set_bin is authoritative).
        Git::set_bin($gitBin);

        $this->repoPath = sys_get_temp_dir() . '/gitrepo_' . uniqid();
        $this->repo = Git::open($this->repoPath, true);
        $this->repo->run('config user.email test@x');
        $this->repo->run('config user.name test');
        // Force the default branch to 'master' regardless of the system's
        // init.defaultBranch config (some environments default to 'main').
        $this->repo->run('symbolic-ref HEAD refs/heads/master');
        // Seed one committed file so HEAD/currentSHA are defined for every test.
        file_put_contents($this->repoPath . '/hello.txt', 'hello');
        $this->repo->add('hello.txt');
        $this->repo->commit('initial commit');
        $this->pathsToClean = array($this->repoPath);
    }

    protected function tearDown(): void
    {
        foreach ($this->pathsToClean as $path) {
            $this->rrmdir($path);
        }
        $this->pathsToClean = array();
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

    /**
     * Track an extra temp path for teardown cleanup (clone targets, etc.).
     */
    private function trackPath(string $path): void
    {
        $this->pathsToClean[] = $path;
    }

    // --- Git::open ---

    public function testOpenWithCreateReturnsGitRepoAndInitializesGit(): void
    {
        $this->assertInstanceOf(GitRepo::class, $this->repo);
        $this->assertTrue(is_dir($this->repoPath . '/.git'));
    }

    public function testOpenWithoutCreateThrowsOnNonExistentPath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('does not exist');
        Git::open('/nonexistent/gitrepo/path/xyz', false);
    }

    public function testOpenWithoutCreateThrowsOnNonGitDirectory(): void
    {
        $nonGit = sys_get_temp_dir() . '/gitrepo_nongit_' . uniqid();
        $this->trackPath($nonGit);
        mkdir($nonGit);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is not a git repository');
        Git::open($nonGit, false);
    }

    // --- Git::is_repo ---

    public function testIsRepoReturnsTrueForGitRepoInstance(): void
    {
        $this->assertTrue(Git::is_repo($this->repo));
    }

    public static function nonGitRepoObjectProvider(): array
    {
        return [
            'Git instance is not a GitRepo' => [new Git()],
            'stdClass is not a GitRepo' => [new stdClass()],
        ];
    }

    #[DataProvider('nonGitRepoObjectProvider')]
    public function testIsRepoReturnsFalseForNonGitRepoObjects(mixed $value): void
    {
        $this->assertFalse(Git::is_repo($value));
    }

    public static function nonObjectValueProvider(): array
    {
        return [
            'string is not an object' => ['not-a-repo'],
            'integer is not an object' => [42],
            'null is not an object' => [null],
        ];
    }

    #[DataProvider('nonObjectValueProvider')]
    public function testIsRepoThrowsTypeErrorForNonObjectValues(mixed $value): void
    {
        // FINDING: lib/Git.php:158-161 — is_repo($var) calls get_class($var)
        // unconditionally. In PHP 8.x get_class() requires an object argument;
        // passing a non-object (string/int/null/bool) raises a TypeError
        // instead of returning false. The contract for a type-check predicate
        // would be to return false for non-GitRepo values including non-objects,
        // but the actual implementation throws. Characterized here as the
        // actual throw behavior so the suite is honest about the gap.
        $this->expectException(TypeError::class);
        Git::is_repo($value);
    }

    // --- GitRepo::run ---

    public function testRunReturnsStdoutForValidCommand(): void
    {
        $out = $this->repo->run('status');
        $this->assertIsString($out);
        $this->assertStringContainsString('On branch master', $out);
        $this->assertStringContainsString('nothing to commit', $out);
    }

    public function testRunDoesNotThrowOnInvalidGitSubcommand(): void
    {
        // FINDING: lib/Git.php:382-422 (run_command) defaults $skip_fail=true,
        // and run() (line 433) / run_args() (line 452) never override it, so a
        // failing git subcommand is silently swallowed: stderr is discarded and
        // empty stdout is returned. The task contract expected run() to throw
        // on an invalid command (try/catch RuntimeException), but the actual
        // implementation does NOT throw through the public run() seam. Even if
        // $skip_fail were false, run_command throws Exception (base) not
        // RuntimeException. Characterized here as the actual no-throw behavior.
        $threw = false;
        $result = null;
        try {
            $result = $this->repo->run('this-is-not-a-git-subcommand');
        } catch (Exception $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'run() does not throw on invalid subcommand (actual behavior)');
        $this->assertSame('', $result, 'run() returns empty stdout on failure (actual behavior)');
    }

    // --- GitRepo::add ---

    public function testAddStagesNewFile(): void
    {
        file_put_contents($this->repoPath . '/new.txt', 'new content');
        $this->repo->add('new.txt');
        $staged = $this->repo->run('diff --cached --name-only');
        $this->assertStringContainsString('new.txt', $staged);
    }

    public function testAddWithArrayStagesMultipleFiles(): void
    {
        file_put_contents($this->repoPath . '/a.txt', 'a');
        file_put_contents($this->repoPath . '/b.txt', 'b');
        $this->repo->add(array('a.txt', 'b.txt'));
        $staged = $this->repo->run('diff --cached --name-only');
        $this->assertStringContainsString('a.txt', $staged);
        $this->assertStringContainsString('b.txt', $staged);
    }

    // --- GitRepo::commit ---

    public function testCommitReturnsOutputContainingBranchAndMessage(): void
    {
        file_put_contents($this->repoPath . '/committed.txt', 'data');
        $this->repo->add('committed.txt');
        $out = $this->repo->commit('a test commit message');
        $this->assertIsString($out);
        $this->assertStringContainsString('a test commit message', $out);
        $this->assertStringContainsString('master', $out);
        $this->assertStringContainsString('1 file changed', $out);
    }

    public function testCommitWithAllFlagAutoStagesModifiedTrackedFile(): void
    {
        // commit($commit_all=true) adds the -a flag, which auto-stages
        // modifications to ALREADY-TRACKED files (not new files).
        file_put_contents($this->repoPath . '/hello.txt', 'changed');
        $out = $this->repo->commit('auto-stage modification', true);
        $this->assertStringContainsString('auto-stage modification', $out);
        // working tree should be clean after the auto-staged commit
        $this->assertStringContainsString('nothing to commit', $this->repo->run('status'));
    }

    public function testCommitWithoutAllFlagDoesNotCommitUnstagedModification(): void
    {
        // commit($commit_all=false) omits -a, so an unstaged modification to a
        // tracked file is NOT committed. git exits without creating a commit
        // and reports "no changes added to commit" (distinct from the
        // "nothing to commit, working tree clean" message seen on a clean tree).
        $shaBefore = $this->repo->currentSHA();
        file_put_contents($this->repoPath . '/hello.txt', 'changed but not staged');
        $out = $this->repo->commit('should not commit this', false);
        $this->assertStringContainsString('no changes added to commit', $out);
        // no commit was created: SHA unchanged
        $this->assertSame($shaBefore, $this->repo->currentSHA());
        // the modification is still in the working tree, unstaged
        $this->assertStringContainsString('modified:   hello.txt', $this->repo->run('status'));
    }

    // --- GitRepo::currentSHA ---

    public function testCurrentSHAReturns40CharLowercaseHex(): void
    {
        $sha = $this->repo->currentSHA();
        $this->assertIsString($sha);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);
    }

    public function testCurrentSHAMatchesRevParseHead(): void
    {
        // Relational identity: currentSHA must equal a direct rev-parse HEAD
        // (computed independently via run, not via the currentSHA method).
        $direct = trim($this->repo->run('rev-parse HEAD'));
        $this->assertSame($direct, $this->repo->currentSHA());
    }

    // --- GitRepo::status ---

    public function testStatusReportsModifiedFile(): void
    {
        file_put_contents($this->repoPath . '/hello.txt', 'modified content');
        $status = $this->repo->status();
        $this->assertStringContainsString('On branch master', $status);
        $this->assertStringContainsString('modified:', $status);
        $this->assertStringContainsString('hello.txt', $status);
    }

    public function testStatusHtmlReplacesNewlinesWithBrTags(): void
    {
        file_put_contents($this->repoPath . '/hello.txt', 'modified content');
        $html = $this->repo->status(true);
        $this->assertStringContainsString('<br />', $html);
        // the plain version must NOT contain <br />
        $this->assertStringNotContainsString('<br />', $this->repo->status(false));
    }

    // --- branches: create_branch, list_branches, active_branch, delete_branch ---

    public function testCreateBranchListBranchesActiveBranchDeleteBranchRoundTrip(): void
    {
        // active branch after init+commit is 'master'
        $this->assertSame('master', $this->repo->active_branch());

        // create a new branch (does not switch to it)
        $this->repo->create_branch('feature-x');

        // list_branches returns both, without asterisk by default
        $branches = $this->repo->list_branches();
        $this->assertContains('master', $branches);
        $this->assertContains('feature-x', $branches);

        // active branch is still 'master' (create_branch does not checkout)
        $this->assertSame('master', $this->repo->active_branch());

        // delete the branch
        $this->repo->delete_branch('feature-x');
        $afterDelete = $this->repo->list_branches();
        $this->assertContains('master', $afterDelete);
        $this->assertNotContains('feature-x', $afterDelete);
    }

    public function testListBranchesKeepAsteriskMarksActiveBranch(): void
    {
        $withAsterisk = $this->repo->list_branches(true);
        $this->assertContains('* master', $withAsterisk);
        // without keep_asterisk, no "* " prefix
        $withoutAsterisk = $this->repo->list_branches(false);
        $this->assertContains('master', $withoutAsterisk);
        $this->assertNotContains('* master', $withoutAsterisk);
    }

    public function testActiveBranchWithKeepAsteriskReturnsStarredName(): void
    {
        $this->assertSame('* master', $this->repo->active_branch(true));
    }

    public function testDeleteBranchForceFlagUsesCapitalD(): void
    {
        // delete_branch($force=true) runs "git branch -D" which can delete
        // unmerged branches. Create a branch with a divergent commit, then
        // force-delete it (a normal -d would refuse an unmerged branch).
        $this->repo->create_branch('unmerged');
        $this->repo->run('checkout unmerged');
        file_put_contents($this->repoPath . '/branch-file.txt', 'on branch');
        $this->repo->add('branch-file.txt');
        $this->repo->commit('commit on unmerged branch');
        $this->repo->run('checkout master');

        // -d (non-force) would fail on an unmerged branch (silently, per
        // FINDING above), so use force=true (-D) which succeeds.
        $this->repo->delete_branch('unmerged', true);
        $this->assertNotContains('unmerged', $this->repo->list_branches());
    }

    // --- tags: add_tag, list_tags ---

    public function testAddTagAndListTagsRoundTrip(): void
    {
        $this->repo->add_tag('v1.0.0', 'first release');
        $tags = $this->repo->list_tags();
        $this->assertContains('v1.0.0', $tags);
    }

    public function testAddTagDefaultsMessageToTagName(): void
    {
        // add_tag($tag, $message=null) uses $tag as the message when no
        // message is supplied (lib/Git.php:817-819).
        $this->repo->add_tag('v2.0');
        $this->assertContains('v2.0', $this->repo->list_tags());
        $tagMessage = trim($this->repo->run('for-each-ref --format=%(contents:subject) refs/tags/v2.0'));
        $this->assertSame('v2.0', $tagMessage);
    }

    public function testListTagsWithPatternFilters(): void
    {
        $this->repo->add_tag('v1.0.0', 'r1');
        $this->repo->add_tag('v1.1.0', 'r2');
        $this->repo->add_tag('v2.0.0', 'r3');
        $v1 = $this->repo->list_tags('v1.*');
        $this->assertContains('v1.0.0', $v1);
        $this->assertContains('v1.1.0', $v1);
        $this->assertNotContains('v2.0.0', $v1);
        // non-matching pattern returns an empty array (after blank trimming)
        $this->assertSame(array(), $this->repo->list_tags('zzz*'));
    }

    // --- clone_to ---

    public function testCloneToCreatesSecondRepoWithCommitHistory(): void
    {
        $sourceSHA = $this->repo->currentSHA();
        $clonePath = sys_get_temp_dir() . '/gitrepo_clone_' . uniqid();
        $this->trackPath($clonePath);

        $this->repo->clone_to($clonePath);

        $this->assertTrue(is_dir($clonePath . '/.git'));
        $cloneRepo = Git::open($clonePath);
        // clone preserves commit history: HEAD SHA matches the source
        $this->assertSame($sourceSHA, $cloneRepo->currentSHA());
        // clone preserves the working tree (hello.txt is checked out)
        $this->assertTrue(file_exists($clonePath . '/hello.txt'));
    }

    public function testCloneToPreservesTags(): void
    {
        $this->repo->add_tag('release-1', 'first release');
        $clonePath = sys_get_temp_dir() . '/gitrepo_clone_tags_' . uniqid();
        $this->trackPath($clonePath);
        $this->repo->clone_to($clonePath);
        $cloneRepo = Git::open($clonePath);
        $this->assertContains('release-1', $cloneRepo->list_tags());
    }

    // --- clone_from ---

    public function testCloneFromClonesSourceHistoryIntoFreshRepo(): void
    {
        // clone_from runs "git clone --local <source> <repo_path>" INTO the
        // current repo_path, so the target must exist as a directory but NOT
        // be a git repo yet. This is only achievable via new GitRepo($path,
        // true, false) — Git::open always passes $_init=true which would run
        // init and leave a .git dir that clone would refuse to overwrite.
        $sourceSHA = $this->repo->currentSHA();
        $targetPath = sys_get_temp_dir() . '/gitrepo_clonefrom_' . uniqid();
        $this->trackPath($targetPath);

        $fresh = new GitRepo($targetPath, true, false);
        $fresh->clone_from($this->repoPath);

        $this->assertTrue(is_dir($targetPath . '/.git'));
        $this->assertSame($sourceSHA, $fresh->currentSHA());
        // --local clone sets up origin pointing at the source
        $this->assertSame('origin', trim($fresh->run('remote')));
    }

    // --- reset ---

    public function testResetHardToOriginMovesHeadBackAndDiscardsWorkingTree(): void
    {
        // Build a clone that has an 'origin' remote pointing at the source.
        $clonePath = sys_get_temp_dir() . '/gitrepo_reset_' . uniqid();
        $this->trackPath($clonePath);
        $this->repo->clone_to($clonePath);
        $cloneRepo = Git::open($clonePath);
        $cloneRepo->run('config user.email test@x');
        $cloneRepo->run('config user.name test');
        $originSHA = $cloneRepo->currentSHA();

        // Advance the clone's master with an extra commit.
        file_put_contents($clonePath . '/extra.txt', 'extra');
        $cloneRepo->add('extra.txt');
        $cloneRepo->commit('extra commit ahead of origin');
        $this->assertTrue(file_exists($clonePath . '/extra.txt'));
        $this->assertNotSame($originSHA, $cloneRepo->currentSHA());

        // reset --hard origin/master moves HEAD back and wipes the working tree.
        $out = $cloneRepo->reset('master', 'origin', true);
        $this->assertStringContainsString('HEAD is now at', $out);
        $this->assertSame($originSHA, $cloneRepo->currentSHA());
        $this->assertFalse(file_exists($clonePath . '/extra.txt'));
    }

    public function testResetMixedWhenHardFalseMovesHeadButKeepsWorkingTree(): void
    {
        // reset($hard=false) runs "git reset <remote>/<branch>" with no --hard
        // flag, which is a MIXED reset (git default): HEAD moves back, the index
        // is reset, but the working tree is left untouched. (The $hard flag is a
        // boolean toggle between --hard and the implicit mixed mode; there is no
        // --soft path through this method.)
        $clonePath = sys_get_temp_dir() . '/gitrepo_reset_mixed_' . uniqid();
        $this->trackPath($clonePath);
        $this->repo->clone_to($clonePath);
        $cloneRepo = Git::open($clonePath);
        $cloneRepo->run('config user.email test@x');
        $cloneRepo->run('config user.name test');
        $originSHA = $cloneRepo->currentSHA();

        file_put_contents($clonePath . '/soft.txt', 'soft');
        $cloneRepo->add('soft.txt');
        $cloneRepo->commit('soft commit ahead of origin');

        $cloneRepo->reset('master', 'origin', false);
        // HEAD moved back to origin/master
        $this->assertSame($originSHA, $cloneRepo->currentSHA());
        // working tree is preserved (mixed reset does not touch it)
        $this->assertTrue(file_exists($clonePath . '/soft.txt'));
        // index was reset, so soft.txt is NOT staged
        $staged = $cloneRepo->run('diff --cached --name-only');
        $this->assertStringNotContainsString('soft.txt', $staged);
        // soft.txt shows as an untracked / unstaged change in status
        $this->assertStringContainsString('soft.txt', $cloneRepo->run('status'));
    }

    public function testResetSilentlyFailsWhenOriginRemoteMissing(): void
    {
        // FINDING: lib/Git.php:761-769 — reset() builds "git reset [--hard]
        // origin/<branch>" and delegates to run_args -> run_command which
        // defaults to $skip_fail=true (see run() finding above). On a repo with
        // no 'origin' remote, git emits "fatal: ambiguous argument
        // 'origin/master'" to stderr and exits non-zero, but run_command
        // swallows it: the return is empty stdout and NO exception is thrown.
        // The repo is left unchanged. Characterized here as the actual no-throw
        // silent-failure behavior (the contract would expect a throw).
        $shaBefore = $this->repo->currentSHA();
        $threw = false;
        $out = null;
        try {
            $out = $this->repo->reset('master', 'origin', true);
        } catch (Exception $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'reset() silently fails when origin is missing (actual behavior)');
        $this->assertSame('', $out, 'reset() returns empty stdout on missing origin (actual behavior)');
        $this->assertSame($shaBefore, $this->repo->currentSHA(), 'HEAD unchanged after silent reset failure');
    }

    // --- revert ---

    public function testRevertUndoesLastCommit(): void
    {
        // Make a second commit, then revert(1) must move HEAD back to the
        // first commit's SHA (verified independently via rev-parse HEAD~1
        // BEFORE the revert, so the expected value is not derived from the
        // revert method itself).
        $firstCommitSHA = trim($this->repo->run('rev-parse HEAD~0'));
        file_put_contents($this->repoPath . '/second.txt', 'second');
        $this->repo->add('second.txt');
        $this->repo->commit('second commit');
        $this->assertNotSame($firstCommitSHA, $this->repo->currentSHA());

        $this->repo->revert(1);
        $this->assertSame($firstCommitSHA, $this->repo->currentSHA());
        // the second commit's file is gone from the working tree (--hard reset)
        $this->assertFalse(file_exists($this->repoPath . '/second.txt'));
    }

    public function testRevertReturnsTrue(): void
    {
        // revert() returns the boolean TRUE (lib/Git.php:792), not git stdout.
        $this->assertTrue($this->repo->revert(1));
    }

    public function testRevertCountZeroClampsToOne(): void
    {
        // FINDING: lib/Git.php:785-787 — revert($count) clamps $count < 1 to 1,
        // so revert(0) is NOT a no-op: it still runs one "git reset --hard
        // HEAD~1". Characterized here against a 2-commit history: revert(0)
        // rewinds to the first commit, proving the clamp fired. (When already
        // at the root commit, HEAD~1 is undefined and the reset fails silently
        // per the $skip_fail finding, so this test needs a 2-commit history.)
        $rootSHA = $this->repo->currentSHA();
        file_put_contents($this->repoPath . '/extra.txt', 'extra');
        $this->repo->add('extra.txt');
        $this->repo->commit('extra commit');
        $this->assertNotSame($rootSHA, $this->repo->currentSHA());

        $this->repo->revert(0);
        $this->assertSame($rootSHA, $this->repo->currentSHA(), 'revert(0) clamped to 1 and rewound one commit');
    }

    // --- push / pull: no network ---

    public function testPushRequiresNetworkAndIsSkipped(): void
    {
        $this->markTestSkipped('push requires a remote network endpoint; skipped to avoid network access');
    }

    public function testPullRequiresNetworkAndIsSkipped(): void
    {
        $this->markTestSkipped('pull requires a remote network endpoint; skipped to avoid network access');
    }
}
