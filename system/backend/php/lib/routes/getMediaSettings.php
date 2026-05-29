<?php
include_once dirname(__FILE__) . '/../MediaSettingsService.php';

trait OperationsRouteGetMediaSettings
{
  /**
   * @OA\Post(
   *    path="/getMediaSettings",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Load saved media settings"
   *   )
   * )
   */
  public function getMediaSettings()
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
      $mediaSettings = HAXCMSMediaSettingsService::readMediaSettings($GLOBALS['HAXCMS']);
      return array(
        'status' => 200,
        'data' => $mediaSettings,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to load media settings',
        )
      );
    }
  }
}
