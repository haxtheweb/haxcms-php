<?php
use PHPUnit\Framework\TestCase;

/**
 * Commit op codes (haxtheweb/issues#3191). Mirrors haxcms-nodejs
 * test/unit/commit-context.test.cjs so both backends write and read the same
 * "[<actor>:<op>[:<txn>]] <message>" subjects.
 */
class CommitContextTest extends TestCase
{
    private $tmpRoot;
    private $siteName = 'commitctxsite';
    private $savedActor;

    protected function setUp(): void
    {
        CommitContext::clear();
        $this->savedActor = getenv('HAX_ACTOR');
        putenv('HAX_ACTOR');
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_commitctx_' . uniqid();
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        CommitContext::clear();
        if ($this->savedActor !== false) {
            putenv('HAX_ACTOR=' . $this->savedActor);
        } else {
            putenv('HAX_ACTOR');
        }
        shell_exec('rm -rf ' . escapeshellarg($this->tmpRoot));
    }

    private function buildSite(): HAXCMSSite
    {
        $site = new HAXCMSSite();
        $site->directory = $this->tmpRoot;
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = $this->siteName;
        $dir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($dir, 0777, true);
        $repo = Git::open($dir, true);
        $repo->run('config user.name haxcmstest');
        $repo->run('config user.email haxcmstest@example.com');
        return $site;
    }

