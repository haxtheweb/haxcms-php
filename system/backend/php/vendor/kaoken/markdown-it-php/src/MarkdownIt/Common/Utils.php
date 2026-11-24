<?php
// Utilities
//

namespace Kaoken\MarkdownIt\Common;


use Exception;
use stdClass;

class Utils
{
    const UNESCAPE_MD = "\\\\([!\"#$%&'()*+,\-\.\/:;<=>?@[\\\\\]^_`{\|}~])";
    const UNESCAPE_MD_RE  = "/".self::UNESCAPE_MD."/";  // /g
    const ENTITY_RE       = '/&([a-z#][a-z0-9]{1,31});/i';  // /g
    const UNESCAPE_ALL_RE = "/".self::UNESCAPE_MD."|&([a-z#][a-z0-9]{1,31});/i";  // /g
    const DIGITAL_ENTITY_TEST_RE = "/^#((?:x[a-f0-9]{1,8}|[0-9]{1,8}))$/i";

    protected static ?Utils $instance=null;

    /**
     * @return Utils
     */
    public static function getInstance(): ?Utils
    {
        if( self::$instance === null ) self::$instance = new Utils();
        return self::$instance;
    }

    /**
     * If matches are found, the new $str will be returned, otherwise $str will be returned unchanged or NULL if an error occurred.
     * @param string $str
     * @return string|null
     */
    public function unescapeMd($str): ?string
    {
        if (strpos ($str,'\\') === false) return $str;
        return preg_replace(self::UNESCAPE_MD_RE, '$1', $str);
    }

    /**
     * @param string $match
     * @param string|null $name
     * @return string
     */
    public function replaceEntityPattern(string $match, ?string $name): string
    {
        if (isset($name)) {
            $e = html_entity_decode('&'.$name.';', ENT_HTML5|ENT_COMPAT);
            if( '&'.$name.';' != $e) return $e;
        }

        if ($name[0] === '#' && preg_match(self::DIGITAL_ENTITY_TEST_RE, $name)) {
            $code = strtolower($name[1]) === 'x' ?
                intval(substr($name,2), 16)
                :
                intval(substr($name,1), 10);
            if (self::isValidEntityCode($code)) {
                return self::fromCodePoint($code);
            }
        }

        return $match;
    }

    /**
     * @param string $str
     * @return string
     */
    public function unescapeAll(string $str): string
    {
        if (strpos($str, '\\') === false && strpos($str, '&') === false) { return $str; }

        return preg_replace_callback(self::UNESCAPE_ALL_RE, function ($match) {
                if ($match[1]) { return $match[1]; }
                return self::replaceEntityPattern($match[0], $match[2]);
            },$str);
    }


    /**
     * @param array $a
     * @param integer $size
     * @param mixed $val
     */
    public function resizeArray(array &$a, int $size, $val=null)
    {
        $l = count($a);
        if( $size > $l ){
            $size -= $l;
            do{ $a[] = $val; } while(--$size > 0);
        }else if( $size < $l ){
            array_splice($a, $size);
        }
    }

    //
    //
    /**
     * Merge objects
     * @param stdClass $obj
     * @param mixed ...$args
     * @return stdClass|array
     * @throws Exception
     */
    public function assign(stdClass $obj, ...$args)
    {
        if(is_object($obj)){
            foreach($args as &$source){
                if (!isset($source)) continue;

                if ( !is_object($source) ) {
                    throw new Exception($source . 'must be object');
                }
                foreach($source as $key=>&$val){
                    $obj->{$key} = $source->{$key};
                }
            }
        }else{
            throw new Exception("Utlis::assign() parameter 1 by not object");
        }

        return $obj;
    }

    /**
     * Remove element from array and put another array at those position.
     * Useful for some operations with tokens
     * @param array $src
     * @param integer $pos
     * @param array $newElements
     * @return array
     */
    public function arrayReplaceAt(array $src, int $pos, array $newElements): array
    {
        if(empty($newElements))$newElements=[];
        return array_merge(array_slice($src, 0, $pos), $newElements, array_slice($src, $pos + 1));
    }

    /**
     * @param int[]|string[] ...$args intrger|string array
     * @return string
     */
    public function fromCharCode(...$args): string
    {
        $output = '';
        foreach($args as $char){
            if(is_string($char)){
                $output .= $char;
            }else if(is_int($char)){
                $output .= chr($char);
            }
        }
        return $output;
    }

    /**
     * @param integer $c
     * @return bool
     */
    public function isValidEntityCode($c): bool
    {
        /*eslint no-bitwise:0*/
        // broken sequence
        if ($c >= 0xD800 && $c <= 0xDFFF) { return false; }
        // never used
        if ($c >= 0xFDD0 && $c <= 0xFDEF) { return false; }
        if (($c & 0xFFFF) === 0xFFFF || ($c & 0xFFFF) === 0xFFFE) { return false; }
        // control codes
        if ($c >= 0x00 && $c <= 0x08) { return false; }
        if ($c === 0x0B) { return false; }
        if ($c >= 0x0E && $c <= 0x1F) { return false; }
        if ($c >= 0x7F && $c <= 0x9F) { return false; }
        // out of range
        if ($c > 0x10FFFF) { return false; }
        return true;
    }

