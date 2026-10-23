<?php
/**
 * One-time sign-in ("magic") links for account recovery.
 *
 * haxtheweb/issues#2854. Parity with haxcms-nodejs src/lib/magicLinks.js
 * (same store path, same JSON shape, same token format) so either backend can
 * redeem a record written by the other's tooling.
 *
 * A link carries a split token `<selector>.<verifier>`:
 *   - selector: random, non-secret lookup key for the stored record
 *   - verifier: 256-bit secret; only its SHA-256 hash is persisted
 * Records live in _config/settings/magicLinks.json next to
 * refreshSessions.json. No database.
 *
 * Issuance is deliberately NOT exposed over HTTP: links are minted by a
 * process with filesystem access to _config (the HAX CLI, or
 * system/backend/php/scripts/magic-link.php). Anyone who can write _config can
 * already rewrite config.php, so this adds no new trust boundary.
 *
 * Concurrency: PHP requests are multi-process, so every read-modify-write runs
 * under an exclusive flock on a sidecar lock file, and the store itself is
 * replaced by temp-file + rename so a reader (including the Node CLI, which
 * doesn't flock) never sees a half-written file.
 */
class MagicLinkService
{
    const DEFAULT_TTL_SECONDS = 600;
    const MIN_TTL_SECONDS = 60;
    const MAX_TTL_SECONDS = 86400;
    const DEFAULT_MAX_OUTSTANDING = 3;
    const SELECTOR_PATTERN = '/^[A-Za-z0-9_-]{12,64}$/';
    const VERIFIER_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/';

