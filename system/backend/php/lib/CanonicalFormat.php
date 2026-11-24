<?php
/**
 * Canonical text formats (haxtheweb/issues#3394): one way to write JSON and
 * YAML, the same byte for byte in haxcms-php and haxcms-nodejs. The Node
 * twin is src/lib/canonicalFormat.js; tests/Unit/fixtures/canonical-format-vectors.json
 * is shared by both test suites and pins the output. Change the rules in
 * both files and the vectors together.
 *
 *   json($value)        compact JSON: API responses
 *   prettyJson($value)  JSON indented 4 spaces: md / html forms, files on disk
 *   yaml($value)        block YAML (rules below)
 *
 * Strings are written as JavaScript's JSON.stringify writes them: "/" and
 * non-ASCII characters as they are, U+2028 / U+2029 too. Numbers follow
 * JavaScript's Number#toString (0.00001, 1e-7, 1e+21), not PHP's float
 * format (1.0e-5).
 *
 * A PHP array that is a list is a JSON list; any other array, and any
 * object, is a mapping. An empty array is an empty list, as json_encode
 * writes it; an empty object (stdClass) is an empty mapping.
 *
 * YAML rules:
 *   - a mapping is "key: value" lines; a list is "- value" lines, indented
 *     2 spaces under the key that holds it
 *   - an empty list is [] and an empty mapping is {}, on the key's line
 *   - a mapping in a list starts on the "- " line; a list in a list starts
 *     on the line after a lone "-"
 *   - null, true, false and numbers are written as JSON writes them
 *   - a key or string is written plain when it matches PLAIN_SCALAR and is
 *     not one of RESERVED_WORDS; otherwise it is a double-quoted JSON
 *     string. The document ends with a newline.
 */
class CanonicalFormat
{
    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;
    const PLAIN_SCALAR = '/^[A-Za-z_][A-Za-z0-9_ .\/-]*$/';
    private static $reservedWords = array('true', 'false', 'null', 'yes', 'no', 'on', 'off', 'y', 'n', 'nan', 'inf');

    /** Compact JSON. */
    public static function json($value)
    {
        return self::encode(self::normalize($value), false, 0);
    }

    /** JSON indented 4 spaces. */
    public static function prettyJson($value)
    {
        return self::encode(self::normalize($value), true, 0);
    }

    /**
     * prettyJson, or false when the value cannot be written as JSON at all
     * (invalid UTF-8, INF / NAN, nesting too deep): nothing is replaced or
     * dropped. For data files such as site.json, whose writers must fail
     * rather than write something else.
     */
    public static function prettyJsonOrFalse($value)
    {
        $text = json_encode($value, self::JSON_FLAGS);
        if (!is_string($text)) {
            return false;
        }
        return self::encode(json_decode($text), true, 0);
    }

