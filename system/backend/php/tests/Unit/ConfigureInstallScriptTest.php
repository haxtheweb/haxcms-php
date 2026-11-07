<?php
use PHPUnit\Framework\TestCase;

/**
 * scripts/configure-install.php replaces the sed-based credential templating
 * in scripts/haxtheweb.sh. It must behave like install.php's step 4:
 * hashed password, exact-assignment var_export replacement, random_bytes
 * secrets, 0600 on the secret files, and idempotent re-runs.
 */
class ConfigureInstallScriptTest extends TestCase
{
    private $repoRoot;
    private $root;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 5);
        $this->root = sys_get_temp_dir() . '/haxcms_configure_install_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/_config', 0777, true);
        copy($this->repoRoot . '/system/boilerplate/systemsetup/config.php', $this->root . '/_config/config.php');
        file_put_contents($this->root . '/_config/.pk', '');
        file_put_contents($this->root . '/_config/.rpk', '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/_config/{,.}*', GLOB_BRACE) as $f) {
            if (is_file($f)) {
                @chmod($f, 0644);
                @unlink($f);
            }
        }
        @rmdir($this->root . '/_config');
        @rmdir($this->root);
    }

    private function runHelper(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->repoRoot . '/scripts/configure-install.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';
        exec($cmd, $output, $code);
        return array('code' => $code, 'output' => implode("\n", $output));
    }

    public function testShellScriptDelegatesToHelperInsteadOfSed(): void
    {
        $sh = (string) file_get_contents($this->repoRoot . '/scripts/haxtheweb.sh');
        $this->assertStringContainsString('scripts/configure-install.php', $sh);
        $this->assertStringNotContainsString('sed -i "s/jimmerson', $sh, 'plaintext password must not be sed-substituted');
        $this->assertStringNotContainsString('sed -i "s/jeff', $sh);
        $this->assertStringNotContainsString('HAXTHEWEBPRIVATEKEY', $sh);
        $this->assertStringNotContainsString('> _config/SALT.txt', $sh, 'salt comes from random_bytes in the helper, not a uuid');
    }

    public function testHelperIsCliOnly(): void
    {
        $src = (string) file_get_contents($this->repoRoot . '/scripts/configure-install.php');
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $src);
    }

    public function testWritesHashedPasswordAndEscapedUser(): void
    {
        // a password with every character a sed delimiter / regex would choke on
        $pass = "p'a\"s/s\\w&o$1rd%@#";
        $result = $this->runHelper(array('--root', $this->root, '--user', 'Ad.min_1', '--pass', $pass));
        $this->assertSame(0, $result['code'], $result['output']);

        $config = (string) file_get_contents($this->root . '/_config/config.php');
        $this->assertStringNotContainsString($pass, $config, 'plaintext password must never be written');
        $this->assertStringNotContainsString('jimmerson', $config);
        $this->assertStringNotContainsString('jeff', $config);
        $this->assertStringNotContainsString('HAXTHEWEBPRIVATEKEY', $config);
        $this->assertStringNotContainsString('HAXTHEWEBREFRESHPRIVATEKEY', $config);
        $this->assertStringContainsString("\$HAXCMS->superUser->name = 'Ad.min_1';", $config);
        // the config must still parse and verify the password through the hash
        $HAXCMS = new stdClass();
        $HAXCMS->superUser = new stdClass();
        include $this->root . '/_config/config.php';
        $this->assertTrue(password_verify($pass, $HAXCMS->superUser->password));
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}(-[0-9a-f]{32}){3}$/', $HAXCMS->privateKey));
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}(-[0-9a-f]{32}){3}$/', $HAXCMS->refreshPrivateKey));
        $this->assertNotSame($HAXCMS->privateKey, $HAXCMS->refreshPrivateKey);
        // the rest of the boilerplate is untouched
        $this->assertStringContainsString("\$HAXCMS->basePath = '/';", $config);
    }

    public function testSaltAndSecretFilesAreOwnerOnly(): void
    {
        $result = $this->runHelper(array('--root', $this->root, '--user', 'admin', '--pass', 'admin'));
        $this->assertSame(0, $result['code'], $result['output']);
        $salt = trim((string) file_get_contents($this->root . '/_config/SALT.txt'));
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}(-[0-9a-f]{32}){3}$/', $salt));
        foreach (array('config.php', 'SALT.txt', '.pk', '.rpk') as $name) {
            clearstatcache();
            $this->assertSame('600', substr(sprintf('%o', fileperms($this->root . '/_config/' . $name)), -3), $name);
        }
    }

    public function testRerunDoesNotClobberConfiguredInstall(): void
    {
        $this->runHelper(array('--root', $this->root, '--user', 'admin', '--pass', 'first'));
        $before = file_get_contents($this->root . '/_config/config.php');
        $saltBefore = file_get_contents($this->root . '/_config/SALT.txt');
        $result = $this->runHelper(array('--root', $this->root, '--user', 'other', '--pass', 'second'));
        $this->assertSame(0, $result['code']);
        $this->assertSame($before, file_get_contents($this->root . '/_config/config.php'));
        $this->assertSame($saltBefore, file_get_contents($this->root . '/_config/SALT.txt'));
    }

    public function testSanitizesUsernameLikeInstaller(): void
    {
        $this->runHelper(array('--root', $this->root, '--user', "ad'; system('x'); \\", '--pass', 'admin'));
        $config = (string) file_get_contents($this->root . '/_config/config.php');
        $this->assertStringContainsString("\$HAXCMS->superUser->name = 'adsystemx';", $config);
    }

    public function testMissingArgumentsFail(): void
    {
        $result = $this->runHelper(array('--root', $this->root, '--user', 'admin'));
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--pass', $result['output']);
        $this->assertStringContainsString('jimmerson', (string) file_get_contents($this->root . '/_config/config.php'));
    }
}
