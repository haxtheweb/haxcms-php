<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../lib/ManagedFiles.php';

/**
 * haxtheweb/issues#3186: managed-file policies (replace / block / json-merge)
 * and security.txt expiry reuse. Mirrors NodeJS test/unit/managed-files.test.cjs.
 */
class ManagedFilesTest extends TestCase
{
    private function hashFile($body)
    {
        return "# BEGIN HAXcms managed\n" . $body . "\n# END HAXcms managed\n";
    }

    private function legacyAgents()
    {
        return file_get_contents(__DIR__ . '/fixtures/agents-md-legacy.txt');
    }

    public function testPolicies(): void
    {
        $this->assertSame('block', ManagedFiles::getPolicy('agents')['policy']);
        $this->assertFalse(ManagedFiles::getPolicy('agents')['render']);
        $this->assertSame('block', ManagedFiles::getPolicy('htaccess')['policy']);
        $this->assertSame('block', ManagedFiles::getPolicy('robots')['policy']);
        $this->assertSame('json-merge', ManagedFiles::getPolicy('package')['policy']);
        $this->assertSame(array('policy' => 'replace', 'render' => true), ManagedFiles::getPolicy('sw'));
        // files/.htaccess is execution hardening and stays fully managed
        $this->assertSame('replace', ManagedFiles::getPolicy('fileshtaccess')['policy']);
    }

    public function testMarkersAreVersionNeutralAndInTheBoilerplate(): void
    {
        foreach (ManagedFiles::MARKERS as $pair) {
            $this->assertSame(0, preg_match('/\d+\.\d+/', $pair['start'] . $pair['end']));
        }
        $base = __DIR__ . '/../../../../boilerplate/site/';
        $this->assertNotNull(ManagedFiles::findBlock(file_get_contents($base . 'AGENTS.md'), 'html'));
        $this->assertNotNull(ManagedFiles::findBlock(file_get_contents($base . '.htaccess'), 'hash'));
        $this->assertNotNull(ManagedFiles::findBlock(file_get_contents($base . 'robots.txt'), 'hash'));
    }

    public function testFindBlockNeedsBothMarkersInOrder(): void
    {
        $this->assertNull(ManagedFiles::findBlock('no markers', 'hash'));
        $this->assertNull(ManagedFiles::findBlock("# BEGIN HAXcms managed\nopen only", 'hash'));
        $this->assertNull(ManagedFiles::findBlock("# END HAXcms managed\n# BEGIN HAXcms managed", 'hash'));
    }

    public function testBlockMergeReplacesOnlyTheManagedSpan(): void
    {
        $existing = "# owner top\n" . $this->hashFile('old rules') . "# owner bottom\n";
        $merged = ManagedFiles::mergeBlock($existing, $this->hashFile('new rules'), 'hash', 'whole');
        $this->assertSame("# owner top\n" . $this->hashFile('new rules') . "# owner bottom\n", $merged);
        $this->assertSame($merged, ManagedFiles::mergeBlock($merged, $this->hashFile('new rules'), 'hash', 'whole'));
    }

    public function testBlockMergeLegacyHandling(): void
    {
        $rendered = $this->hashFile('rules');
        $this->assertSame($rendered, ManagedFiles::mergeBlock(null, $rendered, 'hash', 'whole'));
        $this->assertSame($rendered, ManagedFiles::mergeBlock('old managed copy', $rendered, 'hash', 'whole'));
        $agentsRendered = "<!-- haxcms:managed:start -->\nnew guide\n<!-- haxcms:managed:end -->\n";
        $legacy = $this->legacyAgents();
        $this->assertSame($agentsRendered, ManagedFiles::mergeBlock($legacy, $agentsRendered, 'html', 'hashes'));
        $this->assertSame($agentsRendered, ManagedFiles::mergeBlock(str_replace("\n", "\r\n", $legacy), $agentsRendered, 'html', 'hashes'));
        $edited = $legacy . "\nhouse rule\n";
        $merged = ManagedFiles::mergeBlock($edited, $agentsRendered, 'html', 'hashes');
        $this->assertStringStartsWith("<!-- haxcms:managed:start -->\nnew guide\n<!-- haxcms:managed:end -->", $merged);
        $this->assertStringEndsWith($edited, $merged);
        $this->assertSame($merged, ManagedFiles::mergeBlock($merged, $agentsRendered, 'html', 'hashes'));
        // a boilerplate without markers falls back to replace
        $this->assertSame('plain', ManagedFiles::mergeBlock('anything', 'plain', 'hash', 'whole'));
    }

    public function testJsonMerge(): void
    {
        $rendered = "{\n  \"name\": \"site\",\n  \"scripts\": { \"build\": \"x\" }\n}\n";
        $this->assertSame($rendered, ManagedFiles::mergeJson('{"name":"old","scripts":{"build":"old"}}', $rendered));
        $this->assertSame($rendered, ManagedFiles::mergeJson(null, $rendered));
        $this->assertSame($rendered, ManagedFiles::mergeJson('not json', $rendered));
        $merged = json_decode(ManagedFiles::mergeJson(
            json_encode(array('name' => 'old', 'scripts' => array('build' => 'old', 'lint' => 'eslint .'), 'dependencies' => array('lit' => '^2', 'dayjs' => '^1'), 'private' => true)),
            json_encode(array('name' => 'site', 'version' => '0.0.0', 'scripts' => array('build' => 'hax build'), 'dependencies' => array('lit' => '^3')))
        ), true);
        $this->assertSame('site', $merged['name']);
        $this->assertSame(array('build' => 'hax build', 'lint' => 'eslint .'), $merged['scripts']);
        $this->assertSame(array('lit' => '^3', 'dayjs' => '^1'), $merged['dependencies']);
        $this->assertTrue($merged['private']);
    }

    public function testSecurityTxtExpires(): void
    {
        $now = strtotime('2026-10-28T12:34:56Z');
        $this->assertSame('2027-04-01T00:00:00.000Z', ManagedFiles::getSecurityTxtExpires("Contact: x\nExpires: 2027-04-01T00:00:00.000Z\n", $now));
        $this->assertSame('2027-04-26T00:00:00.000Z', ManagedFiles::getSecurityTxtExpires("Expires: 2026-12-01T00:00:00.000Z\n", $now));
        $this->assertSame('2027-04-26T00:00:00.000Z', ManagedFiles::getSecurityTxtExpires(null, $now));
        $this->assertSame('2027-04-26T00:00:00.000Z', ManagedFiles::getSecurityTxtExpires("Expires: garbage\n", $now));
    }
}
