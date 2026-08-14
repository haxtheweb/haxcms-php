<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the pure JWT seam.
 *
 * Expected values come from RFC 7519 + the security hardening in the class:
 * signature verification must reject a tampered token; the algorithm must be
 * pinned to the HS256/384/512 allowlist so an alg-confusion/'none' attack
 * cannot succeed; the wrong key must fail; a malformed token must throw. These
 * are pure static methods with no instance state, so no fixture is needed.
 */
class JwtTest extends TestCase
{
    public function testEncodeDecodeRoundTripsPayload(): void
    {
        $payload = (object)array('user' => 'alice', 'role' => 'admin', 'n' => 42);
        $jwt = JWT::encode($payload, 'secret-key');
        $decoded = JWT::decode($jwt, 'secret-key');
        $this->assertSame('alice', $decoded->user);
        $this->assertSame('admin', $decoded->role);
        $this->assertSame(42, $decoded->n);
    }

    public function testDecodeRejectsTamperedPayload(): void
    {
        $jwt = JWT::encode((object)array('user' => 'alice'), 'secret-key');
        $parts = explode('.', $jwt);
        // Replace the payload segment with a VALIDLY-encoded different payload
        // (valid base64url + valid JSON) so decode reaches signature
        // verification, which must reject the header+newPayload against the
        // original signature. Swapping a different payload keeps encoding intact
        // and deterministically hits the signature-mismatch path.
        $parts[1] = JWT::urlsafeB64Encode(JWT::jsonEncode(array('user' => 'mallory', 'admin' => true)));
        $this->expectException(UnexpectedValueException::class);
        JWT::decode(implode('.', $parts), 'secret-key');
    }

    public function testDecodeRejectsWrongKey(): void
    {
        $jwt = JWT::encode((object)array('user' => 'alice'), 'secret-key');
        $this->expectException(UnexpectedValueException::class);
        JWT::decode($jwt, 'different-key');
    }

    public function testDecodeRejectsNoneAlgorithm(): void
    {
        // alg-confusion attack: a token claiming alg=none must be rejected by
        // the explicit allowlist, even with an empty signature.
        $header = JWT::urlsafeB64Encode(JWT::jsonEncode(array('typ' => 'JWT', 'alg' => 'none')));
        $body = JWT::urlsafeB64Encode(JWT::jsonEncode(array('user' => 'alice')));
        $token = $header . '.' . $body . '.';
        $this->expectException(UnexpectedValueException::class);
        JWT::decode($token, 'secret-key');
    }

    public function testDecodeRejectsDisallowedRsaAlgorithm(): void
    {
        $header = JWT::urlsafeB64Encode(JWT::jsonEncode(array('typ' => 'JWT', 'alg' => 'RS256')));
        $body = JWT::urlsafeB64Encode(JWT::jsonEncode(array('user' => 'alice')));
        $sig = JWT::urlsafeB64Encode(JWT::sign($header . '.' . $body, 'secret-key', 'HS256'));
        $token = $header . '.' . $body . '.' . $sig;
        $this->expectException(UnexpectedValueException::class);
        JWT::decode($token, 'secret-key');
    }

    public function testDecodeHonorsCustomAllowedAlgorithms(): void
    {
        $payload = (object)array('user' => 'alice');
        $jwt = JWT::encode($payload, 'secret-key', 'HS384');
        $decoded = JWT::decode($jwt, 'secret-key', true, array('HS384'));
        $this->assertSame('alice', $decoded->user);
        // HS256 not in the custom allowlist -> rejected
        $jwt256 = JWT::encode($payload, 'secret-key', 'HS256');
        $this->expectException(UnexpectedValueException::class);
        JWT::decode($jwt256, 'secret-key', true, array('HS384'));
    }

    public function testDecodeRejectsMalformedToken(): void
    {
        // two segments (one dot) -> "Wrong number of segments"
        $this->expectException(UnexpectedValueException::class);
        JWT::decode('only.one', 'secret-key');
    }

    public function testDecodeRejectsEmptyAlgorithm(): void
    {
        $header = JWT::urlsafeB64Encode(JWT::jsonEncode(array('typ' => 'JWT')));
        $body = JWT::urlsafeB64Encode(JWT::jsonEncode(array('user' => 'alice')));
        $sig = JWT::urlsafeB64Encode(JWT::sign($header . '.' . $body, 'secret-key', 'HS256'));
        $token = $header . '.' . $body . '.' . $sig;
        $this->expectException(DomainException::class);
        JWT::decode($token, 'secret-key');
    }

    public function testSignRejectsUnsupportedAlgorithm(): void
    {
        $this->expectException(DomainException::class);
        JWT::sign('msg', 'key', 'RS256');
    }

    public function testUrlsafeBase64RoundTrips(): void
    {
        $this->assertSame('Hello World!', JWT::urlsafeB64Decode(JWT::urlsafeB64Encode('Hello World!')));
        $this->assertSame('', JWT::urlsafeB64Decode(JWT::urlsafeB64Encode('')));
        // urlsafe alphabet: no + / =
        $encoded = JWT::urlsafeB64Encode("\xff\xfe\xfd\xfc");
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }
}
