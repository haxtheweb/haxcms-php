<?php
trait OperationsRouteGetUserData {
  /**
   * @OA\Post(
   *    path="/getUserData",
   *    tags={"cms","authenticated","user","settings"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load data about the logged in user"
   *   )
   * )
   */
  public function getUserData() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        'status' => 200,
        'data' => $GLOBALS['HAXCMS']->userData
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
}
