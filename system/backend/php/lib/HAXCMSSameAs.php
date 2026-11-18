<?php
/**
 * The site's profiles elsewhere (GitHub, LinkedIn, Mastodon, YouTube...),
 * published as schema.org sameAs on the site's Organization node so search
 * engines and agents can tie them to the site (haxtheweb/issues#3367).
 *
 * Invariant: only absolute http(s) URLs with a host and no credentials are
 * kept, each at most MAX_LENGTH characters, deduplicated, at most MAX_COUNT
 * of them, in the order given. Anything else is dropped, never an error, so
 * a stray line can't block saving the rest of the SEO settings. The same
 * normalizer runs on save and again when the head is rendered, so a
 * hand-edited site.json can't put another scheme into structured data.
 * Mirrors haxcms-nodejs src/lib/sameAs.js.
 */
class HAXCMSSameAs
{
    const MAX_COUNT = 12;
    const MAX_LENGTH = 2048;

    /**
     * @param mixed $input an array of URLs, or a string with one per line
     * @return string[]
     */
    public static function normalize($input)
    {
        if (is_array($input)) {
            // a JSON array decodes to a list; anything keyed is not a list
            if (array_keys($input) !== range(0, count($input) - 1) && count($input) > 0) {
                return array();
            }
            $candidates = $input;
        } elseif (is_string($input)) {
            $candidates = preg_split('/[\r\n]+/', $input);
            if (!is_array($candidates)) {
                return array();
            }
        } else {
            return array();
        }
        $seen = array();
        $list = array();
        foreach ($candidates as $candidate) {
            if (count($list) >= self::MAX_COUNT) {
                break;
            }
            if (!is_string($candidate)) {
                continue;
            }
            $value = trim($candidate);
            if ($value === '' || strlen($value) > self::MAX_LENGTH) {
                continue;
            }
            $href = self::webUrl($value);
            if ($href === null || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $list[] = $href;
        }
        return $list;
    }

    /**
     * The URL as WHATWG URL would serialize the parts we keep, or null when
     * it is not an absolute http(s) URL with a host and no credentials.
     */
    private static function webUrl($value)
    {
        // the characters a WHATWG URL parser would rewrite (non-ASCII,
        // spaces, controls, <>"\^`{|}) and dot segments are refused, so
        // both backends keep exactly the same URLs, spelled the same way
        if (preg_match('/[^\x21-\x7e]|[<>"\\\\^`{|}]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host === '' || !preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9._-]+)$/', $host)) {
            return null;
        }
        // a host ending in a number is an IPv4 address to a URL parser,
        // which rewrites shorthand like 1.2 or 0x7f.1; keep only the
        // canonical dotted quad
        $labels = explode('.', rtrim($host, '.'));
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', end($labels))) {
            if (!preg_match('/^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$/', $host)) {
                return null;
            }
        }
        $port = '';
        if (isset($parts['port'])) {
            $default = $scheme === 'https' ? 443 : 80;
            if ((int) $parts['port'] !== $default) {
                $port = ':' . (int) $parts['port'];
            }
        }
        // written out as scheme://host[:port] exactly as kept (no empty or
        // zero-padded port)
        if (!preg_match('#^https?://([^/?\#]*)#i', $value, $raw)) {
            return null;
        }
        $rawHost = preg_replace($scheme === 'https' ? '/:443$/' : '/:80$/', '', strtolower($raw[1]));
        if ($rawHost !== $host . $port) {
            return null;
        }
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (preg_match('#(^|/)\.{1,2}(/|$)#', $path)) {
            return null;
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }
}
