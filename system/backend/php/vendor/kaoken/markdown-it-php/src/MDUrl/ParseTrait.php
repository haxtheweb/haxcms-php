<?php
/**
 * Copyright Joyent, Inc. and other Node contributors.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 Kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 * use javascript version 1.0.1
 * @see https://github.com/markdown-it/mdurl/tree/1.0.1
 */
namespace Kaoken\MDUrl;

//
// Changes from joyent/node:
//
// 1. No leading slash in paths;
//    e.g. in `url.parse('http://foo?bar')` pathname is ``, not `/`
//
// 2. Backslashes are not replaced with slashes;
//    so `http:\\example.org\` is treated like a relative path
//
// 3. Trailing colon is treated like a part of the path;
//    i.e. in `http://example.org:foo` pathname is `:foo`
//
// 4. Nothing is URL-encoded in the resulting object;
//    (in joyent/node some chars in auth and paths are encoded)
//
// 5. `url.parse()` does not have `parseQueryString` argument
//
// 6. Removed extraneous result properties: `host`, `path`, `query`, etc.;
//    which can be constructed using other parts of the url.
//


Trait ParseTrait
{
    /**
     * @return \stdClass
     */
    public function createUrlObject()
    {
        $r = new \stdClass();
        $r->protocol = null;
        $r->slashes = null;
        $r->auth = null;
        $r->port = null;
        $r->hostname = null;
        $r->hash = null;
        $r->search = null;
        $r->pathname = null;
        return $r;
    }

    // Reference: RFC 3986, RFC 1808, RFC 2396

    // define these here so at least they only have to be
    // compiled once on the first module load.
    protected string $protocolPattern = '/^([a-z0-9.+-]+:)/i';
    protected string $portPattern = '/:[0-9]*$/';

    // Special case for a simple path URL
    protected string $simplePathPattern = '/^(\/\/?(?!\/)[^\\?\s]*)(\\?[^\s]*)?$/';

    // RFC 2396: characters reserved for delimiting URLs.
    // We actually just auto-escape these.
    protected array $delims = ['<', '>', '"', '`', ' ', "\r", "\n", "\t"];

    // RFC 2396: characters not allowed for various reasons.
    protected array $unwise = ['{', '}', '|', '\\', '^', '`', '<', '>', '"', '`', ' ', "\r", "\n", "\t"];

    // Allowed by RFCs, but cause of XSS attacks.  Always escape these.
    protected array $autoEscape = ['\'', '{', '}', '|', '\\', '^', '`', '<', '>', '"', '`', ' ', "\r", "\n", "\t"];
    // Characters that are never ever allowed in a hostname.
    // Note that any invalid chars are also handled, but these
    // are the ones that are *expected* to be seen, so we fast-path
    // them.
    protected array $nonHostChars = ['%', '/', '?', ';', '#', '\'', '{', '}', '|', '\\', '^', '`', '<', '>', '"', '`', ' ', "\r", "\n", "\t"];
    protected array $hostEndingChars = ['/', '?', '#'];
    protected int $hostnameMaxLen = 255;
    protected string $hostnamePartPattern = '/^[+a-z0-9A-Z_-]{0,63}$/';
    protected string $hostnamePartStart = '/^([+a-z0-9A-Z_-]{0,63})(.*)$/';
    // protocols that can allow "unsafe" and "unwise" chars.
    /* eslint-disable no-script-url */
    // protocols that never have a hostname.
    protected array $hostlessProtocol = [
        'javascript' => true,
        'javascript:' => true
    ];
    // protocols that always contain a // bit.
    protected array $slashedProtocol = [
        'http' => true,
        'https' => true,
        'ftp' => true,
        'gopher' => true,
        'file' => true,
        'http:' => true,
        'https:' => true,
        'ftp:' => true,
        'gopher:' => true,
        'file:' => true
    ];
    /* eslint-enable no-script-url */


    /**
     * @param string $url
     * @param bool $slashesDenoteHost
     * @return mixed
     */
    public function urlParse(string $url, $slashesDenoteHost=false) {
        if (isset($url) && $url instanceof \stdClass) { return $url; }

        return $this->parse($url, $slashesDenoteHost);
    }

    /**
     * @param string $url
     * @param bool $slashesDenoteHost
     * @return \stdClass
     */
    public function parse(string $url, $slashesDenoteHost=false): \stdClass
    {

        $rest = $url;
        $objUrl = $this->createUrlObject();

        // trim before proceeding.
        // This is to support parse stuff like "  http://foo.com  \n"
        $rest = trim($rest);

        if (!$slashesDenoteHost && count(explode('#', $url)) === 1) {
            // Try fast path regexp
            if (preg_match($this->simplePathPattern, $rest, $simplePath)) {
                $objUrl->pathname = $simplePath[1];
                if (isset($simplePath[2])) {
                    $objUrl->search = $simplePath[2];
                }
                return $objUrl;
            }
        }

        if (preg_match($this->protocolPattern, $rest, $proto)) {
            $proto = $proto[0];
            $lowerProto = strtolower($proto);
            $objUrl->protocol = $proto;
            $rest = substr($rest, strlen ($proto));
        }else
            $proto = 'null';

        // figure out if it's got a host
        // user@server is *always* interpreted as a hostname, and url
        // resolution will treat //foo/bar as host=foo,path=bar because that's
        // how the browser resolves relative URLs.
        if ($slashesDenoteHost || $proto || preg_match("%^//[^@/]+@[^@/]+%",$rest)) {
            $slashes = substr($rest, 0, 2) === '//';
            if ($slashes && !($proto && isset($this->hostlessProtocol[$proto]))) {
                $rest = substr($rest, 2);
                $objUrl->slashes = true;
            }
        }

        if (!array_key_exists($proto, $this->hostlessProtocol) &&
            ($slashes || ($proto !== 'null' && !array_key_exists($proto, $this->slashedProtocol)) ) ) {

            // there's a hostname.
            // the first instance of /, ?, ;, or # ends the host.
            //
            // If there is an @ in the hostname, then non-host chars *are* allowed
            // to the left of the last @ sign, unless some host-ending character
            // comes *before* the @-sign.
            // URLs are obnoxious.
            //
            // ex:
            // http://a@b@c/ => user:a@b host:c
            // http://a@b?@c => user:a host:c path:/?@c

            // v0.12 TODO(isaacs): This is not quite how Chrome does things.
            // Review our test case against browsers more comprehensively.

            // find the first instance of any hostEndingChars
            $hostEnd = -1;
            for ($i = 0; $i < count($this->hostEndingChars); $i++) {
                $hec = strpos($rest,$this->hostEndingChars[$i]);
                if ($hec !== false && ($hostEnd === -1 || $hec < $hostEnd)) {
                    $hostEnd = $hec;
                }
            }

            // at this point, either we have an explicit point where the
            // auth portion cannot go past, or the last @ char is the decider.
            if ($hostEnd === -1) {
                // atSign can be anywhere.
                $atSign = strrpos($rest,'@');
            } else {
                // atSign must be in auth portion.
                // http://a@b/c@d => host:b auth:a path:/c@d
                $atSign = strrpos($rest, '@', $hostEnd-strlen($rest));
            }

            // Now we have a portion which is definitely the auth.
            // Pull that off.
            if ($atSign !== false) {
                $auth = substr($rest, 0, $atSign);
                $rest = substr($rest, $atSign + 1);
                $objUrl->auth = $auth;
            }

            // the host is the remaining to the left of the first non-host char
            $hostEnd = -1;
            for ($i = 0; $i < count($this->nonHostChars); $i++) {
                $hec = strpos($rest, $this->nonHostChars[$i]);
                if ($hec !== false && ($hostEnd === -1 || $hec < $hostEnd)) {
                    $hostEnd = $hec;
                }
            }

            // if we still have not hit it, then the entire thing is a host.
            if ($hostEnd === -1) {
                $hostEnd = strlen ($rest);
            }

            if ($hostEnd > 0 && $rest[$hostEnd - 1] === ':') { $hostEnd--; }
            $host = substr($rest, 0, $hostEnd);
            $rest = substr($rest, $hostEnd);

            // pull out port.
            $this->parseHost($host, $objUrl);

            // we've indicated that there is a hostname,
            // so even if it's empty, it has to be present.
            $objUrl->hostname = empty($objUrl->hostname) ? '' : $objUrl->hostname;

            // if hostname begins with [ and ends with ]
            // assume that it's an IPv6 address.
            $ipv6Hostname = !empty($objUrl->hostname) && $objUrl->hostname[0] === '[' &&
                $objUrl->hostname[strlen ($objUrl->hostname) - 1] === ']';

            // validate a little.
            if (!$ipv6Hostname) {
                $hostparts = preg_split('/\./',$objUrl->hostname);
                for ($i = 0, $l = count($hostparts); $i < $l; $i++) {
                    $part = $hostparts[$i];
                    if (!$part) { continue; }
                    if (!preg_match($this->hostnamePartPattern,$part)) {
                        $newpart = '';
                        for ($j = 0, $k = strlen ($part); $j < $k; $j++) {
                            if (ord($part[$j]) > 127) {
                                // we replace non-ASCII char with a temporary placeholder
                                // we need this to make sure size of hostname is not
                                // broken by replacing non-ASCII by nothing
                                $newpart .= 'x';
                            } else {
                                $newpart .= $part[$j];
                            }
                        }
                        // we test again with ASCII char only
                        if (!preg_match($this->hostnamePartPattern,$newpart)) {
                            $validParts = array_slice($hostparts, 0, $i);
                            $notHost = array_slice($hostparts, $i + 1);
                            if (preg_match($this->hostnamePartStart,$part, $bit)) {
                                array_push($validParts, $bit[1]);
                                array_unshift ($notHost, $bit[2]);
                            }
                            if (count($notHost)) {
                                $rest = implode('.', $notHost) . $rest;
                            }
                            $objUrl->hostname = implode('.', $validParts);
                            break;
                        }
                    }
                }
            }

            if (strlen ($objUrl->hostname) > $this->hostnameMaxLen) {
                $objUrl->hostname = '';
            }

            // strip [ and ] from the hostname
            // the host field still retains them, though
            if ($ipv6Hostname) {
                $objUrl->hostname = substr($objUrl->hostname, 1, strlen ($objUrl->hostname) - 2);
            }
        }

        // chop off from the tail first.
        $hash = strpos($rest, '#');
        if ($hash !== false) {
            // got a fragment string.
            $objUrl->hash = substr($rest,$hash);
            $rest = substr($rest, 0, $hash);
        }
        $qm = strpos($rest, '?');
        if ($qm !== false) {
            $objUrl->search = substr($rest,$qm);
            $rest = substr($rest, 0, $qm);
        }
        if ($rest !== false ) { $objUrl->pathname = $rest; }
        if ( !empty($lowerProto) && isset($this->slashedProtocol[$lowerProto]) &&
            $objUrl->hostname && !$objUrl->pathname) {
            $objUrl->pathname = '';
        }
        return $objUrl;
    }

    /**
     * @param string $host
     * @param $obj
     */
    protected function parseHost(string $host, &$obj) {
        if (preg_match($this->portPattern, $host, $port)) {
            $port = $port[0];
            if ($port !== ':') {
                $obj->port = substr($port, 1);
            }
            $host = substr($host, 0, strlen ($host) - strlen ($port));
        }
        if ($host) { $obj->hostname = $host; }
    }
}