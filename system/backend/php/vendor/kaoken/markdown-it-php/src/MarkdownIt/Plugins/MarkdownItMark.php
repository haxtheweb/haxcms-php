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
 * use javascript version 4.0.0
 * @see https://github.com/markdown-it/markdown-it-mark/tree/4.0.0
 */

namespace Kaoken\MarkdownIt\Plugins;


use Exception;
use Kaoken\MarkdownIt\Common\ArrayObj;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\RulesInline\StateInline;
use stdClass;

class MarkdownItMark
{

    /**
     * @param MarkdownIt $md
     * @throws Exception
     */
    public function plugin(MarkdownIt $md)
    {
        $md->inline->ruler->before('emphasis', 'mark', [$this, 'tokenize']);
        $md->inline->ruler2->before('emphasis', 'mark', [$this, 'postProcess']);
    }
    // Insert each marker as a separate text token, and add it to delimiter list
    //
    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     * @throws Exception
     */
    public function tokenize(StateInline $state, $silent=false): bool
    {
        $start = $state->pos;
        $marker = $state->src[$start];

        if ($silent) { return false; }

        if ($marker !== '=') { return false; }

        $scanned = $state->scanDelims($state->pos, true);
        $len = $scanned->length;
        $ch = $marker;

        if ($len < 2) { return false; }

        if ($len % 2) {
            $token         = $state->push('text', '', 0);
            $token->content = $ch;
            $len--;
        }

        for ($i = 0; $i < $len; $i += 2) {
            $token         = $state->push('text', '', 0);
            $token->content = $ch . $ch;

            if (!$scanned->can_open && !$scanned->can_close) { continue; }

            $obj = new stdClass();
            $obj->marker    = $marker;
            $obj->length    = 0; // disable "rule of 3" length checks meant for emphasis
            $obj->jump      = $i / 2; // 1 delimiter = 2 characters
            $obj->token     = count($state->tokens) - 1;
            $obj->level     =  $state->level;
            $obj->end       =  -1;
            $obj->open      = $scanned->can_open;
            $obj->close     = $scanned->can_close;
            $state->delimiters[] = $obj;
        }

        $state->pos += $scanned->length;

        return true;
    }


    /**
     * Walk through delimiter list and replace text tokens with tags
     * @param StateInline $state
     * @param ArrayObj $delimiters
     */
    private function mark(StateInline &$state, ArrayObj &$delimiters)
    {
        $loneMarkers = [];
        $max = $delimiters->length();

        for ($i = 0; $i < $max; $i++) {
            $startDelim = $delimiters[$i];

            if ($startDelim->marker !== '=') {
                continue;
            }

            if ($startDelim->end === -1) {
                continue;
            }

            $endDelim = $delimiters[$startDelim->end];

            $token          = $state->tokens[$startDelim->token];
            $token->type    = 'mark_open';
            $token->tag     = 'mark';
            $token->nesting = 1;
            $token->markup  = '==';
            $token->content = '';

            $token         = $state->tokens[$endDelim->token];
            $token->type    = 'mark_close';
            $token->tag     = 'mark';
            $token->nesting = -1;
            $token->markup  = '==';
            $token->content = '';

            if ($state->tokens[$endDelim->token - 1]->type === 'text' &&
                $state->tokens[$endDelim->token - 1]->content === '=') {

                $loneMarkers[] = $endDelim->token - 1;
            }
        }

        // If a marker sequence has an odd number of characters, it's splitted
        // like this: `~~~~~` -> `~` + `~~` + `~~`, leaving one marker at the
        // start of the sequence.
        //
        // So, we have to move all those markers after subsequent s_close tags.
        //
        while (count($loneMarkers)) {
            $i = array_pop($loneMarkers);
            $j = $i + 1;

            while ($j < count($state->tokens) && $state->tokens[$j]->type === 'mark_close') {
                $j++;
            }

            $j--;

            if ($i !== $j) {
                $token = $state->tokens[$j];
                $state->tokens[$j] = $state->tokens[$i];
                $state->tokens[$i] = $token;
            }
        }
    }


    /**
     * Walk through delimiter list and replace text tokens with tags
     * @param StateInline $state
     */
    public function postProcess(StateInline &$state)
    {
        $tokens_meta    = &$state->tokens_meta;
        $max            = $state->tokens_meta->length();

        $this->mark($state, $state->delimiters);

        for ($curr = 0; $curr < $max; $curr++) {
            if (!is_null($tokens_meta[$curr])  && !is_null($tokens_meta[$curr]->delimiters)) {
                $this->mark($state, $tokens_meta[$curr]->delimiters);
            }
        }
    }
}