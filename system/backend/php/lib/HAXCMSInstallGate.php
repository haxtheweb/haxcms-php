<?php
/**
 * Security (SEC-DEPLOY): installer placeholder gate.
 *
 * install.php copies system/boilerplate/systemsetup/config.php into _config/
 * during wizard step 1 WITH its published placeholder values (privateKey
 * HAXTHEWEBPRIVATEKEY, refreshPrivateKey HAXTHEWEBREFRESHPRIVATEKEY, superUser
 * jeff / jimmerson) and only templates real secrets + a password hash in step
 * 4. index.php redirects to the installer while that is the case, but the API
 * entry points include config.php unconditionally — so in that window (or if
 * the wizard is abandoned) anyone could log in as jeff/jimmerson or forge JWTs
 * signed with the public placeholder key.
 *
 * The installer front-end (<hax-app-installer>) only talks to install.php
 * (?op=state / ?op=prepare / ?op=advance), never to the system API, so every
 * API route can be refused until installation completes.
 */
class HAXCMSInstallGate
{
    // Exact boilerplate values from system/boilerplate/systemsetup/config.php
    // that install.php step 4 (haxcmsInstallerRunInstallBlock) replaces.
    const PLACEHOLDER_PRIVATE_KEY = 'HAXTHEWEBPRIVATEKEY';
    const PLACEHOLDER_REFRESH_PRIVATE_KEY = 'HAXTHEWEBREFRESHPRIVATEKEY';
    const PLACEHOLDER_SUPERUSER_PASSWORD = 'jimmerson';

    /**
     * Returns TRUE when HAXcms has not finished installing: config.php is
     * missing, or the values it loaded onto $HAXCMS are still the boilerplate
     * placeholders. Checks the loaded runtime values (not a substring scan of
     * the file) so a legitimate username such as "jeffrey" is not misread.
     *
     * @param object $haxcms     the global HAXCMS instance AFTER config.php
     *                           has been included
     * @param string $configPath absolute path of the config.php that was (or
     *                           should have been) included
     */
    public static function isNotInstalled($haxcms, $configPath)
    {
        if (!is_string($configPath) || $configPath === '' || !file_exists($configPath)) {
            return true;
        }
        if (!is_object($haxcms)) {
            return true;
        }
        if (isset($haxcms->privateKey) && $haxcms->privateKey === self::PLACEHOLDER_PRIVATE_KEY) {
            return true;
        }
        if (isset($haxcms->refreshPrivateKey) && $haxcms->refreshPrivateKey === self::PLACEHOLDER_REFRESH_PRIVATE_KEY) {
            return true;
        }
        if (
            isset($haxcms->superUser) && is_object($haxcms->superUser) &&
            isset($haxcms->superUser->password) &&
            $haxcms->superUser->password === self::PLACEHOLDER_SUPERUSER_PASSWORD
        ) {
            return true;
        }
        return false;
    }

    /**
     * Security (M-1): source-level twin of isNotInstalled() for callers that
     * run before bootstrap (index.php, install.php). Matches only the exact
     * boilerplate ASSIGNMENTS (e.g. `->superUser->password = 'jimmerson';`),
     * never a bare substring, so a real config that merely contains "jeff"
     * (username "jeffrey", a comment, ...) is not misread as uninstalled --
     * which previously reopened the unauthenticated installer.
     * Unreadable input returns FALSE (pre-existing behavior).
     */
    public static function configSourceIsNotTemplated($raw)
    {
        if (!is_string($raw)) {
            return false;
        }
        $patterns = array(
            '/->privateKey\s*=\s*([\'"])' . self::PLACEHOLDER_PRIVATE_KEY . '\1\s*;/',
            '/->refreshPrivateKey\s*=\s*([\'"])' . self::PLACEHOLDER_REFRESH_PRIVATE_KEY . '\1\s*;/',
            '/->superUser->password\s*=\s*([\'"])' . self::PLACEHOLDER_SUPERUSER_PASSWORD . '\1\s*;/',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Emit the 503 "not installed" response used by every API entry point.
     * Same envelope shape as system/api.php's unknown-route 404.
     */
    public static function sendNotInstalledResponse()
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        print json_encode(array(
            'status' => 503,
            'message' => 'HAXcms is not installed',
            'code' => 'NOT_INSTALLED',
        ));
    }
}
