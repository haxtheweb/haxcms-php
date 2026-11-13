<?php
use PHPUnit\Framework\TestCase;

include_once dirname(__FILE__) . '/../../lib/PlatformUpgrader.php';

/**
 * Shell-side platform upgrades (haxtheweb/issues#1768): manifest building and
 * validation, verify, and upgrade/rollback against synthetic installs.
 * Invariants under test: site data is never touched, only manifest-listed
 * safe paths are written, nothing changes unless checksums and contents
 * match, and a failed health check rolls back.
 */
class PlatformUpgraderTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/haxcms-upgrader-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->tmp);
    }

    private function rm($path)
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
            $this->rm($path . '/' . $entry);
        }
        rmdir($path);
    }

    private function write($root, $files)
    {
        foreach ($files as $rel => $content) {
            $path = $root . '/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }
    }

    /**
     * A release: tree, manifest inside it, tar.gz, SHA256SUMS, manifest asset.
     */
    private function release($version, $files)
    {
        $name = 'haxcms-php-' . $version;
        $tree = $this->tmp . '/release-' . $version . '/' . $name;
        $this->write($tree, array_merge(array('.version' => $version . "\n"), $files));
        $manifest = HAXCMSPlatformUpgrader::buildManifest($tree, $version);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $dist = $this->tmp . '/dist-' . $version;
        mkdir($dist, 0700, true);
        file_put_contents($dist . '/platform-manifest.json', $json);
        $this->write($tree, array('system/platform-manifest.json' => $json));
        // built with GNU tar, as the release workflow does
        $archive = $dist . '/' . $name . '.tar.gz';
        exec('tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg(dirname($tree)) . ' ' . escapeshellarg($name), $out, $code);
        $this->assertSame(0, $code, 'tar failed');
        file_put_contents($dist . '/SHA256SUMS', hash_file('sha256', $archive) . '  ' . basename($archive) . "\n"
            . hash_file('sha256', $dist . '/platform-manifest.json') . "  platform-manifest.json\n");
        return array(
            'archive' => $archive,
            'sums' => $dist . '/SHA256SUMS',
            'manifest' => $dist . '/platform-manifest.json',
            'tree' => $tree,
        );
    }

    /** An install that came from $release, plus site data. */
    private function install($release)
    {
        $root = $this->tmp . '/install';
        $this->copyTree($release['tree'], $root);
        $this->write($root, array(
            '_config/config.php' => '<?php // secrets',
            '_sites/mysite/site.json' => '{"title":"mine"}',
            '_published/mysite/index.html' => 'published',
            '_archived/old/site.json' => '{}',
        ));
        return $root;
    }

    private function copyTree($from, $to)
    {
        foreach (HAXCMSPlatformUpgrader::listTree($from) as $rel => $type) {
            $this->write($to, array($rel => file_get_contents($from . '/' . $rel)));
        }
        $this->write($to, array('system/platform-manifest.json' => file_get_contents($from . '/system/platform-manifest.json')));
    }

    private function siteData($root)
    {
        return array(
            file_get_contents($root . '/_config/config.php'),
            file_get_contents($root . '/_sites/mysite/site.json'),
            file_get_contents($root . '/_published/mysite/index.html'),
            file_get_contents($root . '/_archived/old/site.json'),
        );
    }

    private function v1()
    {
        return $this->release('26.8.1', array(
            'index.php' => '<?php // v1',
            'system/backend/php/lib/HAXCMS.php' => '<?php // core v1',
            'build/old.js' => 'removed in v2',
        ));
    }

    private function v2()
    {
        return $this->release('26.9.0', array(
            'index.php' => '<?php // v2',
            'system/backend/php/lib/HAXCMS.php' => '<?php // core v2',
            'build/new.js' => 'added in v2',
        ));
    }

    public function testSafePaths(): void
    {
        $this->assertTrue(HAXCMSPlatformUpgrader::isSafeCodePath('system/lib/a.php'));
        foreach (array('', '/etc/passwd', '../x', 'a/../../x', 'a//b', './a', "a\0b", 'a\\b', '_config/config.php', '_sites/x', 'sites/x', '.git/config') as $bad) {
            $this->assertFalse(HAXCMSPlatformUpgrader::isSafeCodePath($bad), json_encode($bad));
        }
    }

    public function testManifestNeverListsSiteData(): void
    {
        $tree = $this->tmp . '/tree';
        $this->write($tree, array(
            'index.php' => 'x',
            '_config/config.php' => 'secret',
            '_sites/a/site.json' => '{}',
            'sites/b.txt' => 'old layout',
        ));
        $manifest = HAXCMSPlatformUpgrader::buildManifest($tree, '1.2.3');
        $this->assertSame(array('index.php'), array_keys($manifest['files']));
        $this->assertSame(hash('sha256', 'x'), $manifest['files']['index.php']);
    }

    public function testParseManifestRejectsUnsafeContent(): void
    {
        $good = array('schema' => 1, 'package' => 'haxcms-php', 'version' => '1.2.3', 'files' => array('index.php' => str_repeat('a', 64)), 'links' => array());
        $this->assertSame('1.2.3', HAXCMSPlatformUpgrader::parseManifest(json_encode($good))['version']);
        $cases = array(
            array_merge($good, array('files' => array('../evil.php' => str_repeat('a', 64)))),
            array_merge($good, array('files' => array('_config/config.php' => str_repeat('a', 64)))),
            array_merge($good, array('files' => array('index.php' => 'nothex'))),
            array_merge($good, array('package' => 'other')),
            array_merge($good, array('version' => '1.2')),
            array_merge($good, array('links' => array('x' => '/etc'))),
            array_merge($good, array('files' => array())),
        );
        foreach ($cases as $case) {
            try {
                HAXCMSPlatformUpgrader::parseManifest(json_encode($case));
                $this->fail('accepted ' . json_encode($case));
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(RuntimeException::class);
        HAXCMSPlatformUpgrader::parseManifest(json_encode($good), '9.9.9');
    }

    public function testVerifyReportsModifiedMissingAndExtra(): void
    {
        $root = $this->install($this->v1());
        $upgrader = new HAXCMSPlatformUpgrader($root);
        $clean = $upgrader->verify();
        $this->assertSame(array(), $clean['modified']);
        $this->assertSame(array(), $clean['missing']);
        $this->assertSame(array(), $clean['extra']);
        file_put_contents($root . '/index.php', 'edited');
        unlink($root . '/build/old.js');
        file_put_contents($root . '/leftover.php', 'x');
        $dirty = $upgrader->verify();
        $this->assertSame(array('index.php'), $dirty['modified']);
        $this->assertSame(array('build/old.js'), $dirty['missing']);
        $this->assertSame(array('leftover.php'), $dirty['extra']);
    }

    public function testUpgradeReplacesCodeRemovesStaleAndKeepsSiteData(): void
    {
        $root = $this->install($this->v1());
        $before = $this->siteData($root);
        $v2 = $this->v2();
        $upgrader = new HAXCMSPlatformUpgrader($root);
        $plan = $upgrader->upgrade(array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest'], 'dryRun' => true));
        $this->assertSame('26.8.1', $plan['from']);
        $this->assertSame('26.9.0', $plan['to']);
        $this->assertSame(array('build/old.js'), $plan['remove']);
        // .version, index.php, HAXCMS.php and build/new.js differ; nothing else is rewritten
        $this->assertSame(4, $plan['write']);
        $this->assertSame('<?php // v1', file_get_contents($root . '/index.php'), 'dry run changes nothing');

        $done = $upgrader->upgrade(array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest']));
        $this->assertSame('26.9.0', $upgrader->installedVersion());
        $this->assertSame('<?php // v2', file_get_contents($root . '/index.php'));
        $this->assertFileDoesNotExist($root . '/build/old.js');
        $this->assertFileExists($root . '/build/new.js');
        $this->assertSame($before, $this->siteData($root));
        $this->assertDirectoryExists($done['backup']);
        $after = $upgrader->verify();
        $this->assertSame(array(), $after['modified']);
        $this->assertSame(array(), $after['missing']);
        $this->assertFileDoesNotExist($root . '/_config/platform-upgrade/.lock');

        $meta = $upgrader->rollback();
        $this->assertSame('26.8.1', $meta['from']);
        $this->assertSame('26.8.1', $upgrader->installedVersion());
        $this->assertSame('<?php // v1', file_get_contents($root . '/index.php'));
        $this->assertFileExists($root . '/build/old.js');
        $this->assertFileDoesNotExist($root . '/build/new.js');
        $this->assertSame(array(), $upgrader->verify()['modified']);
        $this->assertSame($before, $this->siteData($root));
    }

    public function testChecksumMismatchChangesNothing(): void
    {
        $root = $this->install($this->v1());
        $v2 = $this->v2();
        file_put_contents($v2['sums'], str_repeat('0', 64) . '  ' . basename($v2['archive']) . "\n");
        $upgrader = new HAXCMSPlatformUpgrader($root);
        try {
            $upgrader->upgrade(array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest']));
            $this->fail('upgraded with a bad checksum');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Checksum mismatch', $e->getMessage());
        }
        $this->assertSame('26.8.1', $upgrader->installedVersion());
        $this->assertSame(array(), $upgrader->listBackups());
    }

    public function testManifestThatDoesNotMatchArchiveChangesNothing(): void
    {
        $root = $this->install($this->v1());
        $v2 = $this->v2();
        $manifest = json_decode(file_get_contents($v2['manifest']), true);
        $manifest['files']['index.php'] = str_repeat('b', 64);
        file_put_contents($v2['manifest'], json_encode($manifest));
        // SHA256SUMS lists only the archive here, so the manifest check is what catches it
        file_put_contents($v2['sums'], hash_file('sha256', $v2['archive']) . '  ' . basename($v2['archive']) . "\n");
        $upgrader = new HAXCMSPlatformUpgrader($root);
        try {
            $upgrader->upgrade(array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest']));
            $this->fail('upgraded with a mismatched manifest');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('do not match', $e->getMessage());
        }
        $this->assertSame('<?php // v1', file_get_contents($root . '/index.php'));
    }

    public function testFilesChangedByHandBlockUntilOverwriteIsChosen(): void
    {
        $root = $this->install($this->v1());
        file_put_contents($root . '/index.php', '<?php // my edit');
        $v2 = $this->v2();
        $upgrader = new HAXCMSPlatformUpgrader($root);
        $opts = array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest']);
        $plan = $upgrader->upgrade(array_merge($opts, array('dryRun' => true)));
        $this->assertTrue($plan['blocked']);
        $this->assertSame(array('index.php'), $plan['modified']);
        try {
            $upgrader->upgrade($opts);
            $this->fail('replaced a hand-edited file without asking');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('--overwrite-modified', $e->getMessage());
        }
        $this->assertSame('<?php // my edit', file_get_contents($root . '/index.php'));
        $done = $upgrader->upgrade(array_merge($opts, array('overwriteModified' => true)));
        $this->assertSame('<?php // v2', file_get_contents($root . '/index.php'));
        $this->assertSame('<?php // my edit', file_get_contents($done['backup'] . '/files/index.php'));
    }

    public function testDowngradeNeedsExplicitFlagAndConfirmCanCancel(): void
    {
        $v2 = $this->v2();
        $root = $this->install($v2);
        $v1 = $this->v1();
        $upgrader = new HAXCMSPlatformUpgrader($root);
        $opts = array('archive' => $v1['archive'], 'sums' => $v1['sums'], 'manifest' => $v1['manifest']);
        try {
            $upgrader->upgrade($opts);
            $this->fail('downgraded without --allow-downgrade');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('allow-downgrade', $e->getMessage());
        }
        $cancelled = $upgrader->upgrade(array_merge($opts, array('allowDowngrade' => true, 'confirm' => function () {
            return false;
        })));
        $this->assertTrue($cancelled['cancelled']);
        $this->assertSame('26.9.0', $upgrader->installedVersion());
    }

    public function testConcurrentUpgradeIsRefused(): void
    {
        $root = $this->install($this->v1());
        mkdir($root . '/_config/platform-upgrade', 0700, true);
        file_put_contents($root . '/_config/platform-upgrade/.lock', '123');
        $v2 = $this->v2();
        $upgrader = new HAXCMSPlatformUpgrader($root);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Another upgrade is running');
        $upgrader->upgrade(array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest']));
    }

    public function testGitCheckoutIsLeftToGit(): void
    {
        $root = $this->install($this->v1());
        mkdir($root . '/.git');
        $v2 = $this->v2();
        $upgrader = new HAXCMSPlatformUpgrader($root);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('git checkout');
        $upgrader->upgrade(array('archive' => $v2['archive'], 'sums' => $v2['sums'], 'manifest' => $v2['manifest']));
    }

    public function testSha256SumsParsing(): void
    {
        $sums = HAXCMSPlatformUpgrader::parseSha256Sums(str_repeat('a', 64) . "  a.tar.gz\n" . str_repeat('b', 64) . " *b.zip\nnot a line\n");
        $this->assertSame(array('a.tar.gz' => str_repeat('a', 64), 'b.zip' => str_repeat('b', 64)), $sums);
    }

    public function testDownloadsOnlyFromGitHubOverHttps(): void
    {
        $upgrader = new HAXCMSPlatformUpgrader($this->tmp);
        foreach (array('http://github.com/x', 'https://evil.example/x', 'file:///etc/passwd') as $url) {
            try {
                $upgrader->httpGet($url);
                $this->fail('fetched ' . $url);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Refusing', $e->getMessage());
            }
        }
    }

    public function testScriptsRefuseToRunOverHttp(): void
    {
        $root = dirname(__FILE__) . '/../../../../..';
        foreach (array('scripts/platform-upgrade.php', 'scripts/platform-manifest.php') as $script) {
            $source = file_get_contents($root . '/' . $script);
            $this->assertMatchesRegularExpression('/if \(PHP_SAPI !== \'cli\'\) \{\s*http_response_code\(404\);\s*exit;/', $source, $script);
        }
    }
}
