<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/html/ast.js

namespace EndlessCreativity\ElephantPhp\Html;

final readonly class Element implements Node
{
    private const VOID_TAG_NAMES = ['br' => true, 'hr' => true, 'img' => true, 'input' => true];

    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public Tag $tag,
        public array $children = [],
    ) {
    }

    /**
     * @param  list<Node>  $children
     */
    public function withChildren(array $children): self
    {
        return new self(tag: $this->tag, children: $children);
    }

    public function isVoid(): bool
    {
        return $this->children === [] && isset(self::VOID_TAG_NAMES[$this->tag->tagName]);
    }
}
