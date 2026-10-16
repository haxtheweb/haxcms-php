<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the HAXCMS refresh-token family seam.
 *
 * This is the subtle security core: refresh-token rotation with a server-side
 * session store (configDirectory/settings/refreshSessions.json) that detects
 * token theft via jti reuse. Only SHA-256 hashes of jti are stored.
 *
 * Spec-derived expected behavior:
 *   - store is v2: {version:2, users:{<user>:{families:{<fam>:{...}}}}}
 *   - recordRefreshSession: adds families[fam] = {currentJtiHash,
 *     previousJtiHash=null, previousValidUntil=0, exp}; other families kept
 *   - validateRefreshSession (pure, SEC-SESSION): legacy (no family claim) ->
 *     true unless iat <= legacyRevokedAt; family with no entry -> false;
 *     tombstoned family -> false; current jti -> true; previous jti within
 *     30s grace -> true; any other jti -> false (no write)
 *   - rotateRefreshSession: current OR previous-within-grace -> accept +
 *     rotate; stale jti -> TOMBSTONE that family + false; unknown family ->
 *     false, nothing touched
 *   - revokeRefreshSession(user, family): tombstones only that family;
 *     (user, null) sets legacyRevokedAt
 *   - getRefreshToken(store=true): 24h JWT with user/family/jti, session recorded
 *   - decodeRefreshToken: round-trip; wrong (access) key -> false; empty -> false
 *   - validateRefreshToken(false): no cookie -> false; valid -> decoded; expired -> false
 *   - rotateRefreshTokenAndCookie: valid -> new access JWT; reuse/theft -> revoke + null
 *
 * Instances use newInstanceWithoutConstructor (skip stateful constructor); the
 * refresh methods only read privateKey/refreshPrivateKey/salt/configDirectory.
 * $_SERVER['SERVER_SOFTWARE'] set so isCLI() is false (else validateRefreshToken
 * short-circuits to true). refreshSessions.json lives in a per-test temp dir.
 */
