<?php
trait OperationsRouteLogin {
  /**
   * @OA\Post(
   *    path="/login",
   *    tags={"cms","user"},
   *    description="Attempt a user login",
   *    @OA\Parameter(
   *     description="User name",
   *     example="admin",
   *     name="username",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *   @OA\Parameter(
   *     description="Password",
   *     example="admin",
   *     name="password",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *    @OA\Response(
   *        response="200",
   *        description="JWT token as response"
   *   ),
   *    @OA\Response(
   *        response="403",
   *        description="Invalid token / Login is required"
   *   )
   * )
   */
  public function login() {
    // if we don't have a user and the don't answer, bail
    if (isset($this->params['username']) && isset($this->params['password'])) {
      // _ paranoia
      $u = $this->params['username'];
      // driving me insane
      $p = $this->params['password'];
      // _ paranoia ripping up my brain
      // test if this is a valid user login
      if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      } else {
          // set a refresh_token COOKIE that will ship w/ all calls automatically
          setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = true, $_httponly = true);
          return array(
            "status" => 200,
            "jwt" => $GLOBALS['HAXCMS']->getJWT($u),
          );
      }
    }
    //old way
    // if we don't have a user and the don't answer, bail
    else if (isset($this->params['u']) && isset($this->params['p'])) {
      // _ paranoia
      $u = $this->params['u'];
      // driving me insane
      $p = $this->params['p'];
      // _ paranoia ripping up my brain
      // test if this is a valid user login
      if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      } else {
          // set a refresh_token COOKIE that will ship w/ all calls automatically
          setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = true, $_httponly = true);
          return $GLOBALS['HAXCMS']->getJWT($u);
      }
    }
    // login end point requested yet a jwt already exists
    // this is something of a revalidate case
    else if (isset($this->params['jwt'])) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->validateJWT(),
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Login is required',
        )
      );
    } 
  }
}