    private static function clampInt($value, $fallback, $min, $max)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            $num = $fallback;
        }
        else {
            $num = intval($value);
        }
        if ($num < $min) {
            $num = $min;
        }
        if ($num > $max) {
            $num = $max;
        }
        return $num;
    }

    /**
     * Read a server-level setting from wherever the SAPI exposes it: the
     * process env (docker / php-fpm env), Apache SetEnv or nginx fastcgi_param
     * ($_SERVER), and Apache's REDIRECT_ copy that appears after the
     * system/api rewrite.
     */
    private static function readEnvAll($name)
    {
        $values = array();
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            $values[] = $value;
        }
        foreach (array($name, 'REDIRECT_' . $name) as $key) {
            foreach (array($_SERVER, $_ENV) as $source) {
                if (isset($source[$key]) && is_string($source[$key]) && $source[$key] !== '') {
                    $values[] = $source[$key];
                }
            }
        }
        return $values;
    }

    /**
     * A flag is on when ANY source sets it truthy, so a stray value in one
     * place (e.g. DISABLED=0 in the process env) can't override an operator's
     * Apache SetEnv ...=1. Request headers can't reach these keys: PHP exposes
     * headers only as HTTP_* / REDIRECT_HTTP_*.
     */
    private static function envFlag($name)
    {
        foreach (self::readEnvAll($name) as $value) {
            if (self::isTruthyFlag($value)) {
                return true;
            }
        }
        return false;
    }

    private static function readEnv($name)
    {
        $values = self::readEnvAll($name);
        return count($values) > 0 ? $values[0] : false;
    }

    private static function isTruthyFlag($value)
    {
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
    }

    public static function isEnabled($haxcms)
    {
        $settings = self::getSettings($haxcms);
        return $settings['enabled'];
    }

    /**
     * config->iam is set by bootstrapHAX.php when _iamConfig exists (after the
     * constructor derives deploymentProfile, so check both).
     */
    public static function isHaxiamDeployment($haxcms)
    {
        if (!is_object($haxcms) || !isset($haxcms->config) || !is_object($haxcms->config)) {
            return false;
        }
        if (isset($haxcms->config->iam) && $haxcms->config->iam) {
            return true;
        }
        return isset($haxcms->config->deploymentProfile) &&
            strtolower((string) $haxcms->config->deploymentProfile) === 'haxiam-managed';
    }

    /**
     * Resolve policy.
     *   single-site / self-hosted multisite: on by default (with no HTTP
     *     issuance path, only links minted by someone with _config access can be
     *     redeemed); config.security.magicLink.enabled=false turns it off.
     *   HAXiam (config->iam or deploymentProfile haxiam-managed): off by
     *     default; HAXCMS_MAGIC_LINK_ENABLED (env) turns it on. Tenant config is
     *     ignored.
     *   HAXCMS_MAGIC_LINK_DISABLED (env) turns it off everywhere and wins.
     */
    public static function getSettings($haxcms)
    {
        $source = new stdClass();
        if (
            is_object($haxcms) &&
            isset($haxcms->config) &&
            isset($haxcms->config->security) &&
            isset($haxcms->config->security->magicLink) &&
            is_object($haxcms->config->security->magicLink)
        ) {
            $source = $haxcms->config->security->magicLink;
        }
        if (self::isHaxiamDeployment($haxcms)) {
            // HAXiam: off by default. Most HAXiam deploys sign in through
            // third-party OAuth/SSO, so there's no local account to recover.
            // Only the operator can turn it on (env), never a tenant's config.
            $enabled = self::envFlag('HAXCMS_MAGIC_LINK_ENABLED');
        }
        else {
            // single-site / self-hosted multisite: on unless config turns it off
            $enabled = !(isset($source->enabled) && $source->enabled === false);
        }
        // System-level kill switch. Wins over everything, including
        // HAXCMS_MAGIC_LINK_ENABLED, so "off" is always the safe answer.
        if (self::envFlag('HAXCMS_MAGIC_LINK_DISABLED')) {
            $enabled = false;
        }
        // on HAXiam a tenant's config doesn't shape the policy at all (lifetime
        // included); only the operator's environment does
        if (self::isHaxiamDeployment($haxcms)) {
            $source = new stdClass();
        }
        $ttlEnv = self::readEnv('HAXCMS_MAGIC_LINK_TTL_SECONDS');
        $ttlSource = ($ttlEnv !== false && $ttlEnv !== '')
            ? $ttlEnv
            : (isset($source->ttlSeconds) ? $source->ttlSeconds : null);
        return array(
            'enabled' => $enabled,
            'ttlSeconds' => self::clampInt($ttlSource, self::DEFAULT_TTL_SECONDS, self::MIN_TTL_SECONDS, self::MAX_TTL_SECONDS),
            'maxOutstanding' => self::clampInt(
                isset($source->maxOutstanding) ? $source->maxOutstanding : null,
                self::DEFAULT_MAX_OUTSTANDING,
                1,
                20
            ),
        );
    }

    public static function getStorePath($haxcms)
    {
        $dir = (is_object($haxcms) && isset($haxcms->configDirectory) && is_string($haxcms->configDirectory) && $haxcms->configDirectory !== '')
            ? $haxcms->configDirectory
            : HAXCMS_ROOT . '/_config';
        return rtrim($dir, '/') . '/settings/magicLinks.json';
    }

    private static function base64url($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function hashVerifier($verifier)
    {
        return hash('sha256', (string) $verifier);
    }

    /**
     * Run $fn($links) under an exclusive lock and persist what it returns as
     * array('links' => ..., 'result' => ...). Returns 'result'.
     */
    private static function withLockedStore($haxcms, $fn)
    {
        $path = self::getStorePath($haxcms);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // fail closed: never touch the store without the lock
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock the one-time link store; check that the web server user owns ' . dirname($path) . '.');
        }
        @chmod($path . '.lock', 0600);
        self::matchSettingsOwner($path . '.lock');
        try {
            $outcome = call_user_func($fn, self::loadLinks($path));
            if (isset($outcome['links']) && is_array($outcome['links'])) {
                self::saveLinks($path, $outcome['links']);
            }
            return isset($outcome['result']) ? $outcome['result'] : null;
        }
        finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function loadLinks($path)
    {
        if (!file_exists($path)) {
            return array();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return array();
        }
        $parsed = json_decode($raw, true);
        // unreadable / corrupt store behaves as empty, which fails closed
        if (is_array($parsed) && isset($parsed['links']) && is_array($parsed['links'])) {
            return $parsed['links'];
        }
        return array();
    }

    private static function saveLinks($path, $links)
    {
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        // JSON_FORCE_OBJECT only on the outer map so an empty store stays {}
        $payload = '{"links":' . (empty($links) ? '{}' : json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '}';
        if (@file_put_contents($tmp, $payload) === false) {
            throw new RuntimeException('Unable to write the one-time link store.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write the one-time link store.');
        }
        self::matchSettingsOwner($path);
    }

    /**
     * Running the script as root would leave 0600 root-owned files the web
     * server can't read, so every link would fail as "not found". Hand files
     * we create back to whoever owns the settings directory.
     */
    private static function matchSettingsOwner($file)
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        $owner = @fileowner(dirname($file));
        $group = @filegroup(dirname($file));
        if ($owner !== false && $owner !== 0) {
            @chown($file, $owner);
            if ($group !== false) {
                @chgrp($file, $group);
            }
        }
    }

    private static function pruneLinks($links, $nowSeconds)
    {
        $cleaned = array();
        foreach ($links as $selector => $record) {
            if (
                is_array($record) &&
                isset($record['expiresAt']) &&
                intval($record['expiresAt']) > $nowSeconds &&
                empty($record['usedAt'])
            ) {
                $cleaned[$selector] = $record;
            }
        }
        return $cleaned;
    }

    /**
     * Mint a one-time link. Returns array(token, selector, user, expiresAt,
     * ttlSeconds). The returned token is the only copy of the secret.
     *
     * $options: user, channel ('cli' default), ttlSeconds,
     * revokeOutstanding (default true), requestIp, requestUserAgent, now (ms).
     */
    public static function issue($haxcms, $options = array())
    {
        $settings = self::getSettings($haxcms);
        if (!$settings['enabled']) {
            throw new RuntimeException('One-time sign-in links are turned off for this HAXcms (off by default on HAXiam; see HAXCMS_MAGIC_LINK_ENABLED / HAXCMS_MAGIC_LINK_DISABLED / security.magicLink.enabled).', 1);
        }
        $user = (isset($options['user']) && is_string($options['user']) && trim($options['user']) !== '')
            ? trim($options['user'])
            : self::defaultUser($haxcms);
        if ($user === '' || !$haxcms->validateUser($user)) {
            throw new RuntimeException('No HAXcms account named "' . $user . '" exists in this configuration.', 2);
        }
        $ttlSeconds = isset($options['ttlSeconds'])
            ? self::clampInt($options['ttlSeconds'], $settings['ttlSeconds'], self::MIN_TTL_SECONDS, self::MAX_TTL_SECONDS)
            : $settings['ttlSeconds'];
        $issuedAt = self::nowSeconds(isset($options['now']) ? $options['now'] : null);
        $revokeOutstanding = !(isset($options['revokeOutstanding']) && $options['revokeOutstanding'] === false);
        $channel = (isset($options['channel']) && is_string($options['channel']) && $options['channel'] !== '') ? $options['channel'] : 'cli';
        $requestIp = isset($options['requestIp']) && is_string($options['requestIp']) ? $options['requestIp'] : null;
        $requestUserAgent = isset($options['requestUserAgent']) && is_string($options['requestUserAgent']) ? $options['requestUserAgent'] : null;
        $maxOutstanding = $settings['maxOutstanding'];
        return self::withLockedStore($haxcms, function ($raw) use ($user, $ttlSeconds, $issuedAt, $revokeOutstanding, $channel, $requestIp, $requestUserAgent, $maxOutstanding) {
            $links = self::pruneLinks($raw, $issuedAt);
            $mine = array();
            foreach ($links as $selector => $record) {
                if (isset($record['user']) && $record['user'] === $user) {
                    $mine[$selector] = intval($record['issuedAt']);
                }
            }
            asort($mine);
            $mineSelectors = array_keys($mine);
            $dropCount = $revokeOutstanding
                ? count($mineSelectors)
                : max(0, count($mineSelectors) - ($maxOutstanding - 1));
            for ($i = 0; $i < $dropCount; $i++) {
                unset($links[$mineSelectors[$i]]);
            }
            do {
                $selector = self::base64url(random_bytes(12));
            } while (isset($links[$selector]));
            $verifier = self::base64url(random_bytes(32));
            $expiresAt = $issuedAt + $ttlSeconds;
            $links[$selector] = array(
                'selector' => $selector,
                'verifierHash' => self::hashVerifier($verifier),
                'user' => $user,
                'channel' => $channel,
                'issuedAt' => $issuedAt,
                'expiresAt' => $expiresAt,
                'usedAt' => null,
                'requestIp' => $requestIp,
                'requestUserAgent' => $requestUserAgent,
            );
            return array(
                'links' => $links,
                'result' => array(
                    'token' => $selector . '.' . $verifier,
                    'selector' => $selector,
                    'user' => $user,
                    'expiresAt' => $expiresAt,
                    'ttlSeconds' => $ttlSeconds,
                ),
            );
        });
    }

    private static function defaultUser($haxcms)
    {
        if (isset($haxcms->user) && isset($haxcms->user->name) && $haxcms->user->name !== null && $haxcms->user->name !== '') {
            return (string) $haxcms->user->name;
        }
        if (isset($haxcms->superUser) && isset($haxcms->superUser->name) && $haxcms->superUser->name !== null) {
            return (string) $haxcms->superUser->name;
        }
        return '';
    }

    private static function nowSeconds($nowMs = null)
    {
        if ($nowMs === null) {
            return time();
        }
        return intval(floor($nowMs / 1000));
    }

    private static function parseToken($token)
    {
        if (!is_string($token)) {
            return null;
        }
        $token = trim($token);
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        if (!preg_match(self::SELECTOR_PATTERN, $parts[0]) || !preg_match(self::VERIFIER_PATTERN, $parts[1])) {
            return null;
        }
        return array('selector' => $parts[0], 'verifier' => $parts[1]);
    }

    /**
     * Cheap shape check so routes can reject garbage before touching the store
     * and can safely echo a well-formed token into an HTML form.
     */
    public static function isWellFormedToken($token)
    {
        return self::parseToken($token) !== null;
    }

    /**
     * The non-secret selector half of a well-formed token, or '' otherwise.
     * Used to key rate limiting per link rather than per client IP alone.
     */
    public static function getTokenSelector($token)
    {
        $parsed = self::parseToken($token);
        return $parsed === null ? '' : $parsed['selector'];
    }

    /**
     * Redeem a link exactly once. Returns array('ok' => true, 'user' => ...)
     * or array('ok' => false, 'reason' => disabled|malformed|not_found|
     * mismatch|expired|unknown_user). Callers must surface one generic failure
     * to clients; 'reason' is for logs and tests only.
     */
    public static function consume($haxcms, $token, $nowMs = null)
    {
        $settings = self::getSettings($haxcms);
        if (!$settings['enabled']) {
            return array('ok' => false, 'reason' => 'disabled');
        }
        $parsed = self::parseToken($token);
        if ($parsed === null) {
            return array('ok' => false, 'reason' => 'malformed');
        }
        $nowSeconds = self::nowSeconds($nowMs);
        $outcome = self::withLockedStore($haxcms, function ($raw) use ($parsed, $nowSeconds) {
            $selector = $parsed['selector'];
            $record = isset($raw[$selector]) && is_array($raw[$selector]) ? $raw[$selector] : null;
            if ($record === null || !isset($record['verifierHash']) || !is_string($record['verifierHash'])) {
                return array('result' => array('ok' => false, 'reason' => 'not_found'));
            }
            if (!hash_equals($record['verifierHash'], self::hashVerifier($parsed['verifier']))) {
                return array('result' => array('ok' => false, 'reason' => 'mismatch'));
            }
            // the verifier matched, so this link is spent whatever happens next
            $links = self::pruneLinks($raw, $nowSeconds);
            unset($links[$selector]);
            if (!empty($record['usedAt']) || !isset($record['expiresAt']) || intval($record['expiresAt']) <= $nowSeconds) {
                return array('links' => $links, 'result' => array('ok' => false, 'reason' => 'expired'));
            }
            return array('links' => $links, 'result' => array('ok' => true, 'record' => $record));
        });
        if (!$outcome['ok']) {
            return $outcome;
        }
        $record = $outcome['record'];
        if (!isset($record['user']) || !$haxcms->validateUser($record['user'])) {
            return array('ok' => false, 'reason' => 'unknown_user');
        }
        return array(
            'ok' => true,
            'user' => $record['user'],
            'channel' => isset($record['channel']) ? $record['channel'] : 'cli',
        );
    }

    /**
     * Drop every outstanding link (optionally only one user's). Returns the
     * number removed.
     */
    public static function revoke($haxcms, $user = null)
    {
        $now = time();
        return self::withLockedStore($haxcms, function ($raw) use ($user, $now) {
            $links = self::pruneLinks($raw, $now);
            $removed = 0;
            foreach (array_keys($links) as $selector) {
                if ($user === null || $user === '' || $links[$selector]['user'] === $user) {
                    unset($links[$selector]);
                    $removed++;
                }
            }
            return array('links' => $links, 'result' => $removed);
        });
    }
}
