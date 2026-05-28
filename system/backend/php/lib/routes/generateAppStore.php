<?php
trait OperationsRouteGenerateAppStore {
  /**
   * @OA\GET(
   *    path="/generateAppStore",
   *    tags={"hax","api"},
   *    @OA\Parameter(
   *         name="appstore_token",
   *         description="security token for appstore",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Generate the AppStore spec for HAX editor directions"
   *   )
   * )
   */
  public function generateAppStore() {
    // test if this is a valid user login with this specialty token that HAX looks for
    if (
      isset($this->params['appstore_token']) &&
      $GLOBALS['HAXCMS']->validateRequestToken($this->params['appstore_token'], 'appstore') &&
      isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $haxService = new HAXAppStoreService();
      $apikeys = array();
      $baseApps = $haxService->baseSupportedApps();
      foreach ($baseApps as $key => $app) {
        if (
          isset($GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key}) &&
          $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key} != ''
        ) {
          $apikeys[$key] = $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key};
        }
      }
      $appStore = $haxService->loadBaseAppStore($apikeys);
      // pull in the core one we supply, though only upload works currently
      $tmp = json_decode($GLOBALS['HAXCMS']->siteConnectionJSON($this->params['site_token']));
      array_push($appStore, $tmp);
      if (isset($GLOBALS['HAXCMS']->config->appStore->stax)) {
          $staxList = $GLOBALS['HAXCMS']->config->appStore->stax;
      } else {
          $staxList = $haxService->loadBaseStax();
      }
      if (isset($GLOBALS['HAXCMS']->config->appStore->autoloader)) {
          $autoloaderList = $GLOBALS['HAXCMS']->config->appStore->autoloader;
      } else {
          $autoloaderList = json_decode('
        [
          "lesson-overview",
          "lesson-highlight",
          "video-player",
          "meme-maker",
          "lrn-aside",
          "grid-plate",
          "magazine-cover",
          "image-compare-slider",
          "license-element",
          "self-check",
          "multiple-choice",
          "oer-schema",
          "hero-banner",
          "task-list",
          "lrn-table",
          "media-image",
          "lrndesign-blockquote",
          "a11y-gif-player",
          "wikipedia-query",
          "lrn-vocab",
          "full-width-image",
          "person-testimonial",
          "citation-element",
          "stop-note",
          "learning-component",
          "mark-the-words",
          "twitter-embed",
          "spotify-embed",
          "place-holder",
          "lrn-math",
          "q-r",
          "lrndesign-gallery",
          "lrndesign-timeline"
        ]
        ');
      }
      $enabledBlocksFile = $GLOBALS['HAXCMS']->configDirectory . '/settings/enabledBlocks.json';
      if (file_exists($enabledBlocksFile)) {
        $enabledRaw = json_decode(file_get_contents($enabledBlocksFile));
        if (is_array($enabledRaw)) {
          $enabledLookup = array();
          foreach ($enabledRaw as $tag) {
            if (is_string($tag)) {
              $normalized = strtolower(trim($tag));
              if ($normalized !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $normalized)) {
                $enabledLookup[$normalized] = true;
              }
            }
          }
          if (count($enabledLookup) > 0) {
            $filteredAutoloader = array();
            foreach ($autoloaderList as $tag) {
              if (!is_string($tag)) {
                continue;
              }
              $normalized = strtolower(trim($tag));
              if ($normalized !== '' && isset($enabledLookup[$normalized])) {
                $filteredAutoloader[] = $normalized;
              }
            }
            $autoloaderList = array_values(array_unique($filteredAutoloader));
          }
        }
      }
      return array(
          'status' => 200,
          'apps' => $appStore,
          'stax' => $staxList,
          'autoloader' => $autoloaderList
      );
    }
  }
}
