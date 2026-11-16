<?php
include_once dirname(__FILE__) . '/../MediaSettingsService.php';
trait OperationsRouteGetMediaSettings {
  public function getMediaSettings() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    try {
      $mediaSettings = HAXCMSMediaSettingsService::readMediaSettings($GLOBALS['HAXCMS']);
      // Apply effective defaults so null/missing fields are reported with the
      // documented defaults (parity with Node getEffectiveMediaSettings).
      $effective = HAXCMSMediaSettingsService::getEffectiveMediaSettings($mediaSettings);
      return array(
        'status' => 200,
        'data' => $effective,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to load media settings',
        ),
      );
    }
  }
}
