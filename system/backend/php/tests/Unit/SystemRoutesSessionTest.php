<?php
/**
 * Characterization tests for lib/systemRoutes/v1/session.php.
 *
 * Two layers are covered:
 *   1. The file-scoped helper functions (bearer resolution, bearer/refresh
 *      session contexts, IAM authorization).
 *   2. The returned route closure for every session sub-route: login
 *      (failure paths), session (401/403/200), logout, refresh (failure
 *      path), connection-settings (denied path), connection-test (cross-
 *      origin gate, store error, refresh recovery, bearer, anonymous, IAM),
 *      session/user, and the unknown-route 404.
 *
 * $GLOBALS['HAXCMS'] is a configurable fake (SessionRouteFakeHaxcms) so the
 * real Operations methods invoked by the closure (login/logout/
 * refreshAccessToken/connectionSettings/getUserData) run against controlled
 * token decisions. The request context is built via reflection (skipping the
 * $_SERVER-sniffing constructor) and bound directly.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS (verified reachable only under a
 * real HTTP request; they end with `exit`, which terminates both the PHPUnit
 * process and PHPUnit's separate-process template before it can serialize
 * results/coverage):
 *   - 155-159: login flat {status,jwt} envelope -> header/print/exit
 *   - 249-253: refresh flat {status,jwt} envelope -> header/print/exit
 *   - 258-263: connection-settings __noencode JS emit -> header/print/exit
 * Defensively-unreachable (responses from the real Operations methods always
 * carry either __failed or status, and __noencode only reaches the exit):
 *   - 459-461 (__noencode rewrap) and 462-464 (non-array rewrap)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Configurable HAXCMS collaborator for the session route. Every decision the
 * route + Operations methods depend on is a public property.
 */
class SessionRouteFakeHaxcms extends OperationsTestHaxcms
{
    public $bearerToken = '';
    public $bearerUserName = 'testuser';
    public $decodedJwt = false;
    public $validUserResult = true;
    public $refreshTokenDecoded = false;
    public $refreshSessionOk = true;
    public $rotatedToken = 'rotated-access-jwt';
    public $refreshStoreError = false;
    public $sameOrigin = true;
    public $iamAuth = array('allowed' => true, 'status' => 200, 'message' => '');
    public $sessionTokens = array('userToken' => 'ut-token', 'siteToken' => 'st-token');
    public $loginOk = false;
    public $validateJwtResult = false;
    public $decodedRefreshCookie = false;
    public $sessionJwt = null;
    public $tokenCalls = array();
    public $cookieCalls = array();
    public $revokeCalls = array();

    public function getBearerTokenFromRequest()
    {
        return $this->bearerToken;
    }

    public function getBearerTokenUserName($token = '')
    {
        return $this->bearerUserName;
    }

    public function decodeJWT($jwt)
    {
        return $this->decodedJwt;
    }

    public function validateUser($user)
    {
        return $this->validUserResult;
    }

    public function getRequestToken($name)
    {
        return 'request-token-for-' . $name;
    }

    public function getRequestTokenUserName()
    {
        return $this->activeUserName;
    }

    public function generateMachineName($name)
    {
        $machine = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $name));
        return trim($machine, '-');
    }

    public function validateRequestToken($token, $value)
    {
        $this->tokenCalls[] = array((string) $token, (string) $value);
        return parent::validateRequestToken($token, $value);
    }

    public function validateRefreshToken($verify = false)
    {
        return $this->refreshTokenDecoded;
    }

    public function validateRefreshSession($user, $family, $jti, $iat)
    {
        return $this->refreshSessionOk;
    }

    public function revokeRefreshSession($user, $family)
    {
        $this->revokeCalls[] = array($user, $family);
    }

    public function setRefreshTokenCookie($token, $expire = null)
    {
        $this->cookieCalls[] = array($token, $expire);
    }

    public function rotateRefreshTokenAndCookie($decoded)
    {
        return $this->rotatedToken;
    }

    public function validateIAMRouteAuthorization($requireAdmin = false)
    {
        return $this->iamAuth;
    }

    public function isSameOriginRequest()
    {
        return $this->sameOrigin;
    }

    public function getSessionRequestTokens($user, $body = array())
    {
        return $this->sessionTokens;
    }

    public function getLoginRateLimitSettings()
    {
        $s = new stdClass();
        $s->enabled = false;
        $s->windowMs = 60000;
        $s->maxAttempts = 5;
        $s->blockMs = 60000;
        return $s;
    }

    public function testLogin($u, $p, $legacy = false)
    {
        return $this->loginOk;
    }

    public function getRefreshToken($u)
    {
        return 'refresh-token-' . $u;
    }

    public function getJWT($u)
    {
        return 'access-jwt-' . $u;
    }

    public function validateJWT($verify = false)
    {
        return $this->validateJwtResult;
    }

    public function decodeRefreshToken($cookie)
    {
        return $this->decodedRefreshCookie;
    }

    public function appJWTConnectionSettings($basePath = '/')
    {
        return array('basePath' => $basePath, 'jwt' => 'conn-jwt');
    }
}

