<?php
/**
 * Copyright Mathias Bynens <https://mathiasbynens.be/>
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
 *
 *
 * use javascript version 2.3.1
 * @see https://github.com/bestiejs/punycode.js/tree/v2.3.1
 */
namespace Kaoken\Punycode;


use Exception;

class Punycode
{
    /**
     * A string representing the current Punycode.js version number.
     * @type String
     */
    const Version = '2.3.1';

    /** Highest positive signed 32-bit float value */
    const MAX_INT = 2147483647; // aka. 0x7FFFFFFF or 2^31-1

    /** Bootstring parameters */
    const BASE = 36;
    const T_MIN = 1;
    const T_MAX = 26;
    const SKEW = 38;
    const DAMP = 700;
    const INITIAL_BIAS = 72;
    const INITIAL_N = 128; // 0x80
    const DELIMITER = '-'; // 0x2D

    /** Regular expressions */
    const PUNYCODE_RE = "/^xn--/";
    const NON_ASCII_RE = "/[^\x{00}-\x{7F}]/"; // Note: U+007F DEL is excluded too.
    const SEPARATORS_RE = "/[\.。．｡]/u"; // RFC 3490 separators '\x2E\u3002\uFF0E\uFF61'

    /** Error messages */
    const ERRORS = [
        'overflow'=> 'Overflow: input needs wider integers to process',
        'not-basic'=> 'Illegal input >= 0x80 (not a basic code point)',
        'invalid-input'=> 'Invalid input'
    ];

    /** Convenience shortcuts */
    const BASE_MINUS_T_MIN = self::BASE - self::T_MIN;

    /*--------------------------------------------------------------------------*/

    /**
     * A generic error utility function.
     * @private
     * @param string $type The error type.
     * @throws Exception
     */
    protected function error($type) {
        throw new Exception(self::ERRORS[$type]);
    }

    /**
     * A generic `Array#map` utility function.
     * @private
     * @param array $array The array to iterate over.
     * @param callable $callback The function that gets called for every array
     * item.
     * @return array A new array of values returned by the callback function.
     */
    protected function map(array &$array, callable $callback): array
    {
        $result = [];
        $length = count($array);
        while ($length--) {
            $result[$length] = $callback($array[$length]);
        }
        ksort($result);
        return $result;
    }

    /**
     * A simple `Array#map`-like wrapper to work with domain name strings or email
     * addresses.
     * @private
     * @param string $domain The domain name or email address.
     * @param callable $callback The function that gets called for every
     * character.
     * @return string A new string of characters returned by the callback
     * function.
     */
    protected function mapDomain(string $domain, callable $callback): string
    {
        $parts = explode('@', $domain);
        $result = '';
        if ( count($parts) > 1) {
            // In email addresses, only the domain name should be punycoded. Leave
            // the local part (i.e. everything up to `@`) intact.
            $result = $parts[0] . '@';
            $domain = $parts[1];
        }
        // Avoid `split(regex)` for IE8 compatibility. See #17.
        $domain = preg_replace(self::SEPARATORS_RE, ".", $domain);
        $labels = explode('.', $domain);
        $encoded = join('.', $this->map($labels, $callback));
        return $result . $encoded;
    }


    /**
     * Creates a string based on an array of numeric code points.
     * @param array $codePoints The array of numeric code points.
     * @return string The new Unicode string (UCS-2).
     * @see `punycode->ucs2Decode`
     */
    public function ucs2Encode(array $codePoints): string
    {
        $outStr = '';
        foreach ($codePoints as $v) {
            if( 0x10000 <= $v && $v <= 0x10FFFF) {
                $v -= 0x10000;
                $w1 = 0xD800 | ($v >> 10);
                $w2 = 0xDC00 | ($v & 0x03FF);
                $outStr .= chr(0xFF & ($w1 >> 8)) . chr(0xFF & $w1);
                $outStr .= chr(0xFF & ($w2 >> 8)) . chr(0xFF & $w2);
            }else{
                $outStr .= chr(0xFF & ($v >> 8)) . chr(0xFF & $v);
            }
        }
        return $outStr;
    }

    /**
     * Creates an array containing the numeric code points of each Unicode
     * character in the string. While JavaScript uses UCS-2 internally,
     * this function will convert a pair of surrogate halves (each of which
     * UCS-2 exposes as separate characters) into a single code point,
     * matching UTF-16.
     * @param string $string The Unicode input string (UCS-2).
     * @return array The new array of code points.
     * @see `punycode->ucs2encode`
     * @see <https://mathiasbynens.be/notes/javascript-encoding>
     */
    public function ucs2Decode(string $string): array
    {
        $input = [];
        for($i=0,$l=strlen($string);$i<$l;$i+=2){
            $input[] = (ord($string[$i]) << 8) | ord($string[$i+1]);
        }

        $output = [];
        $counter = 0;
        $length = count($input);
        while ($counter < $length) {
            $value = $input[$counter++];
            if ($value >= 0xD800 && $value <= 0xDBFF && $counter < $length) {
                // It's a high surrogate, and there is a next character.
                $extra = $input[$counter++];
                if (($extra & 0xFC00) == 0xDC00) { // Low surrogate.
                    $output[] = (($value & 0x3FF) << 10) + ($extra & 0x3FF) + 0x10000;
                } else {
                    // It's an unmatched surrogate; only append this code unit, in case the
                    // next code unit is the high surrogate of a surrogate pair.
                    $output[] = $value;
                    $counter--;
                }
            } else {
                $output[] = $value;
            }
        }
        return $output;
    }


