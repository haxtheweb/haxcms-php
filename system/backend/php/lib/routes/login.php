<?php
trait OperationsRouteLogin {
  /**
   * Determine the client IP for request-scoped login attempt keys.
   */
  private function getClientIP() {
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] != '') {
      $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
      $forwarded = trim($parts[0]);
      if ($forwarded != '') {
        return $forwarded;
      }
    }
    if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] != '') {
      return $_SERVER['REMOTE_ADDR'];
    }
    return 'unknown';
  }
  /**
   * Build a stable cache key for login attempts by IP and username.
   */
  private function getLoginAttemptCacheKey($username) {
    return 'login-rate:' . sha1($this->getClientIP() . '::' . strval($username));
  }
  /**
   * Load tracked attempts and normalize window expiration.
   */
  private function getLoginAttemptEntry($key, $nowMs, $settings) {
    $entry = null;
    if (isset($GLOBALS['HAXCMS']->cache) && $GLOBALS['HAXCMS']->cache) {
      $entry = $GLOBALS['HAXCMS']->cache->retrieve($key);
    }
    if (!is_array($entry)) {
      $entry = array(
        'firstAttempt' => $nowMs,
        'failedAttempts' => 0,
        'blockedUntil' => 0,
      );
    }
    if (($nowMs - intval($entry['firstAttempt'])) > intval($settings->windowMs)) {
      $entry['firstAttempt'] = $nowMs;
      $entry['failedAttempts'] = 0;
      if (intval($entry['blockedUntil']) <= $nowMs) {
        $entry['blockedUntil'] = 0;
      }
    }
    return $entry;
  }
  /**
   * Persist tracked login attempt state.
   */
  private function saveLoginAttemptEntry($key, $entry, $settings) {
    if (isset($GLOBALS['HAXCMS']->cache) && $GLOBALS['HAXCMS']->cache) {
      // Cache library does not enforce expiry on retrieval, so store with conservative ttl
      // while still validating timestamps explicitly in code.
      $ttlSeconds = intval(ceil((intval($settings->windowMs) + intval($settings->blockMs)) / 1000)) + 60;
      $GLOBALS['HAXCMS']->cache->store($key, $entry, $ttlSeconds);
    }
  }
  /**
   * Clear tracked login state after successful authentication.
   */
  private function clearLoginAttemptEntry($key) {
    if (isset($GLOBALS['HAXCMS']->cache) && $GLOBALS['HAXCMS']->cache) {
      try {
        $GLOBALS['HAXCMS']->cache->erase($key);
      }
      catch (Exception $e) {}
    }
  }
  /**
   * Register a failed attempt and return updated tracker state.
   */
  private function registerFailedLoginAttempt($entry, $nowMs, $settings) {
    $entry['failedAttempts'] = intval($entry['failedAttempts']) + 1;
    if (intval($entry['failedAttempts']) >= intval($settings->maxAttempts)) {
      $entry['blockedUntil'] = $nowMs + intval($settings->blockMs);
      $entry['failedAttempts'] = 0;
      $entry['firstAttempt'] = $nowMs;
    }
    return $entry;
  }
  /**
   * Handle standard username/password login with rate limiting.
   */
  private function processCredentialLogin($u, $p, $legacy = false) {
    $settings = $GLOBALS['HAXCMS']->getLoginRateLimitSettings();
    $nowMs = intval(round(microtime(true) * 1000));
    $attemptKey = $this->getLoginAttemptCacheKey($u);
    $entry = $this->getLoginAttemptEntry($attemptKey, $nowMs, $settings);
    if ($settings->enabled && intval($entry['blockedUntil']) > $nowMs) {
      $retryAfterSeconds = intval(ceil((intval($entry['blockedUntil']) - $nowMs) / 1000));
      if ($retryAfterSeconds > 0) {
        header('Retry-After: ' . $retryAfterSeconds);
      }
      return array(
        '__failed' => array(
          'status' => 429,
          'message' => 'Too many login attempts. Please retry later.',
        ),
      );
    }
    if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
      if ($settings->enabled) {
        $entry = $this->registerFailedLoginAttempt($entry, $nowMs, $settings);
        $this->saveLoginAttemptEntry($attemptKey, $entry, $settings);
      }
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Access denied',
        )
      );
    }
    $this->clearLoginAttemptEntry($attemptKey);
    // set a refresh_token COOKIE that will ship w/ all calls automatically
    setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = true, $_httponly = true);
    if ($legacy) {
      return $GLOBALS['HAXCMS']->getJWT($u);
    }
    return array(
      "status" => 200,
      "jwt" => $GLOBALS['HAXCMS']->getJWT($u),
    );
  }
  /**
   * @OA\Post(
   *    path="/login",
   *    tags={"cms","user"},
   *    description="Attempt a user login",
   *    @OA\Parameter(
   *     description="User name",
   *     example="admin",
   *     name="username",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *   @OA\Parameter(
   *     description="Password",
   *     example="admin",
   *     name="password",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *    @OA\Response(
   *        response="200",
   *        description="JWT token as response"
   *   ),
   *    @OA\Response(
   *        response="403",
   *        description="Invalid token / Login is required"
   *   )
   * )
   */
  public function login() {
    // if we don't have a user and the don't answer, bail
    if (isset($this->params['username']) && isset($this->params['password'])) {
      return $this->processCredentialLogin($this->params['username'], $this->params['password'], false);
    }
    //old way
    // if we don't have a user and the don't answer, bail
    else if (isset($this->params['u']) && isset($this->params['p'])) {
      return $this->processCredentialLogin($this->params['u'], $this->params['p'], true);
    }
    // login end point requested yet a jwt already exists
    // this is something of a revalidate case
    else if (isset($this->params['jwt'])) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->validateJWT(),
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Login is required',
        )
      );
    } 
  }
}
