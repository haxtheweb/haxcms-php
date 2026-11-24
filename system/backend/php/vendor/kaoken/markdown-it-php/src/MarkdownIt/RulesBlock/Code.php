<?php
// Code block (4 spaces padded)

namespace Kaoken\MarkdownIt\RulesBlock;


class Code
{
    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param boolean $silent
     * @return bool
     */
    public function set(StateBlock &$state, int $startLine, int $endLine, bool $silent=false): bool
    {
        if ($state->sCount[$startLine] - $state->blkIndent < 4) { return false; }

        $last = $nextLine = $startLine + 1;

        while ($nextLine < $endLine) {
            if ($state->isEmpty($nextLine)) {
                $nextLine++;
                continue;
            }

            if ($state->sCount[$nextLine] - $state->blkIndent >= 4) {
                $nextLine++;
                $last = $nextLine;
                continue;
            }
            break;
        }

        $state->line = $last;

        $token         = $state->push('code_block', 'code', 0);
        $token->content = $state->getLines($startLine, $last, 4 + $state->blkIndent, false) . "\n";
        $token->map     = [ $startLine, $state->line ];

        return true;
    }
}