    /**
     * Converts a basic code point into a digit/integer.
     * @see `digitToBasic()`
     * @private
     * @param int codePoint The basic numeric code point value.
     * @return int The numeric value of a basic code point (for use in
     * representing integers) in the range `0` to `base - 1`, or `base` if
     * the code point does not represent a value.
     */
    public function basicToDigit($codePoint): int
    {
        if ($codePoint >= 0x30 && $codePoint < 0x3A) {
            return 26 + ($codePoint - 0x30);
        }
        if ($codePoint >= 0x41 && $codePoint < 0x5B) {
            return $codePoint - 0x41;
        }
        if ($codePoint >= 0x61 && $codePoint < 0x7B) {
            return $codePoint - 0x61;
        }
        return self::BASE;
    }

    /**
     * Converts a digit/integer into a basic code point.
     * @param $digit
     * @param $flag
     * @return int The basic code point whose value (when used for
     * representing integers) is `digit`, which needs to be in the range
     * `0` to `base - 1`. If `flag` is non-zero, the uppercase form is
     * used; else, the lowercase form is used. The behavior is undefined
     * if `flag` is non-zero and `digit` has no uppercase form.
     * @see `basicToDigit()`
     * @private
     */
    public function digitToBasic($digit, $flag) : int
    {
        //  0..25 map to ASCII a..z or A..Z
        // 26..35 map to ASCII 0..9
        return $digit + 22 + 75 * ($digit < 26) - (($flag != 0) << 5);
    }

    /**
     * Bias adaptation function as per section 3.4 of RFC 3492.
     * https://tools.ietf.org/html/rfc3492#section-3.4
     * @private
     * @param $delta
     * @param $numPoints
     * @param $firstTime
     * @return false|float
     */
    public function adapt($delta, $numPoints, $firstTime)
    {
        $k = 0;
        $delta = $firstTime ? floor($delta / self::DAMP) : $delta >> 1;
        $delta += floor($delta / $numPoints);
        for (/* no initialization */; $delta > self::BASE_MINUS_T_MIN * self::T_MAX >> 1; $k += self::BASE) {
            $delta = floor($delta / self::BASE_MINUS_T_MIN);
        }
        return floor($k + (self::BASE_MINUS_T_MIN + 1) * $delta / ($delta + self::SKEW));
    }

    /**
     * Converts a Punycode string of ASCII-only symbols to a string of Unicode
     * symbols.
     * @param string $input The Punycode string of ASCII-only symbols.
     * @return string The resulting string of Unicode symbols.
     * @throws Exception
     */
    public function decode(string $input): string
    {
        // Don't use UCS-2.
        $output = [];
        $inputLength = strlen($input);
        $i = 0;
        $n = self::INITIAL_N;
        $bias = self::INITIAL_BIAS;

        // Handle the basic code points: let `basic` be the number of input code
        // points before the last delimiter, or `0` if there is none, then copy
        // the first basic code points to the output.

        $basic = strrpos($input, self::DELIMITER);
        if ($basic === false ) {
            $basic = 0;
        }

        for ($j = 0; $j < $basic; ++$j) {
            // if it's not a basic code point
            if ( ord($input[$j]) >= 0x80) {
                $this->error('not-basic');
            }
            $output[] = ord($input[$j]);
        }

        // Main decoding loop: start just after the last delimiter if any basic code
        // points were copied; start at the beginning otherwise.

        for ( $index = $basic > 0 ? $basic + 1 : 0; $index < $inputLength; /* no final expression */) {

            // `index` is the index of the next character to be consumed.
            // Decode a generalized variable-$length integer into `delta`,
            // which gets added to `i`. The overflow checking is easier
            // if we increase `i` as we go, then subtract off its starting
            // value at the end to obtain `delta`.
            $oldi = $i;
            for ( $w = 1, $k = self::BASE; /* no condition */; $k += self::BASE) {

                if ($index >= $inputLength) {
                    $this->error('invalid-input');
                }

                $digit = $this->basicToDigit(ord($input[$index++]));

                if ($digit >= self::BASE) {
                    $this->error('invalid-input');
                }
                if ($digit > floor((self::MAX_INT - $i) / $w)) {
                    $this->error('overflow');
                }

                $i += $digit * $w;
                $t = $k <= $bias ? self::T_MIN : ($k >= $bias + self::T_MAX ? self::T_MAX : $k - $bias);

                if ($digit < $t) {
                    break;
                }

                $baseMinusT = self::BASE - $t;
                if ($w > floor(self::MAX_INT / $baseMinusT)) {
                    $this->error('overflow');
                }

                $w *= $baseMinusT;

            }

            $out = count($output) + 1;
            $bias = $this->adapt($i - $oldi, $out, $oldi == 0);

            // `i` was supposed to wrap around from `out` to `0`,
            // incrementing `n` each time, so we'll fix that now:
            if (floor($i / $out) > self::MAX_INT - $n) {
                $this->error('overflow');
            }

            $n += floor($i / $out);
            $i %= $out;

            // Insert `n` at position `i` of the output.
            array_splice($output, $i++, 0, $n);
        }

        return mb_convert_encoding($this->ucs2Encode($output), "UTF-8", "UTF-16");
    }

