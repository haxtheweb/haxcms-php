<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the HAXCMS login seam (lib/HAXCMS.php).
 *
 * Covers:
 *   - testLogin: user/superUser credential match, adminFallback, and the
 *     haxcms-login-test event dispatch path.
 *   - validateUser: strict name match + haxcms-validate-user event path.
 *   - verifyStoredPassword (private): exercised THROUGH testLogin -- a stored
 *     password_hash() output exercises the password_verify branch; a plaintext
 *     string exercises the safeStringCompare branch.
 *   - maybeUpgradePlaintextPassword (private): exercised THROUGH testLogin on
 *     the legacy-plaintext success path -- verifies config.php is rewritten to
 *     a hash and in-memory state is synced, plus the read-only-config no-rewrite.
 *   - safeStringCompare (public): timing-safe comparison contract.
 *   - getIntConfigValue (public) / getLoginRateLimitSettings: defaults, config
 *     overrides, bounds clamping, and the enabled===TRUE strict check.
 *
 * Instances use newInstanceWithoutConstructor to skip the stateful god-class
 * constructor; the methods under test only read user/superUser/config/
 * configDirectory/privateKey/salt/events. $_SERVER['SERVER_SOFTWARE'] is set
 * so isCLI() returns false (suite convention). configDirectory points at a
 * per-test temp dir so the plaintext-upgrade path can write a real config.php
 * fixture; rrmdir in tearDown.
 *
 * Expected values derive from the credential-matching contract (independent
 * password_hash/password_verify/hash_equals computations) and the rate-limit
 * spec (stated defaults and bounds), never from re-running the production code.
 */
