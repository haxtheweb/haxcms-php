<?php
include_once dirname(__FILE__) . '/../SkeletonSettingsService.php';
trait OperationsRouteSkeletonsList {
  private function includeDisabledSkeletonsRequest()
  {
    $queryFlag = false;
    if (isset($this->params['includeDisabled'])) {
      $queryFlag = HAXCMSSkeletonSettingsService::normalizeBoolean(
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
        $bodyFlag = HAXCMSSkeletonSettingsService::normalizeBoolean(
          $raw['includeDisabled'],
          false
        );
      }
    }
    return ($queryFlag || $bodyFlag) ? true : false;
  }

  private function discoverSkeletonsListItems($userToken = '')
  {
    $items = array();
    $seen = array();
    $defaultDirs = method_exists($this, 'getDefaultSkeletonDirectories')
      ? $this->getDefaultSkeletonDirectories()
      : array();
    $scopeByDir = array();
    foreach ($defaultDirs as $scope => $scopeDir) {
      if (!is_string($scopeDir) || $scopeDir === '') {
        continue;
      }
      $scopeByDir[rtrim($scopeDir, '/')] = $scope;
    }
    // directories to scan for JSON skeleton definitions
    $dirs = $this->getSkeletonDirectories();
    foreach ($dirs as $dir) {
      $normalizedDir = rtrim($dir, '/');
      $scope = isset($scopeByDir[$normalizedDir]) ? $scopeByDir[$normalizedDir] : 'core';
      if ($scope === 'core' && strpos($normalizedDir, '/user/skeletons') !== false) {
        $scope = 'user';
      }
      if (!($handle = opendir($dir))) {
        continue;
      }
      while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') { continue; }
        $path = $dir . '/' . $file;
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
          continue;
        }
        $rawName = pathinfo($file, PATHINFO_FILENAME);
        $skeletonName = $this->normalizeSkeletonMachineName($rawName);
        if ($skeletonName === '' || $skeletonName === 'default-starter') {
          continue;
        }
        if (in_array($skeletonName, $seen, true)) {
          continue;
        }
        $json = @file_get_contents($path);
        $skeleton = json_decode($json);
        if (!is_object($skeleton)) {
          continue;
        }
        $meta = isset($skeleton->meta) ? $skeleton->meta : new stdClass();
        $title = isset($meta->useCaseTitle) && $meta->useCaseTitle
          ? $meta->useCaseTitle
          : (isset($meta->name) && $meta->name ? $meta->name : $skeletonName);
        $description = isset($meta->useCaseDescription) && $meta->useCaseDescription
          ? $meta->useCaseDescription
          : (isset($meta->description) ? $meta->description : '');
        $image = isset($meta->useCaseImage) ? $meta->useCaseImage : '';
        $priority = 0;
        if (isset($meta->priority) && is_numeric($meta->priority)) {
          $priority = 0 + $meta->priority;
        }
        $category = array();
        if (isset($meta->category) && is_array($meta->category)) {
          $category = $meta->category;
        }
        else if (isset($meta->tags) && is_array($meta->tags)) {
          $category = $meta->tags;
        }
        $attributes = array();
        if (isset($meta->attributes) && is_array($meta->attributes)) {
          $attributes = $meta->attributes;
        }
        $demo = isset($meta->sourceUrl) ? $meta->sourceUrl : '#';
        // Ensure base API path ends with a trailing slash so route
        // concatenation does not produce `/system/apigetSkeleton`.
        $baseAPIPath = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase . '/';
        $skeletonUrl = $baseAPIPath . 'getSkeleton?name=' . urlencode($skeletonName);
        if ($userToken !== '') {
          $skeletonUrl .= '&user_token=' . urlencode($userToken);
        }
        $items[] = array(
          'title' => $title,
          'description' => $description,
          'image' => $image,
          'priority' => $priority,
          'category' => $category,
          'attributes' => $attributes,
          'scope' => $scope,
          'machineName' => $skeletonName,
          'machine-name' => $skeletonName,
          'demo-url' => $demo,
          'skeleton-url' => $skeletonUrl,
        );
        $seen[] = $skeletonName;
      }
      closedir($handle);
    }
    return $items;
  }
  /**
   * Discover available site skeletons from core and user config directories.
   * Returns metadata list compatible with app-hax v2 dashboard.
   * Requires a valid user_token and JWT.
   *
   * @OA\Get(
   *    path="/skeletonsList",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="List available site skeletons"
   *   )
   * )
   */
  public function skeletonsList() {
    // Validate user_token like listSites
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }

    $includeDisabled = $this->includeDisabledSkeletonsRequest();
    $userToken = isset($this->params['user_token']) ? $this->params['user_token'] : '';
    $discovered = $this->discoverSkeletonsListItems($userToken);
    $detectedNames = array();
    foreach ($discovered as $item) {
      if (isset($item['machineName'])) {
        $detectedNames[] = $item['machineName'];
      }
    }
    $enabledSkeletons = HAXCMSSkeletonSettingsService::readEnabledSkeletonMap($GLOBALS['HAXCMS']);
    $withDefaults = HAXCMSSkeletonSettingsService::applyDetectedSkeletonDefaults(
      $GLOBALS['HAXCMS'],
      $enabledSkeletons,
      $detectedNames
    );
    $enabledSkeletons = isset($withDefaults['enabledSkeletons'])
      ? $withDefaults['enabledSkeletons']
      : array();
    if (isset($withDefaults['changed']) && $withDefaults['changed']) {
      HAXCMSSkeletonSettingsService::writeEnabledSkeletonMap(
        $GLOBALS['HAXCMS'],
        $enabledSkeletons
      );
    }
    $items = array();
    foreach ($discovered as $item) {
      $machineName = isset($item['machineName']) ? $item['machineName'] : '';
      $enabled = HAXCMSSkeletonSettingsService::isSkeletonEnabled(
        $GLOBALS['HAXCMS'],
        $machineName,
        $enabledSkeletons
      );
      if (!$includeDisabled && !$enabled) {
        continue;
      }
      $item['enabled'] = $enabled;
      $items[] = $item;
    }

    return array(
      'status' => 200,
      'data' => $items
    );
  }
}
