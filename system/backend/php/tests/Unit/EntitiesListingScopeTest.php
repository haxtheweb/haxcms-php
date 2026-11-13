<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * haxtheweb/issues#3220: /x/api/v1/entities lists system-scope descriptors
 * only to a signed-in caller that sends this site's X-HAXCMS-Site-Token
 * (bound to user:siteName), like every other signed-in site API route. Everyone
 * else gets the site scope, whatever ?scope= asks for. Mirrors the haxcms-nodejs
 * tests in test/unit/entitiesEndpoint.test.cjs.
 */
class EntitiesListingScopeStubHaxcms
{
    // the site token for test-user on the fake site "testsite"
    public $validToken = 'valid-site-token';
    public $validValue = 'test-user:testsite';
    public $checked = array();
    public function isCLI()
    {
        // a deployed web request
        return false;
    }
    public function validateRequestToken($token, $value)
    {
        $this->checked[] = array($token, $value);
        return $token === $this->validToken && $value === $this->validValue;
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
        unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN'], $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
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

    public function testBearerWithoutSiteTokenIs403Required(): void
    {
        $body = $this->listEntities(true);
        $this->assertSame(403, $body['status']);
        $this->assertSame('X-HAXCMS-Site-Token header is required for this endpoint', $body['data']['message']);
        $this->assertArrayNotHasKey('entities', $body['data']);
    }

    public function testEmptySiteTokenIsTreatedAsMissing(): void
    {
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = '';
        $body = $this->listEntities(true);
        $this->assertSame(403, $body['status']);
        $this->assertSame('X-HAXCMS-Site-Token header is required for this endpoint', $body['data']['message']);
    }

    public function testUserTokenAloneNoLongerOpensTheFullListing(): void
    {
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = 'valid-site-token';
        $body = $this->listEntities(true);
        $this->assertSame(403, $body['status']);
        $this->assertSame('X-HAXCMS-Site-Token header is required for this endpoint', $body['data']['message']);
    }

    public function testWrongSiteTokenIs403Invalid(): void
    {
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'not-the-token';
        $body = $this->listEntities(true, 'system');
        $this->assertSame(403, $body['status']);
        $this->assertSame('Invalid X-HAXCMS-Site-Token header', $body['data']['message']);
        // checked against user:siteName, not the user alone
        $this->assertSame(array(array('not-the-token', 'test-user:testsite')), $GLOBALS['HAXCMS']->checked);
    }

    public function testValidSiteTokenKeepsTheMergedListingAndScope(): void
    {
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = 'valid-site-token';
        $merged = $this->listEntities(true);
        $this->assertSame(200, $merged['status']);
        $this->assertSame(6, $merged['data']['count']);
        $system = $this->listEntities(true, 'system');
        $types = array_column($system['data']['entities'], 'type');
        sort($types);
        $this->assertSame(array('site', 'skeleton', 'system', 'theme'), $types);
    }

    public function testEmptyRequirementMakesAuthOptionalInAnyOrder(): void
    {
        $this->assertSame('public', SiteRouteUtils::normalizeSecurityPolicy(array(
            array('bearerAuth' => array(), 'siteTokenHeader' => array()),
            array(),
        )));
        $this->assertSame('public', SiteRouteUtils::normalizeSecurityPolicy(array(
            array(),
            array('bearerAuth' => array(), 'siteTokenHeader' => array()),
        )));
        // without {} the site-token requirement still applies
        $this->assertSame('authenticated-site', SiteRouteUtils::normalizeSecurityPolicy(array(
            array('bearerAuth' => array(), 'siteTokenHeader' => array()),
        )));
    }

    public function testRouteStaysPublicAtTheGate(): void
    {
        $this->assertSame('public', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/entities', 'GET'));
    }
}
