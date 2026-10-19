<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/HAXCMSInstallGate.php';

/**
 * Security (SEC-DEPLOY): the API entry points must refuse every route while
 * _config/config.php is missing or still holds the installer boilerplate
 * placeholders (HAXTHEWEBPRIVATEKEY / HAXTHEWEBREFRESHPRIVATEKEY /
 * jeff + jimmerson). Before this gate, POST session/login with jeff/jimmerson
 * returned a valid JWT on a half-installed deployment.
 */
class HAXCMSInstallGateTest extends TestCase
{
    private $tmpDir;
    private $repoRoot;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_install_gate_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        // __DIR__ = <repo>/system/backend/php/tests/Unit
        $this->repoRoot = dirname(__DIR__, 5);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function fakeHaxcms()
    {
        $h = new stdClass();
        $h->privateKey = null;
        $h->refreshPrivateKey = null;
        $h->superUser = new stdClass();
        $h->superUser->name = null;
        $h->superUser->password = null;
        $h->basePath = '/';
        return $h;
    }

    /**
     * Include a config.php the way the API entry points do (it assigns onto
     * a variable named $HAXCMS in the including scope).
     */
    private function loadConfig($path)
    {
        $HAXCMS = $this->fakeHaxcms();
        include $path;
        return $HAXCMS;
    }

    private function boilerplateConfigPath()
    {
        return $this->repoRoot . '/system/boilerplate/systemsetup/config.php';
    }

    /**
     * Mirrors install.php haxcmsInstallerRunInstallBlock step-4 templating.
     */
    private function templatedConfig($username, $password)
    {
        $configFile = file_get_contents($this->boilerplateConfigPath());
        $configFile = str_replace('HAXTHEWEBPRIVATEKEY', bin2hex(random_bytes(32)), $configFile);
        $configFile = str_replace('HAXTHEWEBREFRESHPRIVATEKEY', bin2hex(random_bytes(32)), $configFile);
        $configFile = str_replace('jeff', $username, $configFile);
        $configFile = str_replace('jimmerson', password_hash($password, PASSWORD_DEFAULT), $configFile);
        $path = $this->tmpDir . '/config.php';
        file_put_contents($path, $configFile);
        return $path;
    }

    public function testMissingConfigIsNotInstalled()
    {
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled($this->fakeHaxcms(), $this->tmpDir . '/config.php'));
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled($this->fakeHaxcms(), ''));
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled(null, $this->boilerplateConfigPath()));
    }

    public function testBoilerplateConfigIsDetectedAsNotInstalled()
    {
        // Ties the gate's constants to the real boilerplate file the
        // installer copies into _config in step 1.
        $copy = $this->tmpDir . '/config.php';
        copy($this->boilerplateConfigPath(), $copy);
        $h = $this->loadConfig($copy);
        $this->assertSame(HAXCMSInstallGate::PLACEHOLDER_PRIVATE_KEY, $h->privateKey);
        $this->assertSame(HAXCMSInstallGate::PLACEHOLDER_REFRESH_PRIVATE_KEY, $h->refreshPrivateKey);
        $this->assertSame(HAXCMSInstallGate::PLACEHOLDER_SUPERUSER_PASSWORD, $h->superUser->password);
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled($h, $copy));
    }

    public function testEachPlaceholderAloneTriggersGate()
    {
        $path = $this->templatedConfig('admin', 'Sup3r!Secret-pass');
        $base = $this->loadConfig($path);
        $this->assertFalse(HAXCMSInstallGate::isNotInstalled($base, $path));

        $h = clone $base;
        $h->privateKey = 'HAXTHEWEBPRIVATEKEY';
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled($h, $path));

        $h = clone $base;
        $h->refreshPrivateKey = 'HAXTHEWEBREFRESHPRIVATEKEY';
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled($h, $path));

        $h = clone $base;
        $h->superUser = clone $base->superUser;
        $h->superUser->password = 'jimmerson';
        $this->assertTrue(HAXCMSInstallGate::isNotInstalled($h, $path));
    }

    public function testTemplatedConfigIsInstalled()
    {
        $path = $this->templatedConfig('admin', 'Sup3r!Secret-pass');
        $this->assertFalse(HAXCMSInstallGate::isNotInstalled($this->loadConfig($path), $path));
    }

    public function testUsernameContainingJeffIsNotMisreadAsPlaceholder()
    {
        // The API gate checks loaded values (and index.php/install.php now use
        // the exact-assignment configSourceIsNotTemplated) so a real account
        // named jeff/jeffrey still works.
        foreach (array('jeff', 'jeffrey') as $name) {
            $path = $this->templatedConfig($name, 'Sup3r!Secret-pass');
            $h = $this->loadConfig($path);
            $this->assertSame($name, $h->superUser->name);
            $this->assertFalse(HAXCMSInstallGate::isNotInstalled($h, $path), $name);
        }
    }

    public function testLegacyPlaintextPasswordOtherThanPlaceholderIsInstalled()
    {
        $path = $this->templatedConfig('admin', 'x');
        $h = $this->loadConfig($path);
        $h->superUser->password = 'some-legacy-plaintext';
        $this->assertFalse(HAXCMSInstallGate::isNotInstalled($h, $path));
    }

    public function testNotInstalledResponseEnvelope()
    {
        ob_start();
        HAXCMSInstallGate::sendNotInstalledResponse();
        $out = ob_get_clean();
        $this->assertSame(array('status' => 503, 'message' => 'HAXcms is not installed', 'code' => 'NOT_INSTALLED'), json_decode($out, true));
    }

    public function testApiEntryPointsAreGatedBeforeDispatch()
    {
        $entries = array(
            'system/api/v1/index.php' => 'SystemApiRouter::dispatch',
            'system/api.php' => 'SystemApiRouter::dispatch',
            'haxcms-jwt.php' => 'appJWTConnectionSettings',
            'system/backend/php/lib/siteRoutes/SiteApiRouter.php' => '$allRoutes = SiteRoutesMap::getRoutesMap()',
        );
        foreach ($entries as $rel => $dispatchMarker) {
            $src = file_get_contents($this->repoRoot . '/' . $rel);
            $gatePos = strpos($src, 'HAXCMSInstallGate::isNotInstalled');
            $dispatchPos = strpos($src, $dispatchMarker);
            $this->assertNotFalse($gatePos, $rel . ' must call HAXCMSInstallGate::isNotInstalled');
            $this->assertNotFalse($dispatchPos, $rel . ' dispatch marker not found');
            $this->assertLessThan($dispatchPos, $gatePos, $rel . ' must gate before dispatching');
        }
    }
}
