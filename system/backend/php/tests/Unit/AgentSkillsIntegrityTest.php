<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every .well-known/agent-skills/ copy in this repo must be internally
 * consistent: each skill its index.json lists exists, and each file's bytes
 * match the sha256 digest the index declares for it. Agents that follow the
 * agentskills.io discovery spec check those digests, so a copy that a
 * formatter or a hand edit touched is a copy they refuse. Refresh copies only
 * with praw's scripts/snapshot-agent-skills.js; drift from PRAW itself is
 * reported by scripts/agent-skills-drift.js (weekly workflow), not here.
 *
 * Mirrors haxcms-nodejs test/unit/agent-skills-integrity.test.cjs.
 */
class AgentSkillsIntegrityTest extends TestCase
{
    private const NAME_RE = '/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/';

    public static function copies(): array
    {
        $root = dirname(__DIR__, 5);
        return [
            'deployment root (system set)' => [$root . '/.well-known/agent-skills'],
            'site boilerplate (site set)' => [$root . '/system/boilerplate/site/.well-known/agent-skills'],
        ];
    }

    private function index(string $dir): array
    {
        $this->assertFileExists($dir . '/index.json');
        $idx = json_decode(file_get_contents($dir . '/index.json'), true);
        $this->assertIsArray($idx, 'index.json does not parse');
        return $idx;
    }

    private function sha(string $file): string
    {
        return 'sha256:' . hash_file('sha256', $file);
    }

    #[DataProvider('copies')]
    public function testIndexFollowsTheDiscoverySpec(string $dir): void
    {
        $idx = $this->index($dir);
        $this->assertMatchesRegularExpression('#agentskills\.io/discovery/#', $idx['$schema']);
        $this->assertNotEmpty($idx['skills']);
        foreach ($idx['skills'] as $s) {
            $this->assertMatchesRegularExpression(self::NAME_RE, $s['name']);
            $this->assertSame('skill-md', $s['type']);
            $this->assertSame($s['name'] . '/SKILL.md', $s['url']);
            $this->assertNotEmpty($s['description']);
            $this->assertLessThanOrEqual(1024, strlen($s['description']));
        }
    }

    #[DataProvider('copies')]
    public function testEverySkillFileMatchesItsDigest(string $dir): void
    {
        $bad = [];
        foreach ($this->index($dir)['skills'] as $s) {
            $file = $dir . '/' . $s['url'];
            if (!is_file($file)) {
                $bad[] = $s['url'] . ' (missing)';
            } elseif ($this->sha($file) !== $s['digest']) {
                $bad[] = $s['url'];
            }
            foreach ($s['files'] ?? [] as $f) {
                $p = $dir . '/' . $s['name'] . '/' . $f['path'];
                if (!is_file($p) || $this->sha($p) !== $f['digest']) {
                    $bad[] = $s['name'] . '/' . $f['path'];
                }
            }
        }
        $this->assertSame([], $bad, "changed since the snapshot; refresh with praw's snapshot-agent-skills.js");
    }

    #[DataProvider('copies')]
    public function testNoSkillFolderTheIndexDoesNotList(string $dir): void
    {
        $listed = array_column($this->index($dir)['skills'], 'name');
        $extra = [];
        foreach (scandir($dir) as $entry) {
            if ($entry[0] !== '.' && is_dir($dir . '/' . $entry) && !in_array($entry, $listed, true)) {
                $extra[] = $entry;
            }
        }
        $this->assertSame([], $extra);
    }
}
