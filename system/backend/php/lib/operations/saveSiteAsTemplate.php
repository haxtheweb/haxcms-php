<?php
trait OperationsRouteSaveSiteAsTemplate {
  public function saveSiteAsTemplate() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    if (
      !isset($this->params['site']) ||
      !is_array($this->params['site']) ||
      !isset($this->params['site']['name']) ||
      !is_string($this->params['site']['name']) ||
      trim($this->params['site']['name']) === ''
    ) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'invalid site name',
        )
      );
    }
    // security (F2/IDOR-001): object-level authorization before filesystem access
    if (!$GLOBALS['HAXCMS']->userCanAccessSite($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Access denied to site',
        )
      );
    }
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if (!$site || !isset($site->manifest)) {
      return array(
        '__failed' => array(
          'status' => 404,
          'message' => 'Site does not exist',
        )
      );
    }
    try {
      $skeleton = $this->buildSiteTemplateSkeleton($site);
      $machineName =
        isset($skeleton['meta']) &&
        is_array($skeleton['meta']) &&
        isset($skeleton['meta']['machineName']) &&
        is_string($skeleton['meta']['machineName']) &&
        trim($skeleton['meta']['machineName']) !== ''
          ? $this->normalizeTemplateMachineName($skeleton['meta']['machineName'])
          : $this->normalizeTemplateMachineName($site->manifest->metadata->site->name);
      if ($machineName === '') {
        $machineName = 'site-template';
      }
      if (!isset($skeleton['meta']) || !is_array($skeleton['meta'])) {
        $skeleton['meta'] = array();
      }
      $skeleton['meta']['name'] = $machineName;
      $skeleton['meta']['machineName'] = $machineName;
      $skeletonsDirectory = $GLOBALS['HAXCMS']->configDirectory . '/user/skeletons';
      if (!file_exists($skeletonsDirectory) && !mkdir($skeletonsDirectory, 0755, true)) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to create skeletons directory',
          )
        );
      }
      if (!is_dir($skeletonsDirectory) || !is_writable($skeletonsDirectory)) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Skeletons directory is not writable',
          )
        );
      }
      $targetPath = $skeletonsDirectory . '/' . $machineName . '.json';
      $payload = json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if ($payload === false) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to encode site skeleton',
          )
        );
      }
      $writeResult = @file_put_contents($targetPath, $payload . PHP_EOL);
      if ($writeResult === false) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to save site skeleton',
          )
        );
      }
      $baseAPIPath = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase . '/';
      $skeletonUrl = $baseAPIPath . 'v1/skeletons/' . rawurlencode($machineName);
      return array(
        'status' => 200,
        'data' => array(
          'saved' => true,
          'name' => $machineName,
          'filename' => $machineName . '.json',
          'path' => $targetPath,
          'link' => $skeletonUrl,
        ),
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save site skeleton',
          'detail' => $e->getMessage(),
        )
      );
    }
  }
}
