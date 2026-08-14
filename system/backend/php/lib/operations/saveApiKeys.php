<?php
include_once dirname(__FILE__) . '/../APIKeysService.php';
trait OperationsRouteSaveApiKeys {
  private function resolveApiKeysPayload() {
    $source = null;
    if (isset($this->params['apiKeys']) && (is_array($this->params['apiKeys']) || is_object($this->params['apiKeys']))) {
      $source = $this->params['apiKeys'];
    }
    else if (isset($this->rawParams['apiKeys']) && (is_array($this->rawParams['apiKeys']) || is_object($this->rawParams['apiKeys']))) {
      $source = $this->rawParams['apiKeys'];
    }
    else if (is_array($this->params)) {
      $source = $this->params;
    }
    if (is_object($source)) {
      return (array) $source;
    }
    if (is_array($source)) {
      return $source;
    }
    return array();
  }

  public function saveApiKeys() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    // security (F3/authz): defense-in-depth — writing API key settings is a
    // system-admin operation that must only be performed by the superUser,
    // regardless of how the route was classified. The router elevates non-GET
    // methods of this route to 'admin' (see
    // SystemRoutesMap::getSystemV1SuperUserRoutes), but this explicit check
    // keeps the handler safe even if route classification changes or the
    // handler is reached directly.
    if ($GLOBALS['HAXCMS']->getActiveUserName() !== $GLOBALS['HAXCMS']->superUser->name) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Admin access required',
        ),
      );
    }
    $payload = $this->resolveApiKeysPayload();
    if (!HAXCMSAPIKeysService::hasSupportedAPIKeyPayload($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing API key payload',
        ),
      );
    }
    try {
      $apiKeys = HAXCMSAPIKeysService::writeAPIKeys($GLOBALS['HAXCMS'], $payload);
      return array(
        'status' => 200,
        'data' => $apiKeys,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save API key settings',
        ),
      );
    }
  }
}
