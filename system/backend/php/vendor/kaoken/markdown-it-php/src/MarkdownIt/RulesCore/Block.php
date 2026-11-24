<?php
namespace Kaoken\MarkdownIt\RulesCore;


class Block
{
    /**
     * @param StateCore $state
     */
    public function set(StateCore &$state): void
    {
        if ($state->inlineMode) {
            $token          = $state->createToken('inline', '', 0);
            $token->content  = $state->src;
            $token->map      = [ 0, 1 ];
            $token->children = [];
            $state->tokens[] = $token;
        } else {
            $state->md->block->parse($state->src, $state->md, $state->env, $state->tokens);
        }
    }
}