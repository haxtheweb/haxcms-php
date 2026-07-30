<?php
/**
 * Integration test for PHP refresh-token rotation + logout revocation.
 * Exercises the HAXCMS helpers directly with a temp config directory.
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

$passed = 0;
$failed = 0;
function ok($cond, $msg) {
  global $passed, $failed;
  if ($cond) { $passed++; echo "  PASS: $msg\n"; }
  else { $failed++; echo "  FAIL: $msg\n"; }
}

// Set up a temp HAXCMS_ROOT with minimal config
$tmpRoot = '/tmp/haxcms-php-test-refresh-' . getmypid();
$configDir = $tmpRoot . '/_config';
@mkdir($configDir . '/settings', 0777, true);
@mkdir($tmpRoot . '/_sites', 0777, true);
@mkdir($configDir . '/cache', 0777, true);
// symlink the real coreConfig + boilerplate so the HAXCMS constructor can load
// themes.json, publishers.json, siteFields.json
@mkdir($tmpRoot . '/system', 0777, true);
@symlink(dirname(__DIR__) . '/../coreConfig', $tmpRoot . '/system/coreConfig');
@symlink(dirname(__DIR__) . '/../boilerplate', $tmpRoot . '/system/boilerplate');

define('HAXCMS_ROOT', $tmpRoot);
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';

// Write minimal config files
file_put_contents($configDir . '/config.json', json_encode(array(
  'security' => new stdClass(),
  'themes' => new stdClass(),
)));
file_put_contents($configDir . '/SALT.txt', 'testsalt');
file_put_contents($configDir . '.pk', 'testprivatekey');
file_put_contents($configDir . '.rpk', 'testrefreshkey');
file_put_contents($configDir . '/.user', json_encode(array('name' => 'admin', 'password' => 'testpass')));
file_put_contents($configDir . '/userData.json', '{}');

echo "\n=== PHP Refresh-Token Rotation Integration Test ===\n";

// Include the real classes
include_once dirname(__DIR__) . '/lib/JWT.php';
include_once dirname(__DIR__) . '/lib/Variables.php';

// The HAXCMS constructor is heavy (loads themes, publishers, site fields,
// etc.). For this isolated refresh-session test we bypass it via reflection
// and set only the properties the rotation helpers need.
include_once dirname(__DIR__) . '/lib/HAXCMS.php';

$hax = (new ReflectionClass('HAXCMS'))->newInstanceWithoutConstructor();
$hax->configDirectory = $configDir;
$hax->refreshPrivateKey = 'testrefreshkey';
$hax->privateKey = 'testprivatekey';
$hax->salt = 'testsalt';
$hax->config = new stdClass();
$hax->config->security = new stdClass();
$hax->protocol = 'http';
$hax->user = new stdClass();
$hax->user->name = 'admin';
$hax->superUser = new stdClass();
$hax->superUser->name = 'admin';

// Test 1: getRefreshToken stamps family/jti + records session
echo "\nTest 1: getRefreshToken stamps family/jti and records session\n";
$user = 'testuser';
$token1 = $hax->getRefreshToken($user, true);
$decoded1 = JWT::decode($token1, $hax->refreshPrivateKey . $hax->salt);
ok(isset($decoded1->family) && isset($decoded1->jti), 'token has family + jti');
ok($decoded1->user === $user, 'token has correct user');

// verify the session was recorded (read the store file directly)
$storePath = $configDir . '/settings/refreshSessions.json';
$sessionsRaw = file_get_contents($storePath);
$sessions = json_decode($sessionsRaw, true);
$jtiHash = hash('sha256', $decoded1->jti);
ok(isset($sessions[$user]), 'session recorded for user');
ok($sessions[$user]['currentJtiHash'] === $jtiHash, 'jti hash recorded');
ok(!isset($sessions[$user]['previousJtiHash']) || $sessions[$user]['previousJtiHash'] === null, 'no previous jti on first issuance');

// Test 2: validateRefreshSession accepts the current jti
echo "\nTest 2: validateRefreshSession accepts current jti\n";
ok($hax->validateRefreshSession($user, $decoded1->family, $decoded1->jti), 'current jti validated');

// Test 3: rotateRefreshTokenAndCookie produces a new token + cookie, moves old jti to previous
echo "\nTest 3: rotateRefreshTokenAndCookie rotates and sets new cookie\n";
// Suppress setcookie output (it sends headers in CLI which is fine but we want to check the return)
$accessJwt1 = $hax->rotateRefreshTokenAndCookie($decoded1);
ok($accessJwt1 !== null && is_string($accessJwt1), 'rotation returned an access JWT');

// read the updated store
$sessions2 = json_decode(file_get_contents($storePath), true);
$oldJtiHash = hash('sha256', $decoded1->jti);
ok($sessions2[$user]['previousJtiHash'] === $oldJtiHash, 'old jti moved to previous');
ok($sessions2[$user]['currentJtiHash'] !== $oldJtiHash, 'store has new current jti');

// Test 4: old jti still valid within grace window
echo "\nTest 4: old jti valid within grace window\n";
ok($hax->validateRefreshSession($user, $decoded1->family, $decoded1->jti), 'old jti still accepted (grace)');

// Test 5: revokeRefreshSession removes the entry
echo "\nTest 5: revokeRefreshSession removes the entry\n";
$hax->revokeRefreshSession($user);
$sessions3 = json_decode(file_get_contents($storePath), true);
ok(!isset($sessions3[$user]), 'session revoked');

// Test 6: replay detection revokes family
echo "\nTest 6: replay detection revokes family\n";
$hax->recordRefreshSession($user, 'fam-replay', 'jti-original', time() + 3600);
$sessions4 = json_decode(file_get_contents($storePath), true);
ok($sessions4[$user]['family'] === 'fam-replay', 'replay test session seeded');

$rotateOk = $hax->rotateRefreshSession($user, 'fam-replay', 'jti-bogus', 'jti-new', time() + 3600);
ok(!$rotateOk, 'bogus jti rotation rejected');

$sessions5 = json_decode(file_get_contents($storePath), true);
ok(!isset($sessions5[$user]), 'family revoked on bogus jti');

// Test 7: legacy token (no family/jti) is accepted and upgraded
echo "\nTest 7: legacy token (no family/jti) accepted and upgraded\n";
$legacyToken = $hax->getRefreshToken($user, false);
$legacyDecoded = JWT::decode($legacyToken, $hax->refreshPrivateKey . $hax->salt);
ok(!isset($legacyDecoded->family) && !isset($legacyDecoded->jti), 'legacy token has no family/jti');
ok($hax->validateRefreshSession($user, null, null), 'legacy token accepted by validateRefreshSession');

// clear store so legacy rotation has a clean slate
$hax->revokeRefreshSession($user);
$legacyAccessJwt = $hax->rotateRefreshTokenAndCookie($legacyDecoded);
ok($legacyAccessJwt !== null, 'legacy token upgraded via rotation');

// Test 8: access JWT has 15-min exp
echo "\nTest 8: access JWT issued with 15-min exp\n";
$accessJwt = $hax->getJWT($user);
$accessDecoded = JWT::decode($accessJwt, $hax->privateKey . $hax->salt);
ok(($accessDecoded->exp - $accessDecoded->iat) === 900, 'access JWT has 15-min (900s) lifetime');
ok($accessDecoded->user === $user, 'access JWT has correct user');

// Test 9: expired refresh token is rejected by decodeRefreshToken
echo "\nTest 9: expired refresh token rejected\n";
$expiredPayload = array(
  'user' => $user,
  'family' => 'fam-exp',
  'jti' => 'jti-exp',
  'iat' => time() - 200,
  'exp' => time() - 120,
);
$expiredToken = JWT::encode($expiredPayload, $hax->refreshPrivateKey . $hax->salt);
// PHP JWT::decode doesn't validate exp automatically (the vendored lib), but
// the validateRefreshToken method does check iat/exp. Test that decodeRefreshToken
// returns the payload (signature valid) and validateRefreshToken rejects it.
$expiredDecoded = $hax->decodeRefreshToken($expiredToken);
// The PHP JWT lib doesn't auto-check exp, so decode succeeds; the temporal
// check happens in validateRefreshToken. Verify the signature is valid:
ok(is_object($expiredDecoded) && isset($expiredDecoded->user), 'expired token signature valid (decode succeeds)');
// validateRefreshToken checks iat/exp manually, but isCLI() bypasses JWT
// checks in CLI mode. Set SERVER_SOFTWARE to simulate a web request.
$_SERVER['SERVER_SOFTWARE'] = 'apache';
$_COOKIE['haxcms_refresh_token'] = $expiredToken;
$validated = $hax->validateRefreshToken(FALSE);
ok($validated === FALSE, 'expired refresh token rejected by validateRefreshToken');
unset($_COOKIE['haxcms_refresh_token']);
unset($_SERVER['SERVER_SOFTWARE']);

// Test 10: store file is written with 0600 permissions
echo "\nTest 10: store file permissions\n";
$perms = fileperms($storePath) & 0777;
ok($perms === 0600, "store file is 0600 (got " . decoct($perms) . ")");

// Cleanup
function rrmdir($dir) {
  if (is_dir($dir)) {
    $objects = scandir($dir);
    foreach ($objects as $obj) {
      if ($obj != '.' && $obj != '..') {
        if (is_dir($dir . '/' . $obj)) rrmdir($dir . '/' . $obj);
        else unlink($dir . '/' . $obj);
      }
    }
    rmdir($dir);
  }
}
rrmdir($tmpRoot);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
