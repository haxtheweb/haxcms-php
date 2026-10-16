<?php
// Security (SEC-SSRF): the Guzzle streaming byte cap (SsrfGuardCappedStream)
// needs the PSR-7 interfaces, so load composer's autoloader here rather than
// relying on every includer having loaded it first.
$__ssrfGuardAutoload = dirname(__FILE__) . '/../vendor/autoload.php';
if (file_exists($__ssrfGuardAutoload)) {
    require_once $__ssrfGuardAutoload;
}
unset($__ssrfGuardAutoload);
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
 *
 * Byte cap (Security SEC-SSRF, parity with safeFetch.js maxBytes): every
 * wrapper buffers at most DEFAULT_MAX_RESPONSE_BYTES (20 MiB) per hop unless
 * the caller passes its own cap; over-cap responses throw SSRF_TOO_LARGE.
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
     * Security (SEC-SSRF): default cap on the number of response-body bytes
     * any safe-fetch wrapper will buffer (20 MiB). Parity with the
     * haxcms-nodejs safeFetch.js maxBytes default. A response whose
     * Content-Length exceeds the cap is rejected before the body is read; a
     * response without (or lying about) Content-Length is aborted as soon as
     * the streamed body crosses the cap. Either way the wrapper throws
     * SsrfGuardException with ssrfCode SSRF_TOO_LARGE. Callers can pass a
     * smaller or larger per-call cap (safeCurlExec $maxBytes,
     * safeFileGetContents $maxBytes, safeGuzzleRequest option 'max_bytes').
     */
    const DEFAULT_MAX_RESPONSE_BYTES = 20971520; // 20 * 1024 * 1024

    /**
     * Security (SEC-SSRF): IPv4 ranges that are never a legitimate outbound
     * fetch target. Compared as packed-address CIDRs (not string prefixes).
     * Parity contract with haxcms-nodejs HAXCMSFile.isPrivateOrReservedIP —
     * keep the two lists identical.
     */
    private static $BLOCKED_IPV4_CIDRS = array(
        '0.0.0.0/8',        // "this network" (RFC 1122), incl. 0.0.0.0
        '10.0.0.0/8',       // private (RFC 1918)
        '100.64.0.0/10',    // carrier-grade NAT (RFC 6598)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local / cloud metadata (169.254.169.254)
        '172.16.0.0/12',    // private (RFC 1918)
        '192.0.0.0/24',     // IETF protocol assignments (RFC 6890)
        '192.0.2.0/24',     // TEST-NET-1 (RFC 5737)
        '192.168.0.0/16',   // private (RFC 1918)
        '198.18.0.0/15',    // benchmarking (RFC 2544)
        '198.51.100.0/24',  // TEST-NET-2 (RFC 5737)
        '203.0.113.0/24',   // TEST-NET-3 (RFC 5737)
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved (RFC 1112), incl. 255.255.255.255 broadcast
    );

    /**
     * Security (SEC-SSRF): IPv6 ranges rejected outright.
     */
    private static $BLOCKED_IPV6_CIDRS = array(
        '::/128',           // unspecified
        '::1/128',          // loopback
        '100::/64',         // discard-only (RFC 6666)
        '64:ff9b:1::/48',   // local-use NAT64 (RFC 8215) — never globally reachable
        'fc00::/7',         // unique local (ULA)
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    );

    /**
     * Security (SEC-SSRF): IPv6 ranges that embed an IPv4 address. The
     * embedded address is decoded from the packed bytes at the given offset
     * and re-checked against the IPv4 list, so e.g. 64:ff9b::7f00:1 (NAT64
     * for 127.0.0.1) and 2002:a9fe:a9fe:: (6to4 for 169.254.169.254) are
     * rejected while 64:ff9b::808:808 (NAT64 for 8.8.8.8) is allowed.
     */
    private static $EMBEDDED_IPV4_IPV6_CIDRS = array(
        array('::ffff:0:0/96', 12),  // IPv4-mapped
        array('::/96', 12),          // IPv4-compatible (deprecated)
        array('64:ff9b::/96', 12),   // NAT64 well-known prefix (RFC 6052)
        array('2002::/16', 2),       // 6to4 (RFC 3056)
    );

    /** @var array packed CIDR cache: cidr => array(packedNetwork, prefixBits) */
    private static $packedCidrCache = array();

    /**
     * Parse a CIDR string into array(packedNetwork, prefixBits), cached.
     */
    private static function packCidr($cidr)
    {
        if (isset(self::$packedCidrCache[$cidr])) {
            return self::$packedCidrCache[$cidr];
        }
        $parts = explode('/', $cidr, 2);
        $packed = @inet_pton($parts[0]);
        $bits = isset($parts[1]) ? (int) $parts[1] : ($packed === false ? 0 : strlen($packed) * 8);
        self::$packedCidrCache[$cidr] = array($packed, $bits);
        return self::$packedCidrCache[$cidr];
    }

    /**
     * Security (SEC-SSRF): true when the packed address $packed (4 or 16
     * bytes, as produced by inet_pton) lies inside $cidr. Byte-wise prefix
     * comparison on packed addresses — never a textual prefix match.
     */
    public static function packedIpInCidr($packed, $cidr)
    {
        list($network, $bits) = self::packCidr($cidr);
        if (!is_string($packed) || $network === false || strlen($packed) !== strlen($network)) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($packed[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }

    /**
     * True for private / reserved / loopback / link-local / metadata /
     * multicast / documentation IPs, and for anything that does not parse as
     * an IP address (fail closed). Matches the Node.js isPrivateOrReservedIP
     * list (see the CIDR tables above).
     *
     * Security (HAX-SEC-007 / SSRF hex-form bypass) is preserved: every
     * spelling is canonicalised by inet_pton first, so ::ffff:7f00:1,
     * ::ffff:127.0.0.1 and 0:0:0:0:0:ffff:7f00:0001 all decode to the same
     * 16 bytes and the embedded IPv4 (127.0.0.1) is re-checked. Legitimate
     * public v6 like 2001:db8::1.2.3.4 is not touched because its prefix is
     * not one of the embedded-IPv4 prefixes.
     */
    public static function isPrivateOrReservedIP($ip)
    {
        if (!is_string($ip) || $ip === '') {
            return true;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            // Not a literal IP (or a zone-scoped / malformed one): fail closed.
            return true;
        }
        if (strlen($packed) === 4) {
            return self::isPrivateOrReservedPackedIPv4($packed);
        }
        if (strlen($packed) !== 16) {
            return true;
        }
        foreach (self::$BLOCKED_IPV6_CIDRS as $cidr) {
            if (self::packedIpInCidr($packed, $cidr)) {
                return true;
            }
        }
        foreach (self::$EMBEDDED_IPV4_IPV6_CIDRS as $entry) {
            if (self::packedIpInCidr($packed, $entry[0])) {
                return self::isPrivateOrReservedPackedIPv4(substr($packed, $entry[1], 4));
            }
        }
        return false;
    }

    /**
     * Decode the IPv4 address embedded in a NAT64 (64:ff9b::/96), 6to4
     * (2002::/16), IPv4-mapped or IPv4-compatible IPv6 address. Returns the
     * dotted quad, or null when $ip is not one of those forms. Exposed for
     * tests and diagnostics.
     */
    public static function decodeEmbeddedIPv4($ip)
    {
        $packed = is_string($ip) ? @inet_pton($ip) : false;
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }
        foreach (self::$EMBEDDED_IPV4_IPV6_CIDRS as $entry) {
            if (self::packedIpInCidr($packed, $entry[0])) {
                $v4 = @inet_ntop(substr($packed, $entry[1], 4));
                return ($v4 === false) ? null : $v4;
            }
        }
        return null;
    }

    private static function isPrivateOrReservedPackedIPv4($packed)
    {
        if (!is_string($packed) || strlen($packed) !== 4) {
            return true;
        }
        foreach (self::$BLOCKED_IPV4_CIDRS as $cidr) {
            if (self::packedIpInCidr($packed, $cidr)) {
                return true;
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
    public static function safeFileGetContents($url, $maxBytes = null)
    {
        return self::safeCurlExec($url, array(), $maxBytes);
    }

    /**
     * Security (SEC-SSRF): normalise a caller-supplied byte cap. Anything
     * that is not a positive integer falls back to DEFAULT_MAX_RESPONSE_BYTES.
     */
    public static function resolveMaxBytes($maxBytes = null)
    {
        if (is_int($maxBytes) && $maxBytes > 0) {
            return $maxBytes;
        }
        if (is_string($maxBytes) && ctype_digit($maxBytes) && (int) $maxBytes > 0) {
            return (int) $maxBytes;
        }
        return self::DEFAULT_MAX_RESPONSE_BYTES;
    }

    /**
     * Security (SEC-SSRF): make sure SsrfGuardCappedStream is declared. It
     * lives in its own file (it implements a PSR-7 interface, which must be
     * autoloadable at declaration time) and is required on first use.
     */
    public static function loadCappedStream()
    {
        if (!class_exists('SsrfGuardCappedStream', false)) {
            require_once dirname(__FILE__) . '/SsrfGuardCappedStream.php';
        }
    }

    /**
     * Security (SEC-SSRF): the exception every wrapper throws when a response
     * body exceeds the byte cap.
     */
    private static function tooLarge($limit)
    {
        return new SsrfGuardException(
            'Remote response exceeded the maximum allowed size of ' . (int) $limit . ' bytes',
            'SSRF_TOO_LARGE'
        );
    }

    /**
     * Security (SEC-SSRF): true when a Content-Length header value announces
     * more than $limit bytes.
     */
    private static function contentLengthExceeds($value, $limit)
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return false;
        }
        // compare as strings-of-digits first so a 30-digit value cannot
        // overflow into a small int
        if (strlen(ltrim($value, '0')) > 18) {
            return true;
        }
        return ((int) $value) > $limit;
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
        // Security (SEC-SSRF): byte cap. 'max_bytes' is a HAXcms option, not a
        // Guzzle one, so strip it before the options reach the client.
        $limit = self::resolveMaxBytes(isset($options['max_bytes']) ? $options['max_bytes'] : null);
        unset($options['max_bytes']);
        $fetcher = function ($currentUrl, $parsed, $pinnedIp) use ($client, $method, $options, $limit) {
            $merged = $options;
            // Force off so Guzzle never auto-follows without a per-hop SSRF
            // re-check; the manual walk in walkRedirects handles every hop.
            $merged['allow_redirects'] = false;
            if (!isset($merged['timeout'])) {
                $merged['timeout'] = self::$SAFE_TIMEOUT;
            }
            $merged = self::applyPinnedResolveToGuzzle($merged, $parsed, $pinnedIp);
            // Security (SEC-SSRF): reject an announced Content-Length over the
            // cap before any body is read, then stream the body into a sink
            // that refuses writes past the cap (catches chunked responses and
            // lying Content-Length; with decode_content the cap applies to the
            // decoded bytes, so a compression bomb is also stopped).
            $callerOnHeaders = isset($merged['on_headers']) && is_callable($merged['on_headers']) ? $merged['on_headers'] : null;
            $merged['on_headers'] = function ($response) use ($limit, $callerOnHeaders) {
                if (
                    $response instanceof \Psr\Http\Message\ResponseInterface &&
                    self::contentLengthExceeds($response->getHeaderLine('Content-Length'), $limit)
                ) {
                    throw self::tooLarge($limit);
                }
                if ($callerOnHeaders !== null) {
                    call_user_func($callerOnHeaders, $response);
                }
            };
            $sink = null;
            if (!isset($merged['sink'])) {
                // Loaded at first use so the cap cannot silently go missing
                // because of include order; throws if PSR-7 is unavailable.
                self::loadCappedStream();
                $sink = new SsrfGuardCappedStream(\GuzzleHttp\Psr7\Utils::streamFor(fopen('php://temp', 'w+b')), $limit);
                $merged['sink'] = $sink;
            }
            try {
                $response = $client->request($method, $currentUrl, $merged);
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                if ($sink !== null && $sink->exceeded) {
                    throw self::tooLarge($limit);
                }
                $previous = $e->getPrevious();
                if ($previous instanceof SsrfGuardException) {
                    throw $previous;
                }
                throw $e;
            }
            if ($sink !== null && $sink->exceeded) {
                throw self::tooLarge($limit);
            }
            $bodySize = $response->getBody()->getSize();
            if ($bodySize !== null && $bodySize > $limit) {
                throw self::tooLarge($limit);
            }
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
    public static function safeCurlExec($url, array $extraOptions = array(), $maxBytes = null)
    {
        $limit = self::resolveMaxBytes($maxBytes);
        $fetcher = function ($currentUrl, $parsed, $pinnedIp) use ($extraOptions, $limit) {
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
            // Security (SEC-SSRF): byte cap. CURLOPT_MAXFILESIZE rejects an
            // announced Content-Length over the cap up front; the progress
            // callback aborts a streamed (chunked / lying) body as soon as it
            // crosses the cap; the final strlen check is belt and braces.
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $limit);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($limit) {
                return ($dlTotal > $limit || $dlNow > $limit) ? 1 : 0;
            });
            foreach ($extraOptions as $key => $value) {
                curl_setopt($ch, $key, $value);
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                $errno = curl_errno($ch);
                curl_close($ch);
                // 63 = CURLE_FILESIZE_EXCEEDED, 42 = CURLE_ABORTED_BY_CALLBACK
                if ($errno === 63 || $errno === 42) {
                    throw self::tooLarge($limit);
                }
                // Surface as a non-redirect final with false body so walkRedirects
                // returns false (matching the legacy safeCurlExec contract).
                return array('status' => 200, 'location' => '', 'body' => false);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            curl_close($ch);
            if (strlen($body) > $limit) {
                throw self::tooLarge($limit);
            }
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