    /**
     * UTF16 -> UTF8
     * @param integer $c
     * @return string
     */
    public function fromCodePoint(int $c): string
    {
        if ($c < 0x7F) // U+0000-U+007F - 1 byte
            return chr($c);
        if ($c < 0x7FF) // U+0080-U+07FF - 2 bytes
            return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
        if ($c < 0xFFFF) // U+0800-U+FFFF - 3 bytes
            return chr(0xE0 | ($c >> 12)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
        // U+010000-U+10FFFF - 4 bytes
        return chr(0xF0 | ($c >> 18)) . chr(0x80 | ($c >> 12) & 0x3F) .chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
    }

    /**
     * @param int $n
     * @return int
     */
    public function getByteCountUtf8(int $n): int
    {
        if (0 <= $n && $n <= 0x7F) {
            return 1;
        }
        else if (0xC2 <= $n && $n <= 0xDF) {
            return 2;
        }
        else if (0xE0 <= $n && $n <= 0xEF) {
            return 3;
        }
        else if (0xF0 <= $n && $n <= 0xF7) {
            return 4;
        }
        else if(0xF8 <= $n && $n <= 0xFB ){
            return 5;
        }
        else if(0xFC <= $n && $n <= 0xFD ){
            return 6;
        }
        return 0;
    }
    /**
     * Acquire the character one last the $pos position.
     * simple and easy utf8 check.
     * @param string $text Only string variable
     * @param integer $pos The current starting position.
     * @param integer $outOffset If the last character is found, its position is substituted.
     * If not found, -1 is substituted.
     * @return string If found, it returns the last. If you can not find, it returns an empty('').
     */
    public function lastCharUTF8(string &$text, int $pos, int &$outOffset): string
    {
        $max = strlen($text);
        $limit = min(max($pos, 0), $max);

        if ($limit === 0) {
            $outOffset = -1;
            return '';
        }

        $idx = $limit - 1;
        while ($idx > 0 && (ord($text[$idx]) & 0xC0) === 0x80) {
            $idx--;
        }

        $len = $this->getByteCountUtf8(ord($text[$idx]));
        if ($len < 1 || $idx + $len > $limit) {
            $len = 1;
        }

        $outOffset = $idx;
        return substr($text, $idx, $len);
    }

    /**
     * Get the character at the current position '$pos'.
     * simple and easy utf8 check.
     * @param string $text Only string variable
     * @param integer $pos The current starting position.
     * @param null|int $outLen The length of the string is substituted.
     * If not found, -1 is substituted.
     * @return If found, Returns a 1 character.
     */
    public function currentCharUTF8(string &$text, int $pos, ?int &$outLen): string
    {
        $max = strlen($text);
        if ($pos < 0 || $pos >= $max) {
            $outLen = 0;
            return '';
        }

        $len = $this->getByteCountUtf8(ord($text[$pos]));
        if ($len < 1 || $pos + $len > $max) {
            $outLen = 1;
            return $text[$pos];
        }

        for ($i = 1; $i < $len; $i++) {
            if ((ord($text[$pos + $i]) & 0xC0) !== 0x80) {
                $outLen = 1;
                return $text[$pos];
            }
        }

        $outLen = $len;
        return substr($text, $pos, $len);
    }


    const REGEXP_ESCAPE_RE = "/[.?*+^$[\]\\(){}|-]/"; // /g

    /**
     * @param string $str
     * @return string|null
     * If matches are found, the new $str will be returned, otherwise $str will be returned unchanged or NULL if an error occurred.
     */
    public function escapeRE(string $str): ?string
    {
        return preg_replace(self::REGEXP_ESCAPE_RE, '\\\\$0', $str);
    }


    /**
     * @param string $ch
     * @return bool
     */
    public function isSpace(string $ch): bool
    {
        return $ch == "\t" || $ch == ' ';
    }

    /**
     * Zs (unicode class) || [\t\f\v\r\n]
     * @param string $code
     * @return bool
     */
    public function isWhiteSpace(string $code): bool
    {
        return preg_match("/\p{Zs}|[\t\f\v\r\n]/u", $code) === 1;
    }


    /*eslint-disable max-len*/
    // https://github.com/markdown-it/uc.micro/tree/2.1.0?tab=readme-ov-file
    const UNICODE_PUNCT = "\p{P}|\p{Pc}\p{Pd}|\p{Pe}|\p{Pf}|\p{Pi}|\p{Po}|\p{Ps}";
    const UNICODE_PUNCT_RE = "/".self::UNICODE_PUNCT."/u";
    const UNICODE_SYMBOL = "\p{S}|\p{Sc}|\p{Sk}|\p{Sm}|\p{So}";
    const UNICODE_SYMBOL_RE = "/".self::UNICODE_SYMBOL."/u";

    /**
     * Currently without astral characters support.
     * @param string $ch
     * @return int
     */
    public function isPunctChar(string $ch): int
    {
        return preg_match(self::UNICODE_PUNCT_RE, $ch) || preg_match(self::UNICODE_SYMBOL_RE, $ch);
    }

    /**
     * Check a Unicode code point for punctuation or symbol classes.
     *
     * @param integer $code
     * @return bool
     */
    public function isPunctCharCode(int $code): bool
    {
        return (bool)$this->isPunctChar($this->fromCodePoint($code));
    }

    /**
     * Markdown ASCII punctuation characters.
     *
     * !, ", #, $, %, &, ', (, ), *, +, ,, -, ., /, :, ;, <, =, >, ?, @, [, \, ], ^, _, `, {, |, }, or ~
     * @see http://spec.commonmark.org/0.15/#ascii-punctuation-character
     *
     * Don't confuse with unicode punctuation !!! It lacks some chars in ascii range.
     *
     * @param string $c
     * @return bool
     */
    public function isMdAsciiPunct(string $c): bool
    {
        if (strlen($c) !== 1) { return false; }
        $code = ord($c);
        if(
            ( $code >= 0x21 /* ! */  && $code <= 0x2F /* / */) ||
            ( $code >= 0x3A /* : */  && $code <= 0x40 /* @ */) ||
            ( $code >= 0x5B /* [ */  && $code <= 0x60 /* ` */) ||
            ( $code >= 0x7B /* { */  && $code <= 0x7E /* ~ */)
        ) return true;
        return false;
    }

    /**
     * Trim only ASCII spaces, tabs and line endings.
     * Unicode spaces are significant in Markdown block content and must remain.
     *
     * @param string $str
     * @return string
     */
    public function asciiTrim(string $str): string
    {
        return preg_replace('/^[ \t\r\n]+|[ \t\r\n]+$/', '', $str) ?? $str;
    }

    // Hepler to unify [reference labels].
    //
    public function normalizeReference($str): string
    {
        // Trim and collapse whitespace
        //
        $str = mb_strtoupper( preg_replace("/\s+/", ' ', trim($str)) ); // /g

        // In node v10 'ẞ'.toLowerCase() === 'Ṿ', which is presumed to be a bug
        // fixed in v12 (couldn't find any details).
        //
        // So treat this one as a special case
        // (remove this when node v10 is no longer supported).
        //
        if (mb_strtoupper('ẞ') === 'Ṿ') {
            $str = mb_strtoupper( preg_replace("/ẞ/", 'ß', trim($str)) ); // /g
        }

        // .toLowerCase().toUpperCase() should get rid of all differences
        // between letter variants.
        //
        // Simple .toLowerCase() doesn't normalize 125 code points correctly,
        // and .toUpperCase doesn't normalize 6 of them (list of exceptions:
        // İ, ϴ, ẞ, Ω, K, Å - those are already uppercased, but have differently
        // uppercased versions).
        //
        // Here's an example showing how it happens. Lets take greek letter omega:
        // uppercase U+0398 (Θ), U+03f4 (ϴ) and lowercase U+03b8 (θ), U+03d1 (ϑ)
        //
        // Unicode entries:
        // 0398;GREEK CAPITAL LETTER THETA;Lu;0;L;;;;;N;;;;03B8;
        // 03B8;GREEK SMALL LETTER THETA;Ll;0;L;;;;;N;;;0398;;0398
        // 03D1;GREEK THETA SYMBOL;Ll;0;L;<compat> 03B8;;;;N;GREEK SMALL LETTER SCRIPT THETA;;0398;;0398
        // 03F4;GREEK CAPITAL THETA SYMBOL;Lu;0;L;<compat> 0398;;;;N;;;;03B8;
        //
        // Case-insensitive comparison should treat all of them as equivalent.
        //
        // But .toLowerCase() doesn't change ϑ (it's already lowercase),
        // and .toUpperCase() doesn't change ϴ (already uppercase).
        //
        // Applying first lower then upper case normalizes any character:
        // '\u0398\u03f4\u03b8\u03d1'.toLowerCase().toUpperCase() === '\u0398\u0398\u0398\u0398'
        //
        // Note: this is equivalent to unicode case folding; unicode normalization
        // is a different step that is not required here.
        //
        // Final result should be uppercased, because it's later stored in an object
        // (this avoid a conflict with Object.prototype members,
        // most notably, `__proto__`)
        //
        return mb_strtoupper(mb_strtolower($str));
    }
}