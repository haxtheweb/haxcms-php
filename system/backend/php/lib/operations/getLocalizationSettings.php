<?php
include_once dirname(__FILE__) . '/../LocalizationSettingsService.php';
trait OperationsRouteGetLocalizationSettings {
  public function getLocalizationSettings() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    try {
      $localizationSettings = HAXCMSLocalizationSettingsService::readLocalizationSettings($GLOBALS['HAXCMS']);
      // Apply effective defaults so null/missing fields are reported with the
      // documented default (parity with Node getEffectiveLocalizationSettings).
      $effective = array(
        'defaultLanguage' => (isset($localizationSettings['defaultLanguage']) && !is_null($localizationSettings['defaultLanguage']))
          ? $localizationSettings['defaultLanguage']
          : HAXCMSLocalizationSettingsService::DEFAULT_LANGUAGE,
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
          'message' => 'Unable to load localization settings',
        ),
      );
    }
  }
}
