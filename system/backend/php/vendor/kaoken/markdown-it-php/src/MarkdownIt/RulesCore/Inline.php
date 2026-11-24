<?php
namespace Kaoken\MarkdownIt\RulesCore;


class Inline
{
    /**
     * @param StateCore $state
     */
    public function set(StateCore &$state): void
    {
        $tokens = &$state->tokens;

        // Parse inlines
        foreach ( $tokens as &$tok ) {
            if ($tok->type === 'inline') {
                $state->md->inline->parse($tok->content, $state->md, $state->env, $tok->children);
            }
        }
    }
}