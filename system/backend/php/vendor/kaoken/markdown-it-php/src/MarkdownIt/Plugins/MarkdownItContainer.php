<?php
/**
 * Copyright (c) 2015 Vitaly Puzrin, Alex Kocharin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 * use javascript version 4.0.0
 * @see https://github.com/markdown-it/markdown-it-container/tree/4.0.0
 */
// Process block-level custom containers
//

namespace Kaoken\MarkdownIt\Plugins;


use Exception;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\RulesBlock\StateBlock;
use Kaoken\MarkdownIt\Token;

class MarkdownItContainer
{
    /**
     * @var MarkdownIt
     */
    protected MarkdownIt $md;
    /**
     * @var string
     */
    protected string $name;
    /**
     * @var array
     */
    protected array $options;
    /**
     * @var int
     */
    protected int $min_markers;
    /**
     * @var string
     */
    protected string $marker_str;
    /**
     * @var string
     */
    protected string $marker_char;
    /**
     * @var int
     */
    protected int $marker_len;
    /**
     * @var callable|array
     */
    protected $validate;
    /**
     * @var object
     */
    protected $render;

    /**
     * @param MarkdownIt $md
     * @param string $name
     * @param null $options
     * @throws Exception
     */
    public function plugin(MarkdownIt $md, string $name, $options=null)
    {
        $this->name = $name;

        if( is_array($options)) $options = (object)$options;
        $options = $options ?? new \stdClass();

        $this->min_markers = 3;
        $this->marker_str  = $options->marker ?? ':';
        $this->marker_char = $this->marker_str[0];
        $this->marker_len  = strlen($this->marker_str);
        $this->validate    = $options->validate ?? [$this, 'validateDefault'];
        $this->render      = $options->render ?? [$this, 'renderDefault'];


        $md->block->ruler->before('fence', 'container_' . $name, [$this,'container'], [
            "alt" => [ 'paragraph', 'reference', 'blockquote', 'list' ]
        ]);
        $md->renderer->rules->{'container_' . $name . '_open'} = $this->render;
        $md->renderer->rules->{'container_' . $name . '_close'} = $this->render;
    }

    /**
     * Second param may be useful if you decide
     * to increase minimal allowed marker length
     * @param $params
     * @return bool
     */
    function validateDefault($params/*, markup*/): bool
    {
        return explode(' ', trim($params), 2)[0] === $this->name;
    }

    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param object $options
     * @param null|object $env
     * @param $slf
     * @return string
     */
    function renderDefault(array &$tokens, int $idx, object $options, ?object $env, $slf): string
    {

        // add a class to the opening tag
        if ($tokens[$idx]->nesting === 1) {
            $tokens[$idx]->attrJoin('class', $this->name);
        }

        return $slf->renderToken($tokens, $idx, $options, $env, $slf);
    }

    /**
     * @param StateBlock $state
     * @param integer $startLine
     * @param integer $endLine
     * @param bool $silent
     * @return bool
     * @throws Exception
     */
    function container(StateBlock $state, int $startLine, int $endLine, bool $silent=false): bool
    {
//    var $pos, $nextLine, $marker_count, $markup, $params, $token,
//    $old_parent, $old_line_max,
        $auto_closed = false;
        $start = $state->bMarks[$startLine] + $state->tShift[$startLine];
        $max = $state->eMarks[$startLine];

        // Check out the first character quickly,
        // this should filter out most of non-containers
        //
        if ($this->marker_char !== $state->src[$start]) { return false; }

        // Check out the rest of the marker string
        //
        for ($pos = $start + 1; $pos <= $max; $pos++) {
            if ($this->marker_str[($pos - $start) % $this->marker_len] !== $state->src[$pos]) {
                break;
            }
        }

        $marker_count = floor(($pos - $start) / $this->marker_len);
        if ($marker_count < $this->min_markers) { return false; }
        $pos -= ($pos - $start) % $this->marker_len;

        $markup = substr($state->src, $start, $pos-$start);
        $params = substr($state->src, $pos, $max-$pos);
        if( is_array($this->validate) ) {
            if (!$this->validate[0]->{$this->validate[1]}($params)) { return false; }
        }else if( is_callable($this->validate) ){
            $fn = $this->validate;
            if (!$fn($params,$markup)) { return false; }
        }


        // Since $start is found, we can report success here in validation mode
        //
        if ($silent) { return true; }

        // Search for the end of the block
        //
        $nextLine = $startLine;

        for (;;) {
            $nextLine++;
            if ($nextLine >= $endLine) {
                // unclosed block should be autoclosed by end of document.
                // also block seems to be autoclosed by end of parent
                break;
            }

            $start = $state->bMarks[$nextLine] + $state->tShift[$nextLine];
            $max = $state->eMarks[$nextLine];

            if ($start < $max && $state->sCount[$nextLine] < $state->blkIndent) {
                // non-empty line with negative indent should stop the list:
                // - ```
                //  test
                break;
            }

            if ($this->marker_char !== $state->src[$start]) { continue; }

            if ($state->sCount[$nextLine] - $state->blkIndent >= 4) {
                // closing fence should be indented less than 4 spaces
                continue;
            }

            for ($pos = $start + 1; $pos <= $max; $pos++) {
                if ($this->marker_str[($pos - $start) % $this->marker_len] !== $state->src[$pos]) {
                    break;
                }
            }

            // closing code fence must be at least as long as the opening one
            if (floor(($pos - $start) / $this->marker_len) < $marker_count) { continue; }

            // make sure tail has spaces only
            $pos -= ($pos - $start) % $this->marker_len;
            $pos = $state->skipSpaces($pos);

            if ($pos < $max) { continue; }

            // found!
            $auto_closed = true;
            break;
        }

        $old_parent = $state->parentType;
        $old_line_max = $state->lineMax;
        $state->parentType = 'container';

        // this will prevent lazy continuations from ever going past our end marker
        $state->lineMax = $nextLine;

        $token_o            = $state->push('container_' . $this->name . '_open', 'div', 1);
        $token_o->markup    = $markup;
        $token_o->block     = true;
        $token_o->info      = $params;
        $token_o->map       = [ $startLine, $nextLine ];

        $state->md->block->tokenize($state, $startLine + 1, $nextLine);

        $token_c            = $state->push('container_' . $this->name . '_close', 'div', -1);
        $token_c->markup    = substr($state->src, $start, $pos-$start);
        $token_c->block     = true;

        $state->parentType = $old_parent;
        $state->lineMax = $old_line_max;
        $state->line = $nextLine + ($auto_closed ? 1 : 0);

        return true;
    }
}