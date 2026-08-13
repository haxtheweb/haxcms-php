<?php
include_once dirname(__FILE__) . '/../SkeletonSettingsService.php';
trait OperationsRouteSaveEnabledSkeletons {
  private function resolveEnabledSkeletonsPayload() {
    if (isset($this->params['enabledSkeletons']) && (is_array($this->params['enabledSkeletons']) || is_object($this->params['enabledSkeletons']))) {
      return $this->params['enabledSkeletons'];
    }
    if (isset($this->rawParams['enabledSkeletons']) && (is_array($this->rawParams['enabledSkeletons']) || is_object($this->rawParams['enabledSkeletons']))) {
      return $this->rawParams['enabledSkeletons'];
    }
    if (is_array($this->params)) {
      return $this->params;
    }
    return null;
  }

  private function enabledSkeletonsListFromPayload($payload) {
    $haxcms = $GLOBALS['HAXCMS'];
    if (is_array($payload)) {
      if (count($payload) === 0) {
        return array();
      }
      if (array_keys($payload) === range(0, count($payload) - 1)) {
        return HAXCMSSkeletonSettingsService::normalizeMachineNameList($haxcms, $payload);
      }
      $map = HAXCMSSkeletonSettingsService::normalizeEnabledSkeletonMap($haxcms, $payload);
      $list = array();
      foreach ($map as $key => $value) {
        if ($value !== false) {
          $list[] = $key;
        }
      }
      return HAXCMSSkeletonSettingsService::normalizeMachineNameList($haxcms, $list);
    }
    if (is_object($payload)) {
      $map = HAXCMSSkeletonSettingsService::normalizeEnabledSkeletonMap($haxcms, $payload);
      $list = array();
      foreach ($map as $key => $value) {
        if ($value !== false) {
          $list[] = $key;
        }
      }
      return HAXCMSSkeletonSettingsService::normalizeMachineNameList($haxcms, $list);
    }
    return null;
  }

  public function saveEnabledSkeletons() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      );
    }
    $payload = $this->resolveEnabledSkeletonsPayload();
    if (is_null($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing enabledSkeletons payload',
        ),
      );
    }
    $enabledSkeletons = $this->enabledSkeletonsListFromPayload($payload);
    if (is_null($enabledSkeletons)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid enabledSkeletons payload',
        ),
      );
    }
    try {
      $haxcms = $GLOBALS['HAXCMS'];
      $discovered = $this->discoverSkeletonsListItems();
      $enabledSet = array_flip($enabledSkeletons);
      $enabledMap = array();
      foreach ($discovered as $item) {
        $machineName = isset($item['machineName']) ? $item['machineName'] : '';
        if ($machineName === '') {
          continue;
        }
        $enabledMap[$machineName] = array_key_exists($machineName, $enabledSet);
      }
      foreach ($enabledSkeletons as $key) {
        if (!array_key_exists($key, $enabledMap)) {
          $enabledMap[$key] = true;
        }
      }
      $savedMap = HAXCMSSkeletonSettingsService::writeEnabledSkeletonMap($haxcms, $enabledMap);
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
          'enabledSkeletons' => $savedEnabled,
          'settings' => $savedMap,
        ),
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save enabled skeleton settings',
        ),
      );
    }
  }
}
