<?php
trait OperationsRouteRefreshAccessToken {
  /**
   * @OA\Post(
   *    path="/refreshAccessToken",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User access token for refreshing JWT when it goes stale"
   *   )
   * )
   */
  public function refreshAccessToken() {
    // check that we have a valid refresh token
    $validRefresh = $GLOBALS['HAXCMS']->validateRefreshToken(FALSE);
    // if we have a valid refresh token then rotate it and issue a new access token
    if ($validRefresh) {
      // Security (H1 rotation): rotate the refresh token (family/jti) and set a
      // new cookie so a stolen old refresh token dies on the legitimate user's
      // next refresh. Legacy tokens without family/jti are upgraded in place.
      $rotatedAccessJwt = $GLOBALS['HAXCMS']->rotateRefreshTokenAndCookie($validRefresh);
      if ($rotatedAccessJwt !== null) {
        return array(
          "status" => 200,
          "jwt" => $rotatedAccessJwt,
        );
      }
      // rotation rejected (possible replay/theft) -> revoke family and clear
      if (isset($validRefresh->user)) {
        $GLOBALS['HAXCMS']->revokeRefreshSession($validRefresh->user);
      }
      $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'haxcms_refresh_token:invalid',
        )
      );
    }
    else {
      // this failed so unset the cookie via the centralized helper (M3)
      $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'haxcms_refresh_token:invalid',
        )
      );
    }
  }
}
