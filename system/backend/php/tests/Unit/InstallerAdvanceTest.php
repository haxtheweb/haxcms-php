<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for install.php ?op=advance password-policy rejection.
 *
 * When a password that fails the minimum policy (10+ chars, at least one
 * letter and one number) is submitted with toStep=4, the installer must NOT
 * advance to step 4. The response must report step 3 with hasErrors + errors
 * and the persisted state file must remain at step 3 so the wizard stays on
 * the credential form. Without this, the state file is left at step 4 and the
 * frontend renders a bogus "Installation complete" screen with credentials
 * that were never written to config.php — the user cannot log in and bounces
 * between index.php and install.php in a loop.
 *
 * install.php is a procedural top-level script (uses __DIR__ relative paths
 * and calls exit() after printing JSON), so these tests spawn it in an
 * isolated PHP subprocess via a temp wrapper that sets up the superglobals.
 * Under CLI SAPI php://input is empty, so install.php falls back to $_POST
 * (install.php lines ~603-607) — the wrapper populates $_POST accordingly.
 */
class InstallerAdvanceTest extends TestCase
{
    private $installPhpPath;
    private $stateFilePath;
    private $configPhpPath;

    protected function setUp(): void
    {
        // install.php lives at the haxcms-php repo root.
        // __DIR__ = .../haxcms-php/system/backend/php/tests/Unit
        // dirname(__DIR__, 5) = .../haxcms-php  (1:tests 2:php 3:backend 4:system 5:root)
        $this->installPhpPath = dirname(__DIR__, 5) . '/install.php';
        $this->stateFilePath = dirname(__DIR__, 5) . '/_config/tmp/.install-state.json';
        $this->configPhpPath = dirname(__DIR__, 5) . '/_config/config.php';
        // Clean any prior state file so each test starts from a known point.
        if (file_exists($this->stateFilePath)) {
            @unlink($this->stateFilePath);
        }
    }

    protected function tearDown(): void
    {
        // Never leave a state file behind — a lingering step=4 state file
        // is exactly the bug these tests guard against.
        if (file_exists($this->stateFilePath)) {
            @unlink($this->stateFilePath);
        }
    }

    /**
     * True when the repo is already fully installed (config.php exists and
     * has been templated with real secrets). In that state install.php's
     * top-level guard redirects to index.php and never reaches the advance
     * handler, so the subprocess tests cannot exercise the rejection path.
     */
    private function isAlreadyInstalled(): bool
    {
        if (!file_exists($this->configPhpPath)) {
            return false;
        }
        $raw = (string) @file_get_contents($this->configPhpPath);
        if ($raw === '') {
            return false;
        }
        // Placeholder tokens present => not finished installing.
        return (
            strpos($raw, 'HAXTHEWEBPRIVATEKEY') === false &&
            strpos($raw, 'HAXTHEWEBREFRESHPRIVATEKEY') === false &&
            strpos($raw, 'jeff') === false &&
            strpos($raw, 'jimmerson') === false
        );
    }

    /**
     * Spawn install.php ?op=advance with a POST body and return the decoded
     * JSON response. The POST array is embedded via var_export so the values
     * survive as a literal PHP array in the wrapper.
     */
    private function runAdvance(array $post): array
    {
        $postLiteral = var_export($post, true);
        $installPath = $this->installPhpPath;
        $script = <<<PHP
<?php
\$_GET = array('op' => 'advance');
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['SCRIPT_NAME'] = '/install.php';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_POST = $postLiteral;
include '$installPath';
PHP;
        $wrapper = sys_get_temp_dir() . '/haxcms_installer_adv_' . uniqid() . '.php';
        file_put_contents($wrapper, $script);
        $output = shell_exec('php ' . escapeshellarg($wrapper) . ' 2>/dev/null');
        @unlink($wrapper);
        $data = json_decode((string) $output, true);
        return is_array($data) ? $data : array();
    }

    /**
     * Load install.php's function definitions (default, no-op path so it
     * neither exits nor runs the install) and invoke the password policy
     * function on the given input.
     */
    private function callPolicy(string $password): ?bool
    {
        $installPath = $this->installPhpPath;
        $script = <<<PHP
<?php
\$_GET = array();
\$_SERVER['SCRIPT_NAME'] = '/install.php';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
include '$installPath';
ob_end_clean();
echo json_encode(array('result' => haxcmsInstallerPasswordMeetsPolicy(\$argv[1])));
PHP;
        $wrapper = sys_get_temp_dir() . '/haxcms_installer_pol_' . uniqid() . '.php';
        file_put_contents($wrapper, $script);
        $output = shell_exec('php ' . escapeshellarg($wrapper) . ' ' . escapeshellarg($password) . ' 2>/dev/null');
        @unlink($wrapper);
        $data = json_decode((string) $output, true);
        return is_array($data) && array_key_exists('result', $data) ? (bool) $data['result'] : null;
    }

