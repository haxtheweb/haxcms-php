<?php
include_once dirname(__FILE__) . '/../ThemeSettingsService.php';
trait OperationsRouteSaveEnabledThemes {
  private function resolveEnabledThemesPayload() {
    if (isset($this->params['enabledThemes']) && (is_array($this->params['enabledThemes']) || is_object($this->params['enabledThemes']))) {
      return $this->params['enabledThemes'];
    }
    if (isset($this->rawParams['enabledThemes']) && (is_array($this->rawParams['enabledThemes']) || is_object($this->rawParams['enabledThemes']))) {
      return $this->rawParams['enabledThemes'];
    }
    if (is_array($this->params)) {
      return $this->params;
    }
    return null;
  }

  private function enabledThemesListFromPayload($payload) {
    $haxcms = $GLOBALS['HAXCMS'];
    if (is_array($payload)) {
      if (count($payload) === 0) {
        return array();
      }
      if (array_keys($payload) === range(0, count($payload) - 1)) {
        return HAXCMSThemeSettingsService::normalizeMachineNameList($haxcms, $payload);
      }
      $map = HAXCMSThemeSettingsService::normalizeEnabledThemeMap($haxcms, $payload);
      $list = array();
      foreach ($map as $key => $value) {
        if ($value !== false) {
          $list[] = $key;
        }
      }
      return HAXCMSThemeSettingsService::normalizeMachineNameList($haxcms, $list);
    }
    if (is_object($payload)) {
      $map = HAXCMSThemeSettingsService::normalizeEnabledThemeMap($haxcms, $payload);
      $list = array();
      foreach ($map as $key => $value) {
        if ($value !== false) {
          $list[] = $key;
        }
      }
      return HAXCMSThemeSettingsService::normalizeMachineNameList($haxcms, $list);
    }
    return null;
  }

  public function saveEnabledThemes() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    $payload = $this->resolveEnabledThemesPayload();
    if (is_null($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing enabledThemes payload',
        ),
      );
    }
    $enabledThemes = $this->enabledThemesListFromPayload($payload);
    if (is_null($enabledThemes)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid enabledThemes payload',
        ),
      );
    }
    try {
      $haxcms = $GLOBALS['HAXCMS'];
      $discovered = HAXCMSThemeSettingsService::discoverThemes($haxcms);
      $enabledSet = array_flip($enabledThemes);
      $detectedNames = array();
      foreach ($discovered as $item) {
        if (isset($item['machineName'])) {
          $detectedNames[] = $item['machineName'];
        }
      }
      $existingMap = HAXCMSThemeSettingsService::readEnabledThemeMap($haxcms);
      $withDefaults = HAXCMSThemeSettingsService::applyDetectedThemeDefaults($haxcms, $existingMap, $detectedNames);
      $enabledMap = isset($withDefaults['enabledThemes']) ? $withDefaults['enabledThemes'] : array();
      foreach ($discovered as $item) {
        $machineName = isset($item['machineName']) ? $item['machineName'] : '';
        if ($machineName === '') {
          continue;
        }
        if (HAXCMSThemeSettingsService::isThemeHidden($item) || HAXCMSThemeSettingsService::isThemeTerrible($item, $machineName)) {
          if (!array_key_exists($machineName, $enabledMap)) {
            $enabledMap[$machineName] = true;
          }
          continue;
        }
        $enabledMap[$machineName] = array_key_exists($machineName, $enabledSet);
      }
      foreach ($enabledThemes as $key) {
        if (!array_key_exists($key, $enabledMap)) {
          $enabledMap[$key] = true;
        }
      }
      $savedMap = HAXCMSThemeSettingsService::writeEnabledThemeMap($haxcms, $enabledMap);
      $savedEnabled = array();
      foreach ($savedMap as $key => $value) {
        if ($value !== false) {
          $savedEnabled[] = $key;
        }
      }
      sort($savedEnabled);
      return array(
        'status' => 200,
        'data' => array(
          'enabledThemes' => $savedEnabled,
          'settings' => $savedMap,
        ),
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save enabled theme settings',
        ),
      );
    }
  }
}
