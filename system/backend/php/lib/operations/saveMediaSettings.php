<?php
include_once dirname(__FILE__) . '/../MediaSettingsService.php';
trait OperationsRouteSaveMediaSettings {
  private function resolveMediaSettingsPayload() {
    $source = null;
    if (isset($this->params['mediaSettings']) && (is_array($this->params['mediaSettings']) || is_object($this->params['mediaSettings']))) {
      $source = $this->params['mediaSettings'];
    }
    else if (isset($this->rawParams['mediaSettings']) && (is_array($this->rawParams['mediaSettings']) || is_object($this->rawParams['mediaSettings']))) {
      $source = $this->rawParams['mediaSettings'];
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

  private function payloadHasNonEmptyValue($payload, $key) {
    if (!is_array($payload) || !array_key_exists($key, $payload)) {
      return false;
    }
    $value = $payload[$key];
    return !is_null($value) && $value !== '';
  }

  public function saveMediaSettings() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    $payload = $this->resolveMediaSettingsPayload();
    if (!HAXCMSMediaSettingsService::hasSupportedMediaSettingsPayload($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing media settings payload',
        ),
      );
    }
    if ($this->payloadHasNonEmptyValue($payload, 'jpegQuality')) {
      if (!HAXCMSMediaSettingsService::isValidJpegQualityPayloadValue($payload['jpegQuality'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Invalid jpegQuality value',
          ),
        );
      }
    }
    if ($this->payloadHasNonEmptyValue($payload, 'maxUploadSizeMb')) {
      if (!HAXCMSMediaSettingsService::isValidMaxUploadSizeMbPayloadValue($payload['maxUploadSizeMb'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Invalid maxUploadSizeMb value',
          ),
        );
      }
    }
    if ($this->payloadHasNonEmptyValue($payload, 'acceptedFormats')) {
      if (!HAXCMSMediaSettingsService::isValidAcceptedFormatsPayloadValue($payload['acceptedFormats'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Invalid acceptedFormats value',
          ),
        );
      }
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
        ),
      );
    }
  }
}