    /** Block YAML, ending with a newline. */
    public static function yaml($value)
    {
        $data = self::normalize($value);
        if (!is_array($data) && !is_object($data)) {
            return self::scalarText($data) . "\n";
        }
        if (self::isEmptyCollection($data)) {
            return self::emptyText($data) . "\n";
        }
        $lines = array();
        if (is_array($data)) {
            self::listLines($data, 0, $lines);
        }
        else {
            self::mappingLines($data, 0, $lines);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * A number as JavaScript's Number#toString writes it, from PHP's
     * shortest round-trip digits.
     */
    public static function numberText($number)
    {
        if (is_int($number)) {
            return (string) $number;
        }
        if (!is_finite($number)) {
            return 'null';
        }
        if ($number == 0) {
            return '0';
        }
        $text = json_encode($number);
        $negative = $text[0] === '-';
        if ($negative) {
            $text = substr($text, 1);
        }
        $exponent = 0;
        $epos = strpos(strtolower($text), 'e');
        if ($epos !== false) {
            $exponent = intval(substr($text, $epos + 1));
            $text = substr($text, 0, $epos);
        }
        $dot = strpos($text, '.');
        $intPart = $dot === false ? $text : substr($text, 0, $dot);
        $fracPart = $dot === false ? '' : substr($text, $dot + 1);
        $digits = $intPart . $fracPart;
        // the decimal point sits after $n digits of $digits
        $n = strlen($intPart) + $exponent;
        $stripped = ltrim($digits, '0');
        $n -= strlen($digits) - strlen($stripped);
        $digits = rtrim($stripped, '0');
        if ($digits === '') {
            return '0';
        }
        $k = strlen($digits);
        if ($k <= $n && $n <= 21) {
            $out = $digits . str_repeat('0', $n - $k);
        }
        else if (0 < $n && $n <= 21) {
            $out = substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        else if (-6 < $n && $n <= 0) {
            $out = '0.' . str_repeat('0', -$n) . $digits;
        }
        else {
            $e = $n - 1;
            $out = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
        }
        return ($negative ? '-' : '') . $out;
    }

    /**
     * A scalar as text, the way JavaScript's String() writes it: true /
     * false, numbers as Number#toString, null as ''. Used for XML values.
     */
    public static function scalarString($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::numberText($value);
        }
        return (string) $value;
    }

    /**
     * A time (seconds since 1970, fractions allowed) in the format of
     * JavaScript's Date#toISOString: 2025-10-09T08:53:20.000Z (UTC,
     * milliseconds always written, fractions of a millisecond dropped).
     */
    public static function isoDate($seconds)
    {
        $milliseconds = (int) floor(floatval($seconds) * 1000);
        $wholeSeconds = intdiv($milliseconds, 1000);
        $rest = $milliseconds % 1000;
        if ($rest < 0) {
            $rest += 1000;
            $wholeSeconds -= 1;
        }
        return gmdate('Y-m-d\\TH:i:s', $wholeSeconds) . '.' . sprintf('%03d', $rest) . 'Z';
    }

    /** JSON-safe copy: lists stay lists, {} stays an object. */
    private static function normalize($value)
    {
        $text = json_encode($value, self::JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($text)) {
            return null;
        }
        return json_decode($text);
    }

    /**
     * A mapping's entries in the order a JavaScript object keeps them: keys
     * that are array indexes ("0", "12", below 2^32 - 1) first in ascending
     * order, then the other keys in the order they were added.
     */
    private static function mappingEntries($map)
    {
        $indexed = array();
        $named = array();
        foreach ($map as $key => $item) {
            $key = (string) $key;
            if (preg_match('/^(0|[1-9][0-9]{0,9})$/', $key) === 1 && floatval($key) < 4294967295) {
                $indexed[] = array($key, $item);
            }
            else {
                $named[] = array($key, $item);
            }
        }
        usort($indexed, function ($a, $b) {
            return floatval($a[0]) <=> floatval($b[0]);
        });
        return array_merge($indexed, $named);
    }

    private static function stringText($text)
    {
        return json_encode((string) $text, self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function encode($value, $pretty, $level)
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::numberText($value);
        }
        if (is_string($value)) {
            return self::stringText($value);
        }
        $isList = is_array($value);
        $entries = array();
        if ($isList) {
            foreach ($value as $item) {
                $entries[] = self::encode($item, $pretty, $level + 1);
            }
        }
        else {
            foreach (self::mappingEntries($value) as $entry) {
                $entries[] = self::stringText($entry[0]) . ($pretty ? ': ' : ':') . self::encode($entry[1], $pretty, $level + 1);
            }
        }
        $open = $isList ? '[' : '{';
        $close = $isList ? ']' : '}';
        if (count($entries) === 0) {
            return $open . $close;
        }
        if (!$pretty) {
            return $open . implode(',', $entries) . $close;
        }
        $pad = str_repeat('    ', $level + 1);
        return $open . "\n" . $pad . implode(",\n" . $pad, $entries) . "\n" . str_repeat('    ', $level) . $close;
    }

    private static function scalarText($value)
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::numberText($value);
        }
        $text = (string) $value;
        if (
            preg_match(self::PLAIN_SCALAR, $text) === 1 &&
            substr($text, -1) !== ' ' &&
            !in_array(strtolower($text), self::$reservedWords, true)
        ) {
            return $text;
        }
        return self::stringText($text);
    }

    private static function emptyText($value)
    {
        return is_array($value) ? '[]' : '{}';
    }

    private static function isEmptyCollection($value)
    {
        if (is_array($value)) {
            return count($value) === 0;
        }
        return is_object($value) && count(get_object_vars($value)) === 0;
    }

    private static function mappingLines($map, $indent, &$lines)
    {
        $pad = str_repeat(' ', $indent);
        foreach (self::mappingEntries($map) as $entry) {
            $item = $entry[1];
            $keyText = self::scalarText($entry[0]);
            if (is_array($item) || is_object($item)) {
                if (self::isEmptyCollection($item)) {
                    $lines[] = $pad . $keyText . ': ' . self::emptyText($item);
                }
                else if (is_array($item)) {
                    $lines[] = $pad . $keyText . ':';
                    self::listLines($item, $indent + 2, $lines);
                }
                else {
                    $lines[] = $pad . $keyText . ':';
                    self::mappingLines($item, $indent + 2, $lines);
                }
            }
            else {
                $lines[] = $pad . $keyText . ': ' . self::scalarText($item);
            }
        }
    }

    private static function listLines($list, $indent, &$lines)
    {
        $pad = str_repeat(' ', $indent);
        foreach ($list as $item) {
            if (is_array($item) || is_object($item)) {
                if (self::isEmptyCollection($item)) {
                    $lines[] = $pad . '- ' . self::emptyText($item);
                }
                else if (is_array($item)) {
                    $lines[] = $pad . '-';
                    self::listLines($item, $indent + 2, $lines);
                }
                else {
                    $inner = array();
                    self::mappingLines($item, $indent + 2, $inner);
                    $inner[0] = $pad . '- ' . substr($inner[0], $indent + 2);
                    foreach ($inner as $line) {
                        $lines[] = $line;
                    }
                }
            }
            else {
                $lines[] = $pad . '- ' . self::scalarText($item);
            }
        }
    }
}
