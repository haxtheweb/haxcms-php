<?php
/**
 * SSRF guard for haxcms-php.
 *
 * Mirrors the haxcms-nodejs safeFetch.js / HAXCMSFile.validateUrlNotSSRF
 * baseline (GHSA-q862-gcgq-5m6g class). The PHP backend had no SSRF guard
 * anywhere; this class provides the shared validation and three safe-fetch
 * wrappers that follow HTTP redirects manually (re-resolving + re-validating
 * every hop) and pin each hop's TCP connection to the SSRF-validated IP via
 * cURL CURLOPT_RESOLVE, closing both the redirect-to-metadata rebinding
 * window and the resolve-check-then-fetch DNS-rebinding TOCTOU.
 *
 * Usage:
 *   SsrfGuard::validateUrlNotSSRF($url)            // throw on private target
 *   $body = SsrfGuard::safeFileGetContents($url)   // curl wrapper (pinned + redirects)
 *   $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, $opts)
 *   $body = SsrfGuard::safeCurlExec($url, $extraOpts)
 *
 * Redirect policy (parity with safeFetch.js SAFE_FETCH_MAX_REDIRECTS=5):
 * up to 5 redirects are followed (6 total fetches); each Location hop is
 * resolved relative to the current URL, re-validated via resolveAndValidate
 * (which rejects private/reserved/loopback/link-local/metadata targets), and
 * fetched with its own pinned connection. Exceeding the hop cap throws
 * SSRF_REDIRECTS; an unparseable Location throws SSRF_REDIRECT.
 *
 * IP pinning (parity with safeFetch.js resolveAndValidateUrl +
 * buildPinnedRequestOptions): resolveAndValidate returns the first validated
 * address alongside the parsed URL; the wrappers feed it to cURL as a
 * CURLOPT_RESOLVE entry of "host:port:addr" (IPv6 addr bracketed) so the
 * actual connect targets the exact SSRF-validated IP while the Host header
 * and TLS SNI stay on the original hostname. This closes the window where a
 * DNS server returns a public IP for the check and a private IP for the
 * connect (DNS rebinding). Guzzle is forced to allow_redirects=false so the
 * manual walk is the only redirect path and every hop is re-validated.
 */
