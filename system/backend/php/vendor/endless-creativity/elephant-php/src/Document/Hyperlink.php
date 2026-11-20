<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Hyperlink)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Hyperlink implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public array $children = [],
        public ?string $href = null,
        public ?string $anchor = null,
        public ?string $targetFrame = null,
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
            href: $this->href,
            anchor: $this->anchor,
            targetFrame: $this->targetFrame,
        );
    }
}
