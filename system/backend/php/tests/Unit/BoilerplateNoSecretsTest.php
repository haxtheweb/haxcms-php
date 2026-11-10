<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security (SEC-INSTALL, SEC-DEPLOY): system/boilerplate is mirrored from
 * haxcms-nodejs by the release script and shipped publicly. A local
 * haxcms-nodejs run can write its runtime secrets (JWT keys, salt, the hashed
 * admin record, the seeded password) into its own boilerplate, so none of
 * those names may ever be committed here. PHP generates its secrets under
 * _config/ during install and never reads these paths.
 */
final class BoilerplateNoSecretsTest extends TestCase
{
    private const SECRET_NAMES = ['.pk', '.rpk', 'SALT.txt', '.user', '.initial-admin-password'];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 5);
    }

    /** @return list<string> paths under system/boilerplate that git tracks */
    private static function trackedBoilerplateFiles(): array
    {
        $root = self::repoRoot();
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            return [];
        }
        $out = [];
        $code = 0;
        exec('git -C ' . escapeshellarg($root) . ' ls-files -- system/boilerplate 2>/dev/null', $out, $code);
        return $code === 0 ? $out : [];
    }

    public function testNoRuntimeSecretIsCommittedInTheBoilerplate(): void
    {
        $tracked = self::trackedBoilerplateFiles();
        if ($tracked === []) {
            $this->markTestSkipped('not a git checkout');
        }
        $leaked = [];
        foreach ($tracked as $path) {
            if (in_array(basename($path), self::SECRET_NAMES, true)) {
                $leaked[] = $path;
            }
        }
        $this->assertSame([], $leaked, 'runtime secret files must not be committed under system/boilerplate');
    }

    public function testInstallerStillHasTheFilesItCopiesFromSystemsetup(): void
    {
        $dir = self::repoRoot() . '/system/boilerplate/systemsetup';
        foreach (['config.json', 'config.php', 'userData.json', 'my-custom-elements.js', '.htaccess', '.user-files-htaccess', '.archived-htaccess', '.published-htaccess'] as $name) {
            $this->assertFileExists($dir . '/' . $name, $name . ' is copied by install.php');
        }
    }
}
