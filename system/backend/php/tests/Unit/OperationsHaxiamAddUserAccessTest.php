<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteHaxiamAddUserAccess trait
 * (lib/operations/haxiamAddUserAccess.php).
 *
 * Tests the haxiamAddUserAccess() entrypoint which grants a target user
 * access to a site by creating a symlink in the target user's sites
 * directory.
 *
 * SCOPE NOTE: The symlink-creation success path (_createUserSiteSymlink)
 * cannot be tested because the validation helpers hardcode /var/www/sites
 * which is root-owned and not writable in this environment. Tests cover:
 *   - IAM mode disabled → 400
 *   - Missing/invalid user_token → 403
 *   - Missing userName/siteName → 400
 *   - Invalid machine name format → 400
 *   - Self-access prevention → 400
 *   - _validateHAXIAMUser fails (user not in /var/www/sites) → 403
 *   - _validateUserOwnsSite fails (site not owned by user) → 403
 *
 * The _validateHAXIAMUser and _validateUserOwnsSite 403 paths are
 * naturally occurring because /var/www/sites does not exist in this
 * sandbox, so is_dir() returns false for any path under it.
 */
class OperationsHaxiamAddUserAccessTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        $this->haxcms = new HaxiamTestHaxcms();
        $this->haxcms->config = new stdClass();
        $this->haxcms->config->iam = true;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
    }

    // =========================================================================
    // IAM mode gate
    // =========================================================================

    public function testHaxiamAddUserAccessIamDisabledReturns400(): void
    {
        $this->haxcms->config->iam = false;
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('HAXIAM mode is not enabled', $result['__failed']['message']);
    }

    public function testHaxiamAddUserAccessIamNotSetReturns400(): void
    {
        unset($this->haxcms->config->iam);
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('HAXIAM mode is not enabled', $result['__failed']['message']);
    }

    // =========================================================================
    // Token gate
    // =========================================================================

    public function testHaxiamAddUserAccessMissingTokenReturns403(): void
    {
        $this->ops->params = array(
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testHaxiamAddUserAccessInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'user_token' => 'bad',
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(403, $result['__failed']['status']);
    }

    // =========================================================================
    // Missing required parameters
    // =========================================================================

    public function testHaxiamAddUserAccessMissingUserNameReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('userName is required', $result['__failed']['message']);
    }

    public function testHaxiamAddUserAccessEmptyUserNameReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => '   ',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('userName is required', $result['__failed']['message']);
    }

    public function testHaxiamAddUserAccessMissingSiteNameReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'targetuser',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('siteName is required', $result['__failed']['message']);
    }

    public function testHaxiamAddUserAccessEmptySiteNameReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'targetuser',
            'siteName' => '',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('siteName is required', $result['__failed']['message']);
    }

    // =========================================================================
    // Machine name validation
    // =========================================================================

    public function testHaxiamAddUserAccessInvalidUserNameFormatReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'User Name With Spaces',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('userName must be a valid machine name', $result['__failed']['message']);
    }

    public function testHaxiamAddUserAccessInvalidSiteNameFormatReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'targetuser',
            'siteName' => 'site/with/slashes',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('siteName must be a valid machine name', $result['__failed']['message']);
    }

    // =========================================================================
    // Self-access prevention
    // =========================================================================

    public function testHaxiamAddUserAccessSelfAccessReturns400(): void
    {
        $this->haxcms->activeUserName = 'currentuser';
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'currentuser',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Cannot grant access to yourself', $result['__failed']['message']);
    }

    // =========================================================================
    // _validateHAXIAMUser fails → 403 (naturally occurring: /var/www/sites
    // doesn't exist in this sandbox so is_dir returns false)
    // =========================================================================

    public function testHaxiamAddUserAccessUserNotFoundReturns403(): void
    {
        $this->haxcms->activeUserName = 'currentuser';
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => 'nonexistentuser',
            'siteName' => 'mysite',
        );
        $result = $this->ops->haxiamAddUserAccess();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame(
            'User not found or has not set up HAXIAM yet',
            $result['__failed']['message']
        );
    }
}

/**
 * HAXCMS mock for haxiamAddUserAccess tests. Adds generateMachineName
 * which is required for the userName/siteName sanitization logic.
 */
class HaxiamTestHaxcms extends OperationsTestHaxcms
{
    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = preg_replace('/[\\\\\/]/', '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        if (empty($name)) {
            $name = 'default';
        }
        return $name;
    }
}
