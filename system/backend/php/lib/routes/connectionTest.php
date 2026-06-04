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
    // if a JWT is present, validate it first
    if (isset($this->params['jwt']) && $this->params['jwt'] != '' && $GLOBALS['HAXCMS']->validateJWT(FALSE)) {
      $jwt = $this->params['jwt'];
    }
    // otherwise attempt to recover from refresh token
    if (!$jwt) {
      $validRefresh = $GLOBALS['HAXCMS']->validateRefreshToken(FALSE);
      if ($validRefresh && isset($validRefresh->user) && $validRefresh->user != '') {
        $jwt = $GLOBALS['HAXCMS']->getJWT($validRefresh->user);
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
    $user = '';
    if (method_exists($GLOBALS['HAXCMS'], 'getAuthenticatedUserName')) {
      $tmpUser = $GLOBALS['HAXCMS']->getAuthenticatedUserName();
      if (!is_null($tmpUser) && $tmpUser != '') {
        $user = $tmpUser;
      }
    }
    return array(
      'status' => 200,
      'authenticated' => TRUE,
      'jwt' => $jwt,
      'refreshed' => $refreshed,
      'user' => $user,
    );
  }
}
