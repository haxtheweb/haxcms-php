<?php
include_once dirname(__FILE__) . '/../APIKeysService.php';

trait OperationsRouteAppStoreSearch
{
  private function appStoreSearchProviderDefinitions()
  {
    return array(
      'youtube' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'www.googleapis.com/youtube/v3',
        'endPoint' => 'search',
        'apiKeyProvider' => 'youtube',
        'apiKeyParam' => 'key',
      ),
      'vimeo' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'api.vimeo.com',
        'endPoint' => 'videos',
        'apiKeyProvider' => 'vimeo',
        'apiKeyParam' => 'access_token',
      ),
      'giphy' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'api.giphy.com',
        'endPoint' => 'v1/gifs/search',
        'apiKeyProvider' => 'giphy',
        'apiKeyParam' => 'api_key',
      ),
      'unsplash' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'api.unsplash.com',
        'endPoint' => 'search/photos',
        'apiKeyProvider' => 'unsplash',
        'apiKeyParam' => 'client_id',
      ),
      'flickr' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'api.flickr.com',
        'endPoint' => 'services/rest',
        'apiKeyProvider' => 'flickr',
        'apiKeyParam' => 'api_key',
      ),
      'nasa' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'images-api.nasa.gov',
        'endPoint' => 'search',
      ),
      'sketchfab' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'api.sketchfab.com',
        'endPoint' => 'v3/search',
      ),
      'dailymotion' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'api.dailymotion.com',
        'endPoint' => 'videos',
      ),
      'wikipedia' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'en.wikipedia.org',
        'endPoint' => 'w/api.php',
      ),
      'ccmixter' => array(
        'method' => 'GET',
        'protocol' => 'https',
        'host' => 'ccmixter.org',
        'endPoint' => 'api/query',
      ),
    );
  }

  private function appStoreSearchFail($status, $message)
  {
    return array(
      '__failed' => array(
        'status' => (int) $status,
        'message' => array(
          'status' => (int) $status,
          'message' => $message,
        ),
      ),
    );
  }

  private function appStoreSearchMergeRequestParams()
  {
    $merged = array();
    if (isset($this->params) && is_array($this->params)) {
      $merged = array_merge($merged, $this->params);
    }
    if (isset($this->rawParams) && is_array($this->rawParams)) {
      $merged = array_merge($merged, $this->rawParams);
    }
    return $merged;
  }

  private function appStoreSearchBuildForwardedParams($source = array())
  {
    $reserved = array(
      'provider',
      'appstore_token',
      'site_token',
      'siteName',
      'site',
      'jwt',
      'token',
    );
    $blockedAuth = array(
      'key',
      'access_token',
      'api_key',
      'client_id',
    );
    $forwarded = array();
    foreach ($source as $key => $value) {
      if (in_array($key, $reserved, true)) {
        continue;
      }
      if (in_array($key, $blockedAuth, true)) {
        continue;
      }
      if (is_null($value)) {
        continue;
      }
      if (is_object($value)) {
        continue;
      }
      $forwarded[$key] = $value;
    }
    return $forwarded;
  }

  private function appStoreSearchResolveSiteToken($requestParams = array())
  {
    if (
      isset($requestParams['site_token']) &&
      trim((string) $requestParams['site_token']) !== ''
    ) {
      return trim((string) $requestParams['site_token']);
    }
    if (
      isset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']) &&
      is_string($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']) &&
      trim($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']) !== ''
    ) {
      return trim($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
    }
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      if (is_array($headers)) {
        foreach ($headers as $name => $value) {
          if (strcasecmp($name, 'X-HAXCMS-Site-Token') === 0) {
            return trim((string) $value);
          }
        }
      }
    }
    return '';
  }

  /**
   * @OA\Get(
   *    path="/appStoreSearch",
   *    tags={"hax","api"},
   *    @OA\Response(
   *        response="200",
   *        description="Broker app store provider search requests without exposing API keys"
   *   )
   * )
   * @OA\Post(
   *    path="/appStoreSearch",
   *    tags={"hax","api"},
   *    @OA\Response(
   *        response="200",
   *        description="Broker app store provider search requests without exposing API keys"
   *   )
   * )
   */
  public function appStoreSearch()
  {
    if (!function_exists('curl_init')) {
      return $this->appStoreSearchFail(500, 'cURL is required for app store broker requests');
    }
    $requestParams = $this->appStoreSearchMergeRequestParams();
    $provider = '';
    if (isset($requestParams['provider'])) {
      $provider = strtolower(trim((string) $requestParams['provider']));
    }
    $providerDefinitions = $this->appStoreSearchProviderDefinitions();
    if (
      $provider === '' ||
      !array_key_exists($provider, $providerDefinitions)
    ) {
      return $this->appStoreSearchFail(400, 'Unsupported app store provider');
    }
    $siteToken = $this->appStoreSearchResolveSiteToken($requestParams);
    $siteName = '';
    if (isset($requestParams['siteName'])) {
      $siteName = trim((string) $requestParams['siteName']);
    }
    else if (
      isset($requestParams['site']) &&
      is_array($requestParams['site']) &&
      isset($requestParams['site']['name'])
    ) {
      $siteName = trim((string) $requestParams['site']['name']);
    }
    $tokenUser = method_exists($GLOBALS['HAXCMS'], 'getRequestTokenUserName')
      ? $GLOBALS['HAXCMS']->getRequestTokenUserName()
      : $GLOBALS['HAXCMS']->getActiveUserName();
    if (
      $siteToken === '' ||
      $siteName === '' ||
      !$GLOBALS['HAXCMS']->validateRequestToken(
        $siteToken,
        $tokenUser . ':' . $siteName
      )
    ) {
      return $this->appStoreSearchFail(403, 'invalid request token');
    }
    $providerConfig = $providerDefinitions[$provider];
    $providerMethod = isset($providerConfig['method'])
      ? strtoupper((string) $providerConfig['method'])
      : 'GET';
    if ($providerMethod === '') {
      $providerMethod = 'GET';
    }
    $forwardedParams = $this->appStoreSearchBuildForwardedParams($requestParams);
    if (
      isset($providerConfig['apiKeyProvider']) &&
      isset($providerConfig['apiKeyParam'])
    ) {
      $effectiveAPIKeys = HAXCMSAPIKeysService::readEffectiveAPIKeys($GLOBALS['HAXCMS']);
      $apiKeyProvider = (string) $providerConfig['apiKeyProvider'];
      $apiKeyParam = (string) $providerConfig['apiKeyParam'];
      $apiKeyValue = '';
      if (isset($effectiveAPIKeys[$apiKeyProvider])) {
        $apiKeyValue = trim((string) $effectiveAPIKeys[$apiKeyProvider]);
      }
      if ($apiKeyValue === '') {
        return $this->appStoreSearchFail(400, 'Missing API key for ' . $provider);
      }
      $forwardedParams[$apiKeyParam] = $apiKeyValue;
    }
    $requestUrl = $providerConfig['protocol'] . '://' . $providerConfig['host'] . '/' . $providerConfig['endPoint'];
    $queryString = http_build_query($forwardedParams);
    $headers = array(
      'Accept: application/json',
    );
    if ($providerMethod !== 'POST' && $queryString !== '') {
      $requestUrl .= '?' . $queryString;
    }
    $curl = curl_init($requestUrl);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $providerMethod);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    if ($providerMethod === 'POST') {
      $headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=UTF-8';
      curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $queryString);
    }
    $upstreamBody = curl_exec($curl);
    if ($upstreamBody === false) {
      $curlError = curl_error($curl);
      curl_close($curl);
      return $this->appStoreSearchFail(502, 'Unable to reach upstream provider: ' . $curlError);
    }
    $upstreamStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $trimmedBody = trim((string) $upstreamBody);
    $upstreamJson = null;
    if ($trimmedBody !== '') {
      $upstreamJson = json_decode($trimmedBody, true);
    }
    if ($upstreamStatus < 200 || $upstreamStatus >= 300) {
      $message = 'Upstream provider request failed (' . $upstreamStatus . ')';
      if (
        is_array($upstreamJson) &&
        isset($upstreamJson['message']) &&
        is_string($upstreamJson['message']) &&
        $upstreamJson['message'] !== ''
      ) {
        $message = $upstreamJson['message'];
      }
      else if (
        is_array($upstreamJson) &&
        isset($upstreamJson['error']) &&
        is_array($upstreamJson['error']) &&
        isset($upstreamJson['error']['message']) &&
        is_string($upstreamJson['error']['message']) &&
        $upstreamJson['error']['message'] !== ''
      ) {
        $message = $upstreamJson['error']['message'];
      }
      if ($upstreamStatus < 400 || $upstreamStatus > 599) {
        $upstreamStatus = 502;
      }
      return $this->appStoreSearchFail($upstreamStatus, $message);
    }
    if (!is_array($upstreamJson)) {
      return $this->appStoreSearchFail(502, 'Upstream provider response was not valid JSON');
    }
    return $upstreamJson;
  }
}
