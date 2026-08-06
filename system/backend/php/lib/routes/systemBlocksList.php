<?php
trait OperationsRouteSystemBlocksList {
  /**
   * @OA\Get(
   *    path="/systemBlocksList",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Load system block inventory and enabled blocks"
   *   )
   * )
   */
  private function normalizeEnabledBoolean($value, $defaultValue = false) {
    if (is_bool($value)) {
      return $value;
    }
    if (is_numeric($value)) {
      return intval($value) !== 0;
    }
    if (is_string($value)) {
      $normalized = strtolower(trim($value));
      if (
        $normalized === 'true' ||
        $normalized === '1' ||
        $normalized === 'yes' ||
        $normalized === 'on'
      ) {
        return true;
      }
      if (
        $normalized === 'false' ||
        $normalized === '0' ||
        $normalized === 'no' ||
        $normalized === 'off'
      ) {
        return false;
      }
    }
    return $defaultValue;
  }

  private function resolveEnabledBlocksFilter() {
    if (is_array($this->params) && array_key_exists('enabled', $this->params)) {
      return $this->normalizeEnabledBoolean($this->params['enabled'], true)
        ? 'enabled'
        : 'disabled';
    }
    return 'all';
  }

  private function filterAutoloaderByEnabledState($autoloader, $enabledFilter, $enabledSet) {
    if ($enabledFilter === 'all') {
      return $autoloader;
    }
    $hasEnabledSet = is_array($enabledSet) && count($enabledSet) > 0;
    if (is_array($autoloader)) {
      if (!$hasEnabledSet) {
        return $enabledFilter === 'enabled' ? array() : $autoloader;
      }
      $filtered = array();
      foreach ($autoloader as $item) {
        if (!is_string($item)) {
          continue;
        }
        $isEnabled = isset($enabledSet[strtolower($item)]);
        if ($enabledFilter === 'enabled' && $isEnabled) {
          $filtered[] = $item;
        }
        else if ($enabledFilter === 'disabled' && !$isEnabled) {
          $filtered[] = $item;
        }
      }
      return $filtered;
    }
    if (is_object($autoloader)) {
      $filtered = new stdClass();
      $keys = array_keys((array) $autoloader);
      foreach ($keys as $key) {
        $isEnabled = $hasEnabledSet && isset($enabledSet[strtolower($key)]);
        if (
          ($enabledFilter === 'enabled' && $isEnabled) ||
          ($enabledFilter === 'disabled' && !$isEnabled)
        ) {
          $filtered->{$key} = $autoloader->{$key};
        }
      }
      return $filtered;
    }
    return $autoloader;
  }

  public function systemBlocksList() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $autoloader = array();
    if (
      isset($GLOBALS['HAXCMS']->config->appStore) &&
      isset($GLOBALS['HAXCMS']->config->appStore->autoloader) &&
      is_array($GLOBALS['HAXCMS']->config->appStore->autoloader)
    ) {
      $autoloader = array_values($GLOBALS['HAXCMS']->config->appStore->autoloader);
    }
    if (count($autoloader) === 0) {
      $autoloader = array('grid-plate');
    }
    $enabledBlocks = array();
    $enabledFile = $GLOBALS['HAXCMS']->configDirectory . '/settings/enabledBlocks.json';
    if (file_exists($enabledFile)) {
      $decoded = json_decode(file_get_contents($enabledFile));
      if (is_array($decoded)) {
        foreach ($decoded as $tag) {
          if (is_string($tag)) {
            $normalized = strtolower(trim($tag));
            if ($normalized !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $normalized)) {
              $enabledBlocks[] = $normalized;
            }
          }
        }
      }
    }
    $enabledBlocks = array_values(array_unique($enabledBlocks));
    sort($enabledBlocks);
    // D24: apply enabled filter (ported from Node systemBlocksList.js)
    $enabledFilter = $this->resolveEnabledBlocksFilter();
    $enabledSet = array_flip($enabledBlocks);
    $filteredAutoloader = $this->filterAutoloaderByEnabledState(
      $autoloader,
      $enabledFilter,
      $enabledSet
    );
    return array(
      'status' => 200,
      'apps' => array(),
      'stax' => array(),
      'autoloader' => $filteredAutoloader,
      'enabledBlocks' => $enabledBlocks,
    );
  }
}
