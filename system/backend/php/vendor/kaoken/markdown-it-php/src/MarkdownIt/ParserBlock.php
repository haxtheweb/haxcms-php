<?php
/** internal
 * class ParserBlock
 *
 * Block-level tokenizer.
 **/

namespace Kaoken\MarkdownIt;

use Kaoken\MarkdownIt\RulesBlock\StateBlock;
use \Exception;

class ParserBlock
{
    protected array $_rules = [
        // First 2 params - rule name & source. Secondary array - list of rules,
        // which can be terminated by this one.
        [ 'table',      \Kaoken\MarkdownIt\RulesBlock\Table::class,      [ 'paragraph', 'reference' ] ],
        [ 'code',       \Kaoken\MarkdownIt\RulesBlock\Code::class ],
        [ 'fence',      \Kaoken\MarkdownIt\RulesBlock\Fence::class,      [ 'paragraph', 'reference', 'blockquote', 'list' ] ],
        [ 'blockquote', \Kaoken\MarkdownIt\RulesBlock\BlockQuote::class, [ 'paragraph', 'reference', 'blockquote', 'list' ] ],
        [ 'hr',         \Kaoken\MarkdownIt\RulesBlock\Hr::class,         [ 'paragraph', 'reference', 'blockquote', 'list' ] ],
        [ 'list',       \Kaoken\MarkdownIt\RulesBlock\CList::class,       [ 'paragraph', 'reference', 'blockquote' ] ],
        [ 'reference',  \Kaoken\MarkdownIt\RulesBlock\Reference::class ],
        [ 'html_block', \Kaoken\MarkdownIt\RulesBlock\HtmlBlock::class, [ 'paragraph', 'reference', 'blockquote' ] ],
        [ 'heading',    \Kaoken\MarkdownIt\RulesBlock\Heading::class,    [ 'paragraph', 'reference', 'blockquote' ] ],
        [ 'lheading',   \Kaoken\MarkdownIt\RulesBlock\LHeading::class],
        [ 'paragraph',  \Kaoken\MarkdownIt\RulesBlock\Paragraph::class ]
    ];
    /**
     * @var Ruler
     */
    public Ruler $ruler;


    public function __construct()
    {
        /**
         * ParserBlock#ruler -> Ruler
         *
         * [[Ruler]] instance. Keep configuration of block rules.
         **/
        $this->ruler = new Ruler();

        foreach ($this->_rules as &$rule) {
            $obj = new \stdClass();
            $obj->alt = $rule[2] ?? [];
            $class = $rule[1];
            $this->ruler->push($rule[0], [new $class(), 'set'], $obj);
        }
    }

    //
//
    /**
     *  Generate tokens for input range
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @throws Exception
     */
    public function tokenize(StateBlock $state, int $startLine, int $endLine)
    {
        $rules = $this->ruler->getRules('');
        $len = count($rules);
        $line = $startLine;
        $hasEmptyLines = false;
        $maxNesting = $state->md->options->maxNesting;

        while ($line < $endLine) {
            $state->line = $line = $state->skipEmptyLines($line);
            if ($line >= $endLine) { break; }

            // Termination condition for nested calls.
            // Nested calls currently used for blockquotes & lists
            if ($state->sCount[$line] < $state->blkIndent) { break; }

            // If nesting level exceeded - skip tail to the end. That's not ordinary
            // situation and we should not care about content.
            if ($state->level >= $maxNesting) {
                $state->line = $endLine;
                break;
            }

            // Try all possible rules.
            // On success, rule should:
            //
            // - update `state.line`
            // - update `state.tokens`
            // - return true
            $prevLine = $state->line;
            $ok = false;

            foreach ($rules as &$rule) {
                if( is_array($rule) )
                    $ok = $rule[0]->{$rule[1]}($state, $line, $endLine, false);
                else
                    $ok = $rule($state, $line, $endLine, false);
                if ($ok) {
                    if ($prevLine >= $state->line) {
                        throw new Exception("block rule didn't increment state.line");
                    }
                    break;
                }
            }

            // this can only happen if user disables paragraph rule
            if (!$ok) throw new Exception('none of the block rules matched');

            // set state.tight if we had an empty line before current tag
            // i.e. latest empty line should not count
            $state->tight = !$hasEmptyLines;

            // paragraph might "eat" one newline after it in nested lists
            if ($state->isEmpty($state->line - 1)) {
                $hasEmptyLines = true;
            }

            $line = $state->line;

            if ($line < $endLine && $state->isEmpty($line)) {
                $hasEmptyLines = true;
                $line++;
                $state->line = $line;
            }
        }
    }


    /**
     * Process input string and push block tokens into `outTokens`
     *
     * @param string $src
     * @param MarkdownIt $md
     * @param null|object $env
     * @param Token[] $outTokens
     */
    public function parse(string $src, MarkdownIt $md, ?object $env, array &$outTokens)
    {
        if (!$src) { return; }

        $state = new StateBlock($src, $md, $env, $outTokens);

        $this->tokenize($state, $state->line, $state->lineMax);
    }
}