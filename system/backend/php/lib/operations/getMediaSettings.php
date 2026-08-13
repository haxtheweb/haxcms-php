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
      $effective = array(
        'jpegQuality' => (isset($mediaSettings['jpegQuality']) && !is_null($mediaSettings['jpegQuality']))
          ? $mediaSettings['jpegQuality']
          : 80,
        'maxUploadSizeMb' => (isset($mediaSettings['maxUploadSizeMb']) && !is_null($mediaSettings['maxUploadSizeMb']))
          ? $mediaSettings['maxUploadSizeMb']
          : 1024,
        'acceptedFormats' => (isset($mediaSettings['acceptedFormats']) && !is_null($mediaSettings['acceptedFormats']))
          ? $mediaSettings['acceptedFormats']
          : 'jpg,jpeg,png,gif,webp,svg',
      );
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
