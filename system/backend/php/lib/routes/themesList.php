<?php
include_once dirname(__FILE__) . '/../ThemeSettingsService.php';

trait OperationsRouteThemesList
{
  private function includeDisabledThemesRequest()
  {
    $queryFlag = false;
    if (isset($this->params['includeDisabled'])) {
      $queryFlag = HAXCMSThemeSettingsService::normalizeBoolean(
        $this->params['includeDisabled'],
        false
      );
    }
    $bodyFlag = false;
    if (
      isset($this->rawParams) &&
      (is_array($this->rawParams) || is_object($this->rawParams))
    ) {
      $raw = is_object($this->rawParams) ? (array) $this->rawParams : $this->rawParams;
      if (array_key_exists('includeDisabled', $raw)) {
        $bodyFlag = HAXCMSThemeSettingsService::normalizeBoolean(
          $raw['includeDisabled'],
          false
        );
      }
    }
    return ($queryFlag || $bodyFlag) ? true : false;
  }

  private function discoverThemesListItems()
  {
    return HAXCMSThemeSettingsService::discoverThemes($GLOBALS['HAXCMS']);
  }

  /**
   * Discover available themes from registered theme config and config directories.
   * Returns metadata list compatible with app-hax v2 dashboard.
   * Requires a valid user_token and JWT.
   *
   * @OA\Get(
   *    path="/themesList",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="List available themes"
   *   )
   * )
   */
  public function themesList()
  {
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

    try {
      $includeDisabled = $this->includeDisabledThemesRequest();
      $discovered = $this->discoverThemesListItems();
      $detectedNames = array();
      foreach ($discovered as $item) {
        if (isset($item['machineName'])) {
          $detectedNames[] = $item['machineName'];
        }
      }
      $enabledThemes = HAXCMSThemeSettingsService::readEnabledThemeMap($GLOBALS['HAXCMS']);
      $withDefaults = HAXCMSThemeSettingsService::applyDetectedThemeDefaults(
        $GLOBALS['HAXCMS'],
        $enabledThemes,
        $detectedNames
      );
      $enabledThemes = isset($withDefaults['enabledThemes'])
        ? $withDefaults['enabledThemes']
        : array();
      if (isset($withDefaults['changed']) && $withDefaults['changed']) {
        HAXCMSThemeSettingsService::writeEnabledThemeMap(
          $GLOBALS['HAXCMS'],
          $enabledThemes
        );
      }

      $items = array();
      foreach ($discovered as $item) {
        $machineName = isset($item['machineName']) ? $item['machineName'] : '';
        if (
          HAXCMSThemeSettingsService::isThemeHidden($item) ||
          HAXCMSThemeSettingsService::isThemeTerrible($item, $machineName)
        ) {
          continue;
        }
        $enabled = HAXCMSThemeSettingsService::isThemeEnabled(
          $GLOBALS['HAXCMS'],
          $machineName,
          $enabledThemes
        );
        if (!$includeDisabled && !$enabled) {
          continue;
        }
        $item['enabled'] = $enabled;
        $item['hidden'] = !$enabled;
        $items[] = $item;
      }

      return array(
        'status' => 200,
        'data' => $items,
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to load theme settings',
        )
      );
    }
  }
}