class SystemRoutesSessionTest extends TestCase
{
    private $savedHaxcms;
    private $savedServer;
    private $savedCookie;
    private $savedGet;
    private $sessionPath;

    protected function setUp(): void
    {
        $this->sessionPath = dirname(__DIR__, 2) . '/lib/systemRoutes/v1/session.php';
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedServer = $_SERVER;
        $this->savedCookie = $_COOKIE;
        $this->savedGet = $_GET;
        $_COOKIE = array();
        $_GET = array();
        unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN'], $_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        http_response_code(200);
        // Define the file-scoped helper functions + load the route closure.
        require_once $this->sessionPath;
        $GLOBALS['HAXCMS'] = new SessionRouteFakeHaxcms();
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_SERVER = $this->savedServer;
        $_COOKIE = $this->savedCookie;
        $_GET = $this->savedGet;
        http_response_code(200);
    }

    private function haxcms()
    {
        return $GLOBALS['HAXCMS'];
    }

    /**
     * Include the route file fresh and invoke the closure with a reflection
     * context; returns raw output, decoded JSON, and the response code.
     */
    private function runHandler($routeSuffix, $method = 'POST', $body = array())
    {
        $context = (new ReflectionClass('SystemApiRequestContext'))->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = $routeSuffix;
        $context->method = $method;
        $context->body = $body;
        $context->params = array();
        $handler = require $this->sessionPath;
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        return array(
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'code' => http_response_code(),
        );
    }

    // ------------------------------------------------------------------
    // haxcmsResolveSessionBearerToken
    // ------------------------------------------------------------------

    public function testResolveSessionBearerTokenReturnsEmptyWithoutHaxcms(): void
    {
        unset($GLOBALS['HAXCMS']);
        $this->assertSame('', haxcmsResolveSessionBearerToken());
    }

    public function testResolveSessionBearerTokenReturnsEmptyWhenMethodMissing(): void
    {
        $GLOBALS['HAXCMS'] = new stdClass();
        $this->assertSame('', haxcmsResolveSessionBearerToken());
    }

    public function testResolveSessionBearerTokenReturnsEmptyForNonString(): void
    {
        $this->haxcms()->bearerToken = array('not', 'a', 'string');
        $this->assertSame('', haxcmsResolveSessionBearerToken());
    }

    public function testResolveSessionBearerTokenTrims(): void
    {
        $this->haxcms()->bearerToken = "  bearer-token-abc \n";
        $this->assertSame('bearer-token-abc', haxcmsResolveSessionBearerToken());
    }

    // ------------------------------------------------------------------
    // haxcmsResolveBearerSessionContext
    // ------------------------------------------------------------------

    public function testResolveBearerSessionContextRejectsEmptyAndNonString(): void
    {
        $this->assertNull(haxcmsResolveBearerSessionContext(''));
        $this->assertNull(haxcmsResolveBearerSessionContext(array('jwt')));
    }

    public function testResolveBearerSessionContextRejectsUndecodableJwt(): void
    {
        $this->haxcms()->decodedJwt = false;
        $this->assertNull(haxcmsResolveBearerSessionContext('bad-jwt'));
    }

