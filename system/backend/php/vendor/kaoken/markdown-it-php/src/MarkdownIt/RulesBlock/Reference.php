<?php
namespace Kaoken\MarkdownIt\RulesBlock;
use Kaoken\MarkdownIt\Common\Utils;


class Reference
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
        $pos = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $max = $state->eMarks[$startLine];
        $nextLine = $startLine + 1;

        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$startLine] - $state->blkIndent >= 4) { return false; }

        if ($state->src[$pos] !== '[') { return false; }

        $getNextLine = function($nextLine) use (&$state) {
            $endLine = $state->lineMax;

            if ($nextLine >= $endLine || $state->isEmpty($nextLine)) {
                // empty line or end of input
                return null;
            }

            $isContinuation = false;

            // this would be a code block normally, but after paragraph
            // it's considered a lazy continuation regardless of what's there
            if ($state->sCount[$nextLine] - $state->blkIndent > 3) {
                $isContinuation = true;
            }

            // quirk for blockquotes, this line should already be checked by that rule
            if ($state->sCount[$nextLine] < 0) {
                $isContinuation = true;
            }

            if (!$isContinuation) {
                $terminatorRules = $state->md->block->ruler->getRules('reference');
                $oldParentType = $state->parentType;
                $state->parentType = 'reference';

                // Some tags can terminate paragraph without empty line.
                $terminate = false;
                for ($i = 0, $l = count($terminatorRules); $i < $l; $i++) {
                    if ($terminatorRules[$i]($state, $nextLine, $endLine, true)) {
                        $terminate = true;
                        break;
                    }
                }

                $state->parentType = $oldParentType;
                if ($terminate) {
                    // terminated by another block
                    return null;
                }
            }
            $pos = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
            $max = $state->eMarks[$nextLine];

            // max + 1 explicitly includes the newline
            return substr($state->src, $pos, ($max+1)-$pos);
        };
        $str = substr($state->src, $pos, ($max+1)-$pos);

        $max = strlen($str);
        $labelEnd = -1;

        for ($pos = 1; $pos < $max; $pos++) {
            $ch = $str[$pos];
            if ($ch === '[') {
                return false;
            } else if ($ch === ']') {
                $labelEnd = $pos;
                break;
            } else if ($ch === "\n") {
                $lineContent = $getNextLine($nextLine);
                if ($lineContent !== null) {
                    $str .= $lineContent;
                    $max = strlen($str);
                    $nextLine++;
                }
            } else if ($ch === '\\') {
                $pos++;
                if ($pos < $max && $str[$pos] === "\n") {
                    $lineContent = $getNextLine($nextLine);
                    if ($lineContent !== null) {
                        $str .= $lineContent;
                        $max = strlen($str);
                        $nextLine++;
                    }
                }
            }
        }

        if ($labelEnd < 0 || $labelEnd+1 >= $max ) return false;
        if ( $str[$labelEnd + 1] !== ':') return false;

        // [$label]:   destination   'title'
        //         ^^^ skip optional whitespace here
        for ($pos = $labelEnd + 2; $pos < $max; $pos++) {
            $ch = $str[$pos];
            if ($ch === "\n") {
                $lineContent = $getNextLine($nextLine);
                if ($lineContent !== null) {
                    $str .= $lineContent;
                    $max = strlen($str);
                    $nextLine++;
                }
            } else if ($state->md->utils->isSpace($ch)) {
                /*eslint no-empty:0*/
            } else {
                break;
            }
        }
        // [$label]:   destination   'title'
        //            ^^^^^^^^^^^ parse this
        $destRes = $state->md->helpers->parseLinkDestination($str, $pos, $max);
        if (!$destRes->ok) { return false; }

        $href = $state->md->normalizeLink($destRes->str);
        if (!$state->md->validateLink($href)) { return false; }

        $pos = $destRes->pos;

        // save cursor $state, we could require to rollback later
        $destEndPos = $pos;
        $destEndLineNo = $nextLine;

        // [$label]:   destination   'title'
        //                       ^^^ skipping those spaces
        $start = $pos;
        for (; $pos < $max; $pos++) {
            $ch = $str[$pos];
            if ($ch === "\n") {
                $lineContent = $getNextLine($nextLine);
                if ($lineContent !== null) {
                    $str .= $lineContent;
                    $max = strlen($str);
                    $nextLine++;
                }
            } else if ($state->md->utils->isSpace($ch)) {
                /*eslint no-empty:0*/
            } else {
                break;
            }
        }

        // [$label]:   destination   'title'
        //                          ^^^^^^^ parse this
        $titleRes = $state->md->helpers->parseLinkTitle($str, $pos, $max);
        while ($titleRes->can_continue) {
            $lineContent = $getNextLine($nextLine);
            if ($lineContent === null) break;
            $str .= $lineContent;
            $pos = $max;
            $max = strlen($str);
            $nextLine++;
            $titleRes = $state->md->helpers->parseLinkTitle($str, $pos, $max, $titleRes);
        }

        $title = false;
        if ($pos < $max && $start !== $pos && $titleRes->ok) {
            $title = $titleRes->str;
            $pos = $titleRes->pos;
        } else {
            $title = '';
            $pos = $destEndPos;
            $nextLine = $destEndLineNo;
        }

        // skip trailing spaces until the rest of the line
        while ($pos < $max) {
            $ch = $str[$pos];
            if (!$state->md->utils->isSpace($ch)) { break; }
            $pos++;
        }

        if ($pos < $max && $str[$pos] !== "\n") {
            if ($title) {
                // garbage at the end of the line after $title,
                // but it could still be a valid reference if we roll back
                $title = '';
                $pos = $destEndPos;
                $nextLine = $destEndLineNo;
                while ($pos < $max) {
                    $ch = $str[$pos];
                    if (!$state->md->utils->isSpace($ch)) { break; }
                    $pos++;
                }
            }
        }

        if ($pos < $max && $str[$pos] !== "\n") {
            // garbage at the end of the line
            return false;
        }

        $label = $state->md->utils->normalizeReference(substr($str, 1, $labelEnd-1));
        if (!$label) {
            // CommonMark 0.20 disallows empty labels
            return false;
        }

        // Reference can not $terminate anything. This check is for safety only.
        /*istanbul ignore if*/
        if ($silent) { return true; }

        if ( !isset($state->env->references) ) {
            $state->env->references = [];
        }
        if ( !isset($state->env->references[$label]) ) {
            $state->env->references[$label] = [];
            $obj = &$state->env->references[$label];
            $obj['title'] = $title;
            $obj['href'] = $href;
        }

        $state->line = $nextLine;
        return true;
    }
}