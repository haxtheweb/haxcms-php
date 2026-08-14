<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the Operations composed-route seam.
 *
 * Operations is the shared business-logic library invoked by v1 API handlers.
 * Each route method follows a shared security gate: validate a request token
 * (user_token or site_token) against the active user, then (for some routes)
 * an object-level authorization / superUser check, then the mutation. These
 * slices pin the GATE behavior — the security-critical pattern shared by all
 * ~40 route methods — via two representative routes:
 *
 *   - getUserData: user_token gate only -> 200 with userData / 403
 *   - getApiKeys:  user_token gate + superUser check -> 200 with keys / 403
 *
 * The seam: `new Operations()` (no constructor), set `$ops->params`, mock
 * `$GLOBALS['HAXCMS']` at the collaborator boundary (validateRequestToken,
 * getActiveUserName, superUser->name, userData, configDirectory). Assertions
 * pin the returned-array contract, not token crypto or service internals.
 *
 * Expected return shapes come from the route contract: success is
 * `['status'=>200, 'data'=>...]`; failure is
 * `['__failed'=>['status'=>403, 'message'=>'...']]`.
 */
class OperationsTokenGateTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $tmpConfigDir;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_ops_test_' . uniqid();
        mkdir($this->tmpConfigDir, 0777, true);
        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpConfigDir;
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
        $this->rrmdir($this->tmpConfigDir);
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

    // --- getUserData: user_token gate ---

    public function testGetUserDataReturns200WithValidToken(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->activeUserName = 'alice';
        $this->haxcms->userData = (object)array('name' => 'alice', 'picture' => 'x.png');
        $this->ops->params = array('user_token' => 'good-token');
        $result = $this->ops->getUserData();
        $this->assertSame(200, $result['status']);
        $this->assertEquals($this->haxcms->userData, $result['data']);
    }

    public function testGetUserDataFailsWithMissingToken(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array();
        $result = $this->ops->getUserData();
        $this->assertSame('__failed', array_key_first($result));
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testGetUserDataFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad-token');
        $result = $this->ops->getUserData();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    // --- getApiKeys: user_token gate + superUser check ---

    public function testGetApiKeysFailsWithMissingToken(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array();
        $result = $this->ops->getApiKeys();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testGetApiKeysFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad');
        $result = $this->ops->getApiKeys();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testGetApiKeysFailsForNonSuperUser(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->activeUserName = 'alice';
        $this->haxcms->superUserName = 'admin';
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->getApiKeys();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Admin access required', $result['__failed']['message']);
    }

    public function testGetApiKeysReturns200ForSuperUser(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->activeUserName = 'admin';
        $this->haxcms->superUserName = 'admin';
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->getApiKeys();
        $this->assertSame(200, $result['status']);
        // readAPIKeys against an empty config dir returns the normalized
        // empty-key map: every supported provider mapped to ''.
        $expected = array(
            'youtube' => '',
            'vimeo' => '',
            'giphy' => '',
            'unsplash' => '',
            'flickr' => '',
            'anthropic' => '',
        );
        $this->assertSame($expected, $result['data']);
    }

    public function testGetApiKeysReturnsStoredKeysForSuperUser(): void
    {
        // write a real apiKeys.json into the temp config and confirm round-trip
        $settingsDir = $this->tmpConfigDir . '/settings';
        mkdir($settingsDir, 0777, true);
        $keys = array('youtube' => 'yt-key', 'vimeo' => '', 'anthropic' => 'sk-xxx');
        file_put_contents($settingsDir . '/apiKeys.json', json_encode($keys));
        $this->haxcms->validRequestToken = true;
        $this->haxcms->activeUserName = 'admin';
        $this->haxcms->superUserName = 'admin';
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->getApiKeys();
        $this->assertSame(200, $result['status']);
        $this->assertSame('yt-key', $result['data']['youtube']);
        $this->assertSame('sk-xxx', $result['data']['anthropic']);
        // unsupported/absent providers normalize to ''
        $this->assertSame('', $result['data']['vimeo']);
        $this->assertSame('', $result['data']['giphy']);
    }
}
