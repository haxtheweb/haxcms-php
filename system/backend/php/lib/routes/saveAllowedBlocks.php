<?php
trait OperationsRouteSaveAllowedBlocks {
  /**
   * @OA\Post(
   *    path="/saveAllowedBlocks",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save allowed blocks into site.json metadata.platform.allowedBlocks"
   *   )
   * )
   */
  public function saveAllowedBlocks() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      // load the site from name
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$this->platformAllows($site, 'siteManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Allowed blocks settings are disabled for this site',
          )
        );
      }
      if (!isset($this->rawParams['platform'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'missing platform settings',
          )
        );
      }

      $platform = $this->rawParams['platform'];
      // platform can arrive as an array depending on request parsing
      if (is_array($platform)) {
        $platform = json_decode(json_encode($platform));
      }

      if (!property_exists($platform, 'allowedBlocks')) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid allowedBlocks',
          )
        );
      }
      if (!is_null($platform->allowedBlocks) && !is_array($platform->allowedBlocks)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid allowedBlocks',
          )
        );
      }
      $cleanAllowedBlocks = null;
      if (is_array($platform->allowedBlocks)) {
        $wcMap = $GLOBALS['HAXCMS']->getWCRegistryJson($site);
        $cleanAllowedBlocks = array();
        foreach ($platform->allowedBlocks as $index => $tag) {
          if (!is_string($tag)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid allowedBlocks entry at index ' . $index,
              )
            );
          }

          $cleanTag = trim($tag);
          if ($cleanTag === '') {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid tag name in allowedBlocks at index ' . $index . ' (empty value)',
              )
            );
          }

          // Allow basic HTML primitives (no dash) OR web components found in wc-registry
          $isHtmlTag = false;
          if (preg_match('/^[a-z][a-z0-9]*$/', $cleanTag) && strpos($cleanTag, '-') === false) {
            $isHtmlTag = true;
          }

          $isRegisteredWc = false;
          if (!$isHtmlTag && $wcMap && isset($wcMap->{$cleanTag})) {
            $isRegisteredWc = true;
          }

          if (!$isHtmlTag && !$isRegisteredWc) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid tag name in allowedBlocks at index ' . $index . ': ' . $cleanTag,
              )
            );
          }

          $cleanAllowedBlocks[] = $cleanTag;
        }
        $cleanAllowedBlocks = array_values(array_unique($cleanAllowedBlocks));
        sort($cleanAllowedBlocks);
      }

      if (!isset($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->platform) || !is_object($site->manifest->metadata->platform)) {
        $site->manifest->metadata->platform = new stdClass();
        $site->manifest->metadata->platform->audience = 'expert';
        $site->manifest->metadata->platform->features = new stdClass();
        $site->manifest->metadata->platform->allowedBlocks = array();
      }

      $site->manifest->metadata->platform->allowedBlocks = $cleanAllowedBlocks;
      $site->manifest->metadata->site->updated = time();

      // don't reorganize the structure
      $site->manifest->save(false);
      $site->gitCommit('Allowed blocks updated');

      return $site->manifest;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }
}