class HAXCMSLoginTest extends TestCase
{
    private $haxcms;
    private $tmpConfigDir;
    private $savedServerSoftware;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->privateKey = 'pk';
        $this->haxcms->salt = 's';
        $this->haxcms->config = new stdClass();
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = null;
        $this->haxcms->user->password = null;
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = null;
        $this->haxcms->superUser->password = null;
        // events is private and uninitialized after newInstanceWithoutConstructor;
        // initialize to an empty array so addEventListener/dispatchEvent behave
        // cleanly per test.
        $this->setPrivateProperty('events', array());
        // Per-test temp config directory for maybeUpgradePlaintextPassword.
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_login_' . uniqid();
        mkdir($this->tmpConfigDir, 0777, true);
        $this->haxcms->configDirectory = $this->tmpConfigDir;
        // Force isCLI() false (suite convention).
        $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
    }

    protected function tearDown(): void
    {
        if ($this->savedServerSoftware !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        $this->rrmdir($this->tmpConfigDir);
    }

    private function setPrivateProperty(string $name, $value): void
    {
        $ref = new ReflectionClass(HAXCMS::class);
        $prop = $ref->getProperty($name);
        $prop->setValue($this->haxcms, $value);
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
     * Write a minimal config.php fixture containing a plaintext password line
     * for the given account. Returns the absolute path to the file.
     */
    private function writeConfigPhp(string $account, string $plaintext, string $name = 'alice'): string
    {
        $configPath = $this->tmpConfigDir . '/config.php';
        $content = "<?php\n"
            . "\$HAXCMS->" . $account . "->name = '" . $name . "';\n"
            . "\$HAXCMS->" . $account . "->password = '" . $plaintext . "';\n";
        file_put_contents($configPath, $content);
        return $configPath;
    }

    /**
     * Extract the password value from a config.php line for the given account.
     */
    private function extractPasswordFromConfig(string $account): ?string
    {
        $configPath = $this->tmpConfigDir . '/config.php';
        if (!file_exists($configPath)) {
            return null;
        }
        $content = file_get_contents($configPath);
        $pattern = '/\$HAXCMS->' . preg_quote($account, '/') . '->password\s*=\s*[\'"]([^\'"]*)[\'"]/';
        if (preg_match($pattern, $content, $m)) {
            return $m[1];
        }
        return null;
    }

    // ===== safeStringCompare (public, direct) =====

    public function testSafeStringCompareEqualStrings(): void
    {
        $this->assertTrue($this->haxcms->safeStringCompare('secret', 'secret'));
    }

    public function testSafeStringCompareDifferentStringsSameLength(): void
    {
        $this->assertFalse($this->haxcms->safeStringCompare('secret', 'secreX'));
    }

    public function testSafeStringCompareDifferentLengths(): void
    {
        $this->assertFalse($this->haxcms->safeStringCompare('short', 'muchlonger'));
    }

    public static function nonStringProvider(): array
    {
        return [
            'null stored' => [null, 'x'],
            'null submitted' => ['x', null],
            'int stored' => [123, 'x'],
            'array submitted' => ['x', array('y')],
        ];
    }

    #[DataProvider('nonStringProvider')]
    public function testSafeStringCompareRejectsNonStringArgs($stored, $submitted): void
    {
        $this->assertFalse($this->haxcms->safeStringCompare($stored, $submitted));
    }

    // ===== testLogin: hash branch (verifyStoredPassword password_verify path) =====

    public function testLoginReturnsTrueForCorrectUserNameAndPasswordHash(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = password_hash('secret', PASSWORD_DEFAULT);
        $this->assertTrue($this->haxcms->testLogin('alice', 'secret'));
    }

    public function testLoginReturnsFalseForWrongPasswordWithHash(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = password_hash('secret', PASSWORD_DEFAULT);
        // name matches but password doesn't -> event path, default grantAccess=false
        $this->assertFalse($this->haxcms->testLogin('alice', 'wrong'));
    }

    public function testLoginReturnsFalseForWrongNameWithHash(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = password_hash('secret', PASSWORD_DEFAULT);
        $this->assertFalse($this->haxcms->testLogin('bob', 'secret'));
    }

    // ===== testLogin: plaintext branch (verifyStoredPassword safeStringCompare path) =====

    public function testLoginReturnsTrueForLegacyPlaintextUserPassword(): void
    {
        // No config.php in the temp dir, so maybeUpgradePlaintextPassword is a
        // no-op (file_exists check fails). This isolates the plaintext-match
        // behavior from the upgrade behavior.
        $this->assertFalse(file_exists($this->tmpConfigDir . '/config.php'));
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'legacyplain';
        $this->assertTrue($this->haxcms->testLogin('alice', 'legacyplain'));
        // unchanged -- no upgrade occurred (no config.php to rewrite)
        $this->assertSame('legacyplain', $this->haxcms->user->password);
    }

    public function testLoginReturnsFalseForWrongPlaintextPassword(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'legacyplain';
        $this->assertFalse($this->haxcms->testLogin('alice', 'wrongplain'));
    }

    public function testLoginReturnsFalseForEmptyStoredPassword(): void
    {
        // verifyStoredPassword returns false for empty stored -- exercised
        // through testLogin: name matches but password check fails, so the
        // event path runs with default grantAccess=false.
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = '';
        $this->assertFalse($this->haxcms->testLogin('alice', 'anything'));
    }

    // ===== testLogin: adminFallback / superUser =====

    public function testLoginAdminFallbackSucceedsWithSuperUser(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = password_hash('userpass', PASSWORD_DEFAULT);
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = password_hash('adminpass', PASSWORD_DEFAULT);
        $this->assertTrue($this->haxcms->testLogin('admin', 'adminpass', true));
    }

    public function testLoginAdminFallbackFalseDoesNotFallBackToSuperUser(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'x';
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = password_hash('adminpass', PASSWORD_DEFAULT);
        // adminFallback=false: superUser credentials are NOT checked; falls to
        // the event path with default grantAccess=false.
        $this->assertFalse($this->haxcms->testLogin('admin', 'adminpass', false));
    }

    public function testLoginAdminFallbackFailsWithWrongSuperUserPassword(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'x';
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = password_hash('adminpass', PASSWORD_DEFAULT);
        $this->assertFalse($this->haxcms->testLogin('admin', 'wrong', true));
    }

    // ===== testLogin: haxcms-login-test event =====

    public function testLoginEventFiresAndListenerCanGrantAccess(): void
    {
        // Neither user nor superUser match -> event path. A listener that sets
        // grantAccess=true makes testLogin return true.
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'x';
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = 'y';
        $this->haxcms->addEventListener('haxcms-login-test', function (&$usr) {
            $usr->grantAccess = true;
        });
        $this->assertTrue($this->haxcms->testLogin('carol', 'whatever'));
    }

    public function testLoginEventPayloadCarriesNamePasswordAndAdminFallback(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'x';
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = 'y';
        $captured = null;
        $this->haxcms->addEventListener('haxcms-login-test', function (&$usr) use (&$captured) {
            $captured = $usr;
        });
        $result = $this->haxcms->testLogin('carol', 'pass', true);
        // no listener granted access -> false
        $this->assertFalse($result);
        // event payload carries the submitted credentials + adminFallback flag
        $this->assertNotNull($captured);
        $this->assertSame('carol', $captured->name);
        $this->assertSame('pass', $captured->password);
        $this->assertTrue($captured->adminFallback);
        $this->assertFalse($captured->grantAccess);
    }

    // ===== validateUser =====

    public function testValidateUserTrueForUserNameMatch(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->assertTrue($this->haxcms->validateUser('alice'));
    }

    public function testValidateUserTrueForSuperUserNameMatch(): void
    {
        $this->haxcms->superUser->name = 'admin';
        $this->assertTrue($this->haxcms->validateUser('admin'));
    }

    public function testValidateUserFalseForNoMatch(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->superUser->name = 'admin';
        // no match -> event path, default grantAccess=false
        $this->assertFalse($this->haxcms->validateUser('nobody'));
    }

    public function testValidateUserIsCaseSensitiveAndTypeStrict(): void
    {
        // validateUser uses === (strict equality), unlike testLogin's name
        // check which uses safeStringCompare. 'Alice' !== 'alice'.
        $this->haxcms->user->name = 'Alice';
        $this->assertFalse($this->haxcms->validateUser('alice'));
        $this->assertTrue($this->haxcms->validateUser('Alice'));
    }

    public function testValidateUserEventListenerCanGrantAccess(): void
    {
        $this->haxcms->user->name = 'alice';
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->addEventListener('haxcms-validate-user', function (&$usr) {
            $usr->grantAccess = true;
        });
        $this->assertTrue($this->haxcms->validateUser('carol'));
    }

    // ===== maybeUpgradePlaintextPassword (private, through testLogin) =====

    public function testLegacyPlaintextPasswordUpgradedToHashOnSuccessfulLogin(): void
    {
        $this->writeConfigPhp('user', 'legacyplain', 'alice');
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'legacyplain';
        $this->assertTrue($this->haxcms->testLogin('alice', 'legacyplain'));
        // config.php rewritten: the password line is now a password_hash() output
        $newPass = $this->extractPasswordFromConfig('user');
        $this->assertNotNull($newPass);
        $info = password_get_info($newPass);
        $this->assertTrue(isset($info['algo']) && $info['algo'] !== null && $info['algo'] !== false);
        // independent verification: standard password_verify confirms the hash
        // matches the submitted plaintext, without importing the production class
        $this->assertTrue(password_verify('legacyplain', $newPass));
        // in-memory state synced to the hash
        $this->assertSame($newPass, $this->haxcms->user->password);
    }

    public function testReadOnlyConfigLoginSucceedsButFileNotRewritten(): void
    {
        $configPath = $this->writeConfigPhp('user', 'legacyplain', 'alice');
        chmod($configPath, 0444);
        if (is_writable($configPath)) {
            chmod($configPath, 0666);
            $this->markTestSkipped('Cannot make config.php unwritable (running as root) -- read-only upgrade path cannot be exercised');
        }
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'legacyplain';
        // login still succeeds (plaintext match) ...
        $this->assertTrue($this->haxcms->testLogin('alice', 'legacyplain'));
        // ... but the file is NOT rewritten -- legacy plaintext remains
        $this->assertSame('legacyplain', $this->extractPasswordFromConfig('user'));
        // in-memory state also unchanged
        $this->assertSame('legacyplain', $this->haxcms->user->password);
        // restore writable so tearDown can clean up
        chmod($configPath, 0666);
    }

    public function testSuperUserLegacyPlaintextUpgradedViaAdminFallback(): void
    {
        $this->writeConfigPhp('superUser', 'adminplain', 'admin');
        $this->haxcms->user->name = 'alice';
        $this->haxcms->user->password = 'x';
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->superUser->password = 'adminplain';
        $this->assertTrue($this->haxcms->testLogin('admin', 'adminplain', true));
        $newPass = $this->extractPasswordFromConfig('superUser');
        $this->assertNotNull($newPass);
        $this->assertTrue(password_verify('adminplain', $newPass));
        $this->assertSame($newPass, $this->haxcms->superUser->password);
    }

    // ===== getIntConfigValue (public, direct) =====

    public function testGetIntConfigValueReturnsFallbackForNonNumeric(): void
    {
        $this->assertSame(42, $this->haxcms->getIntConfigValue('abc', 42, 1, 100));
    }

    public function testGetIntConfigValueClampsBelowMin(): void
    {
        $this->assertSame(1, $this->haxcms->getIntConfigValue(0, 42, 1, 100));
    }

    public function testGetIntConfigValueClampsAboveMax(): void
    {
        $this->assertSame(100, $this->haxcms->getIntConfigValue(999, 42, 1, 100));
    }

    public function testGetIntConfigValuePreservesInRange(): void
    {
        $this->assertSame(50, $this->haxcms->getIntConfigValue(50, 42, 1, 100));
    }

    // ===== getLoginRateLimitSettings =====

    public function testDefaultsWhenNoSecurityConfig(): void
    {
        // config is a bare stdClass with no security property
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $this->assertTrue($settings->enabled);
        $this->assertSame(15 * 60 * 1000, $settings->windowMs);
        $this->assertSame(5, $settings->maxAttempts);
        $this->assertSame(15 * 60 * 1000, $settings->blockMs);
    }

    public function testDefaultsWhenSecurityExistsButNoLoginRateLimit(): void
    {
        $this->haxcms->config->security = new stdClass();
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $this->assertTrue($settings->enabled);
        $this->assertSame(900000, $settings->windowMs);
        $this->assertSame(5, $settings->maxAttempts);
        $this->assertSame(900000, $settings->blockMs);
    }

    public function testConfigOverridesHonored(): void
    {
        $rll = new stdClass();
        $rll->enabled = false;
        $rll->windowMs = 60000;
        $rll->maxAttempts = 10;
        $rll->blockMs = 120000;
        $this->haxcms->config->security = new stdClass();
        $this->haxcms->config->security->loginRateLimit = $rll;
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $this->assertFalse($settings->enabled);
        $this->assertSame(60000, $settings->windowMs);
        $this->assertSame(10, $settings->maxAttempts);
        $this->assertSame(120000, $settings->blockMs);
    }

    public static function enabledProvider(): array
    {
        // enabled is only TRUE when $cfg->enabled === TRUE (strict). When the
        // property is omitted (or null, which isset() treats as absent) the
        // default TRUE is used.
        return [
            'true enables' => [true, true],
            'false disables' => [false, false],
            'integer 1 is not strictly true -> disabled' => [1, false],
            'string "yes" is not strictly true -> disabled' => ['yes', false],
            'integer 0 is not strictly true -> disabled' => [0, false],
            'enabled omitted -> default true' => ['__omit', true],
            'enabled null: isset false -> default true' => [null, true],
        ];
    }

    #[DataProvider('enabledProvider')]
    public function testEnabledOnlyWhenStrictlyTrue($enabledValue, bool $expected): void
    {
        $rll = new stdClass();
        if ($enabledValue !== '__omit') {
            $rll->enabled = $enabledValue;
        }
        $this->haxcms->config->security = new stdClass();
        $this->haxcms->config->security->loginRateLimit = $rll;
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $this->assertSame($expected, $settings->enabled);
    }

    public static function boundsProvider(): array
    {
        // [property, configured value, expected clamped/resolved value]
        // Bounds from the rate-limit spec:
        //   windowMs    10000 .. 86400000  (fallback 900000 = 15 min)
        //   maxAttempts 1 .. 1000          (fallback 5)
        //   blockMs     10000 .. 86400000  (fallback 900000 = 15 min)
        return [
            'windowMs below min clamped to 10000' => ['windowMs', 5000, 10000],
            'windowMs above max clamped to 86400000' => ['windowMs', 999999999, 86400000],
            'windowMs at min preserved' => ['windowMs', 10000, 10000],
            'windowMs at max preserved' => ['windowMs', 86400000, 86400000],
            'windowMs non-numeric uses fallback' => ['windowMs', 'abc', 900000],
            'maxAttempts below min clamped to 1' => ['maxAttempts', 0, 1],
            'maxAttempts above max clamped to 1000' => ['maxAttempts', 5000, 1000],
            'maxAttempts at min preserved' => ['maxAttempts', 1, 1],
            'maxAttempts at max preserved' => ['maxAttempts', 1000, 1000],
            'maxAttempts non-numeric uses fallback' => ['maxAttempts', 'xyz', 5],
            'blockMs below min clamped to 10000' => ['blockMs', 5000, 10000],
            'blockMs above max clamped to 86400000' => ['blockMs', 999999999, 86400000],
            'blockMs at min preserved' => ['blockMs', 10000, 10000],
            'blockMs at max preserved' => ['blockMs', 86400000, 86400000],
            'blockMs non-numeric uses fallback' => ['blockMs', 'foo', 900000],
        ];
    }

    #[DataProvider('boundsProvider')]
    public function testBoundsClampingAndFallback(string $property, $value, int $expected): void
    {
        $rll = new stdClass();
        $rll->{$property} = $value;
        $this->haxcms->config->security = new stdClass();
        $this->haxcms->config->security->loginRateLimit = $rll;
        $settings = $this->haxcms->getLoginRateLimitSettings();
        $this->assertSame($expected, $settings->{$property});
    }
}