    public function testResolveBearerSessionContextRejectsMissingIdOrUser(): void
    {
        $this->haxcms()->decodedJwt = (object) array('user' => 'someone');
        $this->assertNull(haxcmsResolveBearerSessionContext('jwt'));
        $this->haxcms()->decodedJwt = (object) array('id' => 'request-token-for-user');
        $this->assertNull(haxcmsResolveBearerSessionContext('jwt'));
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => '',
        );
        $this->assertNull(haxcmsResolveBearerSessionContext('jwt'));
    }

    public function testResolveBearerSessionContextRejectsIdMismatch(): void
    {
        // getRequestToken('user') resolves to 'request-token-for-user' on the
        // fake, so any other id claim fails the binding check.
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-someone-else',
            'user' => 'someone',
        );
        $this->assertNull(haxcmsResolveBearerSessionContext('jwt'));
    }

    public function testResolveBearerSessionContextRejectsInvalidUser(): void
    {
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => 'someone',
        );
        $this->haxcms()->validUserResult = false;
        $this->assertNull(haxcmsResolveBearerSessionContext('jwt'));
    }

    public function testResolveBearerSessionContextReturnsJwtAndMachineUser(): void
    {
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => 'Some User',
        );
        $context = haxcmsResolveBearerSessionContext('some-jwt');
        $this->assertSame('some-jwt', $context['jwt']);
        $this->assertSame('some-user', $context['user']);
    }

    // ------------------------------------------------------------------
    // haxcmsResolveRefreshSessionContext
    // ------------------------------------------------------------------

    public function testResolveRefreshSessionContextReturnsNullWithoutHaxcmsOrMethod(): void
    {
        unset($GLOBALS['HAXCMS']);
        $this->assertNull(haxcmsResolveRefreshSessionContext());
        $GLOBALS['HAXCMS'] = new stdClass();
        $this->assertNull(haxcmsResolveRefreshSessionContext());
    }

    public function testResolveRefreshSessionContextRejectsInvalidRefreshToken(): void
    {
        $this->haxcms()->refreshTokenDecoded = false;
        $this->assertNull(haxcmsResolveRefreshSessionContext());
        $this->assertSame(array(), $this->haxcms()->revokeCalls);
    }

    public function testResolveRefreshSessionContextRejectsBadUserClaims(): void
    {
        $this->haxcms()->refreshTokenDecoded = (object) array('user' => '');
        $this->assertNull(haxcmsResolveRefreshSessionContext());
        $this->haxcms()->refreshTokenDecoded = (object) array('user' => 'alice');
        $this->haxcms()->validUserResult = false;
        $this->assertNull(haxcmsResolveRefreshSessionContext());
    }

    public function testResolveRefreshSessionContextStoreErrorOnRejectedSession(): void
    {
        $this->haxcms()->refreshTokenDecoded = (object) array(
            'user' => 'alice',
            'family' => 'fam-1',
            'jti' => 'jti-1',
            'iat' => 12345,
        );
        $this->haxcms()->refreshSessionOk = false;
        $this->haxcms()->refreshStoreError = true;
        $this->assertSame(array('storeError' => true), haxcmsResolveRefreshSessionContext());
        // Store unreadable -> no tombstone, no cookie clear.
        $this->assertSame(array(), $this->haxcms()->revokeCalls);
        $this->assertSame(array(), $this->haxcms()->cookieCalls);
    }

    public function testResolveRefreshSessionContextTombstonesRejectedFamily(): void
    {
        $this->haxcms()->refreshTokenDecoded = (object) array(
            'user' => 'alice',
            'family' => 'fam-1',
            'jti' => 'jti-1',
            'iat' => 12345,
        );
        $this->haxcms()->refreshSessionOk = false;
        $this->assertNull(haxcmsResolveRefreshSessionContext());
        $this->assertSame(array(array('alice', 'fam-1')), $this->haxcms()->revokeCalls);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    public function testResolveRefreshSessionContextStoreErrorOnFailedRotation(): void
    {
        $this->haxcms()->refreshTokenDecoded = (object) array('user' => 'alice');
        $this->haxcms()->refreshSessionOk = true;
        $this->haxcms()->rotatedToken = null;
        $this->haxcms()->refreshStoreError = true;
        $this->assertSame(array('storeError' => true), haxcmsResolveRefreshSessionContext());
        $this->assertSame(array(), $this->haxcms()->revokeCalls);
    }

    public function testResolveRefreshSessionContextTombstonesLegacyFamilyOnFailedRotation(): void
    {
        // Legacy token with no family claim -> tombstone only the legacy family.
        $this->haxcms()->refreshTokenDecoded = (object) array('user' => 'alice');
        $this->haxcms()->refreshSessionOk = true;
        $this->haxcms()->rotatedToken = null;
        $this->assertNull(haxcmsResolveRefreshSessionContext());
        $this->assertSame(array(array('alice', null)), $this->haxcms()->revokeCalls);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    public function testResolveRefreshSessionContextRotatesOnSuccess(): void
    {
        $this->haxcms()->refreshTokenDecoded = (object) array(
            'user' => 'alice',
            'family' => 'fam-2',
        );
        $context = haxcmsResolveRefreshSessionContext();
        $this->assertSame('rotated-access-jwt', $context['jwt']);
        $this->assertSame('alice', $context['user']);
    }

    // ------------------------------------------------------------------
    // haxcmsValidateSessionIAMAuthorization
    // ------------------------------------------------------------------

    public function testValidateSessionIAMAuthorizationDefaultsToAllowedWithoutMethod(): void
    {
        $GLOBALS['HAXCMS'] = new stdClass();
        $this->assertSame(
            array('allowed' => true, 'status' => 200, 'message' => ''),
            haxcmsValidateSessionIAMAuthorization()
        );
    }

    public function testValidateSessionIAMAuthorizationDeniesFalseResult(): void
    {
        $this->haxcms()->iamAuth = false;
        $result = haxcmsValidateSessionIAMAuthorization();
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Access denied', $result['message']);
    }

    public function testValidateSessionIAMAuthorizationDeniesArrayResult(): void
    {
        $this->haxcms()->iamAuth = array('allowed' => false, 'status' => 401, 'message' => 'IAM says no');
        $result = haxcmsValidateSessionIAMAuthorization();
        $this->assertFalse($result['allowed']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('IAM says no', $result['message']);
    }

    public function testValidateSessionIAMAuthorizationDeniesArrayResultWithDefaults(): void
    {
        $this->haxcms()->iamAuth = array('allowed' => false);
        $result = haxcmsValidateSessionIAMAuthorization();
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Access denied', $result['message']);
    }

    public function testValidateSessionIAMAuthorizationAllows(): void
    {
        $this->haxcms()->iamAuth = array('allowed' => true, 'status' => 200, 'message' => '');
        $result = haxcmsValidateSessionIAMAuthorization();
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session/login (failure paths; the flat-envelope
    // success path exits and is documented in the file docblock)
    // ------------------------------------------------------------------

    public function testLoginWithoutCredentialsReturns401(): void
    {
        $result = $this->runHandler('v1/session/login', 'POST', array());
        $this->assertSame(401, $result['code']);
        $this->assertSame('Login is required', $result['data']['data']['message']);
    }

    public function testLoginWithBadCredentialsReturns401(): void
    {
        $result = $this->runHandler('v1/session/login', 'POST', array(
            'username' => 'testuser',
            'password' => 'wrong',
        ));
        $this->assertSame(401, $result['code']);
        $this->assertSame('Access denied', $result['data']['data']['message']);
    }

    public function testLoginRevalidateWithInvalidJwtReturns401(): void
    {
        $this->haxcms()->validateJwtResult = false;
        $result = $this->runHandler('v1/session/login', 'POST', array('jwt' => 'stale-jwt'));
        $this->assertSame(401, $result['code']);
        $this->assertSame('Invalid token', $result['data']['data']['message']);
        $this->assertSame('stale-jwt', $this->haxcms()->sessionJwt);
    }

    public function testLoginStripsUserAndSiteTokensFromBody(): void
    {
        // user_token/site_token are stripped from params before login(); the
        // 401 'Login is required' response proves nothing else fired.
        $result = $this->runHandler('v1/session/login', 'POST', array(
            'user_token' => 'evil-ut',
            'site_token' => 'evil-st',
        ));
        $this->assertSame(401, $result['code']);
        $this->assertSame('Login is required', $result['data']['data']['message']);
        $this->assertSame(array(), $this->haxcms()->tokenCalls);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session
    // ------------------------------------------------------------------

    public function testSessionWithoutBearerReturns401MissingJwt(): void
    {
        $result = $this->runHandler('v1/session', 'GET');
        $this->assertSame(401, $result['code']);
        // These session endpoints emit with envelope:false -> payload at top level.
        $this->assertFalse($result['data']['authenticated']);
        $this->assertSame('missing_jwt', $result['data']['reason']);
    }

    public function testSessionWithInvalidBearerReturns401InvalidJwt(): void
    {
        $this->haxcms()->bearerToken = 'garbage';
        $this->haxcms()->decodedJwt = false;
        $result = $this->runHandler('v1/session', 'GET');
        $this->assertSame(401, $result['code']);
        $this->assertSame('invalid_jwt', $result['data']['reason']);
    }

    public function testSessionWithDeniedIamReturns403(): void
    {
        $this->haxcms()->bearerToken = 'good-jwt';
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => 'someone',
        );
        $this->haxcms()->iamAuth = array('allowed' => false, 'status' => 401, 'message' => 'IAM says no');
        $result = $this->runHandler('v1/session', 'GET');
        $this->assertSame(401, $result['code']);
        $this->assertSame('not_authorized', $result['data']['reason']);
        $this->assertSame('IAM says no', $result['data']['message']);
    }

    public function testSessionWithValidBearerReturns200(): void
    {
        $this->haxcms()->bearerToken = 'good-jwt';
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => 'Some User',
        );
        $result = $this->runHandler('v1/session', 'GET');
        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['data']['authenticated']);
        $this->assertSame('good-jwt', $result['data']['jwt']);
        $this->assertSame('some-user', $result['data']['user']);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session/logout
    // ------------------------------------------------------------------

    public function testLogoutClearsRefreshCookieAndReturnsLoggedout(): void
    {
        $result = $this->runHandler('v1/session/logout', 'POST');
        $this->assertSame(200, $result['code']);
        $this->assertSame('loggedout', $result['data']['data']);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
        // No refresh cookie present -> no family revocation attempted.
        $this->assertSame(array(), $this->haxcms()->revokeCalls);
    }

    public function testLogoutRevokesPresentedRefreshFamily(): void
    {
        $_COOKIE['haxcms_refresh_token'] = 'cookie-token';
        $this->haxcms()->decodedRefreshCookie = (object) array(
            'user' => 'alice',
            'family' => 'fam-9',
        );
        $result = $this->runHandler('v1/session/logout', 'POST');
        $this->assertSame(200, $result['code']);
        $this->assertSame('loggedout', $result['data']['data']);
        $this->assertSame(array(array('alice', 'fam-9')), $this->haxcms()->revokeCalls);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session/refresh (failure path; success exits and is
    // documented in the file docblock)
    // ------------------------------------------------------------------

    public function testRefreshWithoutValidTokenReturns401(): void
    {
        $this->haxcms()->refreshTokenDecoded = false;
        $result = $this->runHandler('v1/session/refresh', 'POST');
        $this->assertSame(401, $result['code']);
        $this->assertSame('haxcms_refresh_token:invalid', $result['data']['data']['message']);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    public function testRefreshWithCrossOriginRequestReturns403(): void
    {
        $this->haxcms()->sameOrigin = false;
        $this->haxcms()->refreshTokenDecoded = (object) array('user' => 'alice');
        $result = $this->runHandler('v1/session/refresh', 'POST');
        $this->assertSame(403, $result['code']);
        $this->assertSame('Cross-origin request denied', $result['data']['data']['message']);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session/connection-settings (denied path; the
    // __noencode success path exits and is documented in the file docblock)
    // ------------------------------------------------------------------

    public function testConnectionSettingsWithDeniedIamReturns403(): void
    {
        $this->haxcms()->iamAuth = array('allowed' => false, 'status' => 403, 'message' => 'no config for you');
        $result = $this->runHandler('v1/session/connection-settings', 'GET');
        $this->assertSame(403, $result['code']);
        $this->assertSame('no config for you', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session/connection-test
    // ------------------------------------------------------------------

    public function testConnectionTestDeniesCrossOriginRefreshCookie(): void
    {
        $_COOKIE['haxcms_refresh_token'] = 'cookie-token';
        $this->haxcms()->sameOrigin = false;
        $result = $this->runHandler('v1/session/connection-test', 'GET');
        $this->assertSame(403, $result['code']);
        $this->assertFalse($result['data']['authenticated']);
        $this->assertSame('cross_origin', $result['data']['reason']);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    public function testConnectionTestReturns503WhenStoreUnavailable(): void
    {
        $_COOKIE['haxcms_refresh_token'] = 'cookie-token';
        $this->haxcms()->refreshTokenDecoded = (object) array('user' => 'alice');
        $this->haxcms()->refreshSessionOk = false;
        $this->haxcms()->refreshStoreError = true;
        $result = $this->runHandler('v1/session/connection-test', 'GET');
        $this->assertSame(503, $result['code']);
        $this->assertSame('store_unavailable', $result['data']['reason']);
        // Store error -> no cookie clear (server fault must not log user out).
        $this->assertSame(array(), $this->haxcms()->cookieCalls);
    }

    public function testConnectionTestRecoversViaRefreshCookie(): void
    {
        $_COOKIE['haxcms_refresh_token'] = 'cookie-token';
        $this->haxcms()->refreshTokenDecoded = (object) array(
            'user' => 'alice',
            'family' => 'fam-3',
        );
        $result = $this->runHandler('v1/session/connection-test', 'GET');
        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['data']['authenticated']);
        $this->assertTrue($result['data']['refreshed']);
        $this->assertSame('rotated-access-jwt', $result['data']['jwt']);
        $this->assertSame('alice', $result['data']['user']);
        $this->assertSame('ut-token', $result['data']['userToken']);
        $this->assertSame('st-token', $result['data']['siteToken']);
    }

    public function testConnectionTestRejectsStaleBearerWith401(): void
    {
        // Bearer supplied but invalid + no refresh cookie -> 401 (not the
        // anonymous 200), reserving 200 no_session for true anonymous probes.
        $this->haxcms()->bearerToken = 'stale-jwt';
        $this->haxcms()->decodedJwt = false;
        $result = $this->runHandler('v1/session/connection-test', 'GET');
        $this->assertSame(401, $result['code']);
        $this->assertSame('invalid_session', $result['data']['reason']);
        // The anonymous/failed branch clears the refresh cookie unconditionally.
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    public function testConnectionTestAnonymousReturns200NoSession(): void
    {
        $result = $this->runHandler('v1/session/connection-test', 'GET');
        $this->assertSame(200, $result['code']);
        $this->assertFalse($result['data']['authenticated']);
        $this->assertSame('no_session', $result['data']['reason']);
        $this->assertSame(array(array('', 1)), $this->haxcms()->cookieCalls);
    }

    public function testConnectionTestWithValidBearerAndDeniedIamReturns403(): void
    {
        $this->haxcms()->bearerToken = 'good-jwt';
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => 'someone',
        );
        $this->haxcms()->iamAuth = array('allowed' => false, 'status' => 403, 'message' => 'IAM denies');
        $result = $this->runHandler('v1/session/connection-test', 'GET');
        $this->assertSame(403, $result['code']);
        $this->assertSame('not_authorized', $result['data']['reason']);
        $this->assertSame('IAM denies', $result['data']['message']);
    }

    public function testConnectionTestWithValidBearerReturns200WithRequestTokens(): void
    {
        $this->haxcms()->bearerToken = 'good-jwt';
        $this->haxcms()->decodedJwt = (object) array(
            'id' => 'request-token-for-user',
            'user' => 'Some User',
        );
        $result = $this->runHandler('v1/session/connection-test', 'GET', array('extra' => 'x'));
        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['data']['authenticated']);
        $this->assertFalse($result['data']['refreshed']);
        $this->assertSame('good-jwt', $result['data']['jwt']);
        $this->assertSame('some-user', $result['data']['user']);
        $this->assertSame('ut-token', $result['data']['userToken']);
        $this->assertSame('st-token', $result['data']['siteToken']);
    }

    // ------------------------------------------------------------------
    // Route closure: v1/session/user
    // ------------------------------------------------------------------

    public function testUserRouteFeedsClientHeaderIntoTokenCheck(): void
    {
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = 'client-user-token';
        $this->haxcms()->userData = (object) array('name' => 'testuser', 'roles' => array('editor'));
        $result = $this->runHandler('v1/session/user', 'GET');
        $this->assertSame(200, $result['code']);
        $this->assertSame(
            array('name' => 'testuser', 'roles' => array('editor')),
            $result['data']['data']
        );
        // The X-HAXCMS-User-Token header value reached the token check.
        $this->assertContains(array('client-user-token', 'testuser'), $this->haxcms()->tokenCalls);
    }

    public function testUserRouteWithoutHeaderUsesEmptyToken(): void
    {
        $this->haxcms()->validRequestToken = false;
        $result = $this->runHandler('v1/session/user', 'POST');
        $this->assertSame(403, $result['code']);
        $this->assertSame('invalid request token', $result['data']['data']['message']);
        $this->assertContains(array('', 'testuser'), $this->haxcms()->tokenCalls);
    }

    // ------------------------------------------------------------------
    // Unknown route
    // ------------------------------------------------------------------

    public function testUnknownSessionRouteReturns404(): void
    {
        $result = $this->runHandler('v1/session/fancy', 'GET');
        $this->assertSame(404, $result['code']);
        $this->assertSame('Unknown session route', $result['data']['data']['message']);
    }
}
