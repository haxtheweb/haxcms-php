<?php
include_once dirname(__FILE__) . '/../APIKeysService.php';
trait OperationsRouteGenerateAppStore {
  private function generateAppStoreProviderConnectionMap() {
    return array(
      'www.googleapis.com/youtube/v3|search' => 'youtube',
      'api.vimeo.com|videos' => 'vimeo',
      'api.giphy.com|v1/gifs/search' => 'giphy',
      'api.unsplash.com|search/photos' => 'unsplash',
      'api.flickr.com|services/rest' => 'flickr',
      'images-api.nasa.gov|search' => 'nasa',
      'api.sketchfab.com|v3/search' => 'sketchfab',
      'api.dailymotion.com|videos' => 'dailymotion',
      'en.wikipedia.org|w/api.php' => 'wikipedia',
      'ccmixter.org|api/query' => 'ccmixter',
    );
  }

  private function normalizeAppStoreConnectionSegment($value = '') {
    return strtolower(trim(trim((string) $value, '/')));
  }

  private function resolveProviderForAppStoreItem($app, $providerConnectionMap) {
    if (
      !is_object($app) ||
      !isset($app->connection) ||
      !is_object($app->connection) ||
      !isset($app->connection->operations) ||
      !is_object($app->connection->operations) ||
      !isset($app->connection->operations->browse) ||
      !is_object($app->connection->operations->browse)
    ) {
      return '';
    }
    $connectionUrl = $this->normalizeAppStoreConnectionSegment(
      isset($app->connection->url) ? $app->connection->url : ''
    );
    $browseEndPoint = $this->normalizeAppStoreConnectionSegment(
      isset($app->connection->operations->browse->endPoint)
        ? $app->connection->operations->browse->endPoint
        : ''
    );
    $signature = $connectionUrl . '|' . $browseEndPoint;
    if (isset($providerConnectionMap[$signature])) {
      return $providerConnectionMap[$signature];
    }
    return '';
  }

  private function parseAppStoreConnectionQueryParams($urlString = '') {
    $parseTarget = trim((string) $urlString);
    if ($parseTarget === '') {
      return array();
    }
    if (!preg_match('/^[a-z]+:\/\//i', $parseTarget)) {
      $parseTarget = 'https://' . ltrim($parseTarget, '/');
    }
    $parts = parse_url($parseTarget);
    if (!is_array($parts) || !isset($parts['query'])) {
      return array();
    }
    $params = array();
    parse_str($parts['query'], $params);
    return is_array($params) ? $params : array();
  }

  private function mergeAppStoreConnectionData($base = array(), $extra = array()) {
    $merged = array();
    if (is_object($base)) {
      $base = (array) $base;
    }
    if (is_object($extra)) {
      $extra = (array) $extra;
    }
    if (is_array($base)) {
      foreach ($base as $key => $value) {
        $merged[$key] = $value;
      }
    }
    if (is_array($extra)) {
      foreach ($extra as $key => $value) {
        $merged[$key] = $value;
      }
    }
    return $merged;
  }

  private function sanitizeAppStoreBrokerConnectionData($input = array()) {
    if (is_object($input)) {
      $input = (array) $input;
    }
    if (!is_array($input)) {
      return array();
    }
    $blockedAuthParams = array(
      'key' => true,
      'access_token' => true,
      'api_key' => true,
      'client_id' => true,
      'provider' => true,
      'appstore_token' => true,
      'site_token' => true,
      'siteToken' => true,
      'siteName' => true,
      '__HAXJWT__' => true,
    );
    $sanitized = array();
    foreach ($input as $key => $value) {
      if (isset($blockedAuthParams[$key])) {
        continue;
      }
      $sanitized[$key] = $value;
    }
    return $sanitized;
  }

