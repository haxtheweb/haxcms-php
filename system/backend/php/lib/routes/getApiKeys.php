<?php
include_once dirname(__FILE__) . '/../APIKeysService.php';

trait OperationsRouteGetApiKeys
{
  /**
   * @OA\Post(
   *    path="/getApiKeys",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Load saved integration API keys"
   *   )
   * )
   */
  public function getApiKeys()
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
        )
      );
    }
  }
}
