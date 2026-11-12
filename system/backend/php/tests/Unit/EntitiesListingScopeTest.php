<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * haxtheweb/issues#3220: /x/api/v1/entities lists system-scope descriptors
 * only to a signed-in caller that sends a valid X-HAXCMS-User-Token. Everyone
 * else gets the site scope, whatever ?scope= asks for. Mirrors the haxcms-nodejs
 * tests in test/unit/entitiesEndpoint.test.cjs.
 */
class EntitiesListingScopeStubHaxcms
{
    public $validToken = 'valid-user-token';
    public $validUser = 'test-user';
    public $checked = array();
    public function isCLI()
    {
        // a deployed web request
        return false;
    }
    public function validateRequestToken($token, $value)
    {
        $this->checked[] = array($token, $value);
        return $token === $this->validToken && $value === $this->validUser;
    }
}

class EntitiesListingScopeTest extends TestCase
{
    private $savedServer = array();
    private $savedGet = array();
    private $hadHaxcms = false;
    private $savedHaxcms = null;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedGet = isset($_GET) && is_array($_GET) ? $_GET : array();
        $_GET = array();
        unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $this->hadHaxcms = array_key_exists('HAXCMS', $GLOBALS);
        if ($this->hadHaxcms) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = new EntitiesListingScopeStubHaxcms();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        if ($this->hadHaxcms) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
    }

    private function listEntities($authenticated, $scope = null)
    {
        if (!is_null($scope)) {
            $_GET['scope'] = $scope;
        }
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'v1/entities', '/x/api', $authenticated);
        return invokeSiteRouteHandler('entities.php', $context)['data'];
    }

    public static function scopes(): array
    {
        return array(
            'no scope' => array(null),
            'scope=system' => array('system'),
            'scope=site' => array('site'),
        );
    }

    #[DataProvider('scopes')]
    public function testAnonymousCallersGetSiteScopeOnly($scope): void
    {
        $body = $this->listEntities(false, $scope);
        $this->assertSame(200, $body['status']);
        $types = array_column($body['data']['entities'], 'type');
        sort($types);
        $this->assertSame(array('file', 'item'), $types);
        $this->assertSame(2, $body['data']['count']);
        foreach ($body['data']['entities'] as $entity) {
            $this->assertSame('site', $entity['scope']);
        }
        $this->assertSame(array(), $GLOBALS['HAXCMS']->checked, 'no token check for anonymous callers');
    }

    public function testBearerWithoutUserTokenIs403Required(): void
    {
        $body = $this->listEntities(true);
        $this->assertSame(403, $body['status']);
        $this->assertSame('X-HAXCMS-User-Token header is required for this endpoint', $body['data']['message']);
        $this->assertArrayNotHasKey('entities', $body['data']);
    }

    public function testEmptyUserTokenIsTreatedAsMissing(): void
    {
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = '';
        $body = $this->listEntities(true);
        $this->assertSame(403, $body['status']);
        $this->assertSame('X-HAXCMS-User-Token header is required for this endpoint', $body['data']['message']);
    }

    public function testWrongUserTokenIs403Invalid(): void
    {
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = 'not-the-token';
        $body = $this->listEntities(true, 'system');
        $this->assertSame(403, $body['status']);
        $this->assertSame('Invalid X-HAXCMS-User-Token header', $body['data']['message']);
        $this->assertSame(array(array('not-the-token', 'test-user')), $GLOBALS['HAXCMS']->checked);
    }

    public function testValidUserTokenKeepsTheMergedListingAndScope(): void
    {
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = 'valid-user-token';
        $merged = $this->listEntities(true);
        $this->assertSame(200, $merged['status']);
        $this->assertSame(6, $merged['data']['count']);
        $system = $this->listEntities(true, 'system');
        $types = array_column($system['data']['entities'], 'type');
        sort($types);
        $this->assertSame(array('site', 'skeleton', 'system', 'theme'), $types);
    }

    public function testRouteStaysPublicAtTheGate(): void
    {
        $this->assertSame('public', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/entities', 'GET'));
    }
}
