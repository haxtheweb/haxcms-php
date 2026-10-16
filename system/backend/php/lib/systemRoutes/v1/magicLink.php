<?php
/**
 * session/magic-link — redeem a one-time sign-in link (haxtheweb/issues#2854).
 * Parity with haxcms-nodejs src/systemRoutes/v1/routes/magicLink.js.
 *
 * GET renders a confirmation page and never consumes the link, so link
 * scanners / chat unfurlers / prefetch cannot burn it. POST redeems it once,
 * sets the same haxcms_refresh_token cookie as a password login, then 303s to
 * the app root where session/connection-test finishes bootstrapping (or
 * returns {status, jwt} for JSON clients). Every failure is one generic 401.
 */
include_once dirname(__FILE__) . '/../../MagicLinkService.php';

if (!function_exists('haxcmsMagicLinkEscape')) {
    function haxcmsMagicLinkEscape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
if (!function_exists('haxcmsMagicLinkSubmittedToken')) {
    function haxcmsMagicLinkSubmittedToken($context)
    {
        if (is_array($context->body) && isset($context->body['token']) && is_string($context->body['token']) && $context->body['token'] !== '') {
            return $context->body['token'];
        }
        // HTML form posts are urlencoded; HAXCMS overwrites $_POST with a JSON
        // decode of php://input, so parse the raw body ourselves.
        if (isset($context->rawBody) && is_string($context->rawBody) && $context->rawBody !== '') {
            $form = array();
            parse_str($context->rawBody, $form);
            if (isset($form['token']) && is_string($form['token']) && $form['token'] !== '') {
                return $form['token'];
            }
        }
        if (isset($_GET['token']) && is_string($_GET['token'])) {
            return $_GET['token'];
        }
        return '';
    }
}
if (!function_exists('haxcmsMagicLinkWantsJson')) {
    function haxcmsMagicLinkWantsJson()
    {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
        return strpos($accept, 'application/json') !== false || strpos($contentType, 'application/json') !== false;
    }
}
if (!function_exists('haxcmsMagicLinkAppRoot')) {
    // the app root is whatever prefixes /system/api — keeps HAXiam tenant
    // prefixes (/<user>/system/api/v1) pointing back at that tenant
    function haxcmsMagicLinkAppRoot($context)
    {
        $apiBase = isset($context->apiBasePath) && is_string($context->apiBasePath) ? $context->apiBasePath : '';
        $root = preg_replace('/\/system\/api(?:\/v1)?\/?$/', '', $apiBase);
        if (!is_string($root) || $root === $apiBase) {
            $root = isset($GLOBALS['HAXCMS']->basePath) ? rtrim((string) $GLOBALS['HAXCMS']->basePath, '/') : '';
        }
        return $root . '/';
    }
}
if (!function_exists('haxcmsMagicLinkIsSameOrigin')) {
    // Same model as Node's refreshAccessToken isSameOriginRequest: a present
    // Origin (or Referer) must match the trusted protocol + host; no header
    // (non-browser clients) is allowed, SameSite=Lax remains the main control.
    function haxcmsMagicLinkIsSameOrigin()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        if ($origin === '' && isset($_SERVER['HTTP_REFERER'])) {
            $origin = trim((string) $_SERVER['HTTP_REFERER']);
        }
        if ($origin === '') {
            return true;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            return false;
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return strtolower($parts['scheme']) === $GLOBALS['HAXCMS']->resolveTrustedProtocol() &&
            strtolower($host) === strtolower($GLOBALS['HAXCMS']->resolveTrustedHost());
    }
}
if (!function_exists('haxcmsMagicLinkPage')) {
    function haxcmsMagicLinkPage($title, $bodyHtml)
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width, initial-scale=1">' .
            '<meta name="robots" content="noindex, nofollow">' .
            '<title>' . haxcmsMagicLinkEscape($title) . '</title>' .
            '<style>' .
            'body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fa;color:#1e1e1e}' .
            '@media (prefers-color-scheme:dark){body{background:#1e1e1e;color:#f5f7fa}main{background:#262626!important}}' .
            'main{background:#fff;padding:2rem;border-radius:8px;max-width:28rem;margin:1rem;box-shadow:0 2px 8px rgba(0,0,0,.15)}' .
            'h1{font-size:1.5rem;margin:0 0 1rem}p{line-height:1.5}' .
            'button,a.button{font:inherit;font-weight:700;padding:.75rem 1.5rem;border:0;border-radius:4px;background:#001e44;color:#fff;cursor:pointer;text-decoration:none;display:inline-block}' .
            'button:focus-visible,a.button:focus-visible{outline:3px solid #009cde;outline-offset:2px}' .
            '</style></head><body><main>' . $bodyHtml . '</main></body></html>';
    }
}
if (!function_exists('haxcmsMagicLinkRespond')) {
    /**
     * Emit a response and stop. Returns the response instead when
     * $GLOBALS['HAXCMS_MAGIC_LINK_TEST_CAPTURE'] is set, so unit tests can
     * inspect it without exit().
     */
    function haxcmsMagicLinkRespond($status, $headers, $body)
    {
        $headers['Cache-Control'] = 'no-store';
        // keep the token out of Referer headers sent to anything the page loads
        $headers['Referrer-Policy'] = 'no-referrer';
        if (!empty($GLOBALS['HAXCMS_MAGIC_LINK_TEST_CAPTURE'])) {
            return array('status' => $status, 'headers' => $headers, 'body' => $body);
        }
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        print $body;
        exit;
    }
}
if (!function_exists('haxcmsMagicLinkFailure')) {
    function haxcmsMagicLinkFailure($context, $status, $message)
    {
        $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
        if (haxcmsMagicLinkWantsJson()) {
            return haxcmsMagicLinkRespond($status, array('Content-Type' => 'application/json'), json_encode(array(
                'status' => $status,
                'data' => array('message' => $message),
            )));
        }
        return haxcmsMagicLinkRespond($status, array('Content-Type' => 'text/html; charset=utf-8'), haxcmsMagicLinkPage(
            'Sign-in link not valid',
            '<h1>Sign-in link not valid</h1><p>' . haxcmsMagicLinkEscape($message) . '</p>' .
            '<p><a class="button" href="' . haxcmsMagicLinkEscape(haxcmsMagicLinkAppRoot($context)) . '">Go to site</a></p>'
        ));
    }
}
if (!function_exists('haxcmsMagicLinkRateKey')) {
    // one bucket per client IP, separate from password-login buckets so a
    // lockout on one recovery path doesn't block the other
    function haxcmsMagicLinkRateKey()
    {
        $ip = method_exists($GLOBALS['HAXCMS'], 'resolveClientIP')
            ? $GLOBALS['HAXCMS']->resolveClientIP()
            : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
        return 'magic-link-rate:' . sha1((string) $ip);
    }
}

