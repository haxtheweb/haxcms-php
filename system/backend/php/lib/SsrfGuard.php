<?php
/**
 * SSRF guard for haxcms-php.
 *
 * Mirrors the haxcms-nodejs safeFetch.js / HAXCMSFile.validateUrlNotSSRF
 * baseline (GHSA-q862-gcgq-5m6g class). The PHP backend had no SSRF guard
 * anywhere; this class provides the shared validation and three safe-fetch
 * wrappers that disable HTTP redirects (closing the redirect-to-metadata
 * rebinding window that @file_get_contents / Guzzle / curl currently leave
 * open).
 *
 * Usage:
 *   SsrfGuard::validateUrlNotSSRF($url)            // throw on private target
 *   $body = SsrfGuard::safeFileGetContents($url)   // file_get_contents wrapper
 *   $resp = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, $opts)
 *   $body = SsrfGuard::safeCurlExec($url, $extraOpts)
 *
 * DNS-rebinding note (Phase 2, deferred): the resolve-check-then-fetch window
 * also exists here. PHP can close it cheaply via cURL CURLOPT_RESOLVE to pin
 * the validated IP, but that is a separate consistent pass across all fetch
 * sites (matching the Node.js Phase 2 deferral). Disabling redirects in
 * Phase 1 already closes the cheaper redirect-rebinding variant.
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
            if ($ip === '::1' || $ip === '::' || $ip === '0:0:0:0:0:0:0:1') {
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
            // IPv4-mapped IPv6 (::ffff:a.b.c.d) — unpack and re-check the v4
            if (strpos($lower, '::ffff:') === 0) {
                $v4 = substr($ip, 7);
                return self::isPrivateOrReservedIPv4($v4);
            }
            // IPv4-compatible IPv6 (::a.b.c.d, deprecated ::/96) — same
            // normalization as ::ffff: above. dns_get_record can return
            // ::127.0.0.1 for a ::7f00:1 AAAA record; without this branch it
            // falls through to "IPv6 → public" and bypasses the check. The
            // dotted-quad guard avoids touching legit public v6 like
            // 2001:db8::1.2.3.4. MUST run after the ::ffff: branch.
            if (strpos($lower, '::') === 0 && strpos($ip, '.') !== false) {
                $v4 = substr($ip, 2);
                return self::isPrivateOrReservedIPv4($v4);
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
        return false;
    }

    /**
     * Resolve a URL's hostname and reject if any resolved address is private,
     * reserved, loopback, link-local, or cloud-metadata. Returns the parsed
     * URL array on success. Throws SsrfGuardException on rejection.
     *
     * Checks ALL resolved A records (gethostbynamel) plus AAAA records
     * (dns_get_record) so a hostname that round-robins to an internal address
     * is rejected.
     */
    public static function validateUrlNotSSRF($url)
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
        $literalV4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $literalV6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if ($literalV4 !== false || $literalV6 !== false) {
            $addresses[] = $host;
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
        return $parsed;
    }

    /**
     * file_get_contents() wrapper that validates the URL first and disables
     * HTTP redirects (max_redirects=0) so an attacker cannot redirect from a
     * public IP to a metadata endpoint mid-request. Returns the body string,
     * or false on failure (matching file_get_contents semantics).
     */
    public static function safeFileGetContents($url)
    {
        self::validateUrlNotSSRF($url);
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'max_redirects' => 0,
                'ignore_errors' => true,
                'timeout' => self::$SAFE_TIMEOUT,
            ),
        ));
        return @file_get_contents($url, false, $ctx);
    }

    /**
     * Guzzle request wrapper. Validates the URL, merges allow_redirects=false
     * into the options (unless the caller explicitly enabled redirects), then
     * delegates to $client->request(). Returns the Guzzle Response. Throws
     * SsrfGuardException on SSRF rejection; re-throws Guzzle exceptions as-is.
     */
    public static function safeGuzzleRequest($client, $method, $url, array $options = array())
    {
        self::validateUrlNotSSRF($url);
        $merged = $options;
        if (!isset($merged['allow_redirects'])) {
            $merged['allow_redirects'] = false;
        }
        if (!isset($merged['timeout'])) {
            $merged['timeout'] = self::$SAFE_TIMEOUT;
        }
        return $client->request($method, $url, $merged);
    }

    /**
     * curl wrapper. Validates the URL, sets CURLOPT_FOLLOWLOCATION=false and
     * pins protocols to http/https, then executes. Returns the body string
     * (CURLOPT_RETURNTRANSFER=true). Throws SsrfGuardException on SSRF
     * rejection. On curl failure returns false (caller decides how to handle).
     */
    public static function safeCurlExec($url, array $extraOptions = array())
    {
        self::validateUrlNotSSRF($url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$SAFE_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, 0);
        }
        foreach ($extraOptions as $key => $value) {
            curl_setopt($ch, $key, $value);
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
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
