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
    // if we have a valid refresh token then issue a new access token
    if ($validRefresh) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->getJWT($validRefresh->user),
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
