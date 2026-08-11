<?php
trait OperationsRouteLogin {
  /**
   * Determine the client IP for request-scoped login attempt keys.
   * Security best practice (M2): never trust X-Forwarded-For unless a
   * trusted-proxy allowlist is configured (config->security->trustedProxies).
   * Delegates to HAXCMS::resolveClientIP() so the proxy trust decision is
   * shared with Host-header validation (M4). Without the allowlist, REMOTE_ADDR
   * is used, preventing a spoofed XFF from rotating the rate-limit key.
   */
  private function getClientIP() {
    if (
      isset($GLOBALS['HAXCMS']) &&
      is_object($GLOBALS['HAXCMS']) &&
      method_exists($GLOBALS['HAXCMS'], 'resolveClientIP')
    ) {
      return $GLOBALS['HAXCMS']->resolveClientIP();
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
      // D2/Q8: login failure returns 401 (not 403) per spec; invalid-bearer
      // on protected routes stays 403, but credential login failure is 401.
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'Access denied',
        )
      );
    }
    $this->clearLoginAttemptEntry($attemptKey);
    // set a refresh_token COOKIE that will ship w/ all calls automatically.
    // Security best practice (M3): Secure flag is protocol-driven and
    // SameSite=Lax is set via the centralized helper so flags are consistent
    // across every call site and non-TLS dev/DDEV still works.
    $GLOBALS['HAXCMS']->setRefreshTokenCookie($GLOBALS['HAXCMS']->getRefreshToken($u));
    if ($legacy) {
      return $GLOBALS['HAXCMS']->getJWT($u);
    }
    return array(
      "status" => 200,
      "jwt" => $GLOBALS['HAXCMS']->getJWT($u),
    );
  }
  public function login() {
    // if we don't have a user and the don't answer, bail
    if (isset($this->params['username']) && isset($this->params['password'])) {
      return $this->processCredentialLogin($this->params['username'], $this->params['password'], false);
    }
    // D2/Q7: login end point requested yet a jwt already exists — this is a
    // revalidate case. The body jwt was previously stripped in the session v1
    // handler; it now reaches this branch so JWT-revalidate works in v1.
    // D2/Q8: use validateJWT(false) so an invalid jwt returns a 401 envelope
    // instead of exiting with 403. Set sessionJwt from the body jwt first so
    // validateJWT can decode it (sessionJwt is normally set from the bearer
    // header in the HAXCMS constructor).
    else if (isset($this->params['jwt'])) {
      $bodyJwt = is_string($this->params['jwt']) ? trim($this->params['jwt']) : '';
      if ($bodyJwt !== '') {
        $GLOBALS['HAXCMS']->sessionJwt = $bodyJwt;
      }
      $valid = $GLOBALS['HAXCMS']->validateJWT(false);
      if ($valid) {
        return array(
          "status" => 200,
          "jwt" => $GLOBALS['HAXCMS']->getJWT($GLOBALS['HAXCMS']->getActiveUserName()),
        );
      }
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'Invalid token',
        )
      );
    }
    else {
      // D2/Q8: login required returns 401 (not 403) per spec.
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'Login is required',
        )
      );
    }
  }
}
