<?php
/**
 * Security (F3): count-window rate limit for authenticated file-mutation
 * operations (saveFile / fileOperation). Throttles a single authenticated
 * principal keyed by userName:siteName so a compromised account or stolen
 * site token cannot drive unbounded upload (disk-fill) or image-op (CPU)
 * traffic. Cache-backed (fileops-rate: namespace) so it is shared across all
 * PHP-FPM workers, mirroring the login limiter (operations/login.php). The
 * NodeJS backend uses an in-memory per-process limiter (fileOpsRateLimiter.js)
 * for the same behavior.
 *
 * Returns null when the request may proceed; returns an __failed 429 array
 * (and sets a Retry-After header) when the principal is over the configured
 * max within windowMs or is currently in a block window. The over-threshold
 * call itself is blocked. Defaults (500/5min) accommodate a realistic
 * front-end bulk upload (N sequential single-file ops) while bounding
 * runaway loops / scripted abuse.
 */
trait OperationsRouteFileOpsRateLimit {
  /**
   * @param string $userName validated active user name
   * @param string $siteName resolved site name
   * @return array|null null = allow; __failed 429 array = block
   */
  private function checkFileOpsRateLimit($userName, $siteName) {
    $settings = $GLOBALS['HAXCMS']->getFileOpsRateLimitSettings();
    if (!$settings->enabled) {
      return null;
    }
    if (!isset($GLOBALS['HAXCMS']->cache) || !$GLOBALS['HAXCMS']->cache) {
      // No shared cache available -> do not block operations (fail open).
      return null;
    }
    $nowMs = intval(round(microtime(true) * 1000));
    $key = 'fileops-rate:' . sha1(strval($userName) . '::' . strval($siteName));
    $entry = null;
    try {
      $entry = $GLOBALS['HAXCMS']->cache->retrieve($key);
    }
    catch (Exception $e) {
      $entry = null;
    }
    if (!is_array($entry)) {
      $entry = array(
        'firstAttempt' => $nowMs,
        'attempts' => 0,
        'blockedUntil' => 0,
      );
    }
    if (($nowMs - intval($entry['firstAttempt'])) > intval($settings->windowMs)) {
      $entry['firstAttempt'] = $nowMs;
      $entry['attempts'] = 0;
      if (intval($entry['blockedUntil']) <= $nowMs) {
        $entry['blockedUntil'] = 0;
      }
    }
    if (intval($entry['blockedUntil']) > $nowMs) {
      $retryAfter = intval(ceil((intval($entry['blockedUntil']) - $nowMs) / 1000));
      if ($retryAfter > 0) {
        @header('Retry-After: ' . $retryAfter);
      }
      return array(
        '__failed' => array(
          'status' => 429,
          'message' => $this->buildFileOpsRateLimitMessage($settings, $retryAfter),
        ),
      );
    }
    $entry['attempts'] = intval($entry['attempts']) + 1;
    $blockedNow = false;
    if (intval($entry['attempts']) > intval($settings->max)) {
      $entry['blockedUntil'] = $nowMs + intval($settings->blockMs);
      $entry['attempts'] = 0;
      $entry['firstAttempt'] = $nowMs;
      $blockedNow = true;
    }
    $ttlSeconds = intval(ceil((intval($settings->windowMs) + intval($settings->blockMs)) / 1000)) + 60;
    try {
      $GLOBALS['HAXCMS']->cache->store($key, $entry, $ttlSeconds);
    }
    catch (Exception $e) {}
    if ($blockedNow) {
      $retryAfter = intval(ceil((intval($entry['blockedUntil']) - $nowMs) / 1000));
      if ($retryAfter > 0) {
        @header('Retry-After: ' . $retryAfter);
      }
      return array(
        '__failed' => array(
          'status' => 429,
          'message' => $this->buildFileOpsRateLimitMessage($settings, $retryAfter),
        ),
      );
    }
    return null;
  }
  /**
   * Build a human-readable 429 message that states WHY (file-op rate limit
   * for this site), the configured limit (max per window), and WHEN the
   * caller can retry (derived from retryAfterSeconds). Both the limit and the
   * retry time are dynamic so the message stays accurate when an operator
   * overrides the defaults via config->security->fileOpsRateLimit.
   */
  private function buildFileOpsRateLimitMessage($settings, $retryAfterSeconds) {
    $windowMinutes = max(1, intval(round(intval($settings->windowMs) / 60000)));
    $windowLabel = $windowMinutes . ' minute' . ($windowMinutes !== 1 ? 's' : '');
    $seconds = max(1, intval($retryAfterSeconds));
    if ($seconds >= 60) {
      $retryMinutes = intval(ceil($seconds / 60));
      $retryLabel = $retryMinutes . ' minute' . ($retryMinutes !== 1 ? 's' : '');
    } else {
      $retryLabel = $seconds . ' second' . ($seconds !== 1 ? 's' : '');
    }
    return 'File operation rate limit reached: ' . intval($settings->max) . ' operations per ' . $windowLabel . ' for this site. You can retry in ' . $retryLabel . '.';
  }
}