class HAXCMSRefreshTokenTest extends TestCase
{
    private $haxcms;
    private $tmpConfigDir;
    private $savedServerSoftware;
    private $savedCookie;

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
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_refresh_' . uniqid();
        mkdir($this->tmpConfigDir . '/settings', 0777, true);
        $this->haxcms->configDirectory = $this->tmpConfigDir;
        $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $this->savedCookie = $_COOKIE['haxcms_refresh_token'] ?? null;
        unset($_COOKIE['haxcms_refresh_token']);
    }

    protected function tearDown(): void
    {
        if ($this->savedServerSoftware !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        if ($this->savedCookie !== null) {
            $_COOKIE['haxcms_refresh_token'] = $this->savedCookie;
        } else {
            unset($_COOKIE['haxcms_refresh_token']);
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function sessionsPath(): string
    {
        return $this->tmpConfigDir . '/settings/refreshSessions.json';
    }

    private function loadSessionsFile(): array
    {
        $path = $this->sessionsPath();
        if (!file_exists($path)) {
            return array();
        }
        $parsed = json_decode(file_get_contents($path), true);
        return is_array($parsed) ? $parsed : array();
    }

    private function fam(string $user, string $family): ?array
    {
        $store = $this->loadSessionsFile();
        return $store['users'][$user]['families'][$family] ?? null;
    }

    // --- recordRefreshSession ---

    public function testRecordRefreshSessionWritesCurrentJtiHash(): void
    {
        $family = $this->uuid();
        $jti = $this->uuid();
        $exp = time() + 3600;
        $this->assertTrue($this->haxcms->recordRefreshSession('alice', $family, $jti, $exp));
        $store = $this->loadSessionsFile();
        $this->assertSame(2, $store['version']);
        $entry = $this->fam('alice', $family);
        $this->assertSame(hash('sha256', $jti), $entry['currentJtiHash']);
        $this->assertNull($entry['previousJtiHash']);
        $this->assertSame(0, $entry['previousValidUntil']);
        $this->assertSame($exp, $entry['exp']);
    }

    public function testRecordRefreshSessionKeepsOtherFamilies(): void
    {
        $this->haxcms->recordRefreshSession('alice', 'fam-a', 'ja', time() + 3600);
        $this->haxcms->recordRefreshSession('alice', 'fam-b', 'jb', time() + 3600);
        $this->assertNotNull($this->fam('alice', 'fam-a'));
        $this->assertNotNull($this->fam('alice', 'fam-b'));
    }

    public function testRecordRefreshSessionIgnoresEmptyInputs(): void
    {
        $this->assertFalse($this->haxcms->recordRefreshSession('', 'f', 'j', time() + 100));
        $this->assertFalse($this->haxcms->recordRefreshSession('alice', '', 'j', time() + 100));
        $this->assertFalse($this->haxcms->recordRefreshSession('alice', 'f', '', time() + 100));
        $this->assertFalse($this->haxcms->recordRefreshSession('alice', '__proto__', 'j', time() + 100));
        $this->assertFalse(file_exists($this->sessionsPath()));
    }

    // --- validateRefreshSession ---

    public function testValidateRefreshSessionAcceptsLegacyTokenWithoutFamily(): void
    {
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', '', ''));
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', null, null));
    }

    public function testValidateRefreshSessionRejectsFamilyTokenWithMissingEntry(): void
    {
        // Security (SEC-SESSION): a family-bearing token with no store entry
        // is rejected (only tokens with NO family claim are legacy-accepted).
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', 'f', 'j'));
        $this->assertFalse(file_exists($this->sessionsPath()), 'pure check never writes');
    }

    public function testValidateRefreshSessionRejectsFamilyWithoutJti(): void
    {
        $this->haxcms->recordRefreshSession('alice', 'f', 'j', time() + 3600);
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', 'f', null));
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', 'f', ''));
    }

    public function testValidateRefreshSessionAcceptsCurrentJti(): void
    {
        $family = $this->uuid();
        $jti = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jti, time() + 3600);
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', $family, $jti));
    }

    public function testValidateRefreshSessionRejectsWrongFamily(): void
    {
        $family = $this->uuid();
        $jti = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jti, time() + 3600);
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', 'wrong-family', $jti));
    }

    public function testValidateRefreshSessionRejectsUnknownJtiWithoutWriting(): void
    {
        $family = $this->uuid();
        $jti = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jti, time() + 3600);
        $before = $this->loadSessionsFile();
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', $family, 'never-issued-jti'));
        $this->assertSame($before, $this->loadSessionsFile());
    }

    public function testValidateRefreshSessionAcceptsPreviousJtiWithinGrace(): void
    {
        $family = $this->uuid();
        $jtiA = $this->uuid();
        $jtiB = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jtiA, time() + 3600);
        // rotate A -> B; jtiA becomes previous within the 30s grace window
        $this->assertTrue($this->haxcms->rotateRefreshSession('alice', $family, $jtiA, $jtiB, time() + 3600));
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', $family, $jtiA));
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', $family, $jtiB));
    }

    // --- rotateRefreshSession ---

    public function testRotateRefreshSessionMovesCurrentToPrevious(): void
    {
        $family = $this->uuid();
        $jtiA = $this->uuid();
        $jtiB = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jtiA, time() + 3600);
        $newExp = time() + 7200;
        $this->assertTrue($this->haxcms->rotateRefreshSession('alice', $family, $jtiA, $jtiB, $newExp));
        $entry = $this->fam('alice', $family);
        $this->assertSame(hash('sha256', $jtiB), $entry['currentJtiHash']);
        $this->assertSame(hash('sha256', $jtiA), $entry['previousJtiHash']);
        $this->assertSame($newExp, $entry['exp']);
    }

    public function testRotateRefreshSessionDetectsTheftAndTombstonesFamily(): void
    {
        $family = $this->uuid();
        $jtiA = $this->uuid();
        $jtiB = $this->uuid();
        $jtiStolen = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jtiA, time() + 3600);
        $this->assertTrue($this->haxcms->rotateRefreshSession('alice', $family, $jtiA, $jtiB, time() + 3600));
        $this->assertFalse($this->haxcms->rotateRefreshSession('alice', $family, $jtiStolen, $this->uuid(), time() + 3600));
        $entry = $this->fam('alice', $family);
        $this->assertTrue($entry['revoked'], 'family tombstoned, not deleted');
        $this->assertGreaterThanOrEqual(time() + 86400 + 60 - 2, $entry['exp']);
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', $family, $jtiB),
            'after reuse detection every later attempt is rejected');
        $this->assertFalse($this->haxcms->rotateRefreshSession('alice', $family, $jtiB, $this->uuid(), time() + 3600));
    }

    public function testRotateRefreshSessionRejectsUnknownFamilyWithoutTouchingStore(): void
    {
        $family = $this->uuid();
        $jti = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jti, time() + 3600);
        $before = $this->loadSessionsFile();
        $this->assertFalse($this->haxcms->rotateRefreshSession('alice', $this->uuid(), $jti, $this->uuid(), time() + 3600));
        $this->assertSame($before, $this->loadSessionsFile());
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', $family, $jti));
    }

    public function testRotateRefreshSessionRejectsEmptyInputs(): void
    {
        $this->assertFalse($this->haxcms->rotateRefreshSession('', 'f', 'a', 'b', time() + 100));
        $this->assertFalse($this->haxcms->rotateRefreshSession('alice', '', 'a', 'b', time() + 100));
        $this->assertFalse($this->haxcms->rotateRefreshSession('alice', 'f', '', 'b', time() + 100));
        $this->assertFalse($this->haxcms->rotateRefreshSession('alice', 'f', 'a', '', time() + 100));
    }

    // --- revokeRefreshSession ---

    public function testRevokeRefreshSessionTombstonesOnlyThatFamily(): void
    {
        $this->haxcms->recordRefreshSession('alice', 'fam-a', 'ja', time() + 3600);
        $this->haxcms->recordRefreshSession('alice', 'fam-b', 'jb', time() + 3600);
        $this->haxcms->revokeRefreshSession('alice', 'fam-a');
        $a = $this->fam('alice', 'fam-a');
        $this->assertTrue($a['revoked']);
        $this->assertIsInt($a['revokedAt']);
        $this->assertArrayNotHasKey('currentJtiHash', $a);
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', 'fam-a', 'ja'));
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', 'fam-b', 'jb'));
    }

    public function testRevokeRefreshSessionWithoutFamilyWritesLegacyTombstone(): void
    {
        $legacyIat = time() - 10;
        $this->haxcms->revokeRefreshSession('alice');
        $store = $this->loadSessionsFile();
        $this->assertIsInt($store['users']['alice']['legacyRevokedAt']);
        $this->assertGreaterThan(time(), $store['users']['alice']['legacyRevokedExp']);
        $this->assertFalse($this->haxcms->validateRefreshSession('alice', null, null, $legacyIat));
        // a legacy token issued AFTER the tombstone is accepted
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', null, null, time() + 5));
    }

    public function testRevokeRefreshSessionIgnoresEmptyUser(): void
    {
        $this->haxcms->revokeRefreshSession('');
        $this->haxcms->revokeRefreshSession(null);
        $this->assertFalse(file_exists($this->sessionsPath()));
    }

    // --- getRefreshToken / decodeRefreshToken ---

    public function testGetRefreshTokenWithSessionStoresFamilyAndJti(): void
    {
        $token = $this->haxcms->getRefreshToken('alice', true);
        $this->assertNotEmpty($token);
        $decoded = $this->haxcms->decodeRefreshToken($token);
        $this->assertNotFalse($decoded);
        $this->assertSame('alice', $decoded->user);
        $this->assertNotEmpty($decoded->family);
        $this->assertNotEmpty($decoded->jti);
        $this->assertIsNumeric($decoded->iat);
        $this->assertIsNumeric($decoded->exp);
        // 24-hour expiry
        $this->assertSame(24 * 60 * 60, (int) $decoded->exp - (int) $decoded->iat);
        // session recorded server-side -> current jti validates
        $this->assertTrue($this->haxcms->validateRefreshSession('alice', $decoded->family, $decoded->jti));
    }

    public function testGetRefreshTokenWithoutSessionOmitsFamilyAndJti(): void
    {
        $token = $this->haxcms->getRefreshToken('alice', false);
        $decoded = $this->haxcms->decodeRefreshToken($token);
        $this->assertNotFalse($decoded);
        $this->assertSame('alice', $decoded->user);
        $this->assertFalse(isset($decoded->family));
        $this->assertFalse(isset($decoded->jti));
        // no session file written
        $this->assertFalse(file_exists($this->sessionsPath()));
    }

    public function testDecodeRefreshTokenRejectsAccessTokenKey(): void
    {
        // refresh token signed with refresh key must not decode under access key
        $refresh = $this->haxcms->getRefreshToken('alice', false);
        $this->assertFalse($this->haxcms->decodeJWT($refresh));
        // and vice-versa: access token must not decode as refresh
        $access = $this->haxcms->getJWT('alice');
        $this->assertFalse($this->haxcms->decodeRefreshToken($access));
    }

    public function testDecodeRefreshTokenRejectsEmptyAndNonString(): void
    {
        $this->assertFalse($this->haxcms->decodeRefreshToken(''));
        $this->assertFalse($this->haxcms->decodeRefreshToken(null));
    }

    // --- validateRefreshToken (cookie-based) ---

    public function testValidateRefreshTokenReturnsFalseWithNoCookie(): void
    {
        $this->assertFalse($this->haxcms->validateRefreshToken(false));
    }

    public function testValidateRefreshTokenReturnsDecodedWithValidCookie(): void
    {
        $token = $this->haxcms->getRefreshToken('alice', false);
        $_COOKIE['haxcms_refresh_token'] = $token;
        $decoded = $this->haxcms->validateRefreshToken(false);
        $this->assertNotFalse($decoded);
        $this->assertSame('alice', $decoded->user);
    }

    public function testValidateRefreshTokenReturnsFalseForExpiredCookie(): void
    {
        $token = JWT::encode(
            (object)array('user' => 'alice', 'iat' => time() - 5000, 'exp' => time() - 1000),
            $this->haxcms->refreshPrivateKey . $this->haxcms->salt
        );
        $_COOKIE['haxcms_refresh_token'] = $token;
        $this->assertFalse($this->haxcms->validateRefreshToken(false));
    }

    public function testValidateRefreshTokenReturnsFalseForGarbageCookie(): void
    {
        $_COOKIE['haxcms_refresh_token'] = 'not-a-jwt';
        $this->assertFalse($this->haxcms->validateRefreshToken(false));
    }

    // --- rotateRefreshTokenAndCookie ---

    public function testRotateRefreshTokenAndCookieIssuesNewAccessToken(): void
    {
        // establish a session so rotation finds a current jti
        $token = $this->haxcms->getRefreshToken('alice', true);
        $decoded = $this->haxcms->decodeRefreshToken($token);
        $accessJwt = $this->haxcms->rotateRefreshTokenAndCookie($decoded);
        $this->assertNotNull($accessJwt);
        // the returned string is a valid ACCESS jwt (decodes under access key)
        $accessDecoded = $this->haxcms->decodeJWT($accessJwt);
        $this->assertNotFalse($accessDecoded);
        $this->assertSame('alice', $accessDecoded->user);
    }

    public function testRotateRefreshTokenAndCookieRevokesOnTheft(): void
    {
        $family = $this->uuid();
        $jtiA = $this->uuid();
        $this->haxcms->recordRefreshSession('alice', $family, $jtiA, time() + 3600);
        $decoded = (object)array('user' => 'alice', 'family' => $family, 'jti' => 'stolen-unknown-jti');
        $this->assertNull($this->haxcms->rotateRefreshTokenAndCookie($decoded));
        $this->assertTrue($this->fam('alice', $family)['revoked']);
    }

    public function testRotateRefreshTokenAndCookieReturnsNullForMissingUser(): void
    {
        $decoded = (object)array('user' => '', 'family' => 'f', 'jti' => 'j');
        $this->assertNull($this->haxcms->rotateRefreshTokenAndCookie($decoded));
    }
}
