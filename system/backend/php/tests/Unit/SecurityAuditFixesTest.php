<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for these security invariants:
 *  - SEC-DEPLOY    backend tree denied over HTTP + CLI guards on scripts
 *  - SEC-INSTALL   installer "installed" detection uses exact placeholder assignments
 *  - SEC-DEPLOY    _archived/.htaccess boilerplate exists and denies
 *  - SEC-SESSION   refresh cookie is Secure on HTTPS
 *  - SEC-EXEC      git option-injection / remote-URL guards
 *  - SEC-SANITIZE  site shell color/lang/name encoding
 *  - SEC-SESSION   request-token compares are type-strict
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

    // ---- SEC-DEPLOY: backend tree + CLI-only scripts ----------------------

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

    // ---- SEC-DEPLOY: dot-directories never served ------------------------

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

    // ---- SEC-DEPLOY: the HAXcms version is never served ------------------

    public function testRootManifestStatesNoVersion(): void
    {
        $manifest = json_decode((string) file_get_contents($this->repoRoot . '/manifest.json'));
        $this->assertInstanceOf(\stdClass::class, $manifest);
        $this->assertFalse(property_exists($manifest, 'version'), 'manifest.json is public: no HAXcms version');
    }

    // `yarn release` (commit-and-tag-version) bumps manifest.json by default,
    // which put the version back in the public manifest; .versionrc.json
    // limits it to package.json
    public function testReleaseToolBumpsOnlyPackageJson(): void
    {
        $config = json_decode((string) file_get_contents($this->repoRoot . '/.versionrc.json'), true);
        $this->assertIsArray($config, '.versionrc.json must exist and parse');
        foreach (array('bumpFiles', 'packageFiles') as $key) {
            $files = array_map(function ($entry) {
                return is_array($entry) ? $entry['filename'] : $entry;
            }, $config[$key]);
            $this->assertSame(array('package.json'), $files, $key . ' must list only package.json');
        }
    }

    public function testReleaseFilesThatStateTheVersionAreRefused(): void
    {
        $rule = 'RewriteRule "^(package\.json|CHANGELOG\.md|system/platform-manifest\.json)$" - [F]';
        $conf = (string) file_get_contents($this->repoRoot . '/.htaccess');
        $rulePos = strpos($conf, $rule);
        $this->assertNotFalse($rulePos, '.htaccess must refuse the release files');
        $this->assertGreaterThan(strpos($conf, 'RewriteEngine on'), $rulePos);
        // the root .htaccess sees paths relative to the install root
        $pattern = '#^(package\.json|CHANGELOG\.md|system/platform-manifest\.json)$#';
        foreach (array('package.json', 'CHANGELOG.md', 'system/platform-manifest.json') as $denied) {
            $this->assertSame(1, preg_match($pattern, $denied), $denied . ' must be refused');
        }
        foreach (array('_sites/blog/package.json', 'manifest.json', 'build/es6/node_modules/lit/package.json', 'system/api/v1/index.php') as $allowed) {
            $this->assertSame(0, preg_match($pattern, $allowed), $allowed . ' must stay reachable');
        }
        $nginx = (string) file_get_contents($this->repoRoot . '/scripts/nginx/haxcms-nginx.conf');
        foreach (array('/package.json', '/CHANGELOG.md', '/system/platform-manifest.json') as $location) {
            $this->assertStringContainsString('location = ' . $location . ' { deny all; return 404; }', $nginx);
        }
    }

    // ---- SEC-INSTALL: exact install gate ----------------------------------

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

    // ---- SEC-DEPLOY: _archived and _published denies ---------------------

    public function testArchivedHtaccessBoilerplateDenies(): void
    {
        $htaccess = (string) @file_get_contents($this->repoRoot . '/system/boilerplate/systemsetup/.archived-htaccess');
        $this->assertStringContainsString('Require all denied', $htaccess);
    }

    public function testPublishedHtaccessBoilerplateBlocksListingButServesZips(): void
    {
        $htaccess = (string) @file_get_contents($this->repoRoot . '/system/boilerplate/systemsetup/.published-htaccess');
        $this->assertStringContainsString('Options -Indexes', $htaccess);
        $this->assertStringContainsString('<FilesMatch "\\.zip$">', $htaccess);
        $this->assertStringContainsString('Require all granted', $htaccess);
        $this->assertStringContainsString('Require all denied', $htaccess);
        $installer = (string) file_get_contents($this->repoRoot . '/install.php');
        $this->assertStringContainsString("systemsetup/.published-htaccess', __DIR__ . '/_published/.htaccess'", $installer);
        $nginx = (string) file_get_contents($this->repoRoot . '/scripts/nginx/haxcms-nginx.conf');
        $this->assertStringContainsString('location ^~ /_published/', $nginx);
        $this->assertStringContainsString('autoindex off', $nginx);
    }

    public function testInstallTokenLivesUnderConfigWithLegacyFallback(): void
    {
        $installer = (string) file_get_contents($this->repoRoot . '/install.php');
        $this->assertStringContainsString("__DIR__ . '/_config/_installtoken.txt'", $installer);
        $this->assertStringContainsString("\$installTokenLegacyFilePath = __DIR__ . '/_installtoken.txt'", $installer);
        // _config is deny-all over HTTP, so the token cannot be fetched
        $configHtaccess = (string) file_get_contents($this->repoRoot . '/system/boilerplate/systemsetup/.htaccess');
        $this->assertStringContainsString('Require all denied', $configHtaccess);
    }

    // ---- hardening sweep -------------------------------------------------

    public function testSiteFilesRejectSvgAndPptxMapHasNoSvg(): void
    {
        $src = (string) file_get_contents($this->repoRoot . '/system/backend/php/lib/pptxDeckHelper.php');
        $this->assertStringNotContainsString("'.svg'  => 'image/svg+xml'", $src);
        $ops = new Operations();
        $m = new ReflectionMethod(Operations::class, 'normalizeSiteFilePath');
        $this->assertFalse($m->invoke($ops, 'custom/logo.svg'));
        $this->assertSame('custom/logo.png', $m->invoke($ops, 'custom/logo.png'));
    }

    public function testSiteFilesOnlyWriteCustomAssetsAndNoMarkup(): void
    {
        $ops = new Operations();
        $m = new ReflectionMethod(Operations::class, 'normalizeSiteFilePath');
        // theme/ left the boilerplate (haxtheweb/issues#3337): never a target
        $this->assertFalse($m->invoke($ops, 'theme/theme.css'));
        $this->assertFalse($m->invoke($ops, 'theme/theme.html'));
        // browser-renderable markup is never fetched into the site tree
        foreach (array('x.html', 'x.htm', 'x.HTML', 'x.xhtml', 'x.xml', 'x.svg') as $name) {
            $this->assertFalse($m->invoke($ops, 'custom/' . $name), $name);
            $this->assertFalse($m->invoke($ops, 'custom/build/' . $name), $name);
        }
        // the custom build and its ordinary assets still import
        $this->assertSame('custom/build/custom.es6.js', $m->invoke($ops, 'custom/build/custom.es6.js'));
        $this->assertSame('custom/build/custom.css', $m->invoke($ops, 'custom/build/custom.css'));
        $this->assertSame('custom/assets/a.woff2', $m->invoke($ops, 'custom/assets/a.woff2'));
        // a bare custom/ prefix lookalike is not the custom directory
        $this->assertFalse($m->invoke($ops, 'customx/a.js'));
    }

    public function testDownloadGuardsAlsoSandboxTheResponse(): void
    {
        $htaccess = (string) file_get_contents($this->repoRoot . '/.htaccess');
        $this->assertSame(2, substr_count($htaccess, 'Header always append Content-Security-Policy "sandbox"' . "\n"));
        $this->assertStringContainsString('Header always append Content-Security-Policy "sandbox" env=force_html_download', $htaccess);
        $files = (string) file_get_contents($this->repoRoot . '/system/boilerplate/site/files/.htaccess');
        $this->assertStringContainsString('Header always append Content-Security-Policy "sandbox"', $files);
        foreach (array('/scripts/nginx/haxcms-nginx.conf', '/.ddev/nginx-site.conf') as $conf) {
            $body = (string) file_get_contents($this->repoRoot . $conf);
            // every markup download location (they all end in "xml|svg)$ {")
            $this->assertSame(
                substr_count($body, 'xml|svg)$ {'),
                substr_count($body, 'add_header Content-Security-Policy sandbox always;'),
                $conf . ': every forced markup download is sandboxed'
            );
        }
    }

    public function testForceDownloadRulesCoverCustomAndThemeMarkup(): void
    {
        $htaccess = (string) file_get_contents($this->repoRoot . '/.htaccess');
        $this->assertStringContainsString('m#/(?:custom|theme)/.*\\.(?:xhtml|xml|svg)$#i', $htaccess);
        $nginx = (string) file_get_contents($this->repoRoot . '/scripts/nginx/haxcms-nginx.conf');
        $this->assertStringContainsString('location ~* /(?:custom|theme)/.*\\.(?:xhtml|xml|svg)$', $nginx);
    }

    public function testDockerfileStartsFromProductionIni(): void
    {
        $dockerfile = (string) file_get_contents($this->repoRoot . '/Dockerfile');
        $this->assertStringContainsString('php.ini-production" "$PHP_INI_DIR/php.ini"', $dockerfile);
    }

    public function testDockerBuildContextExcludesLocalReportsAndDevTooling(): void
    {
        $lines = array_map('trim', file($this->repoRoot . '/.dockerignore'));
        foreach (array('security_best_practices_report*', 'security_jwt_refresh_review.md',
            'file_operations_security_report.md', 'tests', 'test', '.github', '.ddev', 'Dockerfile*') as $pattern) {
            $this->assertContains($pattern, $lines, $pattern);
        }
    }

    public function testLegacyOpsBindSiteTokenToRequestTokenUser(): void
    {
        $ops = array('saveNode', 'deleteNode', 'createNode', 'saveManifest', 'saveNodeDetails', 'fileOperation',
            'saveEditorSettings', 'savePlatformSettings', 'saveAppearanceSettings', 'saveAllowedBlocks', 'saveSeoSettings');
        foreach ($ops as $op) {
            $src = (string) file_get_contents($this->repoRoot . '/system/backend/php/lib/operations/' . $op . '.php');
            $this->assertStringNotContainsString("getActiveUserName() . ':' .", $src, $op);
            $this->assertStringContainsString("\$this->getSiteTokenUserName() . ':' .", $src, $op);
        }
        // saveOutline is mission critical and intentionally untouched here
        $outline = (string) file_get_contents($this->repoRoot . '/system/backend/php/lib/operations/saveOutline.php');
        $this->assertStringContainsString("getActiveUserName() . ':' .", $outline);
    }

    // ---- SEC-SESSION: Secure refresh cookie ------------------------------

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

    // ---- SEC-SESSION: request-token key + strict compare -----------------

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

    public function testAuthenticatedUserNeedsAJwtIdThatIsTheExactRequestToken(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        unset($_COOKIE['haxcms_refresh_token']);
        $h = (new ReflectionClass(get_class(new class extends HAXCMS {
            public function __construct() {}
            public function validateUser($name) { return $name === 'alice'; }
        })))->newInstanceWithoutConstructor();
        $h->privateKey = 'pk';
        $h->refreshPrivateKey = 'rpk';
        $h->salt = 's';
        $h->config = new stdClass();
        $sign = function ($id) use ($h) {
            return JWT::encode(array('id' => $id, 'user' => 'alice', 'iat' => time(), 'exp' => time() + 600), $h->privateKey . $h->salt);
        };
        $h->sessionJwt = $sign($h->getRequestToken('user'));
        $this->assertSame('alice', $h->getAuthenticatedUserName());
        foreach (array(true, 0, array('x')) as $looseId) {
            $h->sessionJwt = $sign($looseId);
            $this->assertNull($h->getAuthenticatedUserName(), 'id ' . json_encode($looseId) . ' must not loosely match');
        }
    }

    // ---- SEC-EXEC: git argument guards -----------------------------------

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

    // ---- SEC-EXEC: no shell strings, no unserialize/eval/assert in lib ------

    public function testLibHasNoShellStringOrUnserializeCalls(): void
    {
        $banned = array('exec', 'shell_exec', 'system', 'passthru', 'popen', 'unserialize', 'eval', 'assert');
        $libDir = dirname(__DIR__, 2) . '/lib';
        $found = array();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            $tokens = token_get_all(file_get_contents($file->getPathname()));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                $isName = is_array($token) && ($token[0] === T_STRING || $token[0] === T_EVAL);
                if (!$isName || !in_array(strtolower($token[1]), $banned, true)) {
                    continue;
                }
                // previous significant token: skip ->exec(), ::exec() and `function exec(`
                $prev = null;
                for ($p = $i - 1; $p >= 0; $p--) {
                    if (!is_array($tokens[$p]) || !in_array($tokens[$p][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                        $prev = $tokens[$p];
                        break;
                    }
                }
                if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR), true)) {
                    continue;
                }
                // next significant token must be "(" for a call
                $next = null;
                for ($n = $i + 1; $n < $count; $n++) {
                    if (!is_array($tokens[$n]) || $tokens[$n][0] !== T_WHITESPACE) {
                        $next = $tokens[$n];
                        break;
                    }
                }
                if ($next === '(') {
                    $found[] = str_replace($libDir . '/', '', $file->getPathname()) . ':' . $token[2] . ' ' . $token[1] . '()';
                }
            }
        }
        $this->assertSame(array(), $found, 'SEC-EXEC: use proc_open with an argv array, and plain array constants instead of serialize/unserialize');
    }

    public function testProcOpenIsOnlyGivenArgvArrays(): void
    {
        $libDir = dirname(__DIR__, 2) . '/lib';
        $stringCommands = array();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $name = str_replace($libDir . '/', '', $file->getPathname());
            if (preg_match('/escapeshell(arg|cmd)\s*\(/', $src)) {
                $stringCommands[] = $name . ': escapeshellarg (a shell string is being built)';
            }
            // the first argument of each proc_open( must be an array literal or
            // a variable last assigned an array
            if (preg_match_all('/proc_open\(\s*([^,]+),/', $src, $m)) {
                foreach ($m[1] as $first) {
                    $first = trim($first);
                    if (strpos($first, 'array(') === 0) {
                        continue;
                    }
                    // a variable built as an array, or guarded as one, in the same file
                    if (preg_match('/^\$\w+$/', $first) &&
                        (strpos($src, $first . ' = array(') !== false || strpos($src, '!is_array(' . $first . ')') !== false)) {
                        continue;
                    }
                    $stringCommands[] = $name . ': proc_open(' . $first . ', ...)';
                }
            }
        }
        $this->assertSame(array(), $stringCommands, 'SEC-EXEC: pass proc_open an argv array so no shell is involved');
    }

    public function testNoNonCryptographicRandomnessInLibOrInstaller(): void
    {
        $banned = array('mt_rand', 'rand', 'shuffle', 'str_shuffle', 'array_rand', 'uniqid', 'lcg_value', 'mt_srand', 'srand');
        $libDir = dirname(__DIR__, 2) . '/lib';
        $paths = array($this->repoRoot . '/install.php');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) === '.php') {
                $paths[] = $file->getPathname();
            }
        }
        $found = array();
        foreach ($paths as $path) {
            $tokens = token_get_all(file_get_contents($path));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), $banned, true)) {
                    continue;
                }
                $prev = isset($tokens[$i - 1]) ? $tokens[$i - 1] : null;
                if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
                    continue;
                }
                $next = isset($tokens[$i + 1]) ? $tokens[$i + 1] : null;
                if ($next === '(') {
                    $found[] = basename($path) . ':' . $token[2] . ' ' . $token[1] . '()';
                }
            }
        }
        $this->assertSame(array(), $found, 'use random_int / random_bytes');
    }

    public function testAppStoreUpstreamCallIsBoundedAndKeepsCurlErrorsServerSide(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/operations/appStoreSearch.php');
        $this->assertStringContainsString('CURLOPT_CONNECTTIMEOUT', $src);
        $this->assertStringContainsString('CURLOPT_TIMEOUT,', $src);
        $this->assertDoesNotMatchRegularExpression('/appStoreSearchFail\([^;]*curl_error/', $src);
    }

    public function testInlineImagesAreStagedExclusivelyAndPrivately(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/MaterializeInlineImages.php');
        $this->assertStringNotContainsString('file_put_contents($tmpPath', $src);
        $this->assertStringContainsString("@fopen(\$tmpPath, 'xb')", $src);
        $this->assertStringContainsString('@chmod($tmpPath, 0600)', $src);
    }

    public function testGitAvailabilityProbeReportsAMissingBinaryAsNoGit(): void
    {
        $saved = Git::get_bin();
        try {
            Git::set_bin('/nonexistent/haxcms-git-' . uniqid());
            $repo = (new ReflectionClass(GitRepo::class))->newInstanceWithoutConstructor();
            $this->assertFalse($repo->test_git());
        } finally {
            Git::set_bin($saved);
        }
    }

    // ---- SEC-SANITIZE: site shell output encoding ------------------------

    public function testSafeCssColor(): void
    {
        $this->assertSame('var(--simple-colors-default-theme-blue-7, #FF2222)', HAXSiteConfig::safeCssColor('--simple-colors-default-theme-blue-7'));
        $this->assertSame('#FF2222', HAXSiteConfig::safeCssColor('x);}</style><script>alert(1)</script>'));
        $this->assertSame('#FF2222', HAXSiteConfig::safeCssColor(''));
        $this->assertSame('#FF2222', HAXSiteConfig::safeCssColor(null));
    }
}
