<?php
// lheading (---, ===)

namespace Kaoken\MarkdownIt\RulesBlock;

use Kaoken\MarkdownIt\Common\Utils;

class LHeading
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
        $terminatorRules = $state->md->block->ruler->getRules('paragraph');

        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$startLine] - $state->blkIndent >= 4) { return false; }

        $oldParentType = $state->parentType;
        $state->parentType = 'paragraph'; // use paragraph to match $terminatorRules

        // jump line-by-line until empty one or EOF
        $level = 0;
        $nextLine = $startLine + 1;
        for (; $nextLine < $endLine && !$state->isEmpty($nextLine); $nextLine++) {
            // this would be a code block normally, but after paragraph
            // it's considered a lazy continuation regardless of what's there
            if ($state->sCount[$nextLine] - $state->blkIndent > 3) { continue; }

            //
            // Check for underline in setext header
            //
            if ($state->sCount[$nextLine] >= $state->blkIndent) {
                $pos = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
                $max = $state->eMarks[$nextLine];

                if ($pos < $max) {
                    $marker = $state->src[$pos];

                    if ($marker === '-' || $marker === '=') {
                        $pos = $state->skipChars($pos, $marker);
                        $pos = $state->skipSpaces($pos);

                        if ($pos >= $max) {
                            $level = ($marker === '=' ? 1 : 2);
                            break;
                        }
                    }
                }
            }

            // quirk for blockquotes, this line should already be checked by that rule
            if ($state->sCount[$nextLine] < 0) { continue; }

            // Some tags can $terminate paragraph without empty line.
            $terminate = false;
            foreach( $terminatorRules as &$rule ){
                if( is_array($rule) ){
                    if ($rule[0]->{$rule[1]}($state, $nextLine, $endLine, true)) {
                        $terminate = true;
                        break;
                    }
                }else if( is_callable($rule) ){
                    if ($rule($state, $nextLine, $endLine, true)) {
                        $terminate = true;
                        break;
                    }
                }
            }
            if ($terminate) { break; }
        }

        if (!$level) {
            // Didn't find valid underline
            $state->parentType = $oldParentType;
            return false;
        }

        $content = $state->md->utils->asciiTrim($state->getLines($startLine, $nextLine, $state->blkIndent, false));

        $state->line = $nextLine + 1;

        $token_o            = $state->push('heading_open', 'h' . $level, 1);
        $token_o->markup    = $marker;
        $token_o->map       = [ $startLine, $state->line ];

        $token_i            = $state->push('inline', '', 0);
        $token_i->content   = $content;
        $token_i->map       = [ $startLine, $state->line - 1 ];
        $token_i->children  = [];

        $token_c            = $state->push('heading_close', 'h' . $level, -1);
        $token_c->markup    = $marker;

        $state->parentType = $oldParentType;

        return true;
    }
}