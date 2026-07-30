<?php
trait OperationsRouteLogout {
  /**
   * @OA\Get(
   *    path="/logout",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User logout, front end will kill token"
   *   )
   * )
   */
  public function logout() {
    // Security (H1/L2 revocation): revoke the refresh-token family server-side
    // so a refresh token exfiltrated before logout can't mint new access tokens
    // after this user logs out. Best-effort: legacy tokens without a stored
    // family simply have nothing to revoke.
    try {
      if (isset($_COOKIE['haxcms_refresh_token']) && is_string($_COOKIE['haxcms_refresh_token']) && $_COOKIE['haxcms_refresh_token'] !== '') {
        $decoded = $GLOBALS['HAXCMS']->decodeRefreshToken($_COOKIE['haxcms_refresh_token']);
        if (is_object($decoded) && isset($decoded->user) && $decoded->user !== '') {
          $GLOBALS['HAXCMS']->revokeRefreshSession($decoded->user);
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
