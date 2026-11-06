<?php
include_once dirname(__FILE__) . '/SsrfGuard.php';

/**
 * Outside link checks for Reports (haxtheweb/issues#3167).
 *
 * POST x/api/v1/site/check-links asks whether the outside links an author
 * put in their pages still answer. A browser cannot tell on its own: a
 * cross-origin fetch in no-cors mode is opaque (status 0 for a 200 and a 404
 * alike), so the check runs here. It replaces the old open-apis
 * linkValidator call, which sent the site's links to a third-party service.
 *
 * Abuse limits, so the endpoint cannot be used as an open proxy or a request
 * cannon:
 *  - Only links that appear in this site's own page HTML are checked. The
 *    request names which links; the URL that is fetched is always the one
 *    read from the site's content, never the string from the request body.
 *  - http(s) only, through SsrfGuard::walkRedirects: every hop is resolved and
 *    refused if any address is private, loopback, link-local or metadata, and
 *    curl is pinned to the checked IP (CURLOPT_RESOLVE, no DNS rebinding).
 *  - HEAD first, then GET only when HEAD is refused; the GET aborts at the
 *    first body byte, so nothing is downloaded and only the status and final
 *    URL come back.
 *  - At most MAX_LINKS per request, a per-link timeout and redirect cap, and
 *    a per-request time budget (links not reached are answered "deferred").
 *  - A per user and site budget of network checks per window
 *    (config->security->linkCheckRateLimit); cached answers do not count.
 *  - Answers are cached for RESULT_TTL_SECONDS.
 *
 * Parity: haxcms-nodejs src/lib/linkChecker.js implements the same rules,
 * states and limits. Node checks 4 links at a time; PHP checks them in turn
 * and leans on the request time budget instead.
 */
class LinkChecker
{
    const MAX_LINKS = 25;
    const MAX_HREF_LENGTH = 2048;
    const TIMEOUT_SECONDS = 8;
    const CONNECT_TIMEOUT_SECONDS = 5;
    const RESULT_TTL_SECONDS = 1800;
    const RESULT_CACHE_MAX = 2000;
    const REQUEST_BUDGET_SECONDS = 20;
    const USER_AGENT = 'HAXcms-link-check/1.0 (+https://haxtheweb.org)';

    // Statuses that mean the site answered but will not talk to a script, so
    // a person should open the link: auth walls, bot blocking, rate limits.
    private static $CHECK_BY_HAND_STATUSES = array(401, 403, 407, 429, 451, 999);

    // per-process fallbacks when no shared cache is configured
    private static $memoryResults = array();
    private static $memoryBudgets = array();

    /**
     * The URL to fetch for an href as written in page HTML, or '' when it is
     * not an outside http(s) link. Protocol-relative //host links are checked
     * over https; the fragment is dropped.
     */
    public static function toCheckUrl($href)
    {
        if (!is_string($href)) {
            return '';
        }
        $value = trim($href);
        if ($value === '' || strlen($value) > self::MAX_HREF_LENGTH) {
            return '';
        }
        if (strpos($value, '//') === 0) {
            $value = 'https:' . $value;
        }
        if (!preg_match('/^https?:\/\//i', $value)) {
            return '';
        }
        if (preg_match('/\s/', $value)) {
            return '';
        }
        $hashAt = strpos($value, '#');
        if ($hashAt !== false) {
            $value = substr($value, 0, $hashAt);
        }
        $parsed = @parse_url($value);
        if ($parsed === false || !isset($parsed['host']) || $parsed['host'] === '') {
            return '';
        }
        return $value;
    }

