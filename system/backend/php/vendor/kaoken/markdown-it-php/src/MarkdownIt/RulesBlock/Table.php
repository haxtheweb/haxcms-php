<?php
// GFM table, https://github.github.com/gfm/#tables-extension-

namespace Kaoken\MarkdownIt\RulesBlock;

use Kaoken\MarkdownIt\Common\Utils;

class Table
{
    // Limit the amount of empty autocompleted cells in a table,
    // see https://github.com/markdown-it/markdown-it/issues/1000,
    //
    // Both pulldown-cmark and commonmark-hs limit the number of cells this way to ~200k.
    // We set it to 65k, which can expand user input by a factor of x370
    // (256x256 square is 1.8kB expanded into 650kB).
    const MAX_AUTOCOMPLETED_CELLS = 0x10000;

    /**
     * @param StateBlock $state
     * @param integer $line
     * @return string
     */
    protected function getLine(StateBlock &$state, int $line): string
    {
        $pos = $state->bMarks[$line] + $state->tShift[$line];
        $max = $state->eMarks[$line];

        return substr($state->src, $pos, $max - $pos);
    }

    /**
     * @param string $str
     * @return array
     */
    protected function escapedSplit(string $str): array
    {
        $result = [];
        $pos = 0;
        $max = strlen($str);
        $isEscaped = false;
        $lastPos = 0;
        $current = '';
        $ch  = $str[$pos];

        while ($pos < $max) {
            if ($ch === '|') {
                if (!$isEscaped) {
                    // pipe separating cells, '|'
                    $result[] = $current . substr($str, $lastPos, $pos - $lastPos);
                    $current = '';
                    $lastPos = $pos + 1;
                }else {
                    // escaped pipe, '\|'
                    $current .= substr($str, $lastPos, ($pos - $lastPos) - 1);
                    $lastPos = $pos;
                }
            }

            $isEscaped = $ch === '\\';
            $pos++;

            if( $pos >= $max ) break;
            $ch = $str[$pos];
        }

        $result[] = $current . substr($str, $lastPos);

        return $result;
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
        // should have at least two lines
        if ($startLine + 2 > $endLine) { return false; }

        $nextLine = $startLine + 1;

        if ($state->sCount[$nextLine] < $state->blkIndent) { return false; }

        // if it's indented more than 3 spaces, it should be a code block
        if ($state->sCount[$nextLine] - $state->blkIndent >= 4) { return false; }

        // first character of the second $line should be '|', '-', ':',
        // and no other characters are allowed but spaces;
        // basically, this is the equivalent of /^[-:|][-:|\s]*$/ regexp

        $pos = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
        if ($pos >= $state->eMarks[$nextLine]) { return false; }

        $firstCh = $state->src[$pos++];
        if ($firstCh !== '|' && $firstCh !== '-' && $firstCh !== ':') { return false; }
        if ($pos >= $state->eMarks[$nextLine]) { return false; }

        $secondCh = $state->src[$pos++];
        if ($secondCh !== '|' && $secondCh !== '-' && $secondCh !== ':' && !$state->md->utils->isSpace($secondCh)) {
            return false;
        }

        // if first character is '-', then second character must not be a space
        // (due to parsing ambiguity with list)
        if ($firstCh === '-' && $state->md->utils->isSpace($secondCh)) { return false; }


        while ($pos < $state->eMarks[$nextLine]) {
            $ch = $state->src[$pos];

            if ($ch !== '|' && $ch !== '-' && $ch !== ':' && !$state->md->utils->isSpace($ch)) { return false; }

            $pos++;
        }

        $lineText = self::getLine($state, $startLine + 1);

        $columns = explode('|', $lineText);
        $aligns = [];
        for ($i = 0; $i < count($columns); $i++) {
            $t = trim($columns[$i]);
            if (!$t) {
                // allow empty $columns before and after table, but not in between $columns;
                // e.g. allow ` |---| `, disallow ` ---||--- `
                if ($i === 0 || $i === count($columns) - 1) {
                    continue;
                } else {
                    return false;
                }
            }

            if (!preg_match("/^:?-+:?$/", $t)) { return false; }
            if ($t[strlen($t) - 1] === ':') {
                $aligns[] = $t[0] === ':' ? 'center' : 'right';
            } else if ($t[0] === ':') {
                $aligns[] = 'left';
            } else {
                $aligns[] = '';
            }
        }

        $lineText = trim(self::getLine($state, $startLine));
        if ( strpos($lineText, '|') === false) { return false; }
        if ( $state->sCount[$startLine] - $state->blkIndent >= 4) { return false; }
        $columns = self::escapedSplit($lineText);
        if (count($columns) && $columns[0] === '') array_shift($columns);
        if (count($columns) && $columns[count($columns) - 1] === '') array_pop($columns);

        // header row will define an amount of $columns in the entire table,
        // and align row should be exactly the same (the rest of the rows can differ)
        $columnCount = count($columns);
        if ($columnCount === 0 || $columnCount !== count($aligns) ) { return false; }

        if ($silent) { return true; }

        $oldParentType = $state->parentType;
        $state->parentType = 'table';

        // use 'blockquote' lists for termination because it's
        // the most similar to tables
        $terminatorRules = $state->md->block->ruler->getRules('blockquote');


        $token_to       = $state->push('table_open', 'table', 1);
        $tableLines     = [ $startLine, 0 ];
        $token_to->map  = &$tableLines;

        $token_tho      = $state->push('thead_open', 'thead', 1);
        $token_tho->map = [ &$startLine, $startLine + 1 ];

        $token_htro     = $state->push('tr_open', 'tr', 1);
        $token_htro->map= [ &$startLine, $startLine + 1 ];

        $tbodyLines = false;
        for ($i = 0; $i < count($columns); $i++) {
            $token_ho = $state->push('th_open', 'th', 1);
            if ( !empty($aligns[$i]) ) {
                $token_ho->attrs  = [ [ 'style', 'text-align:' . $aligns[$i] ] ];
            }

            $token_il           = $state->push('inline', '', 0);
            $token_il->content  = trim($columns[$i]);
            $token_il->children = [];

            $state->push('th_close', 'th', -1);
        }

        $state->push('tr_close', 'tr', -1);
        $state->push('thead_close', 'thead', -1);

        $autocompletedCells = 0;

        for ($nextLine = $startLine + 2; $nextLine < $endLine; $nextLine++) {
            if ($state->sCount[$nextLine] < $state->blkIndent) { break; }

            $terminate = false;
            for ($i = 0, $l = count($terminatorRules); $i < $l; $i++) {
                if ($terminatorRules[$i]($state, $nextLine, $endLine, true)) {
                    $terminate = true;
                    break;
                }
            }

            if ($terminate) { break; }
            $lineText = trim(self::getLine($state, $nextLine));
            if (!$lineText) { break; }
            if ( $state->sCount[$nextLine] - $state->blkIndent >= 4) { break; }
            $columns = self::escapedSplit($lineText);
            if (count($columns) && $columns[0] === '') array_shift($columns);
            if (count($columns) && $columns[count($columns) - 1] === '') array_pop($columns);

            // note: autocomplete count can be negative if user specifies more columns than header,
            // but that does not affect intended use (which is limiting expansion)
            $autocompletedCells += $columnCount - count($columns);
            if ($autocompletedCells > self::MAX_AUTOCOMPLETED_CELLS) { break; }

            if ($nextLine === $startLine + 2) {
                $token_tbo      = $state->push('tbody_open', 'tbody', 1);
                $tbodyLines     = [ $startLine + 2, 0 ];
                $token_tbo->map = &$tbodyLines;
            }


            $token_tro      = $state->push('tr_open', 'tr', 1);
            $token_tro->map = [ $nextLine, $nextLine + 1 ];

            for ($i = 0; $i < $columnCount; $i++) {
                $token_tdo  = $state->push('td_open', 'td', 1);
                if ( !empty($aligns[$i]) ) {
                    $token_tdo->attrs = [ [ 'style', 'text-align:' . $aligns[$i] ] ];
                }

                $token_il           = $state->push('inline', '', 0);
                $token_il->content  = isset($columns[$i]) ? trim($columns[$i]) : '';
                $token_il->children = [];

                $state->push('td_close', 'td', -1);
            }
            $state->push('tr_close', 'tr', -1);
        }
        if ($tbodyLines) {
            $state->push('tbody_close', 'tbody', -1);
            $tbodyLines[1] = $nextLine;
        }
        $state->push('table_close', 'table', -1);
        $tableLines[1] = $nextLine;

        $state->parentType = $oldParentType;
        $state->line = $nextLine;
        return true;
    }
}