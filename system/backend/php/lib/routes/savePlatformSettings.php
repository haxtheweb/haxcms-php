<?php
trait OperationsRouteSavePlatformSettings {
  /**
   * @OA\Post(
   *    path="/savePlatformSettings",
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
   *        description=\"Save platform feature settings into site.json metadata.platform.features\"
   *   )
   * )
   */
  public function savePlatformSettings() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      // load the site from name
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
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

      // Validate payload shape

      $validFeatureKeys = array(
        'addPage',
        'saveAndEdit',
        'deletePage',
        'outlineDesigner',
        'styleGuide',
        'insights',
        'siteManifest',
        'themeManifest',
        'authorManifest',
        'seoManifest',
        'pageBreak',
        'addBlock',
        'popularGizmos',
        'recentGizmos',
        'contentMap',
        'viewSource',
        'uploadMedia',
        'onlineMedia',
        'community',
        'pageTemplates',
        'blockTemplates'
      );
      $legacyFeatureKeyMap = array(
        'manifest' => array('siteManifest', 'themeManifest', 'authorManifest', 'seoManifest'),
        'onlineSearch' => array('onlineMedia'),
        'delete' => array('deletePage')
      );
      $featureSources = array();
      if (isset($platform->features) && (is_object($platform->features) || is_array($platform->features))) {
        $featureSources[] = $platform->features;
      }
      if (isset($platform->cmsFeatures) && (is_object($platform->cmsFeatures) || is_array($platform->cmsFeatures))) {
        $featureSources[] = $platform->cmsFeatures;
      }
      if (isset($platform->editorFeatures) && (is_object($platform->editorFeatures) || is_array($platform->editorFeatures))) {
        $featureSources[] = $platform->editorFeatures;
      }
      if (count($featureSources) === 0) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid features',
          )
        );
      }
      $normalizedFeatures = array();
      foreach ($featureSources as $featureSource) {
        if (is_object($featureSource)) {
          $featureSource = (array) $featureSource;
        }
        foreach ($featureSource as $key => $value) {
          // Accept boolean-like values (true/false, "true"/"false", 1/0)
          // and normalize to strict booleans for storage.
          $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
          if (is_null($coerced)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid feature value for ' . $key,
              )
            );
          }
          if (in_array($key, $validFeatureKeys)) {
            $normalizedFeatures[$key] = $coerced;
          }
          else if (isset($legacyFeatureKeyMap[$key])) {
            foreach ($legacyFeatureKeyMap[$key] as $mappedKey) {
              $normalizedFeatures[$mappedKey] = $coerced;
            }
          }
          else {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid feature key',
              )
            );
          }
        }
      }

      // Write features only. Audience and allowed blocks are managed by their
      // dedicated endpoints (saveEditorSettings / saveAllowedBlocks).
      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->platform) || !is_object($site->manifest->metadata->platform)) {
        $site->manifest->metadata->platform = new stdClass();
      }
      $site->manifest->metadata->platform->features = new stdClass();
      foreach ($validFeatureKeys as $i => $k) {
        if (isset($normalizedFeatures[$k])) {
          $site->manifest->metadata->platform->features->{$k} = $normalizedFeatures[$k];
        }
      }
      $site->manifest->metadata->site->updated = time();

      // don't reorganize the structure
      $site->manifest->save(false);
      $site->gitCommit('Platform settings updated');

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