  private function rewriteConnectionToAppStoreBroker($connection, $provider, $siteName) {
    $queryParams = $this->parseAppStoreConnectionQueryParams(
      isset($connection->url) ? $connection->url : ''
    );
    $existingData = array();
    if (isset($connection->data)) {
      if (is_array($connection->data)) {
        $existingData = $connection->data;
      }
      else if (is_object($connection->data)) {
        $existingData = (array) $connection->data;
      }
    }
    $mergedData = $this->sanitizeAppStoreBrokerConnectionData(
      $this->mergeAppStoreConnectionData($queryParams, $existingData)
    );
    $mergedData['siteName'] = $siteName;
    $mergedData['__HAXJWT__'] = true;
    $rewritten = clone $connection;
    $rewritten->protocol = $GLOBALS['HAXCMS']->protocol;
    $normalizedDomain = rtrim((string) $GLOBALS['HAXCMS']->domain, '/');
    $normalizedBasePath = trim((string) $GLOBALS['HAXCMS']->basePath, '/');
    if ($normalizedBasePath !== '') {
      $rewritten->url = $normalizedDomain . '/' . $normalizedBasePath;
    }
    else {
      $rewritten->url = $normalizedDomain;
    }
    $rewrittenHeaders = array();
    if (isset($rewritten->headers)) {
      if (is_object($rewritten->headers)) {
        $rewrittenHeaders = (array) $rewritten->headers;
      }
      else if (is_array($rewritten->headers)) {
        $rewrittenHeaders = $rewritten->headers;
      }
    }
    $siteToken = isset($this->params['site_token'])
      ? trim((string) $this->params['site_token'])
      : '';
    if ($siteToken !== '') {
      if (
        !isset($rewrittenHeaders['X-HAXCMS-Site-Token']) &&
        !isset($rewrittenHeaders['x-haxcms-site-token'])
      ) {
        $rewrittenHeaders['X-HAXCMS-Site-Token'] = $siteToken;
      }
    }
    $rewritten->headers = (object) $rewrittenHeaders;
    $rewritten->data = (object) $mergedData;
    if (!isset($rewritten->operations) || !is_object($rewritten->operations)) {
      $rewritten->operations = new stdClass();
    }
    if (
      !isset($rewritten->operations->browse) ||
      !is_object($rewritten->operations->browse)
    ) {
      $rewritten->operations->browse = new stdClass();
    }
    if (
      !isset($rewritten->operations->browse->method) ||
      trim((string) $rewritten->operations->browse->method) === ''
    ) {
      $rewritten->operations->browse->method = 'GET';
    }
    $rewritten->operations->browse->endPoint =
      $GLOBALS['HAXCMS']->systemRequestBase .
      '/v1/integrations/app-store/providers/' .
      rawurlencode($provider) .
      '/search';
    return $rewritten;
  }

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
    $siteName = '';
    if (
      isset($this->params['site']) &&
      is_array($this->params['site']) &&
      isset($this->params['site']['name'])
    ) {
      $siteName = $this->params['site']['name'];
    }
    else if (isset($this->params['siteName'])) {
      $siteName = $this->params['siteName'];
    }
    $tokenUser = method_exists($GLOBALS['HAXCMS'], 'getRequestTokenUserName')
      ? $GLOBALS['HAXCMS']->getRequestTokenUserName()
      : $GLOBALS['HAXCMS']->getActiveUserName();
    if (
      isset($this->params['site_token']) &&
      $siteName !== '' &&
      $GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['site_token'],
        $tokenUser . ':' . $siteName
      )
    ) {
      $haxService = new HAXAppStoreService();
      $effectiveAPIKeys = HAXCMSAPIKeysService::readEffectiveAPIKeys($GLOBALS['HAXCMS']);
      $baseApps = $haxService->baseSupportedApps();
      $loadKeys = array();
      foreach ($baseApps as $key => $app) {
        if (
          isset($effectiveAPIKeys[$key]) &&
          trim((string) $effectiveAPIKeys[$key]) !== ''
        ) {
          $loadKeys[$key] = trim((string) $effectiveAPIKeys[$key]);
        }
      }
      $appStore = $haxService->loadBaseAppStore($loadKeys);
      $providerConnectionMap = $this->generateAppStoreProviderConnectionMap();
      $rewrittenApps = array();
      foreach ($appStore as $app) {
        if (!is_object($app)) {
          $rewrittenApps[] = $app;
          continue;
        }
        $provider = $this->resolveProviderForAppStoreItem(
          $app,
          $providerConnectionMap
        );
        if (
          $provider !== '' &&
          isset($app->connection) &&
          is_object($app->connection)
        ) {
          $updatedApp = clone $app;
          $updatedApp->connection = $this->rewriteConnectionToAppStoreBroker(
            $app->connection,
            $provider,
            $siteName
          );
          $rewrittenApps[] = $updatedApp;
        }
        else {
          $rewrittenApps[] = $app;
        }
      }
      $appStore = $rewrittenApps;
      $tmp = json_decode(
        $GLOBALS['HAXCMS']->siteConnectionJSON(
          $this->params['site_token'],
          $siteName
        )
      );
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
    return array(
      '__failed' => array(
        'status' => 403,
        'message' => array(
          'status' => 403,
          'message' => 'invalid request token',
        ),
      ),
    );
  }
}