    /**
     * Converts a string of Unicode symbols (e.g. a domain name label) to a
     * Punycode string of ASCII-only symbols.
     * @param string $input The string of Unicode symbols.
     * @return string The resulting Punycode string of ASCII-only symbols.
     * @throws Exception
     */
    public function encode(string $input): string
    {
        $output = [];

        // Convert the input in UCS-2 to an array of Unicode code points.
        $input = $this->ucs2Decode(mb_convert_encoding($input, "UTF-16"));

        // Cache the length.
        $inputLength = count($input);

        // Initialize the state.
        $n = self::INITIAL_N;
        $delta = 0;
        $bias = self::INITIAL_BIAS;

        // Handle the basic code points.
        for ( $i=0; $i<$inputLength; $i++) {
            $currentValue = $input[$i];
            if ( $currentValue < 0x80) {
                $output[] = chr($currentValue);
            }
        }

        $basicLength = count($output);
        $handledCPCount = $basicLength;

        // `handledCPCount` is the number of code points that have been handled;
        // `basicLength` is the number of basic code points.

        // Finish the basic string with a delimiter unless it's empty.
        if ($basicLength) {
            $output[] = self::DELIMITER;
        }

        // Main encoding loop:
        while ($handledCPCount < $inputLength) {

            // All non-basic code points < n have been handled already. Find the next
            // larger one:
            $m = self::MAX_INT;
            for ( $i=0; $i<$inputLength; $i++) {
                $currentValue = $input[$i];
                if ($currentValue >= $n && $currentValue < $m) {
                    $m = $currentValue;
                }
            }

            // Increase `delta` enough to advance the decoder's <n,i> state to <m,0>,
            // but guard against overflow.
            $handledCPCountPlusOne = $handledCPCount + 1;
            if ($m - $n > floor((self::MAX_INT - $delta) / $handledCPCountPlusOne)) {
                $this->error('overflow');
            }

            $delta += ($m - $n) * $handledCPCountPlusOne;
            $n = $m;

            for ( $i=0; $i<$inputLength; $i++) {
                $currentValue = $input[$i];
                if ($currentValue < $n && ++$delta > self::MAX_INT) {
                    $this->error('overflow');
                }
                if ($currentValue === $n) {
                    // Represent delta as a generalized variable-$length integer.
                    $q = $delta;
                    for ($k = self::BASE; /* no condition */; $k += self::BASE) {
                        $t = $k <= $bias ? self::T_MIN : ($k >= $bias + self::T_MAX ? self::T_MAX : $k - $bias);
                        if ($q < $t) {
                            break;
                        }
                        $qMinusT = $q - $t;
                        $baseMinusT = self::BASE - $t;
                        $output[] = chr($this->digitToBasic($t + $qMinusT % $baseMinusT, 0));
                        $q = floor($qMinusT / $baseMinusT);
                    }

                    $output[] = chr($this->digitToBasic($q, 0));
                    $bias = $this->adapt($delta, $handledCPCountPlusOne, $handledCPCount === $basicLength);
                    $delta = 0;
                    ++$handledCPCount;
                }
            }

            ++$delta;
            ++$n;

        }
        return join('', $output);
    }

    /**
     * Converts a Punycode string representing a domain name or an email address
     * to Unicode. Only the Punycoded $parts of the input will be converted, i.e.
     * it doesn't matter if you call it on a string that has already been
     * converted to Unicode.
     * @param string $input The Punycoded domain name or email address to
     * convert to Unicode.
     * @return string The Unicode representation of the given Punycode
     * string.
     */
    public  function toUnicode(string $input): string
    {
        return $this->mapDomain($input, function($string) {
            return preg_match(self::PUNYCODE_RE, $string)
                ? $this->decode(mb_strtolower(substr($string, 4)))
                : $string;
        });
    }

    /**
     * Converts a Unicode string representing a domain name or an email address to
     * Punycode. Only the non-ASCII $parts of the domain name will be converted,
     * i.e. it doesn't matter if you call it with a domain that's already in
     * ASCII.
     * @param string $input The domain name or email address to convert, as a
     * Unicode string.
     * @return string The Punycode representation of the given domain name or
     * email address.
     */
    public function toASCII(string $input): string
    {
        return $this->mapDomain($input, function($string) {
            return preg_match(self::NON_ASCII_RE, $string)
                ? 'xn--' . $this->encode($string)
                : $string;
        });
    }
}