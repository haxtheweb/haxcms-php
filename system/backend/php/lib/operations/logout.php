<?php
trait OperationsRouteLogout {
  public function logout() {
    // Security (H1/L2 revocation): revoke the refresh-token family server-side
    // so a refresh token exfiltrated before logout can't mint new access tokens
    // after this user logs out. Best-effort: legacy tokens without a stored
    // family simply have nothing to revoke.
    try {
      if (isset($_COOKIE['haxcms_refresh_token']) && is_string($_COOKIE['haxcms_refresh_token']) && $_COOKIE['haxcms_refresh_token'] !== '') {
        $decoded = $GLOBALS['HAXCMS']->decodeRefreshToken($_COOKIE['haxcms_refresh_token']);
        if (is_object($decoded) && isset($decoded->user) && $decoded->user !== '') {
          // Security (SEC-SESSION): tombstone ONLY the presented family (or,
          // for a legacy no-family token, every legacy token issued so far)
          // so the revoked token can never be re-seeded while the user's
          // other devices stay signed in. Parity with NodeJS logout.js.
          $GLOBALS['HAXCMS']->revokeRefreshSession(
            $decoded->user,
            (isset($decoded->family) && $decoded->family !== '') ? $decoded->family : null
          );
        }
      }
    }
    catch (Exception $e) {}
    // Security best practice (M3): clear via the centralized helper so the
    // Secure/SameSite flags match how the cookie was set (required for the
    // browser to actually delete it).
    $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
    return array(
      "status" => 200,
      "data" => 'loggedout',
    );
  }
}
