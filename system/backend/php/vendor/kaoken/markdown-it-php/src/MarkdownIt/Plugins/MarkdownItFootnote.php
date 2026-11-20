<?php
/**
 * Copyright (c) 2014-2015 Vitaly Puzrin, Alex Kocharin.
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
 * @see https://github.com/markdown-it/markdown-it-footnote/tree/4.0.0
 */
// Process footnotes
//
namespace Kaoken\MarkdownIt\Plugins;


use Exception;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\RulesBlock\StateBlock;
use Kaoken\MarkdownIt\RulesCore\StateCore;
use Kaoken\MarkdownIt\RulesInline\StateInline;
use Kaoken\MarkdownIt\Token;

class MarkdownItFootnote
{
////////////////////////////////////////////////////////////////////////////////
// Renderer partials
    /**
     * @param MarkdownIt $md
     * @throws Exception
     */
    function plugin(MarkdownIt $md)
    {

        /**
         * @param Token[] $tokens
         * @param integer $idx
         * @param object  $options
         * @param object  $env
         * @param object  $slf
         * @return string
         */
        $md->renderer->rules->footnote_ref = function (&$tokens, $idx, $options, $env, $slf) {
            $fn = $slf->rules->footnote_anchor_name;
            $id      = $fn($tokens, $idx, $options, $env, $slf);
            $fn = $slf->rules->footnote_caption;
            $caption = $fn($tokens, $idx, $options, $env, $slf);
            $refid   = $id;

            if ( isset($tokens[$idx]->meta->subId) && $tokens[$idx]->meta->subId > 0) {
                $refid .= ':' . $tokens[$idx]->meta->subId;
            }

            return '<sup class="footnote-ref"><a href="#fn' . $id . '" id="fnref' . $refid . '">' . $caption . '</a></sup>';
        };
        /**
         * @param Token[] $tokens
         * @param integer $idx
         * @param object  $options
         * @return string
         */
        $md->renderer->rules->footnote_block_open = function(&$tokens, $idx, &$options) {
            return ($options->xhtmlOut ? "<hr class=\"footnotes-sep\" />\n" : "<hr class=\"footnotes-sep\">\n") .
                "<section class=\"footnotes\">\n" .
                "<ol class=\"footnotes-list\">\n";
        };
        $md->renderer->rules->footnote_block_close = function () {
            return "</ol>\n</section>\n";
        };
        /**
         * @param Token[] $tokens
         * @param integer $idx
         * @param object $options
         * @param object $env
         * @param $slf
         * @return string
         */
        $md->renderer->rules->footnote_open = function (array &$tokens, int $idx, object $options, object $env, $slf) {
            $fn = $slf->rules->footnote_anchor_name;
            $id = $fn($tokens, $idx, $options, $env, $slf);

            if ( isset($tokens[$idx]->meta->subId) && $tokens[$idx]->meta->subId > 0) {
                $id .= ':' . $tokens[$idx]->meta->subId;
            }

            return '<li id="fn' . $id . '" class="footnote-item">';
        };
        $md->renderer->rules->footnote_close = function () {
            return "</li>\n";
        };
        /**
         * @param Token[] $tokens
         * @param integer $idx
         * @param object $options
         * @param $env
         * @param $slf
         * @return string
         */
        $md->renderer->rules->footnote_anchor = function (array &$tokens, int $idx, object $options, $env, $slf) {
            $fn = $slf->rules->footnote_anchor_name;
            $id = $fn($tokens, $idx, $options, $env, $slf);

            if ( isset($tokens[$idx]->meta->subId) && $tokens[$idx]->meta->subId > 0) {
                $id .= ':' . $tokens[$idx]->meta->subId;
            }

            /* ↩ with escape code to prevent display as Apple Emoji on iOS */
            return ' <a href="#fnref' . $id . '" class="footnote-backref">↩︎</a>';
        };

        // helpers (only used in other rules, no $tokens are attached to those)
        /**
         * @param Token[] $tokens
         * @param integer $idx
         * @param null $options
         * @param null $env
         * @param null $slf
         * @return string
         */
        $md->renderer->rules->footnote_caption = function (array $tokens, int $idx, $options=null, $env=null, $slf=null) {
            $n = (string)($tokens[$idx]->meta->id + 1);

            if ( isset($tokens[$idx]->meta->subId) && $tokens[$idx]->meta->subId > 0) {
                $n .= ':' . $tokens[$idx]->meta->subId;
            }

            return '[' . $n . ']';
        };
        /**
         * @param Token[] $tokens
         * @param integer $idx
         * @param $options
         * @param $env
         * @param null $slf
         * @return string
         */
        $md->renderer->rules->footnote_anchor_name  = function (array $tokens, int $idx, $options, $env, $slf=null) {
            $n = (string)($tokens[$idx]->meta->id + 1);
            $prefix = '';

            if ( isset($env->docId) && is_string($env->docId) ) {
                $prefix = '-' . $env->docId . '-';
            }

            return $prefix . $n;
        };

        /**
         * Process footnote block definition
         * @param StateBlock $state
         * @param int $startLine
         * @param int $endLine
         * @param bool $silent
         * @return bool
         */
        $footnote_def = function(StateBlock $state, int $startLine, int $endLine, $silent=false)
        {
            $start = $state->bMarks[$startLine] + $state->tShift[$startLine];
            $max = $state->eMarks[$startLine];

            // line should be at least 5 chars - "[^x]:"
            if ($start + 4 > $max) { return false; }

            if ($state->src[$start] !== '[') { return false; }
            if ($state->src[$start+1] !== '^') { return false; }

            for ($pos = $start + 2; $pos < $max; $pos++) {
                if ($state->src[$pos] === ' ') { return false; }
                if ($state->src[$pos] === ']') {
                    break;
                }
            }

            if ($pos === $start + 2) { return false; } // no empty footnote labels
            if ($pos + 1 >= $max || $state->src[++$pos] !== ':') { return false; }
            if ($silent) { return true; }
            $pos++;

            if (!isset($state->env->footnotes)) { $state->env->footnotes = new \stdClass(); }
            if (!isset($state->env->footnotes->refs)) { $state->env->footnotes->refs =[]; }
            $label = substr($state->src, $start + 2, $pos - 2 - ($start + 2));
            $state->env->footnotes->refs[':' . $label] = -1;

            $token_fref_o               = $state->createToken('footnote_reference_open', '', 1);
            $token_fref_o->meta         = new \stdClass();
            $token_fref_o->meta->label  = $label;
            $token_fref_o->level        = $state->level++;
            $state->tokens[] = $token_fref_o;

            $oldBMark = $state->bMarks[$startLine];
            $oldTShift = $state->tShift[$startLine];
            $oldSCount = $state->sCount[$startLine];
            $oldParentType = $state->parentType;

            $posAfterColon = $pos;
            $initial = $offset = $state->sCount[$startLine] + $pos - ($state->bMarks[$startLine] + $state->tShift[$startLine]);

            while ($pos < $max) {
                $ch = $state->src[$pos];

                if ($state->md->utils->isSpace($ch)) {
                    if ($ch === "\t") {
                        $offset += 4 - $offset % 4;
                    } else {
                        $offset++;
                    }
                } else {
                    break;
                }

                $pos++;
            }

            $state->tShift[$startLine] = $pos - $posAfterColon;
            $state->sCount[$startLine] = $offset - $initial;

            $state->bMarks[$startLine] = $posAfterColon;
            $state->blkIndent += 4;
            $state->parentType = 'footnote';

            if ($state->sCount[$startLine] < $state->blkIndent) {
                $state->sCount[$startLine] += $state->blkIndent;
            }

            $state->md->block->tokenize($state, $startLine, $endLine, true);

            $state->parentType = $oldParentType;
            $state->blkIndent -= 4;
            $state->tShift[$startLine] = $oldTShift;
            $state->sCount[$startLine] = $oldSCount;
            $state->bMarks[$startLine] = $oldBMark;

            $token_fref_c           = $state->createToken('footnote_reference_close', '', -1);
            $token_fref_c->level    = --$state->level;
            $state->tokens[] = $token_fref_c;

            return true;
        };

        /**
         * Process inline footnotes (^[...])
         * @param StateInline $state
         * @param boolean $silent
         * @return bool
         * @throws Exception
         */
        $footnote_inline = function(StateInline $state, bool $silent)
        {

            $max = $state->posMax;
            $start = $state->pos;

            if ($start + 2 >= $max) { return false; }
            if ($state->src[$start] !== '^') { return false; }
            if ($state->src[$start+1] !== '[') { return false; }

            $labelStart = $start + 2;
            $labelEnd = $state->md->helpers->parseLinkLabel($state, $start + 1);

            // parser failed to find ']', so it's not a valid note
            if ($labelEnd < 0) { return false; }

            // We found the end of the link, and know for a fact it's a valid link;
            // so all that's left to do is to call tokenizer.
            //
            if (!$silent) {
                if (!isset($state->env->footnotes)) { $state->env->footnotes = new \stdClass(); }
                if (!isset($state->env->footnotes->list)) { $state->env->footnotes->list = []; }
                $footnoteId = count($state->env->footnotes->list);

                $tokens = [];
                $state->md->inline->parse(
                    substr($state->src, $labelStart, $labelEnd-$labelStart),
                    $state->md,
                    $state->env,
                    $tokens
                );

                $token      = $state->push('footnote_ref', '', 0);
                $token->meta = new \stdClass();
                $token->meta->id = $footnoteId;

                $state->env->footnotes->list[$footnoteId] = new \stdClass();
                $state->env->footnotes->list[$footnoteId]->content =  substr($state->src, $labelStart, $labelEnd);
                $state->env->footnotes->list[$footnoteId]->tokens = $tokens;
            }

            $state->pos = $labelEnd + 1;
            $state->posMax = $max;
            return true;
        };

        /**
         * Process footnote references ([^...])
         * @param StateInline $state
         * @param boolean $silent
         * @return bool
         */
        $footnote_ref = function(StateInline $state, bool $silent)
        {
            $max = $state->posMax;
            $start = $state->pos;

            // should be at least 4 chars - "[^x]"
            if ($start + 3 > $max) { return false; }

            if (!isset($state->env->footnotes) || !isset($state->env->footnotes->refs)) { return false; }
            if ($state->src[$start] !== '[') { return false; }
            if ($state->src[$start+1] !== '^') { return false; }

            for ($pos = $start + 2; $pos < $max; $pos++) {
                if ($state->src[$pos] === ' ') { return false; }
                if ($state->src[$pos] === "\n") { return false; }
                if ($state->src[$pos] === ']') {
                    break;
                }
            }

            if ($pos === $start + 2) { return false; } // no empty footnote labels
            if ($pos >= $max) { return false; }
            $pos++;

            $label = substr($state->src, $start + 2, $pos - 1 - ($start + 2));
            if ( !isset($state->env->footnotes->refs[':' . $label]) ) { return false; }

            if (!$silent) {
                if (!isset($state->env->footnotes->list)) { $state->env->footnotes->list = []; }

                if ($state->env->footnotes->refs[':' . $label] < 0) {
                    $footnoteId = count($state->env->footnotes->list);
                    $state->env->footnotes->list[$footnoteId] = new \stdClass();
                    $state->env->footnotes->list[$footnoteId]->label = $label;
                    $state->env->footnotes->list[$footnoteId]->count = 0;
                    $state->env->footnotes->refs[':' . $label] = $footnoteId;
                } else {
                    $footnoteId = $state->env->footnotes->refs[':' . $label];
                }

                $footnoteSubId = $state->env->footnotes->list[$footnoteId]->count;
                $state->env->footnotes->list[$footnoteId]->count++;

                $token      = $state->push('footnote_ref', '', 0);
                $token->meta = new \stdClass();
                $token->meta->id = $footnoteId;
                $token->meta->subId = $footnoteSubId;
                $token->meta->label = $label;
            }

            $state->pos = $pos;
            $state->posMax = $max;
            return true;
        };

        /**
         * Glue footnote tokens to end of token stream
         * @param StateCore $state
         */
        $footnote_tail = function(StateCore $state) {
            $insideRef = false;
            $refTokens = [];
            if (!isset($state->env->footnotes)) { return; }

            $state->tokens = array_filter($state->tokens, function ($tok) use(&$insideRef, &$refTokens, &$current, &$currentLabel) {
                if ($tok->type === 'footnote_reference_open') {
                    $insideRef = true;
                    $current = [];
                    $currentLabel = $tok->meta->label;
                    return false;
                }
                if ($tok->type === 'footnote_reference_close') {
                    $insideRef = false;
                    // prepend ':' to avoid conflict with Object.prototype members
                    $refTokens[':' . $currentLabel] = $current;
                    return false;
                }
                if ($insideRef) { $current[] =$tok; }
                return !$insideRef;
            });

            if (!isset($state->env->footnotes->list)) { return; }
            $list = &$state->env->footnotes->list;

            $state->tokens[] = $state->createToken('footnote_block_open', '', 1);

            for ($i = 0, $l = count($list); $i < $l; $i++) {
                $token_fo               = $state->createToken('footnote_open', '', 1);
                $token_fo->meta         = new \stdClass();
                $token_fo->meta->id     = $i;
                $token_fo->meta->label  =  $list[$i]->label ??  '';
                $state->tokens[] = $token_fo;

                if ( isset($list[$i]->tokens) ) {
                    $tokens = [];

                    $token_po           = $state->createToken('paragraph_open', 'p', 1);
                    $token_po->block    = true;
                    $tokens[] = $token_po;

                    $token_i            = $state->createToken('inline', '', 0);
                    $token_i->children  = $list[$i]->tokens;
                    $token_i->content   = $list[$i]->content;
                    $tokens[] = $token_i;

                    $token_pc           = $state->createToken('paragraph_close', 'p', -1);
                    $token_pc->block    = true;
                    $tokens[] = $token_pc;

                } else if ( isset($list[$i]->label) ) {
                    $tokens = null;
                    if(array_key_exists(':' . $list[$i]->label, $refTokens)){
                        $tokens = $refTokens[':' . $list[$i]->label];
                    }
                }

                if(isset($tokens))
                    $state->tokens = array_merge($state->tokens, $tokens);
                if ($state->tokens[count($state->tokens) - 1]->type === 'paragraph_close') {
                    $lastParagraph = array_pop($state->tokens);
                } else {
                    $lastParagraph = null;
                }

                $t = isset($list[$i]->count) && $list[$i]->count > 0 ? $list[$i]->count : 1;
                for ($j = 0; $j < $t; $j++) {
                    $token_a                = $state->createToken('footnote_anchor', '', 0);
                    $token_a->meta          = new \stdClass();
                    $token_a->meta->id      = $i;
                    $token_a->meta->subId   = $j;
                    $token_a->meta->label   = $list[$i]->label ?? '';
                    $state->tokens[] = $token_a;
                }

                if ($lastParagraph) {
                    $state->tokens[] = $lastParagraph;
                }

                $state->tokens[] = $state->createToken('footnote_close', '', -1);
            }

            $state->tokens[] = $state->createToken('footnote_block_close', '', -1);
        };

        $md->block->ruler->before('reference', 'footnote_def', $footnote_def, [ 'alt'=> [ 'paragraph', 'reference' ] ]);
        $md->inline->ruler->after('image', 'footnote_inline', $footnote_inline);
        $md->inline->ruler->after('footnote_inline', 'footnote_ref', $footnote_ref);
        $md->core->ruler->after('inline', 'footnote_tail', $footnote_tail);
    }
}