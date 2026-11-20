<?php
// Block quotes

namespace Kaoken\MarkdownIt\RulesBlock;

use Exception;
use Kaoken\MarkdownIt\Common\Utils;

class BlockQuote
{
    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param boolean $silent
     * @return bool
     * @throws Exception
     */
    public function set(StateBlock &$state, int $startLine, int $endLine, $silent=false): bool
    {
        $oldLineMax = $state->lineMax;
        $pos = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $max = $state->eMarks[$startLine];

        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$startLine] - $state->blkIndent >= 4) { return false; }

        // check the block quote marker
        if ($state->src[$pos] !== '>') { return false; }

        // we know that it's going to be a valid blockquote,
        // so no point trying to find the end of it in $silent mode
        if ($silent) { return true; }

        $oldBMarks  = [];
        $oldBSCount = [];
        $oldSCount  = [];
        $oldTShift  = [];

        $terminatorRules = $state->md->block->ruler->getRules('blockquote');

        $oldParentType = $state->parentType;
        $state->parentType = 'blockquote';
        $lastLineEmpty = false;

        // Search the end of the block
        //
        // Block ends with either:
        //  1. an empty line outside:
        //     ```
        //     > test
        //
        //     ```
        //  2. an empty line inside:
        //     ```
        //     >
        //     test
        //     ```
        //  3. another tag
        //     ```
        //     > test
        //      - - -
        //     ```
        for ($nextLine = $startLine; $nextLine < $endLine; $nextLine++) {
            // check if it's outdented, i.e. it's inside list item and indented
            // less than said list item:
            //
            // ```
            // 1. anything
            //    > current blockquote
            // 2. checking this line
            // ```
            $isOutdented = $state->sCount[$nextLine] < $state->blkIndent;

            $pos = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
            $max = $state->eMarks[$nextLine];

            if ($pos >= $max) {
                // Case 1: line is not inside the blockquote, and this line is empty.
                break;
            }
            if ($state->src[$pos++]=== '>' && !$isOutdented) {
                // This line is inside the blockquote.

                // set offset past spaces and ">"
                $initial = $state->sCount[$nextLine] + 1;

                // skip one optional space after '>'
                if ($state->src[$pos] === ' ') {
                    // ' >   test '
                    //     ^ -- position start of line here:
                    $pos++;
                    $initial++;
                    $adjustTab = false;
                    $spaceAfterMarker = true;
                } else if ($state->src[$pos] === "\t") {
                    $spaceAfterMarker = true;

                    if (($state->bsCount[$nextLine] + $initial) % 4 === 3) {
                        // '  >\t  test '
                        //       ^ -- position start of line here (tab has width===1)
                        $pos++;
                        $initial++;
                        $adjustTab = false;
                    } else {
                        // ' >\t  test '
                        //    ^ -- position start of line here + shift bsCount slightly
                        //         to make extra space appear
                        $adjustTab = true;
                    }
                } else {
                    $spaceAfterMarker = false;
                }

                $offset = $initial;
                $oldBMarks[] = $state->bMarks[$nextLine];
                $state->bMarks[$nextLine] = $pos;

                while ($pos < $max) {
                    $ch = $state->src[$pos];

                    if ($state->md->utils->isSpace($ch)) {
                        if ($ch === "\t") {
                            $offset += 4 - ($offset + $state->bsCount[$nextLine] + ($adjustTab ? 1 : 0)) % 4;
                        } else {
                            $offset++;
                        }
                    } else {
                        break;
                    }

                    $pos++;
                }

                $lastLineEmpty = $pos >= $max;

                $oldBSCount[] = $state->bsCount[$nextLine];
                $state->bsCount[$nextLine] = $state->sCount[$nextLine] + 1 + ($spaceAfterMarker ? 1 : 0);

                $oldSCount[] = $state->sCount[$nextLine];
                $state->sCount[$nextLine] = $offset - $initial;

                $oldTShift[] = $state->tShift[$nextLine];
                $state->tShift[$nextLine] = $pos - $state->bMarks[$nextLine];
                continue;
            }

            // Case 2: line is not inside the blockquote, and the last line was empty.
            if ($lastLineEmpty) { break; }

            // Case 3: another tag found.
            $terminate = false;
            foreach ($terminatorRules as &$val) {
                $ret = false;
                if( is_callable($val)) $ret = $val($state, $nextLine, $endLine, true);
                else if( is_array($val)) $ret = $val[0]->{$val[1]}($state, $nextLine, $endLine, true);

                if ($ret) {
                    $terminate = true;
                    break;
                }
            }

            if ($terminate) {
                // Quirk to enforce "hard termination mode" for paragraphs;
                // normally if you call `tokenize(state, startLine, nextLine)`,
                // paragraphs will look below nextLine for paragraph continuation,
                // but if blockquote is terminated by another tag, they shouldn't
                $state->lineMax = $nextLine;

                if ($state->blkIndent !== 0) {
                    // state.blkIndent was non-zero, we now set it to zero,
                    // so we need to re-calculate all offsets to appear as
                    // if indent wasn't changed
                    $oldBMarks[] = $state->bMarks[$nextLine];
                    $oldBSCount[] = $state->bsCount[$nextLine];
                    $oldTShift[] = $state->tShift[$nextLine];
                    $oldSCount[] = $state->sCount[$nextLine];
                    $state->sCount[$nextLine] -= $state->blkIndent;
                }

                break;
            }

            $oldBMarks[] = $state->bMarks[$nextLine];
            $oldBSCount[] = $state->bsCount[$nextLine];
            $oldTShift[] = $state->tShift[$nextLine];
            $oldSCount[] = $state->sCount[$nextLine];

            // A negative indentation means that this is a paragraph continuation
            //
            $state->sCount[$nextLine] = -1;
        }

        $oldIndent = $state->blkIndent;
        $state->blkIndent = 0;

        $token_o        = $state->push('blockquote_open', 'blockquote', 1);
        $token_o->markup = '>';
        $token_o->map    = $lines = [ $startLine, 0 ];

        $state->md->block->tokenize($state, $startLine, $nextLine);

        $token_c        = $state->push('blockquote_close', 'blockquote', -1);
        $token_c->markup = '>';

        $state->lineMax = $oldLineMax;
        $state->parentType = $oldParentType;
        $lines[1] = $state->line;

        // Restore original tShift; this might not be necessary since the parser
        // has already been here, but just to make sure we can do that.
        for ($i = 0; $i < count($oldTShift); $i++) {
            $state->bMarks[$i + $startLine] = $oldBMarks[$i];
            $state->tShift[$i + $startLine] = $oldTShift[$i];
            $state->sCount[$i + $startLine] = $oldSCount[$i];
            $state->bsCount[$i + $startLine] = $oldBSCount[$i];
        }
        $state->blkIndent = $oldIndent;

        return true;
    }
}