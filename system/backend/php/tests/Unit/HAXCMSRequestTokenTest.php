<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the HAXCMS request-token + site-token seam.
 *
 * getRequestToken($value) = urlsafe-base64(HMAC-SHA256(value, privateKey.salt)).
 * The deterministic-HMAC assertions use KNOWN-GOOD LITERALS computed
 * independently (a standalone hash_hmac computation that never imports the
 * HAXCMS class), so they are not tautological — a bug in the HMAC formula or
 * key derivation would disagree with the literal. Relational properties
 * (determinism, distinctness, validate<->generate identity) pin the contract.
 *
 * Instances are built via newInstanceWithoutConstructor to skip the stateful
 * god-class constructor; the token methods only read privateKey/salt/config.
 * $_SERVER['SERVER_SOFTWARE'] is set in setUp so isCLI() returns false and the
 * real validation path runs (otherwise validateRequestToken short-circuits to
 * true under the PHPUnit CLI sapi).
 */
class HAXCMSRequestTokenTest extends TestCase
{
    private $haxcms;
    private $savedServerSoftware;

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->privateKey = 'pk';
        $this->haxcms->refreshPrivateKey = 'rpk';
        $this->haxcms->salt = 's';
        $this->haxcms->config = new stdClass();
        // config->iam intentionally unset -> normalizeIAMTokenValue is identity
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = 'alice';
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = 'admin';
        // Force isCLI() false so validateRequestToken runs the real path.
        $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
    }

    protected function tearDown(): void
    {
        if ($this->savedServerSoftware !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
    }

    public function testGetRequestTokenProducesKnownGoodLiteral(): void
    {
        // Independent source of truth: hash_hmac('sha256','user','pks') urlsafe.
        $this->assertSame(
            'Jd62QPYkVNX8LggmbsDW-ofdCI9C1oQsa6UX4XIQmgQ',
            $this->haxcms->getRequestToken('user')
        );
    }

    public function testGetRequestTokenIsDeterministic(): void
    {
        $this->assertSame(
            $this->haxcms->getRequestToken('alice'),
            $this->haxcms->getRequestToken('alice')
        );
    }

    public function testGetRequestTokenDistinctForDifferentValues(): void
    {
        $this->assertNotSame(
            $this->haxcms->getRequestToken('alice'),
            $this->haxcms->getRequestToken('bob')
        );
    }

    public function testGetRequestTokenDistinctForDifferentKeys(): void
    {
        $a = $this->haxcms->getRequestToken('alice');
        $this->haxcms->privateKey = 'different';
        $b = $this->haxcms->getRequestToken('alice');
        $this->assertNotSame($a, $b);
    }

    public function testValidateRequestTokenAcceptsCorrectToken(): void
    {
        $token = $this->haxcms->getRequestToken('alice');
        $this->assertTrue($this->haxcms->validateRequestToken($token, 'alice'));
    }

    public function testValidateRequestTokenRejectsWrongToken(): void
    {
        $this->assertFalse($this->haxcms->validateRequestToken('not-the-token', 'alice'));
    }

    public function testValidateRequestTokenRejectsNullToken(): void
    {
        $this->assertFalse($this->haxcms->validateRequestToken(null, 'alice'));
    }

    public function testValidateRequestTokenRejectsTokenForDifferentValue(): void
    {
        $token = $this->haxcms->getRequestToken('alice');
        // token bound to 'alice' must not validate against 'bob'
        $this->assertFalse($this->haxcms->validateRequestToken($token, 'bob'));
    }

    public function testGetSiteTokenForSiteNameProducesKnownGoodLiteral(): void
    {
        // requestTokenUser resolves to user->name 'alice'; site token =
        // getRequestToken('alice:mysite') = known-good literal below.
        $this->assertSame(
            'HHk3WMmcGe2LnbfS5N-VFp0aoIb17qKce0DLkbIAX3M',
            $this->haxcms->getSiteTokenForSiteName('mysite')
        );
    }

    public function testValidateSiteTokenAcceptsCorrectToken(): void
    {
        $token = $this->haxcms->getSiteTokenForSiteName('mysite');
        $this->assertTrue($this->haxcms->validateSiteToken('mysite', $token));
    }

    public function testValidateSiteTokenRejectsWrongToken(): void
    {
        $this->assertFalse($this->haxcms->validateSiteToken('mysite', 'wrong-token'));
    }

    public function testValidateSiteTokenRejectsTokenForDifferentSite(): void
    {
        $token = $this->haxcms->getSiteTokenForSiteName('mysite');
        // a token bound to 'mysite' must not validate for 'other-site'
        $this->assertFalse($this->haxcms->validateSiteToken('other-site', $token));
    }
}
