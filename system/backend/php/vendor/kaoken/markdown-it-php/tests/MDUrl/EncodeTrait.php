<?php
namespace Kaoken\Test\MDUrl;

/**
 * @package Kaoken\Test\MDUrl
 * @property \Kaoken\Test\MDUrl\Fixtures\Url $dt
 * @property \Kaoken\MDUrl\MDUrl             $mdurl
 */
trait EncodeTrait
{
    private function encode()
    {
        $this->group("encode", function ($g){
            $g->group('should encode percent', function($gg) {
                $gg->strictEqual($this->mdurl->encode('%%%'), '%25%25%25');
            });

            $g->group('should encode control chars', function($gg) {
                $gg->strictEqual($this->mdurl->encode("\r\n"), '%0D%0A');
            });

            $g->group('should not encode parts of an url', function($gg) {
                $gg->strictEqual($this->mdurl->encode('?#'), '?#');
            });

            $g->group('should not encode []^ - commonmark tests', function($gg) {
                $gg->strictEqual($this->mdurl->encode('[]^'), '%5B%5D%5E');
            });

            $g->group('should encode spaces', function($gg) {
                $gg->strictEqual($this->mdurl->encode('my url'), 'my%20url');
            });

            $g->group('should encode unicode', function($gg) {
                $gg->strictEqual($this->mdurl->encode('φου'), '%CF%86%CE%BF%CF%85');
            });

            $g->group('should encode % if it doesn\'t start a valid escape seq', function($gg) {
                $gg->strictEqual($this->mdurl->encode('%FG'), '%25FG');
            });

            $g->group('should preserve non-utf8 encoded characters', function($gg) {
                $gg->strictEqual($this->mdurl->encode('%00%FF'), '%00%FF');
            });

            $g->group('should encode characters on the cache borders', function($gg) {
                // protects against off-by-one in cache implementation
                $gg->strictEqual($this->mdurl->encode(rawurldecode("%00%7F%C2%80")), '%00%7F%C2%80');
            });

            $g->group('arguments', function($gg) {
                $gg->group('encode(string, unescapedSet)', function($ggg) {
                    $ggg->strictEqual($this->mdurl->encode('!@#$', '@$'), '%21@%23$');
                });

                $gg->group('encode(string, keepEscaped=true)', function($ggg) {
                    $ggg->strictEqual($this->mdurl->encode('%20%2G', true), '%20%252G');
                });

                $gg->group('encode(string, keepEscaped=false)', function($ggg) {
                    $ggg->strictEqual($this->mdurl->encode('%20%2G', false), '%2520%252G');
                });

                $gg->group('encode(string, unescapedSet, keepEscaped)', function($ggg) {
                    $ggg->strictEqual($this->mdurl->encode('!@%25', '@', false), '%21@%2525');
                });
            });

            $g->group('surrogates', function($gg) {
                if( PHP_VERSION_ID >= 70000){
                    $gg->group('bad surrogates (high)', function($ggg) {
                        $ggg->strictEqual($this->mdurl->encode("\u{D800}foo"), '%ED%A0%80foo');
                        $ggg->strictEqual($this->mdurl->encode("foo\u{D800}"), 'foo%ED%A0%80');
                    });

                    $gg->group('bad surrogates (low)', function($ggg) {
                        $ggg->strictEqual($this->mdurl->encode("\u{DD00}foo"), '%ED%B4%80foo');
                        $ggg->strictEqual($this->mdurl->encode("foo\u{DD00}"), 'foo%ED%B4%80');
                    });

                    $gg->group('valid one', function($ggg) {
                        $ggg->strictEqual($this->mdurl->encode("\u{D800}\u{DD00}"), '%ED%A0%80%ED%B4%80');
                    });
                }else{
                    $gg->group('bad surrogates (high)', function($ggg) {

                        $ggg->strictEqual($this->mdurl->encode("\xED\xA0\x80foo"), '%ED%A0%80foo');
                        $ggg->strictEqual($this->mdurl->encode("foo\xED\xA0\x80"), 'foo%ED%A0%80');
                    });

                    $gg->group('bad surrogates (low)', function($ggg) {
                        $ggg->strictEqual($this->mdurl->encode("\xED\xB4\x80foo"), '%ED%B4%80foo');
                        $ggg->strictEqual($this->mdurl->encode("foo\xED\xB4\x80"), 'foo%ED%B4%80');
                    });

                    $gg->group('valid one', function($ggg) {
                        $ggg->strictEqual($this->mdurl->encode("\xED\xA0\x80\xED\xB4\x80"), '%ED%A0%80%ED%B4%80');
                    });
                }
            });
        });
    }
}