<?php
/**
 * Copyright (c) 2014-2015 Vitaly Puzrin, Alex Kocharin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 * use javascript version 2.0.0
 * @see https://github.com/markdown-it/markdown-it-abbr/tree/2.0.0
 */
// Enclose abbreviations in <abbr> tags
//
namespace Kaoken\MarkdownIt\Plugins;

use Kaoken\MarkdownIt\Common\Utils;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\RulesBlock\StateBlock;
use Kaoken\MarkdownIt\RulesCore\StateCore;


class MarkdownItAbbr
{
    const OTHER_CHARS      = ' \r\n$+<=>^`|~';


    /**
     * @param MarkdownIt $md
     * @throws \Exception
     */
    public function plugin($md)
    {
        $md->block->ruler->before('reference', 'abbr_def', [$this, 'abbr_def'], [ 'alt' => [ 'paragraph', 'reference' ] ]);
        $md->core->ruler->after('linkify', 'abbr_replace', [$this, 'abbr_replace']);
    }

    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param bool $silent
     * @return bool
     */
    public function abbr_def(StateBlock $state, int $startLine, int $endLine, $silent=false): bool
    {
        $pos = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $max = $state->eMarks[$startLine];

        if ($pos + 2 >= $max) { return false; }

        if ($state->src[$pos++] !== '*') { return false; }
        if ($state->src[$pos++] !== '[') { return false; }

        $labelStart = $pos;

        $labelEnd = - 1;
        for (; $pos < $max; $pos++) {
            $ch = $state->src[$pos];
            if ($ch === '[') {
                return false;
            } else if ($ch === ']') {
                $labelEnd = $pos;
                break;
            } else if ($ch === '\\') {
                $pos++;
            }
        }

        if ( $labelEnd < 0 ) {
            return false;
        }else if($state->src[$labelEnd + 1] !== ':'){
            return false;
        }

        if ($silent) { return true; }

        $label = preg_replace("/\\\\(.)/", '$1', substr($state->src, $labelStart, $labelEnd-$labelStart));
        $title = trim(substr($state->src, $labelEnd + 2, $max-($labelEnd + 2)));
        if (strlen($label) === 0) { return false; }
        if (strlen($title) === 0) { return false; }
        if (!isset($state->env->abbreviations)) { $state->env->abbreviations = []; }
        // prepend ':' to avoid conflict with Object.prototype members
        if ( !isset($state->env->abbreviations[':' . $label]) ) {
            $state->env->abbreviations[':' . $label] = $title;
        }

        $state->line = $startLine + 1;
        return true;
    }


    /**
     * @param StateCore $state
     */
    public function abbr_replace(StateCore $state)
    {
        $blockTokens = &$state->tokens;

        if (!isset($state->env->abbreviations)) { return; }

        //------------------------------------
        $a = array_map(function ($x) {
                return substr($x, 1);
             }, array_keys($state->env->abbreviations));
            uasort($a, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $tmpReg = join('|', array_map(function($x) use(&$state) { return $state->md->utils->escapeRE($x); }, $a));
        $regSimple = '/(?:' . $tmpReg . ')/';
        unset($a);

        //------------------------------------
        $other = join('', array_map(function($x) use(&$state){return $state->md->utils->escapeRE($x);},str_split(self::OTHER_CHARS)));

        $reg =  '/(^|' . Utils::UNICODE_PUNCT . '|\p{Z}|[' . $other . '])';
        $reg .= '(' . $tmpReg . ')($|\p{P}|\p{Z}|[' . $other . '])/u';

        for ($j = 0, $l = count($blockTokens); $j < $l; $j++) {
            if ($blockTokens[$j]->type !== 'inline') { continue; }
            $tokens = &$blockTokens[$j]->children;

            // We scan from the end, to keep position when new tags added.
            for ($i = count($tokens) - 1; $i >= 0; $i--) {
                $currentToken = &$tokens[$i];
                if ($currentToken->type !== 'text') { continue; }

                $pos = 0;
                $text = $currentToken->content;
                $nodes = [];

                // fast regexp run to determine whether there are any abbreviated words
                // in the current $token
                if (!preg_match($regSimple, $text)) { continue; }

                while (preg_match_all($reg, $text, $m, PREG_SET_ORDER|PREG_OFFSET_CAPTURE, $pos)) {
                    $m = $m[0];
                    if ($m[0][1] > 0 || strlen($m[1][0]) > 0) {
                        $token         = $state->createToken('text', '', 0);
                        $token->content = substr($text, $pos, $m[0][1] + strlen($m[1][0]) - $pos);
                        $nodes[] = $token;
                    }

                    $token         = $state->createToken('abbr_open', 'abbr', 1);
                    $token->attrs   = [ [ 'title', $state->env->abbreviations[':' . $m[2][0]] ] ];
                    $nodes[] = $token;

                    $token         = $state->createToken('text', '', 0);
                    $token->content = $m[2][0];
                    $nodes[] = $token;

                    $token         = $state->createToken('abbr_close', 'abbr', -1);
                    $nodes[] = $token;

                    $pos = ($m[3][1]+1) - strlen($m[3][0]);
                }

                if (!count($nodes)) { continue; }

                if ($pos < strlen($text)) {
                    $token         = $state->createToken('text', '', 0);
                    $token->content = substr($text, $pos);
                    $nodes[] = $token;
                }

                // replace current node
                $tokens = $state->md->utils->arrayReplaceAt($tokens, $i, $nodes);
                $blockTokens[$j]->children = &$tokens;
            }
        }
    }
}