return function ($context) {
    // Turned off (e.g. HAXiam behind OAuth/SSO): behave as if the endpoint
    // doesn't exist. No confirmation page, no hint a recovery path exists.
    if (!MagicLinkService::isEnabled($GLOBALS['HAXCMS'])) {
        return haxcmsMagicLinkRespond(404, array('Content-Type' => 'application/json'), json_encode(array(
            'status' => 404,
            'data' => array('message' => 'Not found'),
        )));
    }
    $generic = 'This sign-in link is invalid or has expired. Generate a new one and try again.';
    $token = haxcmsMagicLinkSubmittedToken($context);
    if (strtoupper($context->method) === 'GET') {
        if (!MagicLinkService::isWellFormedToken($token)) {
            return haxcmsMagicLinkFailure($context, 400, $generic);
        }
        if (haxcmsMagicLinkWantsJson()) {
            return haxcmsMagicLinkRespond(405, array('Content-Type' => 'application/json'), json_encode(array(
                'status' => 405,
                'data' => array('message' => 'POST the token to this endpoint to complete sign-in.'),
            )));
        }
        return haxcmsMagicLinkRespond(200, array('Content-Type' => 'text/html; charset=utf-8'), haxcmsMagicLinkPage(
            'Sign in to HAXcms',
            '<h1>Sign in to HAXcms</h1>' .
            '<p>This one-time link signs you in. It stops working once used or when it expires.</p>' .
            '<form method="post"><input type="hidden" name="token" value="' . haxcmsMagicLinkEscape($token) . '">' .
            '<button type="submit">Sign in</button></form>'
        ));
    }
    // POST
    if (!haxcmsMagicLinkIsSameOrigin()) {
        return haxcmsMagicLinkFailure($context, 403, 'Cross-origin request denied');
    }
    $settings = $GLOBALS['HAXCMS']->getLoginRateLimitSettings();
    $nowMs = intval(round(microtime(true) * 1000));
    $cache = (isset($GLOBALS['HAXCMS']->cache) && $GLOBALS['HAXCMS']->cache) ? $GLOBALS['HAXCMS']->cache : null;
    $rateKey = haxcmsMagicLinkRateKey();
    $entry = $cache ? $cache->retrieve($rateKey) : null;
    if (!is_array($entry) || ($nowMs - intval($entry['firstAttempt'])) > intval($settings->windowMs)) {
        $entry = array(
            'firstAttempt' => $nowMs,
            'failedAttempts' => 0,
            'blockedUntil' => is_array($entry) && intval($entry['blockedUntil']) > $nowMs ? intval($entry['blockedUntil']) : 0,
        );
    }
    if ($settings->enabled && intval($entry['blockedUntil']) > $nowMs) {
        header('Retry-After: ' . intval(ceil((intval($entry['blockedUntil']) - $nowMs) / 1000)));
        return haxcmsMagicLinkFailure($context, 429, 'Too many failed sign-in attempts. Please try again later.');
    }
    $result = MagicLinkService::consume($GLOBALS['HAXCMS'], $token, $nowMs);
    if (!$result['ok']) {
        if ($settings->enabled && $cache) {
            $entry['failedAttempts'] = intval($entry['failedAttempts']) + 1;
            if ($entry['failedAttempts'] >= intval($settings->maxAttempts)) {
                $entry['blockedUntil'] = $nowMs + intval($settings->blockMs);
                $entry['failedAttempts'] = 0;
                $entry['firstAttempt'] = $nowMs;
            }
            $cache->store($rateKey, $entry, intval(ceil((intval($settings->windowMs) + intval($settings->blockMs)) / 1000)) + 60);
        }
        return haxcmsMagicLinkFailure($context, 401, $generic);
    }
    if ($cache) {
        try {
            $cache->erase($rateKey);
        }
        catch (Exception $e) {}
    }
    $GLOBALS['HAXCMS']->setRefreshTokenCookie($GLOBALS['HAXCMS']->getRefreshToken($result['user']));
    if (haxcmsMagicLinkWantsJson()) {
        return haxcmsMagicLinkRespond(200, array('Content-Type' => 'application/json'), json_encode(array(
            'status' => 200,
            'jwt' => $GLOBALS['HAXCMS']->getJWT($result['user']),
        )));
    }
    return haxcmsMagicLinkRespond(303, array('Location' => haxcmsMagicLinkAppRoot($context)), '');
};
