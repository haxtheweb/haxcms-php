<?php
// Parse backticks

namespace Kaoken\MarkdownIt\RulesInline;

use Kaoken\MarkdownIt\Common\Utils;

class Backticks
{
    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function backticks(StateInline &$state, bool $silent): bool
    {
        $pos = $state->pos;
        $ch = $state->src[$pos];

        if ($ch !== '`') { return false; }

        $start = $pos;
        $pos++;
        $max = $state->posMax;

        // scan marker length
        while ($pos < $max && $state->src[$pos] === '`') { $pos++; }

        $marker = substr($state->src, $start, $pos-$start);
        $openerLength = strlen($marker);



        if ($state->backticksScanned && ($state->backticks[$openerLength] ?? 0) <= $start) {
            if (!$silent) $state->pending .= $marker;
            $state->pos += $openerLength;
            return true;
        }

        $matchEnd = $pos;

        // Nothing found in the cache, scan until the end of the line (or until marker is found)
        while (($matchStart = strpos($state->src, '`', $matchEnd)) !== false) {
            $matchEnd = $matchStart + 1;

            // scan marker length
            while ($matchEnd < $max && $state->src[$matchEnd] === '`') { $matchEnd++; }

            $closerLength = $matchEnd - $matchStart;

            if ($closerLength === $openerLength) {
                // Found matching closer length.
                if (!$silent) {
                    $token          = $state->push('code_inline', 'code', 0);
                    $token->markup  = $marker;
                    $token->content = preg_replace("/\n/", ' ', substr($state->src, $pos, $matchStart-$pos)); // /g
                    $token->content = preg_replace("/^ (.+) $/", "$1", $token->content); // /g
                }
                $state->pos = $matchEnd;
                return true;
            }
            // Some different length found, put it in cache as upper limit of where closer can be found
            $state->backticks[$closerLength] = $matchStart;
        }

        // Scanned through the end, didn't find anything
        $state->backticksScanned = true;

        if (!$silent) $state->pending .= $marker;
        $state->pos += $openerLength;
        return true;
    }
}