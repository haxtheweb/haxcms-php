<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fixes from security_best_practices_report_2026-10-19.md:
 *  - H-2  backend tree denied over HTTP + CLI guards on scripts
 *  - M-1  installer "installed" detection uses exact placeholder assignments
 *  - M-2  _archived/.htaccess boilerplate exists and denies
 *  - L-2  refresh cookie is Secure on HTTPS
 *  - L-4  git option-injection / remote-URL guards
 *  - L-5  site shell color/lang/name encoding
 *  - L-7  request-token compares are type-strict
 */
class SecurityAuditFixesTest extends TestCase
{
    private $repoRoot;
    private $savedServerSoftware;

    protected function setUp(): void
    {
        // __DIR__ = <repo>/system/backend/php/tests/Unit
        $this->repoRoot = dirname(__DIR__, 5);
        $this->savedServerSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->savedServerSoftware !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
    }

    private function bareHaxcms()
    {
        $h = (new ReflectionClass(HAXCMS::class))->newInstanceWithoutConstructor();
        $h->privateKey = 'pk';
        $h->refreshPrivateKey = 'rpk';
        $h->salt = 's';
        $h->config = new stdClass();
        return $h;
    }

    // ---- H-2 -------------------------------------------------------------

    public function testBackendTreeDeniesHttpAccess(): void
    {
        $htaccess = (string) @file_get_contents($this->repoRoot . '/system/backend/php/.htaccess');
        $this->assertStringContainsString('Require all denied', $htaccess);
        $this->assertStringContainsString('Deny from all', $htaccess);
    }

    public function testTestAndDiagnosticScriptsAreCliOnly(): void
    {
        $scripts = array_merge(
            glob($this->repoRoot . '/system/backend/php/tests/*.php'),
            glob($this->repoRoot . '/system/backend/php/scripts/*.php')
        );
        $this->assertNotEmpty($scripts);
        foreach ($scripts as $script) {
            $src = (string) file_get_contents($script);
            $this->assertMatchesRegularExpression(
                '/PHP_SAPI|php_sapi_name\(\)/',
                $src,
                basename($script) . ' must refuse non-CLI execution'
            );
        }
    }

    // ---- H-1 -------------------------------------------------------------

    public function testDotDirectoryDenyRulePresentInRootAndSiteHtaccess(): void
    {
        $rule = 'RewriteRule "(^|/)\.(?!well-known(/|$))" - [F]';
        foreach (array('.htaccess', 'system/boilerplate/site/.htaccess') as $rel) {
            $conf = (string) file_get_contents($this->repoRoot . '/' . $rel);
            $rulePos = strpos($conf, $rule);
            $enginePos = strpos($conf, 'RewriteEngine on');
            $this->assertNotFalse($rulePos, $rel . ' must deny dot-directories');
            $this->assertNotFalse($enginePos, $rel);
            $this->assertGreaterThan($enginePos, $rulePos, $rel . ': rule must follow RewriteEngine on');
            // first RewriteRule in the file, so nothing can short-circuit it
            $this->assertSame($rulePos, strpos($conf, 'RewriteRule'), $rel . ': must be the first RewriteRule');
        }
    }

    public function testDotDirectoryDenyPatternSemantics(): void
    {
        // Same PCRE pattern Apache evaluates against the per-directory path.
        $pattern = '#(^|/)\.(?!well-known(/|$))#';
        foreach (array('.git/HEAD', '.git/config', '_sites/blog/.git/objects/ab/cd', '_archived/x/.git/HEAD', '.github/workflows/ci.yml', 'files/.secret', '.env') as $denied) {
            $this->assertSame(1, preg_match($pattern, $denied), $denied . ' must be denied');
        }
        foreach (array('.well-known/agent-skills/index.json', '_sites/blog/.well-known/api-catalog', '_sites/blog/files/a.b.png', 'build/es6/node_modules/lit/index.js', 'index.html', 'site.json') as $allowed) {
            $this->assertSame(0, preg_match($pattern, $allowed), $allowed . ' must stay reachable');
        }
    }

