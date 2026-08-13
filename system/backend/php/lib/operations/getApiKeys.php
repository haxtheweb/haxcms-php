<?php
include_once dirname(__FILE__) . '/../APIKeysService.php';
trait OperationsRouteGetApiKeys {
  public function getApiKeys() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    try {
      $apiKeys = HAXCMSAPIKeysService::readAPIKeys($GLOBALS['HAXCMS']);
      return array(
        'status' => 200,
        'data' => $apiKeys,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to load API key settings',
        ),
      );
    }
  }
}