    private function lastSubject(): string
    {
        $dir = $this->tmpRoot . '/' . $this->siteName;
        return trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' log -1 --format=%s 2>&1'));
    }

    // ---- allowlists ----

    public function testActorAcceptsOnlyHumanOrAgent(): void
    {
        $this->assertSame('A', CommitContext::normalizeActor('agent'));
        $this->assertSame('A', CommitContext::normalizeActor(' Agent '));
        $this->assertSame('H', CommitContext::normalizeActor('human'));
        $this->assertSame('H', CommitContext::normalizeActor('h'));
        foreach (array('S', 'X', 'system', 'robot', '', 'mcp') as $value) {
            $this->assertSame('', CommitContext::normalizeActor($value), $value);
        }
    }

    public function testOpKeepsLettersAndDigitsOnly(): void
    {
        $this->assertSame('updateSiteOutline', CommitContext::normalizeOp('updateSiteOutline'));
        $this->assertSame('updateItem', CommitContext::normalizeOp(' update Item '));
        $this->assertSame('updatex', CommitContext::normalizeOp('up]date:[x'));
        $this->assertSame('', CommitContext::normalizeOp('9lives'));
        $this->assertSame(48, strlen(CommitContext::normalizeOp(str_repeat('a', 80))));
    }

    public function testTxnShape(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{4}$/', CommitContext::createTxn());
        }
    }

    public function testRequestDefaultsToHuman(): void
    {
        $ctx = CommitContext::startRequest('', 'updateItem');
        $this->assertSame('H', $ctx['actor']);
        $this->assertSame('updateItem', $ctx['op']);
        foreach (array('S', 'X', 'robot', 'agent] [H') as $value) {
            $this->assertSame('H', CommitContext::startRequest($value, 'x')['actor'], $value);
        }
        $this->assertSame('A', CommitContext::startRequest('agent', 'x')['actor']);
    }

    public function testFallbackOutsideRequests(): void
    {
        $this->assertSame('S', CommitContext::getFallback()['actor']);
        putenv('HAX_ACTOR=agent');
        $this->assertSame('A', CommitContext::getFallback()['actor']);
    }

    // ---- format / parse ----

    public function testPrefixShape(): void
    {
        $this->assertSame('[A:updateItem:x9r1]', CommitContext::formatPrefix(array('actor' => 'A', 'op' => 'updateItem', 'txn' => 'x9r1')));
        $this->assertSame('[S:upgradeManagedFiles]', CommitContext::formatPrefix(array('actor' => 'S', 'op' => 'upgradeManagedFiles', 'txn' => '')));
        $this->assertSame('[S:system]', CommitContext::formatPrefix(array()));
        $this->assertSame('[S:update:abcd]', CommitContext::formatPrefix(array('actor' => 'X', 'op' => 'up date]', 'txn' => 'a b c d')));
    }

    public function testExamplesRoundTrip(): void
    {
        $examples = array(
            '[H:updateItem:p7qa] Page details updated: Photosynthesis basics (item-3f2a)',
            '[H:updateSiteOutline:kp39] Page deleted: Old intro (item-8c1e)',
            '[H:updateSiteOutline:kp39] Outline updated in bulk',
            '[H:replaceContent:m2d8] Bulk replace chloroplast -> chloroplasts across 6 pages',
            '[A:updateItem:x9r1] Page details updated: Photosynthesis basics (item-3f2a)',
            '[S:upgradeManagedFiles] Managed files upgraded: legacy bootstrap',
            '[A:direct] Fixed alt text on 3 images',
        );
        foreach ($examples as $line) {
            $parsed = CommitContext::parse($line);
            $this->assertNotSame('X', $parsed['actor'], $line);
            $this->assertSame($line, CommitContext::formatPrefix($parsed) . ' ' . $parsed['summary']);
        }
    }

    public function testNonMatchingSubjectsAreExternal(): void
    {
        $cases = array(
            'Outline updated in bulk' => 'Outline updated in bulk',
            "'Outline updated in bulk'" => 'Outline updated in bulk',
            'Fix typo   on   about page  ' => 'Fix typo on about page',
            '[X:external] hand edit' => '[X:external] hand edit',
            '[H:update-item:abcd] dashes not allowed' => '[H:update-item:abcd] dashes not allowed',
        );
        foreach ($cases as $line => $summary) {
            $this->assertSame(
                array('actor' => 'X', 'op' => '', 'txn' => '', 'summary' => $summary),
                CommitContext::parse($line),
                $line
            );
        }
    }

    public function testMessageIsSanitizedTrimmedAndCapped(): void
    {
        $ctx = array('actor' => 'A', 'op' => 'updateItem', 'txn' => 'x9r1');
        $this->assertSame(
            '[A:updateItem:x9r1] Page added: Bobs page',
            CommitContext::formatCommitMessage("  Page   added:\tBob's \"page\"  ", '', $ctx)
        );
        $out = CommitContext::formatCommitMessage('Page details updated: ' . str_repeat('x', 400), '', $ctx);
        $this->assertLessThanOrEqual(200, strlen($out));
        $this->assertStringStartsWith('[A:updateItem:x9r1] Page details updated: ', $out);
        $this->assertSame('[A:siteCreate:x9r1] Site created', CommitContext::formatCommitMessage('Site created', 'siteCreate', $ctx));
        $this->assertSame('[A:direct] Fixed alt text', CommitContext::formatCommitMessage('[A:direct] Fixed alt text', '', $ctx));
    }

    // ---- operationIds ----

    public function testOperationIdsComeFromTheSpecs(): void
    {
        $this->assertSame('updateSiteOutline', CommitContext::getOperationId('site', 'PATCH', 'v1/site/outline'));
        $this->assertSame('createItem', CommitContext::getOperationId('site', 'POST', 'v1/items'));
        $this->assertSame('deleteItem', CommitContext::getOperationId('site', 'DELETE', 'v1/items/:idOrSlug'));
        $this->assertSame('restoreItemRevision', CommitContext::getOperationId('site', 'POST', 'v1/items/:idOrSlug/revisions/:revisionId/restore'));
        $this->assertSame('createSite', CommitContext::getOperationId('system', 'POST', 'v1/sites'));
        $this->assertSame('postV1WidgetsFrobnicate', CommitContext::getOperationId('site', 'POST', 'v1/widgets/:id/frobnicate'));
    }

    public function testEveryWriteRouteIsInTheSpec(): void
    {
        $index = CommitContext::buildOperationIdIndex();
        $missing = array();
        $maps = array('site' => SiteRoutesMap::getRoutesMap(), 'system' => SystemRoutesMap::getRoutesMap());
        foreach ($maps as $scope => $map) {
            foreach ($map as $method => $routes) {
                if ($method === 'GET' || $method === 'OPTIONS') {
                    continue;
                }
                foreach ($routes as $route => $file) {
                    if (!isset($index[$scope][strtolower($method) . ' ' . $route])) {
                        $missing[] = $scope . ' ' . $method . ' ' . $route;
                    }
                }
            }
        }
        $this->assertSame(array(), $missing);
    }

    // ---- real commits ----

    public function testGitCommitUsesTheRequestContext(): void
    {
        $site = $this->buildSite();
        file_put_contents($this->tmpRoot . '/' . $this->siteName . '/a.html', 'a');
        CommitContext::set(array('actor' => 'H', 'op' => 'updateSiteOutline', 'txn' => 'kp39'));
        $site->gitCommit('Outline updated in bulk');
        $this->assertSame('[H:updateSiteOutline:kp39] Outline updated in bulk', $this->lastSubject());
    }

    public function testGitCommitOutsideARequestIsSystemWithOptionalOp(): void
    {
        $site = $this->buildSite();
        file_put_contents($this->tmpRoot . '/' . $this->siteName . '/a.html', 'a');
        $site->gitCommit('Managed files upgraded: legacy bootstrap', 'upgradeManagedFiles');
        $this->assertSame('[S:upgradeManagedFiles] Managed files upgraded: legacy bootstrap', $this->lastSubject());
    }
}
