<?php
include_once dirname(__FILE__) . '/../MediaSettingsService.php';

trait OperationsRouteSaveMediaSettings
{
  /**
   * @OA\Post(
   *    path="/saveMediaSettings",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Persist media settings"
   *   )
   * )
   */
  public function saveMediaSettings()
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
      isset($this->rawParams['mediaSettings']) &&
      (is_array($this->rawParams['mediaSettings']) || is_object($this->rawParams['mediaSettings']))
    ) {
      $payload = $this->rawParams['mediaSettings'];
    }
    else if (is_array($this->rawParams)) {
      $payload = $this->rawParams;
    }
    if (is_object($payload)) {
      $payload = (array) $payload;
    }
    if (!HAXCMSMediaSettingsService::hasSupportedMediaSettingsPayload($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing media settings payload',
        )
      );
    }
    if (
      array_key_exists('jpegQuality', $payload) &&
      !HAXCMSMediaSettingsService::isValidJpegQualityPayloadValue($payload['jpegQuality'])
    ) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid jpegQuality value',
        )
      );
    }
    if (
      array_key_exists('maxUploadSizeMb', $payload) &&
      !HAXCMSMediaSettingsService::isValidMaxUploadSizeMbPayloadValue($payload['maxUploadSizeMb'])
    ) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid maxUploadSizeMb value',
        )
      );
    }
    if (
      array_key_exists('acceptedFormats', $payload) &&
      !HAXCMSMediaSettingsService::isValidAcceptedFormatsPayloadValue($payload['acceptedFormats'])
    ) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid acceptedFormats value',
        )
      );
    }
    try {
      $mediaSettings = HAXCMSMediaSettingsService::writeMediaSettings($GLOBALS['HAXCMS'], $payload);
      return array(
        'status' => 200,
        'data' => $mediaSettings,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save media settings',
        )
      );
    }
  }
}