class SsrfGuard
{
    /**
     * Total timeout (seconds) for the safe-fetch wrappers, matching the
     * SAFE_FETCH_TIMEOUT_MS constant in haxcms-nodejs src/lib/safeFetch.js
     * (SEC-02). Prevents a slow/hanging upstream from holding a request open.
     */
    private static $SAFE_TIMEOUT = 15;
    /**
     * Maximum number of redirects followed before failing with SSRF_REDIRECTS,
     * matching SAFE_FETCH_MAX_REDIRECTS in haxcms-nodejs src/lib/safeFetch.js.
     * The walk performs up to $MAX_REDIRECTS+1 total fetches (initial + 5
     * hops); a 6th redirect response (hop === $MAX_REDIRECTS) throws.
     */
    private static $MAX_REDIRECTS = 5;
    /**
     * True for private / reserved / loopback / link-local / metadata IPs.
     * Matches the Node.js isPrivateOrReservedIP list.
     */
    public static function isPrivateOrReservedIP($ip)
    {
        if (!is_string($ip) || $ip === '') {
            return true;
        }
        // IPv6 normalization via inet_pton so ::1 / :: / ULA / link-local match
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            $lower = strtolower($ip);
            // IPv6 loopback (::1) and unspecified (::) — matched on the packed
            // bytes so every equivalent textual spelling (::1, ::0001,
            // 0:0:0:0:0:0:0:1, etc.) is caught, not just the canonical forms.
            $zero16 = str_repeat("\x00", 16);
            if ($packed === $zero16 || $packed === substr($zero16, 0, 15) . "\x01") {
                return true;
            }
            // IPv6 unique local (fc00::/7 -> starts fc or fd)
            if (strpos($lower, 'fc') === 0 || strpos($lower, 'fd') === 0) {
                return true;
            }
            // IPv6 link-local (fe80::/10)
            if (strpos($lower, 'fe80') === 0) {
                return true;
            }
            // IPv4-mapped IPv6 (::ffff:0:0/96) and IPv4-compatible IPv6
            // (::/96): normalize by decoding the trailing 4 bytes of the
            // packed address via inet_ntop and re-checking with
            // isPrivateOrReservedIPv4.
            //
            // Security (HAX-SEC-007 / SSRF hex-form bypass): the previous
            // text-based match only recognized the dotted-quad spelling
            // (::ffff:127.0.0.1) and passed substr($ip, 7) — yielding
            // "7f00:1" for the hex spelling — which matched no private IPv4
            // prefix, so ::ffff:7f00:1 (loopback 127.0.0.1),
            // ::ffff:a9fe:a9fe (metadata 169.254.169.254), and
            // ::ffff:c0a8:0001 (192.168.0.1) were all misclassified as
            // public and reached loopback/internal/metadata targets. inet_pton
            // canonicalizes every equivalent spelling to the same 16 bytes, so
            // decoding the trailing 4 bytes recovers the canonical dotted-quad
            // for both hex and dotted input. The prefix check (first 10 bytes
            // zero + bytes 10-11 = 0xffff for mapped; first 12 bytes zero for
            // compat) avoids touching legitimate public v6 like
            // 2001:db8::1.2.3.4, whose trailing 4 bytes happen to look like an
            // IPv4 address but whose prefix is not the mapped/compat prefix.
            $prefix10 = substr($packed, 0, 10);
            $octets10to11 = substr($packed, 10, 2);
            $isMapped = ($prefix10 === str_repeat("\x00", 10) && $octets10to11 === "\xff\xff");
            $isCompat = ($prefix10 === str_repeat("\x00", 10) && $octets10to11 === "\x00\x00");
            if ($isMapped || $isCompat) {
                $v4 = @inet_ntop(substr($packed, 12, 4));
                if ($v4 !== false) {
                    return self::isPrivateOrReservedIPv4($v4);
                }
                return true; // fail-closed if inet_ntop somehow fails
            }
            return false;
        }
        return self::isPrivateOrReservedIPv4($ip);
    }

    private static function isPrivateOrReservedIPv4($ip)
    {
        if (!is_string($ip) || $ip === '') {
            return true;
        }
        if ($ip === '0.0.0.0') {
            return true;
        }
        if (strpos($ip, '127.') === 0) {
            return true;
        }
        // link-local / cloud metadata (169.254.169.254)
        if (strpos($ip, '169.254.') === 0) {
            return true;
        }
        if (strpos($ip, '10.') === 0) {
            return true;
        }
        if (strpos($ip, '192.168.') === 0) {
            return true;
        }
        // private Class B (172.16.0.0/12)
        if (strpos($ip, '172.') === 0) {
            $parts = explode('.', $ip);
            if (isset($parts[1])) {
                $second = (int) $parts[1];
                if ($second >= 16 && $second <= 31) {
                    return true;
                }
            }
        }
        // carrier-grade NAT (100.64.0.0/10, RFC 6598) — reachable in some
        // cloud/LAN topologies; parity with haxcms-nodejs isPrivateOrReservedIPv4.
        if (strpos($ip, '100.') === 0) {
            $parts = explode('.', $ip);
            if (isset($parts[1])) {
                $second = (int) $parts[1];
                if ($second >= 64 && $second <= 127) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Resolve a URL's hostname and reject if any resolved address is private,
     * reserved, loopback, link-local, or cloud-metadata. Returns an array with
     * the parsed URL (as parse_url produces) AND the first validated address
     * so the caller can pin the TCP connection to that exact IP, closing the
     * resolve-check-then-fetch DNS-rebinding TOCTOU. Throws SsrfGuardException
     * on rejection.
     *
     * Checks ALL resolved A records (gethostbynamel) plus AAAA records
     * (dns_get_record) so a hostname that round-robins to an internal address
     * is rejected. Mirrors haxcms-nodejs safeFetch.js resolveAndValidateUrl.
     */
    private static function resolveAndValidate($url)
    {
        $parsed = @parse_url($url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new SsrfGuardException('Invalid URL', 'SSRF_INVALID_URL');
        }
        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SsrfGuardException('Unsupported URL protocol', 'SSRF_PROTOCOL');
        }
        $host = $parsed['host'];
        if ($host === '') {
            throw new SsrfGuardException('URL is missing a hostname', 'SSRF_HOSTNAME');
        }
        // parse_url('http://[::1]/') returns host as '[::1]' WITH brackets.
        // filter_var() rejects the bracketed form, so strip the surrounding []
        // for the literal-IP check. $host (bracketed) is still used for DNS
        // resolution below; $literalHost (unbracketed) is what isPrivateOrReservedIP
        // needs. For non-bracketed hosts $literalHost === $host.
        $literalHost = preg_match('/^\[(.+)\]$/', $host, $m) ? $m[1] : $host;
        $addresses = array();

        // IPv4 A records — gethostbynamel returns all resolved IPs (or false)
        $aRecords = @gethostbynamel($host);
        if (is_array($aRecords)) {
            foreach ($aRecords as $addr) {
                $addresses[] = $addr;
            }
        }

        // IPv6 AAAA records
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (isset($rec['ipv6']) && $rec['ipv6'] !== '') {
                        $addresses[] = $rec['ipv6'];
                    }
                }
            }
        }

        // If the host is itself a literal IP, validate it directly
        $literalV4 = filter_var($literalHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $literalV6 = filter_var($literalHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if ($literalV4 !== false || $literalV6 !== false) {
            $addresses[] = $literalHost;
        }

        if (count($addresses) === 0) {
            throw new SsrfGuardException('Unable to resolve URL hostname', 'SSRF_DNS');
        }
        foreach ($addresses as $addr) {
            if (self::isPrivateOrReservedIP($addr)) {
                throw new SsrfGuardException(
                    'URL target resolves to a private, reserved, loopback, link-local, or metadata address',
                    'SSRF_PRIVATE'
                );
            }
        }
        return array('parsed' => $parsed, 'pinnedIp' => $addresses[0]);
    }

    /**
     * Resolve + validate, returning just the parsed URL. Public, backward-
     * compatible contract (callers and tests expect the parse_url array).
     * Internally delegates to resolveAndValidate and discards the pinned IP.
     */
    public static function validateUrlNotSSRF($url)
    {
        $resolved = self::resolveAndValidate($url);
        return $resolved['parsed'];
    }

    /**
     * Build the CURLOPT_RESOLVE entry list that pins a connection to the
     * SSRF-validated $pinnedIp for the host:port implied by $parsed. IPv6
     * addresses are bracketed in the ADDRESS field per libcurl docs. The
     * HOST field uses the bare (unbracketed) hostname. Returns an empty array
     * when the inputs are incomplete so callers can skip pinning safely.
     */
    public static function buildPinnedResolveEntries(array $parsed, $pinnedIp)
    {
        if (!is_array($parsed) || !isset($parsed['host']) || !is_string($pinnedIp) || $pinnedIp === '') {
            return array();
        }
        $host = $parsed['host'];
        $resolveHost = preg_match('/^\[(.+)\]$/', $host, $m) ? $m[1] : $host;
        $scheme = strtolower(isset($parsed['scheme']) ? $parsed['scheme'] : 'http');
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        $port = (isset($parsed['port']) && $parsed['port'] !== '') ? (int) $parsed['port'] : $defaultPort;
        $isV6 = (filter_var($pinnedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);
        $addressField = $isV6 ? '[' . $pinnedIp . ']' : $pinnedIp;
        return array($resolveHost . ':' . $port . ':' . $addressField);
    }

    /**
     * Resolve a redirect Location header relative to the current URL, matching
     * haxcms-nodejs `new URL(location, currentUrl)` semantics via Guzzle PSR-7
     * UriResolver. Returns the absolute URL string, or false if either input
     * is empty or the resolution fails (caller throws SSRF_REDIRECT).
     */
    public static function resolveRedirectUrl($baseUrl, $location)
    {
        if (!is_string($baseUrl) || $baseUrl === '' || !is_string($location) || $location === '') {
            return false;
        }
        try {
            $base = \GuzzleHttp\Psr7\Utils::uriFor($baseUrl);
            $rel = \GuzzleHttp\Psr7\Utils::uriFor($location);
            $resolved = \GuzzleHttp\Psr7\UriResolver::resolve($base, $rel);
            $str = (string) $resolved;
            if ($str === '') {
                return false;
            }
            return $str;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Extract the first value of a named header from a raw header block
     * (as produced by curl with CURLOPT_HEADER=true). Case-insensitive name
     * match. Returns '' when the header is absent.
     */
    private static function extractHeader($headerBlock, $name)
    {
        if (!is_string($headerBlock) || $headerBlock === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $headerBlock);
        $prefix = strtolower($name) . ':';
        foreach ($lines as $line) {
            if (stripos($line, $prefix) === 0) {
                return trim(substr($line, strlen($prefix)));
            }
        }
        return '';
    }

    /**
     * Bounded redirect walk shared by all safe-fetch wrappers. $fetcher is
     * called once per hop with the current URL (already re-resolved + re-
     * validated), its parsed array, and the pinned IP; it must return an
     * array with 'status' (int), 'location' (string, the Location header
     * value or ''), and 'body' (the Response / body string / false). Non-3xx
     * and 304 responses are returned as-is; 3xx with no Location are returned
     * as-is; 3xx with a Location are re-resolved and re-validated for the
     * next hop. Exceeding $MAX_REDIRECTS throws SSRF_REDIRECTS; an unparseable
     * Location throws SSRF_REDIRECT. Mirrors the safeFetch.js main loop.
     */
    public static function walkRedirects($url, $fetcher)
    {
        $currentUrl = $url;
        for ($hop = 0; $hop <= self::$MAX_REDIRECTS; $hop++) {
            $resolved = self::resolveAndValidate($currentUrl);
            $result = call_user_func($fetcher, $currentUrl, $resolved['parsed'], $resolved['pinnedIp']);
            if (!is_array($result)) {
                throw new SsrfGuardException('safe-fetch fetcher returned a non-array result', 'SSRF_FETCH');
            }
            $status = isset($result['status']) ? (int) $result['status'] : 0;
            // not a redirect (or 304 Not Modified) -> final response
            if ($status < 300 || $status >= 400 || $status === 304) {
                return isset($result['body']) ? $result['body'] : null;
            }
            if ($hop === self::$MAX_REDIRECTS) {
                throw new SsrfGuardException('safe-fetch exceeded the redirect hop cap', 'SSRF_REDIRECTS');
            }
            $location = isset($result['location']) ? (string) $result['location'] : '';
            if ($location === '') {
                // 3xx with no Location header -> nothing to follow, return as-is
                return isset($result['body']) ? $result['body'] : null;
            }
            $nextUrl = self::resolveRedirectUrl($currentUrl, $location);
            if ($nextUrl === false) {
                throw new SsrfGuardException('safe-fetch received an invalid redirect Location', 'SSRF_REDIRECT');
            }
            $currentUrl = $nextUrl;
        }
        // unreachable: the loop always returns or throws on the last allowed hop
        throw new SsrfGuardException('safe-fetch unexpected loop exit', 'SSRF_REDIRECT');
    }

    /**
     * file_get_contents() equivalent that validates the URL, pins the TCP
     * connection to the SSRF-validated IP, and follows redirects manually
     * with per-hop re-validation. Delegates to safeCurlExec (curl-based) so
     * all three wrappers share the same pinning + redirect policy. Returns
     * the body string, or false on curl failure (matching file_get_contents
     * semantics). Throws SsrfGuardException on SSRF rejection, hop cap
     * (SSRF_REDIRECTS), or bad Location (SSRF_REDIRECT).
     */
    public static function safeFileGetContents($url)
    {
        return self::safeCurlExec($url);
    }

    /**
     * Merge a CURLOPT_RESOLVE pin for the current hop into a Guzzle options
     * array (returns a new array). Preserves any caller-supplied curl options
     * and replaces a prior host:port pin so the per-hop validated IP wins.
     */
    private static function applyPinnedResolveToGuzzle(array $merged, array $parsed, $pinnedIp)
    {
        if (!defined('CURLOPT_RESOLVE')) {
            return $merged;
        }
        $entries = self::buildPinnedResolveEntries($parsed, $pinnedIp);
        if (count($entries) === 0) {
            return $merged;
        }
        if (!isset($merged['curl']) || !is_array($merged['curl'])) {
            $merged['curl'] = array();
        }
        $existing = isset($merged['curl'][CURLOPT_RESOLVE]) && is_array($merged['curl'][CURLOPT_RESOLVE])
            ? $merged['curl'][CURLOPT_RESOLVE]
            : array();
        // Drop any prior entry for this exact host:port so the new pin overrides.
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $resolveHost = preg_match('/^\[(.+)\]$/', $host, $m) ? $m[1] : $host;
        $scheme = strtolower(isset($parsed['scheme']) ? $parsed['scheme'] : 'http');
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        $port = (isset($parsed['port']) && $parsed['port'] !== '') ? (int) $parsed['port'] : $defaultPort;
        $prefix = $resolveHost . ':' . $port . ':';
        $kept = array();
        foreach ($existing as $line) {
            if (strpos($line, $prefix) !== 0) {
                $kept[] = $line;
            }
        }
        $kept[] = $entries[0];
        $merged['curl'][CURLOPT_RESOLVE] = $kept;
        return $merged;
    }

    /**
     * Guzzle request wrapper. Validates the URL, forces allow_redirects=false
     * (so the manual walk is the only redirect path and every hop is re-
     * validated), pins each hop's connection to the SSRF-validated IP via
     * CURLOPT_RESOLVE, then delegates to $client->request(). Returns the
     * final Guzzle Response. Throws SsrfGuardException on SSRF rejection
     * (SSRF_*), hop cap (SSRF_REDIRECTS), or bad Location (SSRF_REDIRECT);
     * re-throws Guzzle exceptions as-is.
     */
    public static function safeGuzzleRequest($client, $method, $url, array $options = array())
    {
        $fetcher = function ($currentUrl, $parsed, $pinnedIp) use ($client, $method, $options) {
            $merged = $options;
            // Force off so Guzzle never auto-follows without a per-hop SSRF
            // re-check; the manual walk in walkRedirects handles every hop.
            $merged['allow_redirects'] = false;
            if (!isset($merged['timeout'])) {
                $merged['timeout'] = self::$SAFE_TIMEOUT;
            }
            $merged = self::applyPinnedResolveToGuzzle($merged, $parsed, $pinnedIp);
            $response = $client->request($method, $currentUrl, $merged);
            return array(
                'status' => $response->getStatusCode(),
                'location' => $response->getHeaderLine('Location'),
                'body' => $response,
            );
        };
        return self::walkRedirects($url, $fetcher);
    }

    /**
     * curl wrapper. Validates the URL, pins the connection to the SSRF-
     * validated IP via CURLOPT_RESOLVE, sets CURLOPT_FOLLOWLOCATION=false and
     * pins protocols to http/https, then executes. Follows redirects manually
     * with per-hop re-validation (shared walkRedirects policy). Returns the
     * final body string (CURLOPT_RETURNTRANSFER=true) or false on curl
     * failure (caller decides how to handle). Throws SsrfGuardException on
     * SSRF rejection, hop cap (SSRF_REDIRECTS), or bad Location (SSRF_REDIRECT).
     */
    public static function safeCurlExec($url, array $extraOptions = array())
    {
        $fetcher = function ($currentUrl, $parsed, $pinnedIp) use ($extraOptions) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $currentUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            // Include headers in the output so we can read the status + Location
            // for the manual redirect walk; they are stripped before return.
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::$SAFE_TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, 0);
            }
            if (defined('CURLOPT_RESOLVE')) {
                $entries = self::buildPinnedResolveEntries($parsed, $pinnedIp);
                if (count($entries) > 0) {
                    curl_setopt($ch, CURLOPT_RESOLVE, $entries);
                }
            }
            foreach ($extraOptions as $key => $value) {
                curl_setopt($ch, $key, $value);
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                curl_close($ch);
                // Surface as a non-redirect final with false body so walkRedirects
                // returns false (matching the legacy safeCurlExec contract).
                return array('status' => 200, 'location' => '', 'body' => false);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            curl_close($ch);
            return array(
                'status' => $status,
                'location' => self::extractHeader($headers, 'Location'),
                'body' => $body,
            );
        };
        return self::walkRedirects($url, $fetcher);
    }
}

class SsrfGuardException extends Exception
{
    public $ssrfCode;
    public function __construct($message, $code = 'SSRF', Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->ssrfCode = $code;
    }
}
