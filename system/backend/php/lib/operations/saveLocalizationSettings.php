<?php
include_once dirname(__FILE__) . '/../LocalizationSettingsService.php';
trait OperationsRouteSaveLocalizationSettings {
  private function resolveLocalizationSettingsPayload() {
    $source = null;
    if (isset($this->params['localizationSettings']) && (is_array($this->params['localizationSettings']) || is_object($this->params['localizationSettings']))) {
      $source = $this->params['localizationSettings'];
    }
    else if (isset($this->rawParams['localizationSettings']) && (is_array($this->rawParams['localizationSettings']) || is_object($this->rawParams['localizationSettings']))) {
      $source = $this->rawParams['localizationSettings'];
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

  private function localizationPayloadHasNonEmptyValue($payload, $key) {
    if (!is_array($payload) || !array_key_exists($key, $payload)) {
      return false;
    }
    $value = $payload[$key];
    return !is_null($value) && $value !== '';
  }

  public function saveLocalizationSettings() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    $payload = $this->resolveLocalizationSettingsPayload();
    if (!HAXCMSLocalizationSettingsService::hasSupportedLocalizationSettingsPayload($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing localization settings payload',
        ),
      );
    }
    if ($this->localizationPayloadHasNonEmptyValue($payload, 'defaultLanguage')) {
      if (!HAXCMSLocalizationSettingsService::isValidDefaultLanguagePayloadValue($payload['defaultLanguage'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Invalid defaultLanguage value',
          ),
        );
      }
    }
    try {
      $localizationSettings = HAXCMSLocalizationSettingsService::writeLocalizationSettings($GLOBALS['HAXCMS'], $payload);
      // keep the in-memory config in sync so createSite and other runtime
      // consumers see the new default without a reload
      if (isset($GLOBALS['HAXCMS']->config)) {
        if (!isset($GLOBALS['HAXCMS']->config->localization)) {
          $GLOBALS['HAXCMS']->config->localization = new stdClass();
        }
        $GLOBALS['HAXCMS']->config->localization->defaultLanguage = $localizationSettings['defaultLanguage'];
      }
      return array(
        'status' => 200,
        'data' => $localizationSettings,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save localization settings',
        ),
      );
    }
  }
}