    // ---- M-1 -------------------------------------------------------------

    public function testBoilerplateConfigSourceIsNotTemplated(): void
    {
        $raw = file_get_contents($this->repoRoot . '/system/boilerplate/systemsetup/config.php');
        $this->assertTrue(HAXCMSInstallGate::configSourceIsNotTemplated($raw));
    }

    public function testJeffSubstringNoLongerReopensInstaller(): void
    {
        $raw = "<?php\n" .
            "\$HAXCMS->privateKey = 'a1b2';\n" .
            "\$HAXCMS->refreshPrivateKey = 'c3d4';\n" .
            "// note for jeff and jimmerson fans\n" .
            "\$HAXCMS->superUser->name = 'jeffrey';\n" .
            "\$HAXCMS->superUser->password = '\$2y\$10\$abcdefghijklmnopqrstuv';\n";
        $this->assertFalse(HAXCMSInstallGate::configSourceIsNotTemplated($raw));
    }

    public function testEachExactPlaceholderAssignmentStillDetected(): void
    {
        $templated = "<?php\n\$HAXCMS->privateKey = 'x';\n\$HAXCMS->refreshPrivateKey = 'y';\n\$HAXCMS->superUser->password = 'z';\n";
        $this->assertFalse(HAXCMSInstallGate::configSourceIsNotTemplated($templated));
        $this->assertTrue(HAXCMSInstallGate::configSourceIsNotTemplated(str_replace("privateKey = 'x'", "privateKey = 'HAXTHEWEBPRIVATEKEY'", $templated)));
        $this->assertTrue(HAXCMSInstallGate::configSourceIsNotTemplated(str_replace("refreshPrivateKey = 'y'", 'refreshPrivateKey = "HAXTHEWEBREFRESHPRIVATEKEY"', $templated)));
        $this->assertTrue(HAXCMSInstallGate::configSourceIsNotTemplated(str_replace("password = 'z'", "password = 'jimmerson'", $templated)));
    }

    public function testUnreadableConfigKeepsPreviousBehavior(): void
    {
        $this->assertFalse(HAXCMSInstallGate::configSourceIsNotTemplated(false));
    }

    public function testEntryPointsUseExactGateNotSubstringScan(): void
    {
        foreach (array('index.php', 'install.php') as $rel) {
            $src = (string) file_get_contents($this->repoRoot . '/' . $rel);
            $this->assertStringContainsString('HAXCMSInstallGate::configSourceIsNotTemplated', $src, $rel);
            $this->assertStringNotContainsString("'jeff') !== false", $src, $rel . ' must not substring-scan for jeff');
            $this->assertStringNotContainsString("str_replace('jeff'", $src, $rel . ' must not globally replace jeff');
        }
    }

    // ---- M-2 -------------------------------------------------------------

    public function testArchivedHtaccessBoilerplateDenies(): void
    {
        $htaccess = (string) @file_get_contents($this->repoRoot . '/system/boilerplate/systemsetup/.archived-htaccess');
        $this->assertStringContainsString('Require all denied', $htaccess);
    }

    // ---- L-2 -------------------------------------------------------------

    public function testRefreshCookieSecureOnHttps(): void
    {
        $saved = getenv('NODE_ENV');
        putenv('NODE_ENV');
        try {
            $h = $this->bareHaxcms();
            $h->protocol = 'http';
            $this->assertFalse($h->refreshCookieShouldBeSecure(), 'plain HTTP dev stays non-Secure');
            $h->protocol = 'https';
            $this->assertTrue($h->refreshCookieShouldBeSecure(), 'HTTPS request gets Secure');
            $h->protocol = 'http';
            $h->config->security = (object) array('forceSecureCookie' => true);
            $this->assertTrue($h->refreshCookieShouldBeSecure(), 'forceSecureCookie still honoured');
            $h->config = new stdClass();
            putenv('NODE_ENV=production');
            $this->assertTrue($h->refreshCookieShouldBeSecure(), 'production still Secure');
        } finally {
            if ($saved === false) {
                putenv('NODE_ENV');
            } else {
                putenv('NODE_ENV=' . $saved);
            }
        }
    }

