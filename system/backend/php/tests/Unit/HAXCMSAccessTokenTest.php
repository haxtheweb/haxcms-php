<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the HAXCMS access-token seam.
 *
 * getJWT($name) issues a 15-minute HS256 JWT carrying id=getRequestToken('user'),
 * iat, exp, user. decodeJWT verifies the signature AND enforces the temporal
 * claims (exp mandatory + not-expired, nbf not-in-future, iat not-in-future,
 * 60s leeway) — the hardening that made the 15-min expiry real instead of
 * cosmetic. validateJWT additionally checks id matches and validateUser.
 *
 * Expected values are spec-derived: a valid fresh token decodes; an expired,
 * missing-exp, future-nbf, future-iat, or wrong-key token is rejected. The id
 * claim must equal the known-good request-token literal. $_SERVER['SERVER_SOFTWARE']
 * is set so isCLI() is false (otherwise decodeJWT/validateJWT short-circuit).
 */
class HAXCMSAccessTokenTest extends TestCase
{
    private $haxcms;
    private $savedServerSoftware;
    private const KNOWN_USER_TOKEN = 'Jd62QPYkVNX8LggmbsDW-ofdCI9C1oQsa6UX4XIQmgQ';

    protected function setUp(): void
    {
        $this->haxcms = (new ReflectionClass(HAXCMS::class))
            ->newInstanceWithoutConstructor();
        $this->haxcms->privateKey = 'pk';
        $this->haxcms->refreshPrivateKey = 'rpk';
        $this->haxcms->salt = 's';
        $this->haxcms->config = new stdClass();
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = 'alice';
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = 'admin';
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

    private function craftToken(array $claims): string
    {
        return JWT::encode((object)$claims, $this->haxcms->privateKey . $this->haxcms->salt);
    }

    public function testGetJwtProducesValidDecodableToken(): void
    {
        $jwt = $this->haxcms->getJWT('alice');
        $decoded = $this->haxcms->decodeJWT($jwt);
        $this->assertNotFalse($decoded);
        $this->assertSame('alice', $decoded->user);
        $this->assertSame(self::KNOWN_USER_TOKEN, $decoded->id);
        $this->assertIsNumeric($decoded->iat);
        $this->assertIsNumeric($decoded->exp);
        // 15-minute expiry
        $this->assertSame(900, (int)$decoded->exp - (int)$decoded->iat);
    }

    public function testDecodeJwtRoundTripsFreshToken(): void
    {
        $jwt = $this->haxcms->getJWT('alice');
        $now = time();
        $decoded = $this->haxcms->decodeJWT($jwt);
        $this->assertNotFalse($decoded);
        $this->assertGreaterThanOrEqual($now - 5, (int)$decoded->iat);
        $this->assertLessThanOrEqual($now + 5, (int)$decoded->iat);
    }

    public function testDecodeJwtRejectsExpiredToken(): void
    {
        $jwt = $this->craftToken(array(
            'id' => self::KNOWN_USER_TOKEN,
            'iat' => time() - 2000,
            'exp' => time() - 1000,
            'user' => 'alice',
        ));
        $this->assertFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testDecodeJwtRejectsTokenMissingExpiration(): void
    {
        $jwt = $this->craftToken(array(
            'id' => self::KNOWN_USER_TOKEN,
            'iat' => time(),
            'user' => 'alice',
        ));
        $this->assertFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testDecodeJwtRejectsNotYetValidToken(): void
    {
        $jwt = $this->craftToken(array(
            'id' => self::KNOWN_USER_TOKEN,
            'iat' => time(),
            'exp' => time() + 900,
            'nbf' => time() + 5000,
            'user' => 'alice',
        ));
        $this->assertFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testDecodeJwtRejectsFutureIssuedToken(): void
    {
        $jwt = $this->craftToken(array(
            'id' => self::KNOWN_USER_TOKEN,
            'iat' => time() + 5000,
            'exp' => time() + 900,
            'user' => 'alice',
        ));
        $this->assertFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testDecodeJwtRejectsWrongKey(): void
    {
        $jwt = JWT::encode(
            (object)array('id' => self::KNOWN_USER_TOKEN, 'iat' => time(), 'exp' => time() + 900, 'user' => 'alice'),
            'wrong-key'
        );
        $this->assertFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testDecodeJwtRejectsRefreshKeyToken(): void
    {
        // access-token decode must use the access key, not the refresh key
        $jwt = JWT::encode(
            (object)array('id' => self::KNOWN_USER_TOKEN, 'iat' => time(), 'exp' => time() + 900, 'user' => 'alice'),
            $this->haxcms->refreshPrivateKey . $this->haxcms->salt
        );
        $this->assertFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testDecodeJwtRejectsEmptyAndNonString(): void
    {
        $this->assertFalse($this->haxcms->decodeJWT(''));
        $this->assertFalse($this->haxcms->decodeJWT(null));
    }

    public function testDecodeJwtAcceptsTokenNearExpiryWithinLeeway(): void
    {
        // 60s leeway: a token expired 30s ago must still validate
        $jwt = $this->craftToken(array(
            'id' => self::KNOWN_USER_TOKEN,
            'iat' => time() - 1000,
            'exp' => time() - 30,
            'user' => 'alice',
        ));
        $this->assertNotFalse($this->haxcms->decodeJWT($jwt));
    }

    public function testValidateJwtAcceptsValidSessionToken(): void
    {
        // endOnInvalid=false so an invalid token returns false instead of exit
        $this->haxcms->sessionJwt = $this->haxcms->getJWT('alice');
        $this->assertTrue($this->haxcms->validateJWT(false));
    }

    public function testValidateJwtRejectsTamperedSessionToken(): void
    {
        $jwt = $this->haxcms->getJWT('alice');
        $parts = explode('.', $jwt);
        $parts[1] = substr($parts[1], 0, -1) . (substr($parts[1], -1) === 'A' ? 'B' : 'A');
        $this->haxcms->sessionJwt = implode('.', $parts);
        $this->assertFalse($this->haxcms->validateJWT(false));
    }

    public function testValidateJwtRejectsUnknownUser(): void
    {
        // valid signature + valid claims, but user not in user/superUser
        $jwt = $this->craftToken(array(
            'id' => self::KNOWN_USER_TOKEN,
            'iat' => time(),
            'exp' => time() + 900,
            'user' => 'mallory',
        ));
        $this->haxcms->sessionJwt = $jwt;
        $this->assertFalse($this->haxcms->validateJWT(false));
    }

    public function testValidateJwtRejectsMismatchedId(): void
    {
        // signature valid, user valid, but id != getRequestToken('user')
        $jwt = $this->craftToken(array(
            'id' => 'not-the-right-id',
            'iat' => time(),
            'exp' => time() + 900,
            'user' => 'alice',
        ));
        $this->haxcms->sessionJwt = $jwt;
        $this->assertFalse($this->haxcms->validateJWT(false));
    }
}
