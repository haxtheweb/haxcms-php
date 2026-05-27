<?php
include_once dirname(__FILE__) . '/../SystemStatusService.php';

trait OperationsRouteSystemStatus
{
  /**
   * @OA\Post(
   *    path="/systemStatus",
   *    tags={"cms","authenticated"},
   *    @OA\Response(
   *        response="200",
   *        description="System status report"
   *   )
   * )
   */
  public function systemStatus() {
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
    $report = HAXCMSSystemStatusService::buildHAXCMSStatusReport($GLOBALS['HAXCMS']);
    return array(
      'status' => 200,
      'data' => $report,
    );
  }
}
