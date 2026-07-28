<?php
trait OperationsRouteConnectionTest {
  /**
   * @OA\Get(
   *    path="/connectionTest",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="Validate current auth state before presenting authenticated UI"
   *   )
   * )
   * @OA\Post(
   *    path="/connectionTest",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="Validate current auth state before presenting authenticated UI"
   *   )
   * )
   */
  public function connectionTest() {
    $jwt = null;
    $refreshed = FALSE;
    $user = '';
    // JWT must arrive via the Authorization Bearer header, never request params.
    // Mirrors NodeJS connectionTest.js getValidatedJWTFromRequest.
    $jwtInput = '';
    if (method_exists($GLOBALS['HAXCMS'], 'getBearerTokenFromRequest')) {
      $jwtInput = (string) $GLOBALS['HAXCMS']->getBearerTokenFromRequest();
    }
    if ($jwtInput !== '') {
      $decoded = $GLOBALS['HAXCMS']->decodeJWT($jwtInput);
      if (
        $decoded !== FALSE &&
        isset($decoded->id) &&
        $decoded->id == $GLOBALS['HAXCMS']->getRequestToken('user') &&
        isset($decoded->user) &&
        $GLOBALS['HAXCMS']->validateUser($decoded->user)
      ) {
        $jwt = $jwtInput;
        $user = $GLOBALS['HAXCMS']->generateMachineName($decoded->user);
      }
    }
    // otherwise attempt to recover from refresh token
    if (!$jwt) {
      $validRefresh = $GLOBALS['HAXCMS']->validateRefreshToken(FALSE);
      if ($validRefresh && isset($validRefresh->user) && $validRefresh->user != '') {
        $jwt = $GLOBALS['HAXCMS']->getJWT($validRefresh->user);
        $user = $GLOBALS['HAXCMS']->generateMachineName($validRefresh->user);
        $refreshed = TRUE;
      }
    }
    if (!$jwt) {
      $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => array(
            'status' => 401,
            'authenticated' => FALSE,
            'reason' => 'invalid_session',
            'message' => 'Authentication failed',
          ),
        ),
      );
    }
    if (method_exists($GLOBALS['HAXCMS'], 'validateIAMRouteAuthorization')) {
      $authorization = $GLOBALS['HAXCMS']->validateIAMRouteAuthorization(TRUE);
      if (is_array($authorization) && isset($authorization['allowed']) && !$authorization['allowed']) {
        return array(
          '__failed' => array(
            'status' => isset($authorization['status']) ? (int) $authorization['status'] : 403,
            'message' => array(
              'status' => isset($authorization['status']) ? (int) $authorization['status'] : 403,
              'authenticated' => FALSE,
              'reason' => 'not_authorized',
              'message' => isset($authorization['message']) && $authorization['message'] != '' ? $authorization['message'] : 'Access denied',
            ),
          ),
        );
      }
    }
    $token = $user != '' ? $GLOBALS['HAXCMS']->getRequestToken($user) : '';
    return array(
      'jwt' => $jwt,
      'token' => $token,
    );
  }
}
