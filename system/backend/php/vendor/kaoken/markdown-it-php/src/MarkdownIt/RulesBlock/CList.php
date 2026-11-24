<?php
// Lists

namespace Kaoken\MarkdownIt\RulesBlock;

use Kaoken\MarkdownIt\Common\Utils;

class CList
{
    /**
     * Search `[-+*][\n ]`, returns next pos after marker on success
     * or -1 on fail.
     * @param StateBlock $state
     * @param integer $startLine
     * @return int
     */
    protected function skipBulletListMarker(StateBlock &$state, int $startLine): int
    {
        $pos = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $max = $state->eMarks[$startLine];


        $marker = $state->src[$pos];
        $pos++;
        // Check bullet
        if ($marker !== '*' &&
            $marker !== '-' &&
            $marker !== '+') {
            return -1;
        }

        if ($pos < $max) {
            $ch = $state->src[$pos];

            if (!$state->md->utils->isSpace($ch)) {
                // " -test " - is not a list item
                return -1;
            }
        }

        return $pos;
    }


    /**
     * Search `\d+[.)][\n ]`, returns next pos after marker on success
     * or -1 on fail.
     * @param StateBlock $state
     * @param integer $startLine
     * @return int
     */
    protected function skipOrderedListMarker(StateBlock &$state, int $startLine): int
    {
        $start = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $pos = $start;
        $max = $state->eMarks[$startLine];

        // List marker should have at least 2 chars (digit + dot)
        if ($pos + 1 >= $max) { return -1; }

        $code = ord($state->src[$pos]);
        $pos++;

        if ( $code < 0x30/* 0 */ || $code > 0x39/* 9 */) { return -1; }

        while (true) {
            // EOL -> fail
            if ($pos >= $max) { return -1; }

            $code = ord($state->src[$pos]);
            $pos++;

            if ( $code >= 0x30/* 0 */ && $code <= 0x39/* 9 */ ) {

                // List $marker should have no more than 9 digits
                // (prevents integer overflow in browsers)
                if ($pos - $start >= 10) { return -1; }

                continue;
            }

            // found valid $marker
            if ($code === 0x29/* ) */ || $code === 0x2e/* . */) {
                break;
            }

            return -1;
        }


        if ($pos < $max) {
            $ch = $state->src[$pos];

            if (!$state->md->utils->isSpace($ch)) {
                // " 1.test " - is not a list item
                return -1;
            }
        }
        return $pos;
    }

