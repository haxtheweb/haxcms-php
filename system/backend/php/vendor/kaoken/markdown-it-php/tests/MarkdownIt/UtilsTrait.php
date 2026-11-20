<?php
/**
 *
 * @see https://github.com/markdown-it/markdown-it/blob/master/test/utils.js
 */

namespace Kaoken\Test\MarkdownIt;

use Kaoken\MarkdownIt\Common\Utils as UtilCommon;

trait UtilsTrait
{

    private function utils()
    {
        $this->group('Utils',function($g){
            $this->utilsUtils($g);
        });
    }

    /**
     * @param \Kaoken\Test\GroupTest $g
     */
    private function utilsUtils($g)
    {
        $u = new UtilCommon();
        
        $g->group('fromCodePoint', function ($gg) use($u){
            $gg->strictEqual($u->fromCodePoint(0x20), ' ');
            $gg->strictEqual($u->fromCodePoint(0x1F601), '😁');
        });
        //------------------------------------------------------
        $g->group('isValidEntityCode', function ($gg) use($u) {
    
            $gg->strictEqual($u->isValidEntityCode(0x20), true);
            $gg->strictEqual($u->isValidEntityCode(0xD800), false);
            $gg->strictEqual($u->isValidEntityCode(0xFDD0), false);
            $gg->strictEqual($u->isValidEntityCode(0x1FFFF), false);
            $gg->strictEqual($u->isValidEntityCode(0x1FFFE), false);
            $gg->strictEqual($u->isValidEntityCode(0x00), false);
            $gg->strictEqual($u->isValidEntityCode(0x0B), false);
            $gg->strictEqual($u->isValidEntityCode(0x0E), false);
            $gg->strictEqual($u->isValidEntityCode(0x7F), false);
        });
        //------------------------------------------------------
        /*$g->group('replaceEntities', function ($gg) use($u) {
            $gg->strictEqual($u->replaceEntities('&amp;'), '&');
            $gg->strictEqual($u->replaceEntities('&#32;'), ' ');
            $gg->strictEqual($u->replaceEntities('&#x20;'), ' ');
            $gg->strictEqual($u->replaceEntities('&amp;&amp;'), '&&');
    
            $gg->strictEqual($u->replaceEntities('&am;'), '&am;');
            $gg->strictEqual($u->replaceEntities('&#00;'), '&#00;');
        });*/
        //------------------------------------------------------
        $g->group('assign', function ($gg) use($u) {
            $gg->deepEqual($u->assign((object)["a"=>1], null, (object)["b"=>2]), (object)["a"=>1, "b"=>2]);
            $gg->throws(function ()  use($u){
                $u->assign((object)[], 123);
            });
        });
        //------------------------------------------------------
        $g->group('escapeRE', function ($gg) use($u) {
            $gg->strictEqual($u->escapeRE(" .?*+^$[]\\\\(){}|-"), " \\.\\?\\*\\+\\^\\$\\[\\]\\\\\\(\\)\\{\\}\\|\\-" );
        });
        //------------------------------------------------------
        $g->group('isWhiteSpace', function ($gg) use($u) {
            $gg->strictEqual($u->isWhiteSpace("\x20\x00"), true);
            $gg->strictEqual($u->isWhiteSpace(chr(0x09)), true);
            $gg->strictEqual($u->isWhiteSpace(chr(0x30)), false);
        });
        //------------------------------------------------------
        $g->group('isMdAsciiPunct', function ($gg) use($u) {
            $gg->strictEqual($u->isMdAsciiPunct(chr(0x30)), false);

            foreach(str_split("!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~") as $val ){
                $gg->strictEqual($u->isMdAsciiPunct($val), true);
            }
        });
        //------------------------------------------------------
        $g->group('unescapeMd', function ($gg) use($u) {
            $gg->strictEqual($u->unescapeMd("\\foo"), "\\foo");
            $gg->strictEqual($u->unescapeMd('foo'), 'foo');

            foreach(str_split("!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~") as $ch ){
                $gg->strictEqual($u->unescapeMd("\\" . $ch), $ch);
            }
        });
    }
}