<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Document implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public array $children = [],
        public Notes $notes = new Notes(),
        public Comments $comments = new Comments(),
    ) {
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function withChildren(array $children): self
    {
        return new self(children: $children, notes: $this->notes, comments: $this->comments);
    }
}