    /**
     * @param StateBlock $state
     * @param integer $idx
     */
    protected function markTightParagraphs(StateBlock &$state, int $idx)
    {
        $level = $state->level + 2;

        for ($i = $idx + 2, $l = count($state->tokens) - 2; $i < $l; $i++) {
            if ($state->tokens[$i]->level === $level && $state->tokens[$i]->type === 'paragraph_open') {
                $state->tokens[$i + 2]->hidden = true;
                $state->tokens[$i]->hidden = true;
                $i += 2;
            }
        }
    }

    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param boolean $silent
     * @return bool
     */
    public function set(StateBlock &$state, int $startLine, int $endLine, $silent=false): bool
    {
        $nextLine = $startLine;
        $isTerminatingParagraph = false;
        $tight = true;

        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$nextLine] - $state->blkIndent >= 4) { return false; }

        // Special case:
        //  - item 1
        //   - item 2
        //    - item 3
        //     - item 4
        //      - this one is a paragraph continuation
        if ($state->listIndent >= 0 &&
            $state->sCount[$nextLine] - $state->listIndent >= 4 &&
            $state->sCount[$nextLine] < $state->blkIndent) {
            return false;
        }

        // limit conditions when list can interrupt
        // a paragraph (validation mode only)
        if ($silent && $state->parentType === 'paragraph') {
            // Next list item should still $terminate previous list item;
            //
            // This code can fail if plugins use blkIndent as well as lists,
            // but I hope the spec gets fixed long before that happens.
            //
            if ($state->sCount[$nextLine] >= $state->blkIndent) {
                $isTerminatingParagraph = true;
            }
        }

        // Detect list type and position after marker
        $start = 0;
        if (($posAfterMarker = self::skipOrderedListMarker($state, $nextLine)) >= 0) {
            $isOrdered = true;
            $start = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
            $markerValue = intval(substr($state->src, $start, $posAfterMarker - $start - 1));

            // If we're starting a new ordered list right after
            // a paragraph, it should $start with 1.
            if ($isTerminatingParagraph && $markerValue !== 1) return false;

        } else if (($posAfterMarker = self::skipBulletListMarker($state, $nextLine)) >= 0) {
            $isOrdered = false;

        } else {
            return false;
        }

        // If we're starting a new unordered list right after
        // a paragraph, first line should not be empty.
        if ($isTerminatingParagraph) {
            if ($state->skipSpaces($posAfterMarker) >= $state->eMarks[$nextLine]) return false;
        }

        // For validation mode we can $terminate immediately
        if ($silent) { return true; }

        // We should $terminate list on style change. Remember first one to compare.
        $markerCharCode = $state->src[$posAfterMarker - 1];

        // Start list
        $listTokIdx = count($state->tokens);

        if ($isOrdered) {
            $token       = $state->push('ordered_list_open', 'ol', 1);
            if ($markerValue !== 1) {
                $token->attrs = [ [ 'start', $markerValue ] ];
            }

        } else {
            $token       = $state->push('bullet_list_open', 'ul', 1);
        }

        $token->map    = $listLines = [ $nextLine, 0 ];
        $token->markup = $markerCharCode;

        //
        // Iterate list items
        //

        $prevEmptyEnd = false;
        $terminatorRules = $state->md->block->ruler->getRules('list');

        $oldParentType = $state->parentType;
        $state->parentType = 'list';

        while ($nextLine < $endLine) {
            $pos = $posAfterMarker;
            $max = $state->eMarks[$nextLine];

            $initial = $state->sCount[$nextLine] + $posAfterMarker - ($state->bMarks[$nextLine] + $state->tShift[$nextLine]);
            $offset = $initial;
            while ($pos < $max) {
                $ch = $state->src[$pos];

                if ($ch === "\t") {
                    $offset += 4 - ($offset + $state->bsCount[$nextLine]) % 4;
                } else if ($ch === ' ') {
                    $offset++;
                }else {
                    break;
                }

                $pos++;
            }

            $contentStart = $pos;

            if ($contentStart >= $max) {
                // trimming space in "-    \n  3" case, $indent is 1 here
                $indentAfterMarker = 1;
            } else {
                $indentAfterMarker = $offset - $initial;
            }

            // If we have more than 4 spaces, the $indent is 1
            // (the rest is just indented code block)
            if ($indentAfterMarker > 4) { $indentAfterMarker = 1; }

            // "  -  test"
            //  ^^^^^ - calculating total length of this thing
            $indent = $initial + $indentAfterMarker;

            // Run subparser & write tokens
            $token          = $state->push('list_item_open', 'li', 1);
            $token->markup  = $markerCharCode;
            $itemLines      = [ $nextLine, 0 ];
            $token->map     = $itemLines;
            if ($isOrdered) {
                $token->info = substr($state->src, $start, $posAfterMarker - $start - 1);
            }

            // change current state, then restore it after parser subcall
            $oldTight = $state->tight;
            $oldTShift = $state->tShift[$nextLine];
            $oldSCount = $state->sCount[$nextLine];

            //  - example list
            // ^ listIndent position will be here
            //   ^ blkIndent position will be here
            //
            $oldListIndent      = $state->listIndent;
            $state->listIndent  = $state->blkIndent;
            $state->blkIndent   = $indent;

            $state->tight       = true;
            $state->tShift[$nextLine] = $contentStart - $state->bMarks[$nextLine];
            $state->sCount[$nextLine] = $offset;

            if ($contentStart >= $max && $state->isEmpty($nextLine + 1)) {
                // workaround for this case
                // (list item is empty, list terminates before "foo"):
                // ~~~~~~~~
                //   -
                //
                //     foo
                // ~~~~~~~~
                $state->line = min($state->line + 2, $endLine);
            } else {
                $state->md->block->tokenize($state, $nextLine, $endLine, true);
            }

            // If any of list item is tight, mark list as tight
            if (!$state->tight || $prevEmptyEnd) {
                $tight = false;
            }
            // Item become loose if finish with empty line,
            // but we should filter last element, because it means list finish
            $prevEmptyEnd = ($state->line - $nextLine) > 1 && $state->isEmpty($state->line - 1);

            $state->blkIndent           = $state->listIndent;
            $state->listIndent          = $oldListIndent;
            $state->tShift[$nextLine]  = $oldTShift;
            $state->sCount[$nextLine]  = $oldSCount;
            $state->tight = $oldTight;

            $token        = $state->push('list_item_close', 'li', -1);
            $token->markup = $markerCharCode;

            $nextLine = $state->line;
            $itemLines[1] = $nextLine;

            if ($nextLine >= $endLine) { break; }

            //
            // Try to check if list is terminated or continued.
            //
            if ($state->sCount[$nextLine] < $state->blkIndent) { break; }

            // if it's indented more than 3 spaces, it should be a code block
            if ($state->sCount[$nextLine] - $state->blkIndent >= 4) { break; }

            // fail if terminating block found
            $terminate = false;
            foreach ( $terminatorRules as &$rule) {
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

            // fail if list has another type
            if ($isOrdered) {
                $posAfterMarker = self::skipOrderedListMarker($state, $nextLine);
                if ($posAfterMarker < 0) { break; }
                $start = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
            } else {
                $posAfterMarker = self::skipBulletListMarker($state, $nextLine);
                if ($posAfterMarker < 0) { break; }
            }

            if ($markerCharCode !== $state->src[$posAfterMarker - 1]) { break; }
        }

        // Finalize list
        if ($isOrdered) {
            $token = $state->push('ordered_list_close', 'ol', -1);
        } else {
            $token = $state->push('bullet_list_close', 'ul', -1);
        }
        $token->markup = $markerCharCode;

        $listLines[1] = $nextLine;
        $state->line = $nextLine;

        $state->parentType = $oldParentType;

        // mark paragraphs tight if needed
        if ($tight) {
            self::markTightParagraphs($state, $listTokIdx);
        }

        return true;
    }
}