<?php
// ~~strike through~~
//

namespace Kaoken\MarkdownIt\RulesInline;


use Exception;
use Kaoken\MarkdownIt\Common\ArrayObj;

class Strikethrough
{
    /**
     * Insert each marker as a separate text $token, and add it to delimiter list
     * @param StateInline $state
     * @param bool $silent
     * @return bool
     * @throws Exception
     */
    public function tokenize(StateInline &$state, $silent=false): bool
    {
        $marker = $state->src[$state->pos];

        if ($silent) { return false; }

        if ($marker !== '~') { return false; }

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
            $token->content= $ch . $ch;

            $obj = new \stdClass();
            $obj->marker= $marker;
            $obj->length=  0; // disable "rule of 3" length checks meant for emphasis
            $obj->token =  count($state->tokens) - 1;
            $obj->end   =  -1;
            $obj->open  =  $scanned->can_open;
            $obj->close =  $scanned->can_close;

            $state->delimiters[] = $obj;
        }

        $state->pos += $scanned->length;

        return true;
    }

    /**
     * @param StateInline $state
     * @param ArrayObj $delimiters
     */
    private function strikethrough(StateInline &$state, ArrayObj &$delimiters)
    {
        $loneMarkers = [];
        $max = $delimiters->length();

        for ($i = 0; $i < $max; $i++) {
            $startDelim = $delimiters[$i];

            if ($startDelim->marker !== '~') {
                continue;
            }

            if ($startDelim->end === -1) {
                continue;
            }

            $endDelim = $delimiters[$startDelim->end];

            $token          = &$state->tokens[$startDelim->token];
            $token->type    = 's_open';
            $token->tag     = 's';
            $token->nesting = 1;
            $token->markup  = '~~';
            $token->content = '';

            $token          = &$state->tokens[$endDelim->token];
            $token->type    = 's_close';
            $token->tag     = 's';
            $token->nesting = -1;
            $token->markup  = '~~';
            $token->content = '';

            if ($state->tokens[$endDelim->token - 1]->type === 'text' &&
                $state->tokens[$endDelim->token - 1]->content === '~') {

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

            while ($j < count($state->tokens) && $state->tokens[$j]->type === 's_close') {
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
    public function postProcess(StateInline &$state) {
        $tokens_meta= &$state->tokens_meta;
        $max        = $state->tokens_meta->length();

        $this->strikethrough($state, $state->delimiters);

        for ($curr = 0; $curr < $max; $curr++) {
            if (!is_null($tokens_meta[$curr]) && !is_null($tokens_meta[$curr]->delimiters)) {
                $this->strikethrough($state, $tokens_meta[$curr]->delimiters);
            }
        }
    }
}