    /**
     * Generate a password via install.php and verify it satisfies the
     * policy. The auto-generated password is used by the hosting-provider
     * direct-install path when no password is supplied, so it must always
     * meet the policy (incl. the symbol requirement).
     */
    private function generatedPasswordMeetsPolicy(): ?bool
    {
        $installPath = $this->installPhpPath;
        $script = <<<PHP
<?php
\$_GET = array();
\$_SERVER['SCRIPT_NAME'] = '/install.php';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
include '$installPath';
ob_end_clean();
\$gen = haxcmsInstallerGeneratePassword();
echo json_encode(array('password' => \$gen, 'meets' => haxcmsInstallerPasswordMeetsPolicy(\$gen)));
PHP;
        $wrapper = sys_get_temp_dir() . '/haxcms_installer_gen_' . uniqid() . '.php';
        file_put_contents($wrapper, $script);
        $output = shell_exec('php ' . escapeshellarg($wrapper) . ' 2>/dev/null');
        @unlink($wrapper);
        $data = json_decode((string) $output, true);
        return is_array($data) && array_key_exists('meets', $data) ? (bool) $data['meets'] : null;
    }

    // =========================================================================
    // Password policy function (haxcmsInstallerPasswordMeetsPolicy)
    // =========================================================================

    public function testPolicyRejectsShortPassword(): void
    {
        $this->assertFalse($this->callPolicy('Ab1'));
    }

    public function testPolicyRejectsLettersOnly(): void
    {
        // 10 chars but no number, no uppercase, no symbol.
        $this->assertFalse($this->callPolicy('abcdefghij'));
    }

    public function testPolicyRejectsNumbersOnly(): void
    {
        // 10 chars but no letter.
        $this->assertFalse($this->callPolicy('1234567890'));
    }

    public function testPolicyRejectsNoSymbol(): void
    {
        // 9 chars, upper + lower + number, but no symbol.
        $this->assertFalse($this->callPolicy('GoodPass1'));
    }

    public function testPolicyRejectsNoUppercase(): void
    {
        // 8 chars, lower + number + symbol, but no uppercase.
        $this->assertFalse($this->callPolicy('goodpa1!'));
    }

    public function testPolicyRejectsNoLowercase(): void
    {
        // 8 chars, upper + number + symbol, but no lowercase.
        $this->assertFalse($this->callPolicy('GOODPA1!'));
    }

    public function testPolicyRejectsNoNumber(): void
    {
        // 9 chars, upper + lower + symbol, but no number.
        $this->assertFalse($this->callPolicy('GoodPass!'));
    }

    public function testPolicyAcceptsCompliantPassword(): void
    {
        // 8 chars (boundary), upper + lower + number + symbol.
        $this->assertTrue($this->callPolicy('GoodPa1!'));
    }

    public function testGeneratedPasswordMeetsPolicy(): void
    {
        // The auto-generated password (direct-install path) must always
        // satisfy the policy; otherwise a hoster that sends no password
        // would get an admin password that fails its own validation.
        $this->assertTrue($this->generatedPasswordMeetsPolicy());
    }

    // =========================================================================
    // ?op=advance integration — weak password must not advance to step 4
    // =========================================================================

    public function testWeakPasswordResponseStaysOnStep3(): void
    {
        if ($this->isAlreadyInstalled()) {
            $this->markTestSkipped('Repo already installed; install.php guard redirects away.');
        }
        $data = $this->runAdvance(array(
            'toStep' => 4,
            'language' => 'en',
            'username' => 'admin',
            'password' => 'short1',
        ));
        $this->assertArrayHasKey('step', $data, 'Advance handler should return JSON with a step');
        $this->assertSame(3, $data['step'], 'Weak password must keep the wizard on step 3, not step 4');
        $this->assertTrue(!empty($data['hasErrors']), 'Response must flag hasErrors on password rejection');
        $this->assertNotEmpty($data['errors'], 'Response must include error messages');
    }

    public function testWeakPasswordPersistsStateAtStep3(): void
    {
        if ($this->isAlreadyInstalled()) {
            $this->markTestSkipped('Repo already installed; install.php guard redirects away.');
        }
        $this->runAdvance(array(
            'toStep' => 4,
            'language' => 'en',
            'username' => 'admin',
            'password' => 'short1',
        ));
        $this->assertFileExists($this->stateFilePath, 'State file should exist after a rejected advance');
        $state = json_decode((string) file_get_contents($this->stateFilePath), true);
        $this->assertIsArray($state);
        $this->assertSame(3, (int) $state['step'], 'Persisted state must be step 3 after password rejection');
    }

    public function testWeakPasswordDoesNotWriteConfigPhp(): void
    {
        if ($this->isAlreadyInstalled()) {
            $this->markTestSkipped('Repo already installed; install.php guard redirects away.');
        }
        $hadConfig = file_exists($this->configPhpPath);
        $this->runAdvance(array(
            'toStep' => 4,
            'language' => 'en',
            'username' => 'admin',
            'password' => 'short1',
        ));
        // A rejected install must not template config.php with secrets.
        if (!$hadConfig) {
            $this->assertFileDoesNotExist($this->configPhpPath, 'Rejected install must not create config.php');
        }
    }
}
