<?php
// Horizontal rule

namespace Kaoken\MarkdownIt\RulesBlock;

use Kaoken\MarkdownIt\Common\Utils;


class Hr
{
    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param boolean $silent
     * @return bool
     */
    public function set(StateBlock &$state, int $startLine, int $endLine, $silent=false): bool
    {
        $max = $state->eMarks[$startLine];
        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$startLine] - $state->blkIndent >= 4) { return false; }

        $pos = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $marker = $state->src[$pos++];

        // Check hr $marker
        if ($marker !== '*' &&
            $marker !== '-' &&
            $marker !== '_') {
            return false;
        }

        // markers can be mixed with spaces, but there should be at least 3 of them

        $cnt = 1;
        while ($pos < $max) {
            $ch = $state->src[$pos++];
            if ($ch !== $marker && !$state->md->utils->isSpace($ch)) { return false; }
            if ($ch === $marker) { $cnt++; }
        }

        if ($cnt < 3) { return false; }

        if ($silent) { return true; }

        $state->line = $startLine + 1;

        $token        = $state->push('hr', 'hr', 0);
        $token->map    = [ $startLine, $state->line ];
        $token->markup = str_repeat($marker, $cnt + 1);

        return true;
    }
}