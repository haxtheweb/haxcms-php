<?php
namespace Kaoken\MarkdownIt\RulesBlock;


class Paragraph
{
    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param boolean $silent I do not use it
     * @return bool
     */
    public function set(StateBlock &$state, int $startLine, int $endLine, bool $silent=false): bool
    {
        $terminatorRules = $state->md->block->ruler->getRules('paragraph');

        $oldParentType = $state->parentType;
        $nextLine = $startLine + 1;
        $state->parentType = 'paragraph';

        // jump line-by-line until empty one or EOF
        for (; $nextLine < $endLine && !$state->isEmpty($nextLine); $nextLine++) {
            // this would be a code block normally, but after paragraph
            // it's considered a lazy continuation regardless of what's there
            if ($state->sCount[$nextLine] - $state->blkIndent > 3) { continue; }

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

        $content = $state->md->utils->asciiTrim($state->getLines($startLine, $nextLine, $state->blkIndent, false));

        $state->line = $nextLine;

        $token_o            = $state->push('paragraph_open', 'p', 1);
        $token_o->map       = [ $startLine, $state->line ];

        $token_i            = $state->push('inline', '', 0);
        $token_i->content   = $content;
        $token_i->map       = [ $startLine, $state->line ];
        $token_i->children  = [];

        $state->push('paragraph_close', 'p', -1);

        $state->parentType = $oldParentType;

        return true;
    }

}