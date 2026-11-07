<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once dirname(__DIR__, 2) . '/lib/siteRoutes/SiteApiSecurity.php';

/**
 * SiteApiSecurity::validateSiteApiAccess branches with no coverage before:
 *
 * 1. The 'authenticated-user' policy (X-HAXCMS-User-Token header). No route
 *    in site-spec.yaml declares userTokenHeader today, so the branch is only
 *    reachable through the spec-derived policy table. The test injects one
 *    route policy into SiteRouteUtils' policy cache (restored in tearDown) so
 *    the branch is pinned before a spec change starts routing traffic to it.
 *    Mirrors Node validateSiteApiRouteAccess authenticated-user.
 *
 * 2. The fallback bearer decode (resolveBearerUserName when the HAXCMS
 *    global has no getBearerTokenUserName). Security note N3 in the source
 *    says this path must enforce exp / nbf / iat exactly like the primary
 *    HAXCMS path, with 60s leeway; these tests hold it to that: expired,
 *    missing exp, future nbf, future iat, wrong key, alg none and a token
 *    with no user are all rejected (403 Invalid bearer token), a valid token
 *    authenticates as the machine-name form of its user.
 *
 * Signing keys are random bytes generated per test; no credential literals.
 */
class SiteApiSecurityUserTokenAndFallbackJwtTest extends TestCase
{
    private $savedServer = array();
    private $savedPolicies = null;
    private $policyProperty = null;
    private $hadHaxcms = false;
    private $savedHaxcms = null;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'],
            $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']
        );
        putenv('HTTP_AUTHORIZATION');
        $this->hadHaxcms = array_key_exists('HAXCMS', $GLOBALS);
        if ($this->hadHaxcms) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->policyProperty = new ReflectionProperty('SiteRouteUtils', 'siteApiAuthPolicies');
        $this->policyProperty->setAccessible(true);
        $this->savedPolicies = $this->policyProperty->getValue();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $this->policyProperty->setValue(null, $this->savedPolicies);
        if ($this->hadHaxcms) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
    }

    /**
     * Load the real spec-derived policy table, then add one synthetic route
     * that requires a user token.
     */
    private function injectUserTokenRoute(): void
    {
        $this->policyProperty->setValue(null, null);
        $policies = SiteRouteUtils::readSiteApiAuthPoliciesFromOpenApiSpec();
        $policies['post:v1/phpunit-user-token-probe'] = 'authenticated-user';
        $this->policyProperty->setValue(null, $policies);
    }

    private function validateUserTokenRoute(): array
    {
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'v1/phpunit-user-token-probe');
        return SiteApiSecurity::validateSiteApiAccess($context, 'v1/phpunit-user-token-probe', 'POST');
    }

    private function randomKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    // =========================================================================
    // authenticated-user policy
    // =========================================================================

    public function testInjectedPolicyIsWhatTheRouteResolvesTo(): void
    {
        $this->injectUserTokenRoute();
        $this->assertSame(
            'authenticated-user',
            SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/phpunit-user-token-probe', 'POST')
        );
    }

    public function testUserTokenRouteWithoutCredentialsIs401(): void
    {
        $this->injectUserTokenRoute();
        $GLOBALS['HAXCMS'] = new SiteApiSecurityUserTokenStubHaxcms();
        $result = $this->validateUserTokenRoute();
        $this->assertFalse($result['allowed']);
        $this->assertSame(401, $result['status']);
    }

    public function testUserTokenRouteRequiresTheHeader(): void
    {
        $this->injectUserTokenRoute();
        $stub = new SiteApiSecurityUserTokenStubHaxcms();
        $stub->bearerUser = 'alice';
        $GLOBALS['HAXCMS'] = $stub;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->randomKey();
        $result = $this->validateUserTokenRoute();
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('X-HAXCMS-User-Token header is required for this endpoint', $result['message']);
        $this->assertSame(array(), $stub->validatedTokens, 'no token check without a header');
    }

    public function testEmptyUserTokenHeaderIsTreatedAsMissing(): void
    {
        $this->injectUserTokenRoute();
        $stub = new SiteApiSecurityUserTokenStubHaxcms();
        $stub->bearerUser = 'alice';
        $GLOBALS['HAXCMS'] = $stub;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->randomKey();
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = '';
        $result = $this->validateUserTokenRoute();
        $this->assertSame(403, $result['status']);
        $this->assertSame('X-HAXCMS-User-Token header is required for this endpoint', $result['message']);
    }

    public function testInvalidUserTokenIs403(): void
    {
        $this->injectUserTokenRoute();
        $stub = new SiteApiSecurityUserTokenStubHaxcms();
        $stub->bearerUser = 'alice';
        $stub->requestTokenValid = false;
        $GLOBALS['HAXCMS'] = $stub;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->randomKey();
        $userToken = $this->randomKey();
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $userToken;
        $result = $this->validateUserTokenRoute();
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid X-HAXCMS-User-Token header', $result['message']);
        // the user token is validated against the BEARER user, not anything
        // the client can choose
        $this->assertSame(array(array($userToken, 'alice')), $stub->validatedTokens);
    }

    public function testValidUserTokenIsAllowed(): void
    {
        $this->injectUserTokenRoute();
        $stub = new SiteApiSecurityUserTokenStubHaxcms();
        $stub->bearerUser = 'alice';
        $GLOBALS['HAXCMS'] = $stub;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->randomKey();
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $this->randomKey();
        $result = $this->validateUserTokenRoute();
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('alice', $result['userName']);
    }

    public function testUserTokenIsNotAcceptedWhenHaxcmsCannotValidateIt(): void
    {
        $this->injectUserTokenRoute();
        // a HAXCMS object without validateRequestToken must fail closed
        $stub = new SiteApiSecurityBearerOnlyStubHaxcms();
        $stub->bearerUser = 'alice';
        $GLOBALS['HAXCMS'] = $stub;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->randomKey();
        $_SERVER['HTTP_X_HAXCMS_USER_TOKEN'] = $this->randomKey();
        $result = $this->validateUserTokenRoute();
        $this->assertFalse($result['allowed']);
        $this->assertSame('Invalid X-HAXCMS-User-Token header', $result['message']);
    }

    // =========================================================================
    // fallback bearer decode (no getBearerTokenUserName on the HAXCMS global)
    // =========================================================================

    private function fallbackStub(): SiteApiSecurityFallbackJwtStubHaxcms
    {
        $stub = new SiteApiSecurityFallbackJwtStubHaxcms();
        $stub->privateKey = $this->randomKey();
        $stub->salt = $this->randomKey();
        $GLOBALS['HAXCMS'] = $stub;
        return $stub;
    }

    private function signFor(SiteApiSecurityFallbackJwtStubHaxcms $stub, array $claims): string
    {
        return JWT::encode((object) $claims, $stub->privateKey . $stub->salt);
    }

    /**
     * 'v1/site/outline' PATCH is authenticated-site in the real spec; with no site
     * token header the request never gets past the bearer check unless the
     * bearer is valid, so the status tells the two apart:
     *   403 'Invalid bearer token' -> bearer rejected
     *   403 'X-HAXCMS-Site-Token header is required...' -> bearer accepted
     */
    private function validateBearerRoute(string $token): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'v1/site/outline');
        return SiteApiSecurity::validateSiteApiAccess($context, 'v1/site/outline', 'PATCH');
    }

    private function assertBearerRejected(array $result): void
    {
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('Invalid bearer token', $result['message']);
    }

    public function testFallbackDecodeAcceptsAValidToken(): void
    {
        $stub = $this->fallbackStub();
        $now = time();
        $result = $this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'Alice Smith',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 600,
        )));
        $this->assertNotSame('Invalid bearer token', $result['message']);
        $this->assertSame('X-HAXCMS-Site-Token header is required for this endpoint', $result['message']);
        $this->assertSame('alice-smith', $result['userName']);
    }

    public function testFallbackDecodeRejectsExpiredToken(): void
    {
        $stub = $this->fallbackStub();
        $now = time();
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'iat' => $now - 7200,
            'exp' => $now - 3600,
        ))));
    }

    public function testFallbackDecodeHonorsTheLeewayWindow(): void
    {
        $stub = $this->fallbackStub();
        $now = time();
        // expired 30s ago: inside the 60s leeway
        $inside = $this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'exp' => $now - 30,
        )));
        $this->assertNotSame('Invalid bearer token', $inside['message']);
        // expired 120s ago: outside it
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'exp' => $now - 120,
        ))));
    }

    public function testFallbackDecodeRejectsTokenWithoutExp(): void
    {
        $stub = $this->fallbackStub();
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'iat' => time(),
        ))));
    }

    public function testFallbackDecodeRejectsNonNumericExp(): void
    {
        $stub = $this->fallbackStub();
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'exp' => 'never',
        ))));
    }

    public function testFallbackDecodeRejectsFutureNbf(): void
    {
        $stub = $this->fallbackStub();
        $now = time();
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'nbf' => $now + 3600,
            'exp' => $now + 7200,
        ))));
    }

    public function testFallbackDecodeRejectsFutureIat(): void
    {
        $stub = $this->fallbackStub();
        $now = time();
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => 'alice',
            'iat' => $now + 3600,
            'exp' => $now + 7200,
        ))));
    }

    public function testFallbackDecodeRejectsTokenSignedWithAnotherKey(): void
    {
        $stub = $this->fallbackStub();
        $token = JWT::encode((object) array('user' => 'alice', 'exp' => time() + 600), $this->randomKey());
        $this->assertBearerRejected($this->validateBearerRoute($token));
    }

    public function testFallbackDecodeRejectsAlgNone(): void
    {
        $this->fallbackStub();
        $header = JWT::urlsafeB64Encode(JWT::jsonEncode(array('typ' => 'JWT', 'alg' => 'none')));
        $body = JWT::urlsafeB64Encode(JWT::jsonEncode(array('user' => 'alice', 'exp' => time() + 600)));
        $this->assertBearerRejected($this->validateBearerRoute($header . '.' . $body . '.'));
    }

    public function testFallbackDecodeRejectsTokenWithoutUser(): void
    {
        $stub = $this->fallbackStub();
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'exp' => time() + 600,
        ))));
        $this->assertBearerRejected($this->validateBearerRoute($this->signFor($stub, array(
            'user' => '',
            'exp' => time() + 600,
        ))));
    }

    public function testFallbackDecodeRejectsMalformedToken(): void
    {
        $this->fallbackStub();
        $this->assertBearerRejected($this->validateBearerRoute('not-a-jwt'));
    }

    public function testFallbackWithoutKeysRejectsEvenAWellFormedToken(): void
    {
        $stub = new SiteApiSecurityFallbackJwtStubHaxcms();
        $GLOBALS['HAXCMS'] = $stub;
        $token = JWT::encode((object) array('user' => 'alice', 'exp' => time() + 600), $this->randomKey());
        $this->assertBearerRejected($this->validateBearerRoute($token));
    }

    public function testPublicRouteFallbackDecodeRejectsExpiredTokenAsAnonymous(): void
    {
        $stub = $this->fallbackStub();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->signFor($stub, array(
            'user' => 'alice',
            'exp' => time() - 3600,
        ));
        $context = makeSiteRouteContext(new SiteRoutesFakeSite(), array(), 'v1/items');
        $result = SiteApiSecurity::validateSiteApiAccess($context, 'v1/items', 'GET');
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['authenticated']);
        $this->assertNull($result['userName']);
    }
}

/** HAXCMS stub with the primary bearer path plus a recording token check. */
class SiteApiSecurityUserTokenStubHaxcms
{
    public $bearerUser = '';
    public $requestTokenValid = true;
    public $validatedTokens = array();
    public function getBearerTokenUserName()
    {
        return $this->bearerUser;
    }
    public function authenticateBasicAuthorization()
    {
        return array('attempted' => false, 'authenticated' => false, 'userName' => '');
    }
    public function validateRequestToken($token, $value)
    {
        $this->validatedTokens[] = array($token, $value);
        return $this->requestTokenValid;
    }
}

/** HAXCMS stub that can name the bearer user but cannot validate tokens. */
class SiteApiSecurityBearerOnlyStubHaxcms
{
    public $bearerUser = '';
    public function getBearerTokenUserName()
    {
        return $this->bearerUser;
    }
}

/**
 * HAXCMS stub WITHOUT getBearerTokenUserName so SiteApiSecurity takes its own
 * JWT decode path. Keys are assigned per test from random bytes.
 */
class SiteApiSecurityFallbackJwtStubHaxcms
{
    public $privateKey;
    public $salt;
    public function generateMachineName($name)
    {
        $name = strtolower(trim((string) $name));
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name);
        return trim($name, '-');
    }
}
