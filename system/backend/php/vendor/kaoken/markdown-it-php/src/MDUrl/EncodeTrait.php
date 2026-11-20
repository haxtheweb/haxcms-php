<?php
/**
 * Copyright (c) 2015 Vitaly Puzrin, Alex Kocharin.
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


trait EncodeTrait
{
    protected string $defaultChars = ";/?:@&=+$,-_.!~*'()#";
    protected string $componentChars = "-_.!~*'()";
    protected array $encodeCache = [];


    /**
     * Create a lookup array where anything but characters in `chars` string
     * and alphanumeric chars is percent-encoded.
     * @param string $exclude Ascii character staring
     * @return array
     */
    protected function &getEncodeCache(string $exclude): array
    {
        if (array_key_exists($exclude, $this->encodeCache)) { return $this->encodeCache[$exclude]; }

        $cache = [];

        for ($i = 0; $i < 128; $i++) {
            // /^[0-9a-z]$/i
            if ( $i >= 0x30 && $i <= 0x39 ||
                $i >= 0x41 && $i <= 0x5a ||
                $i >= 0x61 && $i <= 0x7a ) {
                // always allow unencoded alphanumeric characters
                $cache[] = chr($i);
            } else {
                $cache[] = sprintf("%%%02X",$i);
            }
        }

        for ($i = 0, $l = strlen($exclude); $i < $l; $i++) $cache[ord($exclude[$i])] = $exclude[$i];


        $this->encodeCache[$exclude] = $cache;
        return $cache;
    }


    /**
     * Encode unsafe characters with percent-encoding, skipping already encoded sequences.
     * @param string $string String to encode
     * @param null $exclude List of characters to ignore (in addition to a-zA-Z0-9)
     * @param bool $keepEscaped Don't encode '%' in a correct escape sequence (default: true)
     * @return string
     */
    public function encode(string $string, $exclude=null, bool $keepEscaped=true): string
    {
        $result = '';

        if ( !is_string($exclude) ) {
            // encode($string, $keepEscaped)
            $keepEscaped  = $exclude;
            $exclude = $this->defaultChars;
        }
        if( !isset($keepEscaped) ) $keepEscaped = true;

        $cache = $this->getEncodeCache($exclude);

        for ($i = 0, $l = mb_strlen($string); $i < $l; $i++) {
            $ch = mb_substr($string,$i,1);
            $chLen = strlen($ch);

            if ($keepEscaped && $ch === '%' && $i + 2 < $l) {
                if (preg_match("/^[0-9a-f]{2}$/i", mb_substr($string, $i + 1, 2))) {
                    $result .= mb_substr($string, $i, 3);
                    $i += 2;
                    continue;
                }
            }

            if ( $chLen === 1 && ($code = ord($ch)) < 128) {
                $result .= $cache[$code];
                continue;
            }

            $result .= rawurlencode($ch);
        }
        return $result;
    }
}