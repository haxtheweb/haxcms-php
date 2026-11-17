<?php
trait OperationsRouteLogout {
  public function logout() {
    // DRAFT plugin platform: who is signing out, for user.after.logout below
    $pluginUserName = '';
    // Security (SEC-SESSION): revoke the refresh-token family server-side
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
          $pluginUserName = (string) $decoded->user;
        }
      }
    }
    catch (Exception $e) {}
    // Security (SEC-SESSION): clear via the centralized helper so the
    // Secure/SameSite flags match how the cookie was set (required for the
    // browser to actually delete it).
    $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
    // DRAFT plugin platform: user.after.logout (spec/06), once the session has ended. Sign-out is never held up
    // or stopped by a plugin, so there is no before hook here.
    if ($pluginUserName !== '' && getenv('HAXCMS_PLUGINS') !== 'off') {
      include_once __DIR__ . '/../plugins/HAXCMSPluginSystem.php';
      HAXCMSPluginSystem::event(null, 'user.after.logout', HAXCMSPluginSystem::userRecord($pluginUserName), array('user' => null));
    }
    return array(
      "status" => 200,
      "data" => 'loggedout',
    );
  }
}
