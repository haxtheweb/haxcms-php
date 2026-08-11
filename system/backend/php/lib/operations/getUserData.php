<?php
trait OperationsRouteGetUserData {
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
