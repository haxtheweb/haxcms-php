<?php
trait OperationsRouteRefreshAccessToken {
  public function refreshAccessToken() {
    // Security (SEC-SESSION): this endpoint authenticates via a SameSite=Lax
    // cookie and rotates it (state change). Lax still sends the cookie on
    // cross-site top-level GET navigations, so require a same-origin request
    // (Origin, else Referer, must match the trusted host/protocol; any
    // Sec-Fetch-Site: cross-site is rejected). Parity with NodeJS
    // refreshAccessToken.js isSameOriginRequest.
    if (!$GLOBALS['HAXCMS']->isSameOriginRequest()) {
      $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Cross-origin request denied',
        )
      );
    }
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
      // rotation rejected -> clear the cookie. Security (SEC-SESSION): no
      // blanket revoke here; reuse of a stale jti within the live family is
      // already tombstoned by HAXCMS::evaluateRefreshSession, and a
      // mismatched/unknown family must not log out the user's current one.
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
