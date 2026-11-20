<?php
// Core state object
//

namespace Kaoken\MarkdownIt\RulesCore;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Token;

class StateCore
{
    /**
     * @var string
     */
    public string $src = '';
    /**
     * @var null|object
     */
    public ?object $env;
    /**
     * @var Token[]
     */
    public array $tokens = [];
    public bool $inlineMode = false;
    /**
     * @var null|MarkdownIt
     */
    public ?MarkdownIt $md = null; // link to parser instance

    /**
     * StateCore constructor.
     * @param string $src
     * @param MarkdownIt $md
     * @param object|null $env
     */
    public function __construct(string $src, MarkdownIt $md, object $env=null) {
        $this->src = $src;
        $this->env = $env;
        $this->tokens = [];
        $this->inlineMode = false;
        $this->md = $md;
    }

    /**
     * @param string $type
     * @param string $tag
     * @param integer $nesting
     * @return Token
     */
    public function createToken(string $type, string $tag, int $nesting): Token
    {
        return new Token($type, $tag, $nesting);
    }
}