    /**
     * Outside links in a block of page HTML: array of href (trimmed, as
     * written) => URL that is fetched for it.
     */
    public static function extractOutsideLinks($html)
    {
        $found = array();
        if (!is_string($html) || $html === '' || !class_exists('DOMDocument')) {
            return $found;
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($doc->getElementsByTagName('a') as $anchor) {
            if (!$anchor->hasAttribute('href')) {
                continue;
            }
            $href = trim($anchor->getAttribute('href'));
            if (isset($found[$href])) {
                continue;
            }
            $checkUrl = self::toCheckUrl($href);
            if ($checkUrl !== '') {
                $found[$href] = $checkUrl;
            }
        }
        return $found;
    }

    /**
     * State for a final HTTP status: ok, redirected, check (answered but
     * will not talk to a script) or broken.
     */
    public static function classifyStatus($status, $redirected)
    {
        $status = intval($status);
        if ($status >= 200 && $status < 400) {
            return array('state' => $redirected ? 'redirected' : 'ok', 'reason' => '');
        }
        if (in_array($status, self::$CHECK_BY_HAND_STATUSES, true)) {
            return array('state' => 'check', 'reason' => 'refused');
        }
        return array('state' => 'broken', 'reason' => 'http');
    }

    /**
     * State for a failure: an SsrfGuard code, or a curl errno (as
     * 'CURL_<n>').
     */
    public static function errorReason($code)
    {
        $code = strval($code);
        switch ($code) {
            case 'SSRF_PRIVATE':
                return array('state' => 'skipped', 'reason' => 'private');
            case 'SSRF_INVALID_URL':
            case 'SSRF_PROTOCOL':
            case 'SSRF_HOSTNAME':
                return array('state' => 'broken', 'reason' => 'invalid');
            case 'SSRF_DNS':
            case 'CURL_6':
                return array('state' => 'broken', 'reason' => 'dns');
            case 'CURL_28':
                return array('state' => 'check', 'reason' => 'timeout');
            case 'SSRF_REDIRECTS':
            case 'SSRF_REDIRECT':
            case 'CURL_47':
                return array('state' => 'broken', 'reason' => 'redirects');
            case 'CURL_35':
            case 'CURL_51':
            case 'CURL_53':
            case 'CURL_54':
            case 'CURL_58':
            case 'CURL_59':
            case 'CURL_60':
            case 'CURL_77':
            case 'CURL_83':
                return array('state' => 'broken', 'reason' => 'tls');
        }
        return array('state' => 'broken', 'reason' => 'connection');
    }

    /**
     * One request to a pinned, SSRF-validated hop. Returns status, Location
     * header and curl errno (0 when a status line came back).
     */
    public static function curlStatus($url, $parsed, $pinnedIp, $method)
    {
        $location = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: */*'));
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, 0);
        }
        if (defined('CURLOPT_RESOLVE')) {
            $entries = SsrfGuard::buildPinnedResolveEntries($parsed, $pinnedIp);
            if (count($entries) > 0) {
                curl_setopt($ch, CURLOPT_RESOLVE, $entries);
            }
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($resource, $line) use (&$location) {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }
            return strlen($line);
        });
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            // stop at the first body byte: only the status line is wanted
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($resource, $chunk) {
                return 0;
            });
        }
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
        curl_close($ch);
        // 23 = CURLE_WRITE_ERROR from the deliberate abort above
        if ($status > 0 && ($errno === 0 || $errno === 23)) {
            $errno = 0;
        }
        return array('status' => $status, 'location' => $location, 'errno' => $errno);
    }

    /**
     * Follow one URL through SsrfGuard::walkRedirects with $method, using
     * $hopFetcher($url, $parsed, $pinnedIp, $method) for each hop (test
     * seam; defaults to curlStatus). Returns status, final url, errno.
     */
    private static function walk($url, $method, $hopFetcher)
    {
        $fetcher = function ($currentUrl, $parsed, $pinnedIp) use ($method, $hopFetcher) {
            $hop = call_user_func($hopFetcher, $currentUrl, $parsed, $pinnedIp, $method);
            if (intval($hop['errno']) !== 0) {
                throw new SsrfGuardException('link check request failed', 'CURL_' . intval($hop['errno']));
            }
            return array(
                'status' => intval($hop['status']),
                'location' => isset($hop['location']) ? strval($hop['location']) : '',
                'body' => array('status' => intval($hop['status']), 'url' => $currentUrl),
            );
        };
        return SsrfGuard::walkRedirects($url, $fetcher);
    }

    /**
     * Network check of one URL. HEAD first; many servers refuse HEAD, so any
     * status of 400 or more other than a plain 404/410 is retried once as a
     * GET. Returns state, reason, status, finalUrl.
     */
    public static function checkUrl($url, $hopFetcher = null)
    {
        if (!is_callable($hopFetcher)) {
            $hopFetcher = array('LinkChecker', 'curlStatus');
        }
        try {
            $final = self::walk($url, 'HEAD', $hopFetcher);
            $status = intval($final['status']);
            if ($status >= 400 && $status !== 404 && $status !== 410) {
                $final = self::walk($url, 'GET', $hopFetcher);
            }
            $finalUrl = isset($final['url']) ? strval($final['url']) : '';
            $redirected = ($finalUrl !== '' && $finalUrl !== $url);
            $verdict = self::classifyStatus($final['status'], $redirected);
            return array(
                'state' => $verdict['state'],
                'reason' => $verdict['reason'],
                'status' => intval($final['status']),
                'finalUrl' => $redirected ? $finalUrl : '',
            );
        }
        catch (SsrfGuardException $e) {
            $failed = self::errorReason($e->ssrfCode);
        }
        catch (Exception $e) {
            $failed = self::errorReason('');
        }
        return array(
            'state' => $failed['state'],
            'reason' => $failed['reason'],
            'status' => null,
            'finalUrl' => '',
        );
    }

    private static function cacheRetrieve($cache, $key)
    {
        if ($cache && method_exists($cache, 'retrieve')) {
            try {
                return $cache->retrieve($key);
            }
            catch (Exception $e) {
                return null;
            }
        }
        return null;
    }

    private static function cacheStore($cache, $key, $value, $ttl)
    {
        if ($cache && method_exists($cache, 'store')) {
            try {
                $cache->store($key, $value, $ttl);
            }
            catch (Exception $e) {
            }
        }
    }

    /**
     * Fixed-window budget of network checks per user and site. Returns
     * allowed, remaining, retryAfterSeconds; an allowed request spends $n.
     * $delta < 0 gives checks back (deferred links).
     */
    public static function changeBudget($cache, $key, $n, $nowMs, $settings)
    {
        $max = intval($settings->max);
        if (!$settings->enabled || $n === 0) {
            return array('allowed' => true, 'remaining' => $max, 'retryAfterSeconds' => 0);
        }
        $cacheKey = 'linkcheck-rate:' . sha1(strval($key));
        $entry = $cache ? self::cacheRetrieve($cache, $cacheKey) : (isset(self::$memoryBudgets[$cacheKey]) ? self::$memoryBudgets[$cacheKey] : null);
        $windowMs = intval($settings->windowMs);
        if (!is_array($entry) || ($nowMs - intval($entry['windowStart'])) >= $windowMs) {
            if ($n < 0) {
                return array('allowed' => true, 'remaining' => $max, 'retryAfterSeconds' => 0);
            }
            $entry = array('windowStart' => $nowMs, 'used' => 0);
        }
        $remaining = $max - intval($entry['used']);
        if ($n > $remaining) {
            return array(
                'allowed' => false,
                'remaining' => max(0, $remaining),
                'retryAfterSeconds' => max(1, intval(ceil((intval($entry['windowStart']) + $windowMs - $nowMs) / 1000))),
            );
        }
        $entry['used'] = max(0, intval($entry['used']) + $n);
        if ($cache) {
            self::cacheStore($cache, $cacheKey, $entry, intval(ceil($windowMs / 1000)) + 60);
        }
        else {
            self::$memoryBudgets[$cacheKey] = $entry;
        }
        return array('allowed' => true, 'remaining' => $max - intval($entry['used']), 'retryAfterSeconds' => 0);
    }

    /**
     * Check the requested hrefs against the site's own outside links.
     *
     * $o keys: siteHtml (string, every page's HTML), links (array of hrefs as
     * the client found them), siteKey (string, result cache namespace),
     * rateKey (user::site), rateSettings (enabled, windowMs, max), cache
     * (HAXCMS Cache or null), hopFetcher (test seam), nowMs, budgetSeconds.
     * Returns rateLimited, retryAfterSeconds, remaining, results, checked,
     * cached, deferred.
     */
    public static function checkSiteLinks($o)
    {
        $nowMs = isset($o['nowMs']) ? intval($o['nowMs']) : intval(round(microtime(true) * 1000));
        $cache = isset($o['cache']) ? $o['cache'] : null;
        $hopFetcher = isset($o['hopFetcher']) ? $o['hopFetcher'] : null;
        $budgetSeconds = isset($o['budgetSeconds']) ? floatval($o['budgetSeconds']) : self::REQUEST_BUDGET_SECONDS;
        $rateKey = isset($o['rateKey']) ? strval($o['rateKey']) : '';
        $rateSettings = $o['rateSettings'];
        $requested = array();
        $seen = array();
        $list = isset($o['links']) && is_array($o['links']) ? $o['links'] : array();
        foreach ($list as $value) {
            if (!is_string($value)) {
                continue;
            }
            $href = trim($value);
            if ($href === '' || strlen($href) > self::MAX_HREF_LENGTH || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $requested[] = $href;
        }
        $siteLinks = self::extractOutsideLinks(isset($o['siteHtml']) ? $o['siteHtml'] : '');
        $resultsKey = 'linkcheck-results:' . sha1(isset($o['siteKey']) ? strval($o['siteKey']) : '');
        $stored = $cache ? self::cacheRetrieve($cache, $resultsKey) : (isset(self::$memoryResults[$resultsKey]) ? self::$memoryResults[$resultsKey] : null);
        if (!is_array($stored)) {
            $stored = array();
        }
        // Security: the fetch target always comes from the site's own content
        // ($siteLinks), looked up by the requested href; a requested href that
        // is not in the site is answered "skipped" and never fetched.
        $results = array();
        $toFetch = array();
        foreach ($requested as $index => $href) {
            $results[$index] = array(
                'href' => $href,
                'state' => 'skipped',
                'reason' => 'not-in-site',
                'status' => null,
                'finalUrl' => '',
                'cached' => false,
                'checkedAt' => null,
            );
            if (!isset($siteLinks[$href])) {
                continue;
            }
            $siteUrl = $siteLinks[$href];
            if (isset($stored[$siteUrl]) && is_array($stored[$siteUrl]) && ($nowMs - intval($stored[$siteUrl]['at'])) <= self::RESULT_TTL_SECONDS * 1000) {
                $hit = $stored[$siteUrl];
                $results[$index]['state'] = $hit['state'];
                $results[$index]['reason'] = $hit['reason'];
                $results[$index]['status'] = $hit['status'];
                $results[$index]['finalUrl'] = $hit['finalUrl'];
                $results[$index]['cached'] = true;
                $results[$index]['checkedAt'] = gmdate('Y-m-d\TH:i:s.000\Z', intval(floor(intval($hit['at']) / 1000)));
                continue;
            }
            if (!isset($toFetch[$siteUrl])) {
                $toFetch[$siteUrl] = array();
            }
            $toFetch[$siteUrl][] = $index;
        }
        $budget = self::changeBudget($cache, $rateKey, count($toFetch), $nowMs, $rateSettings);
        if (!$budget['allowed']) {
            return array(
                'rateLimited' => true,
                'retryAfterSeconds' => $budget['retryAfterSeconds'],
                'remaining' => $budget['remaining'],
                'results' => array(),
                'checked' => 0,
                'cached' => 0,
                'deferred' => 0,
            );
        }
        $deadline = microtime(true) + $budgetSeconds;
        $deferred = 0;
        foreach ($toFetch as $siteUrl => $indexes) {
            if (microtime(true) >= $deadline) {
                $deferred++;
                foreach ($indexes as $index) {
                    $results[$index]['reason'] = 'deferred';
                }
                continue;
            }
            $outcome = self::checkUrl($siteUrl, $hopFetcher);
            $atMs = intval(round(microtime(true) * 1000));
            $stored[$siteUrl] = array(
                'at' => $atMs,
                'state' => $outcome['state'],
                'reason' => $outcome['reason'],
                'status' => $outcome['status'],
                'finalUrl' => $outcome['finalUrl'],
            );
            foreach ($indexes as $index) {
                $results[$index]['state'] = $outcome['state'];
                $results[$index]['reason'] = $outcome['reason'];
                $results[$index]['status'] = $outcome['status'];
                $results[$index]['finalUrl'] = $outcome['finalUrl'];
                $results[$index]['checkedAt'] = gmdate('Y-m-d\TH:i:s.000\Z', intval(floor($atMs / 1000)));
            }
        }
        if ($deferred > 0) {
            self::changeBudget($cache, $rateKey, -$deferred, $nowMs, $rateSettings);
        }
        if (count($toFetch) - $deferred > 0) {
            // keep the newest RESULT_CACHE_MAX answers
            if (count($stored) > self::RESULT_CACHE_MAX) {
                $stored = array_slice($stored, count($stored) - self::RESULT_CACHE_MAX, null, true);
            }
            if ($cache) {
                self::cacheStore($cache, $resultsKey, $stored, self::RESULT_TTL_SECONDS + 60);
            }
            else {
                self::$memoryResults[$resultsKey] = $stored;
            }
        }
        $cachedCount = 0;
        foreach ($results as $row) {
            if ($row['cached']) {
                $cachedCount++;
            }
        }
        return array(
            'rateLimited' => false,
            'retryAfterSeconds' => 0,
            'remaining' => $budget['remaining'] + $deferred,
            'results' => array_values($results),
            'checked' => count($toFetch) - $deferred,
            'cached' => $cachedCount,
            'deferred' => $deferred,
        );
    }

    // Test-only: forget the per-process fallbacks.
    public static function resetForTesting()
    {
        self::$memoryResults = array();
        self::$memoryBudgets = array();
    }
}
