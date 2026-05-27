<?php
include_once dirname(__FILE__) . '/../APIKeysService.php';

trait OperationsRouteSaveApiKeys
{
  /**
   * @OA\Post(
   *    path="/saveApiKeys",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Persist integration API keys"
   *   )
   * )
   */
  public function saveApiKeys()
  {
    if (
      !isset($_SERVER['REQUEST_METHOD']) ||
      strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST'
    ) {
      return array(
        '__failed' => array(
          'status' => 405,
          'message' => 'method not allowed',
        )
      );
    }
    if (
      !isset($this->params['user_token']) ||
      !$GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['user_token'],
        $GLOBALS['HAXCMS']->getActiveUserName()
      )
    ) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $payload = array();
    if (
      isset($this->rawParams['apiKeys']) &&
      (is_array($this->rawParams['apiKeys']) || is_object($this->rawParams['apiKeys']))
    ) {
      $payload = $this->rawParams['apiKeys'];
    }
    else if (is_array($this->rawParams)) {
      $payload = $this->rawParams;
    }
    if (is_object($payload)) {
      $payload = (array) $payload;
    }
    if (!HAXCMSAPIKeysService::hasSupportedAPIKeyPayload($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing API key payload',
        )
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
        )
      );
    }
  }
}
