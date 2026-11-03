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
 * The sites root is resolved by _haxiamSitesRoot() (config->iamSitesRoot,
 * then IAM_ROOT/users_sites, then /var/www/sites). Filesystem tests point
 * config->iamSitesRoot at a temp directory laid out like HAXiam's
 * users_sites tree (<user>/_sites plus a "sites" symlink), so the success
 * path and every validation failure can be exercised.
 *
 * Status contract (front end treats unknown 403s and any 404 as an expired
 * session and logs the user out, so business-rule failures avoid them):
 *   - IAM mode disabled, bad input, self grant, user not set up -> 400
 *   - missing/invalid user_token, site neither owned by nor
 *     shared with the caller                                    -> 403
 * Anyone a site was shared with can share it onward; the new link always
 * points at the real owner's site.
 *   - target user already has access                             -> 409
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
    // Filesystem-backed paths, against a temp HAXiam users_sites tree
    // =========================================================================

    private $sitesRoot;

    /**
     * Build <root>/<user>/_sites with a "sites" -> "./_sites" symlink, the
     * way haxiam's IAM::liberate() does, and point the operation at it.
     */
    private function makeSitesRoot(array $users, array $ownedSites = array()): string
    {
        $root = sys_get_temp_dir() . '/haxiam-sites-' . bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        foreach ($users as $user) {
            mkdir($root . '/' . $user . '/_sites', 0755, true);
            symlink('./_sites', $root . '/' . $user . '/sites');
        }
        foreach ($ownedSites as $user => $sites) {
            foreach ($sites as $site) {
                mkdir($root . '/' . $user . '/_sites/' . $site, 0755, true);
                file_put_contents($root . '/' . $user . '/_sites/' . $site . '/site.json', '{}');
            }
        }
        $this->haxcms->config->iamSitesRoot = $root;
        $this->sitesRoot = $root;
        return $root;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function grant(string $target, string $site): array
    {
        $this->haxcms->activeUserName = 'owner';
        $this->ops->params = array(
            'user_token' => 'good',
            'userName' => $target,
            'siteName' => $site,
        );
        return $this->ops->haxiamAddUserAccess();
    }

    public function testUserNotSetUpReturns400NotA403(): void
    {
        $this->makeSitesRoot(array('owner'), array('owner' => array('mysite')));
        try {
            $result = $this->grant('nonexistentuser', 'mysite');
            $this->assertSame(400, $result['__failed']['status']);
            $this->assertSame(
                'User not found or has not set up HAXIAM yet',
                $result['__failed']['message']
            );
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testSiteNotOwnedByCallerReturns403(): void
    {
        $this->makeSitesRoot(array('owner', 'friend'));
        try {
            $result = $this->grant('friend', 'notmine');
            $this->assertSame(403, $result['__failed']['status']);
            $this->assertSame(
                'You do not own this site or site does not exist',
                $result['__failed']['message']
            );
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testSharedSiteCanBeSharedOnward(): void
    {
        // "owner" (the caller) only has mysite because author shared it
        $root = $this->makeSitesRoot(array('owner', 'friend', 'author'), array('author' => array('mysite')));
        symlink('../../author/sites/mysite', $root . '/owner/_sites/mysite');
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame('success', $result['status']);
            $link = $root . '/friend/sites/mysite';
            // the new link points straight at the real owner, not at the
            // person who shared it, so it survives them losing access
            $this->assertSame('../../author/sites/mysite', readlink($link));
            $this->assertSame(realpath($root . '/author/_sites/mysite'), realpath($link));
            unlink($root . '/owner/_sites/mysite');
            $this->assertFileExists($link . '/site.json');
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testSharedSiteCannotBeSharedBackWithItsOwner(): void
    {
        $root = $this->makeSitesRoot(array('owner', 'author'), array('author' => array('mysite')));
        symlink('../../author/sites/mysite', $root . '/owner/_sites/mysite');
        try {
            $result = $this->grant('author', 'mysite');
            $this->assertSame(409, $result['__failed']['status']);
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testLinkOutsideSitesRootIsNotShareable(): void
    {
        // a link a user planted pointing anywhere else must not count
        $root = $this->makeSitesRoot(array('owner', 'friend'));
        $outside = sys_get_temp_dir() . '/haxiam-outside-' . bin2hex(random_bytes(6));
        mkdir($outside . '/mysite', 0755, true);
        symlink($outside . '/mysite', $root . '/owner/_sites/mysite');
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame(403, $result['__failed']['status']);
            $this->assertFalse(is_link($root . '/friend/sites/mysite'));
        } finally {
            $this->removeTree($this->sitesRoot);
            $this->removeTree($outside);
        }
    }

    public function testLinkToDifferentlyNamedSiteIsNotShareable(): void
    {
        // owner/sites/mysite -> author/_sites/secret must not grant "secret"
        $root = $this->makeSitesRoot(array('owner', 'friend', 'author'), array('author' => array('secret')));
        symlink('../../author/sites/secret', $root . '/owner/_sites/mysite');
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame(403, $result['__failed']['status']);
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testLinkToNonSiteFolderIsNotShareable(): void
    {
        // pointing at another user's whole folder, not one of their sites
        $root = $this->makeSitesRoot(array('owner', 'friend', 'author'));
        mkdir($root . '/author/mysite', 0755, true);
        symlink('../../author/mysite', $root . '/owner/_sites/mysite');
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame(403, $result['__failed']['status']);
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testBrokenSharedLinkIsNotShareable(): void
    {
        // the original owner deleted the site; the old link dangles
        $root = $this->makeSitesRoot(array('owner', 'friend', 'author'));
        symlink('../../author/sites/mysite', $root . '/owner/_sites/mysite');
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame(403, $result['__failed']['status']);
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testSiteOwnerResolvesThroughSymlinkedRoot(): void
    {
        // e.g. /var/www/sites -> <haxiam>/users_sites on a real server
        $real = $this->makeSitesRoot(array('owner', 'friend', 'author'), array('author' => array('mysite')));
        symlink('../../author/sites/mysite', $real . '/owner/_sites/mysite');
        $alias = $real . '-alias';
        symlink($real, $alias);
        $this->haxcms->config->iamSitesRoot = $alias;
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame('success', $result['status']);
            $this->assertSame('../../author/sites/mysite', readlink($real . '/friend/_sites/mysite'));
        } finally {
            unlink($alias);
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testGrantCreatesWorkingSymlinkInTargetSites(): void
    {
        $root = $this->makeSitesRoot(array('owner', 'friend'), array('owner' => array('mysite')));
        try {
            $result = $this->grant('friend', 'mysite');
            $this->assertSame('success', $result['status']);
            $link = $root . '/friend/sites/mysite';
            $this->assertTrue(is_link($link));
            // the relative target resolves through the "sites" alias to the
            // owner's real site directory
            $this->assertSame(
                realpath($root . '/owner/_sites/mysite'),
                realpath($link)
            );
            $this->assertFileExists($link . '/site.json');
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testAlreadyHasAccessReturns409(): void
    {
        $this->makeSitesRoot(array('owner', 'friend'), array('owner' => array('mysite')));
        try {
            $this->assertSame('success', $this->grant('friend', 'mysite')['status']);
            $again = $this->grant('friend', 'mysite');
            $this->assertSame(409, $again['__failed']['status']);
            $this->assertSame('User already has access to this site', $again['__failed']['message']);
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testNoFailureUsesA404(): void
    {
        // a 404 from this route would log the user out in app-hax
        $this->makeSitesRoot(array('owner'), array('owner' => array('mysite')));
        try {
            foreach (array(array('ghost', 'mysite'), array('owner2', 'nope')) as $case) {
                $result = $this->grant($case[0], $case[1]);
                $this->assertNotSame(404, $result['__failed']['status']);
            }
        } finally {
            $this->removeTree($this->sitesRoot);
        }
    }

    public function testSitesRootDefaultsWhenNotConfigured(): void
    {
        unset($this->haxcms->config->iamSitesRoot);
        $method = new ReflectionMethod(Operations::class, '_haxiamSitesRoot');
        $method->setAccessible(true);
        $expected = defined('IAM_ROOT') ? rtrim(IAM_ROOT, '/') . '/users_sites' : '/var/www/sites';
        $this->assertSame($expected, $method->invoke($this->ops));
    }

    public function testSitesRootHonorsConfigOverride(): void
    {
        $this->haxcms->config->iamSitesRoot = '/srv/haxiam/users_sites/';
        $method = new ReflectionMethod(Operations::class, '_haxiamSitesRoot');
        $method->setAccessible(true);
        $this->assertSame('/srv/haxiam/users_sites', $method->invoke($this->ops));
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
