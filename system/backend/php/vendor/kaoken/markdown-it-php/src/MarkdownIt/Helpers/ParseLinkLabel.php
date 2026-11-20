<?php
// Parse link label
//
// this function assumes that first character ("[") already matches;
// returns the end of the label
//
namespace Kaoken\MarkdownIt\Helpers;


use Exception;
use Kaoken\MarkdownIt\RulesInline\StateInline;

trait ParseLinkLabel
{
    /**
     * @param StateInline $state
     * @param integer $start
     * @param boolean $disableNested
     * @return int
     * @throws Exception
     */
    public function parseLinkLabel(StateInline &$state, int $start, bool $disableNested=false): int
    {
        $max = $state->posMax;
        $oldPos = $state->pos;

        $state->pos = $start + 1;
        $level = 1;
        $found = false;

        while ($state->pos < $max) {
            $marker = $state->src[$state->pos];
            if ($marker === ']' ) {
                $level--;
                if ($level === 0) {
                    $found = true;
                    break;
                }
            }

            $prevPos = $state->pos;
            $state->md->inline->skipToken($state);
            if ($marker === '[' ) {
                if ($prevPos === $state->pos - 1) {
                    // increase level if we find text `[`, which is not a part of any token
                    $level++;
                } else if ($disableNested) {
                    $state->pos = $oldPos;
                    return -1;
                }
            }
        }

        $labelEnd = -1;
        if ($found) {
            $labelEnd = $state->pos;
        }

        // restore old state
        $state->pos = $oldPos;

        return $labelEnd;
    }
}