<?php
include_once dirname(__FILE__) . '/../SkeletonSettingsService.php';

trait OperationsRouteSaveEnabledSkeletons
{
  private function isListArray($value)
  {
    if (!is_array($value)) {
      return false;
    }
    if (count($value) === 0) {
      return true;
    }
    return array_keys($value) === range(0, count($value) - 1);
  }

  private function enabledSkeletonListFromPayload($payload)
  {
    if (is_object($payload)) {
      $payload = (array) $payload;
    }
    if ($this->isListArray($payload)) {
      return HAXCMSSkeletonSettingsService::normalizeMachineNameList(
        $GLOBALS['HAXCMS'],
        $payload
      );
    }
    if (is_array($payload)) {
      $normalized = HAXCMSSkeletonSettingsService::normalizeEnabledSkeletonMap(
        $GLOBALS['HAXCMS'],
        $payload
      );
      $list = array();
      foreach ($normalized as $key => $enabled) {
        if ($enabled !== false) {
          $list[] = $key;
        }
      }
      return HAXCMSSkeletonSettingsService::normalizeMachineNameList(
        $GLOBALS['HAXCMS'],
        $list
      );
    }
    return null;
  }

  /**
   * @OA\Post(
   *    path="/saveEnabledSkeletons",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Persist enabled skeleton settings"
   *   )
   * )
   */
  public function saveEnabledSkeletons()
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
      array_key_exists('enabledSkeletons', $this->rawParams)
    ) {
      $payload = $this->rawParams['enabledSkeletons'];
    }
    else if (isset($this->rawParams)) {
      $payload = $this->rawParams;
    }
    if (is_null($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing enabledSkeletons payload',
        )
      );
    }
    $enabledSkeletons = $this->enabledSkeletonListFromPayload($payload);
    if (is_null($enabledSkeletons)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Invalid enabledSkeletons payload',
        )
      );
    }
    try {
      $discovered = method_exists($this, 'discoverSkeletonsListItems')
        ? $this->discoverSkeletonsListItems()
        : array();
      $enabledSet = array_flip($enabledSkeletons);
      $enabledMap = array();
      foreach ($discovered as $item) {
        $machineName = isset($item['machineName']) ? $item['machineName'] : '';
        $normalizedName = HAXCMSSkeletonSettingsService::normalizeMachineName(
          $GLOBALS['HAXCMS'],
          $machineName
        );
        if ($normalizedName === '') {
          continue;
        }
        $enabledMap[$normalizedName] = array_key_exists($normalizedName, $enabledSet);
      }
      foreach ($enabledSkeletons as $machineName) {
        if (!array_key_exists($machineName, $enabledMap)) {
          $enabledMap[$machineName] = true;
        }
      }
      $savedMap = HAXCMSSkeletonSettingsService::writeEnabledSkeletonMap(
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
        )
      );
    }
  }
}
