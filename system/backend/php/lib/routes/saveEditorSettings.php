<?php
trait OperationsRouteSaveEditorSettings {
  /**
   * @OA\Post(
   *    path="/saveEditorSettings",
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
   *        description="Save editor settings into site.json metadata.platform.audience"
   *   )
   * )
   */
  public function saveEditorSettings() {
    if (!isset($this->params['site']) || !isset($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'missing site name',
        )
      );
    }
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$this->platformAllows($site, 'siteManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Editor settings are disabled for this site',
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
      if (is_array($platform)) {
        $platform = json_decode(json_encode($platform));
      }

      if (!isset($platform->audience) || !is_string($platform->audience)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid audience',
          )
        );
      }
      $audience = strtolower(trim($platform->audience));
      $allowedAudiences = array('novice', 'expert');
      if (!in_array($audience, $allowedAudiences)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid audience',
          )
        );
      }

      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->platform) || !is_object($site->manifest->metadata->platform)) {
        $site->manifest->metadata->platform = new stdClass();
      }

      $site->manifest->metadata->platform->audience = $audience;
      $site->manifest->metadata->site->updated = time();

      $site->manifest->save(false);
      $site->gitCommit('Editor settings updated');

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
