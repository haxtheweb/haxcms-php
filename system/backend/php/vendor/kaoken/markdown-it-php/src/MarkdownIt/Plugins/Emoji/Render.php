<?php

namespace Kaoken\MarkdownIt\Plugins\Emoji;


use Kaoken\MarkdownIt\Token;

class Render
{
    /**
     * @param Token[] $tokens
     * @param int $idx
     * @param null $options
     * @param null $env
     * @return string
     */
    function emoji_html(array &$tokens, int $idx, $options=null, $env=null): string
    {
        return $tokens[$idx]->content;
    }
}