<?php
// Process *this* and _that_
//

namespace Kaoken\MarkdownIt\RulesInline;


use Exception;
use Kaoken\MarkdownIt\Common\ArrayObj;

class Emphasis
{
    /**
     * Insert each $marker as a separate text $token, and add it to delimiter list
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     * @throws Exception
     */
    public function tokenize(StateInline &$state, bool $silent=false): bool
    {
        $start = $state->pos;
        $marker = $state->src[$start];

        if ($silent) { return false; }

        if ($marker !== '_' && $marker !== '*') { return false; }

        $scanned = $state->scanDelims($state->pos, $marker === '*');

        for ($i = 0; $i < $scanned->length; $i++) {
            $token          = $state->push('text', '', 0);
            $token->content = $marker;

            $state->delimiters[] = (object)[
                // Char code of the starting $marker (number)->
                //
                "marker" => $marker,

                // Total length of these series of $delimiters->
                //
                "length" => $scanned->length,

                // A position of the $token this delimiter corresponds to->
                //
                "token" =>  count($state->tokens) - 1,

                // If this delimiter is matched as a valid opener, `end` will be
                // equal to its position, otherwise it's `-1`->
                //
                "end" =>    -1,

                // Boolean flags that determine if this delimiter could open or close
                // an emphasis->
                //
                "open" =>   $scanned->can_open,
                "close" =>  $scanned->can_close
            ];
        }

        $state->pos += $scanned->length;

        return true;
    }

    /**
     *
     * @param StateInline $state
     * @param ArrayObj $delimiters
     */
    private function emphasis(StateInline &$state, ArrayObj &$delimiters)
    {
        $max = $delimiters->length();

        for ($i = $max - 1; $i >= 0; $i--) {
            $startDelim = $delimiters[$i];

            if ($startDelim->marker !== '_' && $startDelim->marker !== '*') {
                continue;
            }

            // Process only opening markers
            if ($startDelim->end === -1) {
                continue;
            }

            $endDelim = $delimiters[$startDelim->end];

            // If the previous delimiter has the same marker and is adjacent to this one,
            // merge those into one strong delimiter->
            //
            // `<em><em>whatever</em></em>` -> `<strong>whatever</strong>`
            //
            $isStrong = $i > 0 &&
                $delimiters[$i - 1]->end === $startDelim->end + 1 &&
                // check that first two markers match and adjacent
                $delimiters[$i - 1]->marker === $startDelim->marker &&
                $delimiters[$i - 1]->token === $startDelim->token - 1 &&
                // check that last two markers are adjacent (we can safely assume they match)
                $delimiters[$startDelim->end + 1]->token === $endDelim->token + 1;

            $ch = $startDelim->marker;

            $token_o            = &$state->tokens[$startDelim->token];
            $token_o->type      = $isStrong ? 'strong_open' : 'em_open';
            $token_o->tag       = $isStrong ? 'strong' : 'em';
            $token_o->nesting   = 1;
            $token_o->markup    = $isStrong ? $ch . $ch : $ch;
            $token_o->content   = '';

            $token_c            = &$state->tokens[$endDelim->token];
            $token_c->type      = $isStrong ? 'strong_close' : 'em_close';
            $token_c->tag       = $isStrong ? 'strong' : 'em';
            $token_c->nesting   = -1;
            $token_c->markup    = $isStrong ? $ch . $ch : $ch;
            $token_c->content   = '';

            if ($isStrong) {
                $state->tokens[$delimiters[$i - 1]->token]->content = '';
                $state->tokens[$delimiters[$startDelim->end + 1]->token]->content = '';
                $i--;
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

        $this->emphasis($state, $state->delimiters);

        for ($curr = 0; $curr < $max; $curr++) {
            if (!is_null($tokens_meta[$curr])  && !is_null($tokens_meta[$curr]->delimiters)) {
                $this->emphasis($state, $tokens_meta[$curr]->delimiters);
            }
        }
    }
}