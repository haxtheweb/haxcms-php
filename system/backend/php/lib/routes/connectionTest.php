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
    // Extract a JWT string from the request; only flat string values are accepted
    $jwtInput = null;
    if (isset($this->params['jwt']) && is_string($this->params['jwt']) && $this->params['jwt'] !== '') {
      $jwtInput = $this->params['jwt'];
    }
    // If a JWT string is present, validate it directly and capture the user
    if ($jwtInput !== null && $jwtInput !== '') {
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
      setcookie('haxcms_refresh_token', '', 1, '/', '', true, true);
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
