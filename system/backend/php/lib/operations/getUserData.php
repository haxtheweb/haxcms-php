<?php
trait OperationsRouteGetUserData {
  public function getUserData() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // userData.json is shared by the install and its boilerplate has no
      // userName, so the signed-in name fills it in. The dashboard and the
      // site label the user menu with it and key the saved character to it
      // (parity with haxcms-nodejs routes/getUserData.js).
      $data = $GLOBALS['HAXCMS']->userData;
      $data = is_object($data) ? clone $data : (object) (is_array($data) ? $data : array());
      if (!isset($data->userName) || !is_string($data->userName) || $data->userName === '') {
        $name = $GLOBALS['HAXCMS']->getActiveUserName();
        $data->userName = is_string($name) ? $name : '';
      }
      return array(
        'status' => 200,
        'data' => $data
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
