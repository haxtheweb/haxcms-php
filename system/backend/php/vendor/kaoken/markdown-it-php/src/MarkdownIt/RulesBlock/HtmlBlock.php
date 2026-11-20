<?php

namespace Kaoken\MarkdownIt\RulesBlock;

use Kaoken\MarkdownIt\Common\Utils;
use Kaoken\MarkdownIt\Common\HtmlRegex;
use Kaoken\MarkdownIt\Common\HtmlBlocks;

class HtmlBlock
{

    // An array of opening and corresponding closing sequences for html tags,
    // last argument defines whether it can terminate a paragraph or not
    //
    protected array $HTML_SEQUENCES = [];


    public function __construct()
    {
        $this->HTML_SEQUENCES = [
            [ "/^<(script|pre|style|textarea)(?=(\s|>|$))/i", "/<\/(script|pre|style|textarea)>/i", true ],
            [ "/^<!--/",          "/-->/",   true ],
            [ "/^<\?/",            "/\?>/",   true ],
            [ "/^<![A-Z]/",         "/>/",   true ],
            [ "/^<!\[CDATA\[/", "/\]\]>/",   true ],
            [ "/^<\/?(" . join('|', HtmlBlocks::BLOCKS) . ")(?=(\s|\/?>|$))/i", "/^$/", true ],
            [ "/" . HtmlRegex::HTML_OPEN_CLOSE_TAG . "\s*$/",  "/^$/", false ]
        ];
    }

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

        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$startLine] - $state->blkIndent >= 4) { return false; }

        if (!$state->md->options->html) { return false; }

        if ($state->src[$pos] !== '<') { return false; }

        $lineText = substr($state->src,$pos, $max-$pos);

        for ($i = 0, $l = count($this->HTML_SEQUENCES); $i < $l; $i++) {
            if (preg_match($this->HTML_SEQUENCES[$i][0], $lineText)) { break; }
        }

        if ($i === $l ) { return false; }

        if ($silent) {
            // true if this sequence can be a terminator, false otherwise
            return $this->HTML_SEQUENCES[$i][2];
        }

        $nextLine = $startLine + 1;

        // Only block types 6 and 7 end on a blank line. For block types
        // 1-5 (for example comments), an outdented blank line is content and
        // the block continues until its explicit closing sequence.
        $endsOnBlankLine = preg_match($this->HTML_SEQUENCES[$i][1], '') === 1;

        // If we are here - we detected HTML block.
        // Let's roll down till block end.
        if (!preg_match($this->HTML_SEQUENCES[$i][1], $lineText)) {
            for (; $nextLine < $endLine; $nextLine++) {
                if ($state->sCount[$nextLine] < $state->blkIndent) {
                    if ($endsOnBlankLine || !$state->isEmpty($nextLine)) { break; }
                }

                $pos = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
                $max = $state->eMarks[$nextLine];
                $lineText = substr($state->src, $pos, $max-$pos);

                if (preg_match($this->HTML_SEQUENCES[$i][1],$lineText)) {
                    if (strlen($lineText) !== 0) { $nextLine++; }
                    break;
                }
            }
        }

        $state->line = $nextLine;

        $token         = $state->push('html_block', '', 0);
        $token->map     = [ $startLine, $nextLine ];
        $token->content = $state->getLines($startLine, $nextLine, $state->blkIndent, true);

        return true;
    }
}