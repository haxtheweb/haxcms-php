<?php
include_once dirname(__FILE__) . '/../ThemeSettingsService.php';

trait OperationsRouteSaveEnabledThemes
{
  private function isThemeListArray($value)
  {
    if (!is_array($value)) {
      return false;
    }
    if (count($value) === 0) {
      return true;
    }
    return array_keys($value) === range(0, count($value) - 1);
  }

  private function enabledThemeListFromPayload($payload)
  {
    if (is_object($payload)) {
      $payload = (array) $payload;
    }
    if ($this->isThemeListArray($payload)) {
      return HAXCMSThemeSettingsService::normalizeMachineNameList(
        $GLOBALS['HAXCMS'],
        $payload
      );
    }
    if (is_array($payload)) {
      $normalized = HAXCMSThemeSettingsService::normalizeEnabledThemeMap(
        $GLOBALS['HAXCMS'],
        $payload
      );
      $list = array();
      foreach ($normalized as $key => $enabled) {
        if ($enabled !== false) {
          $list[] = $key;
        }
      }
      return HAXCMSThemeSettingsService::normalizeMachineNameList(
        $GLOBALS['HAXCMS'],
        $list
      );
    }
    return null;
  }

  private function discoverThemeRecords()
  {
    $discovered = method_exists($this, 'discoverThemesListItems')
      ? $this->discoverThemesListItems()
      : HAXCMSThemeSettingsService::discoverThemes($GLOBALS['HAXCMS']);
    $records = array();
    foreach ($discovered as $item) {
      $machineName = '';
      $record = array();
      if (is_array($item) && isset($item['machineName'])) {
        $machineName = $item['machineName'];
        $record = $item;
      }
      else if (is_object($item) && isset($item->machineName)) {
        $machineName = $item->machineName;
        $record = (array) $item;
      }
      $normalized = HAXCMSThemeSettingsService::normalizeMachineName(
        $GLOBALS['HAXCMS'],
        $machineName
      );
      if ($normalized === '' || array_key_exists($normalized, $records)) {
        continue;
      }
      $record['machineName'] = $normalized;
      $records[$normalized] = $record;
    }
    return $records;
  }

  /**
   * @OA\Post(
   *    path="/saveEnabledThemes",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Persist enabled theme settings"
   *   )
   * )
   */
  public function saveEnabledThemes()
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
    $payload = null;
    if (
      isset($this->rawParams) &&
      is_array($this->rawParams) &&
      array_key_exists('enabledThemes', $this->rawParams)
    ) {
      $payload = $this->rawParams['enabledThemes'];
    }
    else if (isset($this->rawParams)) {
      $payload = $this->rawParams;
    }
    if (is_null($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing enabledThemes payload',
        )
      );
    }
    $enabledThemes = $this->enabledThemeListFromPayload($payload);
    if (is_null($enabledThemes)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid enabledThemes payload',
        )
      );
    }
    try {
      $discoveredThemes = $this->discoverThemeRecords();
      $detectedNames = array_keys($discoveredThemes);
      $detectedNameLookup = array_flip($detectedNames);
      $enabledThemes = array_values(array_filter(
        $enabledThemes,
        function ($machineName) use ($detectedNameLookup) {
          return array_key_exists($machineName, $detectedNameLookup);
        }
      ));
      $existingMap = HAXCMSThemeSettingsService::readEnabledThemeMap($GLOBALS['HAXCMS']);
      $withDefaults = HAXCMSThemeSettingsService::reconcileDetectedThemeMap(
        $GLOBALS['HAXCMS'],
        $existingMap,
        $detectedNames,
        $detectedNames
      );
      $existingMap = isset($withDefaults['enabledThemes'])
        ? $withDefaults['enabledThemes']
        : array();
      $enabledSet = array_flip($enabledThemes);
      $enabledMap = $existingMap;
      foreach ($discoveredThemes as $machineName => $themeData) {
        if ($machineName === '') {
          continue;
        }
        if (
          HAXCMSThemeSettingsService::isThemeHidden($themeData) ||
          HAXCMSThemeSettingsService::isThemeTerrible($themeData, $machineName)
        ) {
          if (!array_key_exists($machineName, $enabledMap)) {
            $enabledMap[$machineName] = true;
          }
          continue;
        }
        $enabledMap[$machineName] = array_key_exists($machineName, $enabledSet);
      }
      foreach ($enabledThemes as $machineName) {
        if (!array_key_exists($machineName, $enabledMap)) {
          $enabledMap[$machineName] = true;
        }
      }
      $savedMap = HAXCMSThemeSettingsService::writeEnabledThemeMap(
        $GLOBALS['HAXCMS'],
        $enabledMap
      );
      $savedEnabled = array();
      foreach ($savedMap as $key => $enabled) {
        if ($enabled !== false) {
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
        )
      );
    }
  }
}
