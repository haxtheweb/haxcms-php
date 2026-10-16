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
        // Security (SEC-SESSION): include fresh request tokens (userToken, and
        // siteToken when a site context is resolvable) so an editor that
        // booted anonymously can pick them up without reloading
        // connection-settings. Parity with NodeJS refreshAccessToken.js.
        return array_merge(
          array(
            "status" => 200,
            "jwt" => $rotatedAccessJwt,
          ),
          $GLOBALS['HAXCMS']->getSessionRequestTokens(
            $GLOBALS['HAXCMS']->generateMachineName($validRefresh->user),
            is_array($this->params) ? $this->params : array()
          )
        );
      }
      // Security (SEC-SESSION, PHP-only): the session store could not be read
      // or written (e.g. corrupt file / disk error). Do NOT tombstone or
      // clear the cookie -- that would log the user out for a server fault.
      if (!empty($GLOBALS['HAXCMS']->refreshStoreError)) {
        return array(
          '__failed' => array(
            'status' => 503,
            'message' => 'Session store unavailable',
          )
        );
      }
      // rotation rejected (possible replay/theft) -> tombstone ONLY the
      // presented family (a legacy no-family token sets the user's legacy
      // tombstone) and clear. Security (SEC-SESSION): other families (other
      // devices) are untouched. Parity with NodeJS refreshAccessToken.js.
      if (isset($validRefresh->user)) {
        $GLOBALS['HAXCMS']->revokeRefreshSession(
          $validRefresh->user,
          (isset($validRefresh->family) && $validRefresh->family !== '') ? $validRefresh->family : null
        );
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
