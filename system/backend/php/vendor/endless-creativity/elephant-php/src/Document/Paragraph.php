<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Paragraph implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public array $children = [],
        public ?string $styleId = null,
        public ?string $styleName = null,
        public ?NumberingLevel $numbering = null,
    ) {
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function withChildren(array $children): self
    {
        return new self(
            children: $children,
            styleId: $this->styleId,
            styleName: $this->styleName,
            numbering: $this->numbering,
        );
    }
}
