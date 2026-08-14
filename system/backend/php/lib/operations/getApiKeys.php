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
    // security (F3/authz): defense-in-depth — API keys are system-wide
    // credentials and must only be readable by the superUser, regardless of
    // how the route was classified. The router elevates this route to 'admin'
    // (see SystemRoutesMap::getSystemV1SuperUserReadRoutes), but this explicit
    // check keeps the handler safe even if route classification changes or the
    // handler is reached directly.
    if ($GLOBALS['HAXCMS']->getActiveUserName() !== $GLOBALS['HAXCMS']->superUser->name) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Admin access required',
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