    // ---- L-3 / L-7 -------------------------------------------------------

    public function testRequestTokenKeyIsSeparatedFromJwtKey(): void
    {
        $h = $this->bareHaxcms();
        $direct = rtrim(strtr(base64_encode(hash_hmac('sha256', 'user', 'pks', true)), '+/', '-_'), '=');
        $this->assertNotSame($direct, $h->getRequestToken('user'));
    }

    public function testValidateRequestTokenIsTypeStrict(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $h = $this->bareHaxcms();
        $token = $h->getRequestToken('alice');
        $this->assertTrue($h->validateRequestToken($token, 'alice'));
        $this->assertFalse($h->validateRequestToken(true, 'alice'), 'boolean must not loosely match');
        $this->assertFalse($h->validateRequestToken(0, 'alice'));
        $this->assertFalse($h->validateRequestToken(array($token), 'alice'));
    }

    // ---- L-4 -------------------------------------------------------------

    public function testGitSafeArg(): void
    {
        $this->assertTrue(GitRepo::isSafeArg('master'));
        $this->assertTrue(GitRepo::isSafeArg('feature/x-1'));
        $this->assertFalse(GitRepo::isSafeArg('--receive-pack=touch /tmp/pwn'));
        $this->assertFalse(GitRepo::isSafeArg('-f'));
        $this->assertFalse(GitRepo::isSafeArg(''));
        $this->assertFalse(GitRepo::isSafeArg("main\nevil"));
        $this->assertFalse(GitRepo::isSafeArg(null));
    }

    public function testGitSafeRemoteUrl(): void
    {
        $this->assertTrue(GitRepo::isSafeRemoteUrl('https://github.com/org/site.git'));
        $this->assertTrue(GitRepo::isSafeRemoteUrl('ssh://git@host/org/site.git'));
        $this->assertTrue(GitRepo::isSafeRemoteUrl('git@github.com:org/site.git'));
        $this->assertFalse(GitRepo::isSafeRemoteUrl('ext::sh -c touch% /tmp/pwn'));
        $this->assertFalse(GitRepo::isSafeRemoteUrl('file:///etc'));
        $this->assertFalse(GitRepo::isSafeRemoteUrl('/var/www/other-repo'));
        $this->assertFalse(GitRepo::isSafeRemoteUrl('--upload-pack=x'));
    }

    public function testGitRefusesOptionLikeBranchAndRemote(): void
    {
        $gitBin = trim((string) shell_exec('which git 2>/dev/null'));
        if ($gitBin === '') {
            $this->markTestSkipped('git binary not available');
        }
        Git::set_bin($gitBin);
        $path = sys_get_temp_dir() . '/haxcms_git_guard_' . uniqid();
        $repo = Git::open($path, true);
        try {
            $this->assertSame('', $repo->create_branch('--help'));
            $this->assertSame('', $repo->push('origin', '--receive-pack=touch ' . $path . '/pwned'));
            $this->assertSame('', $repo->set_remote('origin', 'ext::sh -c touch% ' . $path . '/pwned2'));
            $this->assertFileDoesNotExist($path . '/pwned');
            $this->assertFileDoesNotExist($path . '/pwned2');
            $this->assertSame('', trim((string) shell_exec('git -C ' . escapeshellarg($path) . ' remote 2>/dev/null')));
        } finally {
            shell_exec('rm -rf ' . escapeshellarg($path));
        }
    }

    // ---- L-5 -------------------------------------------------------------

    public function testSafeCssColor(): void
    {
        $this->assertSame('var(--simple-colors-default-theme-blue-7, #FF2222)', HAXSiteConfig::safeCssColor('--simple-colors-default-theme-blue-7'));
        $this->assertSame('#FF2222', HAXSiteConfig::safeCssColor('x);}</style><script>alert(1)</script>'));
        $this->assertSame('#FF2222', HAXSiteConfig::safeCssColor(''));
        $this->assertSame('#FF2222', HAXSiteConfig::safeCssColor(null));
    }
}
