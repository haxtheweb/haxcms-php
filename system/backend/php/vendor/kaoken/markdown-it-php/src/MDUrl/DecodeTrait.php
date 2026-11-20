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


trait DecodeTrait
{

    protected array $decodeCache = [];


    public function decodeDefaultChars(): string
    {return ';/?:@&=+$,#';}

    /**
     * @param string $exclude Ascii character staring
     * @return array
     */
    protected function getDecodeCache(string $exclude): array
    {
        if (array_key_exists($exclude, $this->encodeCache)) { return $this->encodeCache[$exclude]; }

        $cache = [];

        for ($i = 0; $i < 128; $i++) {
            $cache[] = chr($i);
        }

        for ($i = 0; $i < strlen($exclude); $i++) {
            $ch = ord($exclude[$i]);
            $cache[$ch] = sprintf("%%%02X",$ch);
        }

        return $cache;
    }


    /**
     * Decode percent-encoded string.
     * @param string $string
     * @param string|null $exclude
     * @return string|string[]|null
     */
    public function decode(string $string, $exclude=null)
    {
        if ( !is_string( $exclude) ) {
            $exclude = $this->decodeDefaultChars();
        }

        $cache = $this->getDecodeCache($exclude);

        return preg_replace_callback("/(%[a-f0-9]{2})+/i", function($seq) use(&$cache) {
            $result = '';
            $seq = $seq[0];
            for ($i = 0, $l = strlen($seq); $i < $l; $i += 3) {
                $b1 = intval(substr($seq, $i + 1, 2), 16);

                if ($b1 < 0x80) {
                    $result .= $cache[$b1];
                    continue;
                }
                $result .= chr($b1);
            }
            return $result;
        },$string);
    }
}