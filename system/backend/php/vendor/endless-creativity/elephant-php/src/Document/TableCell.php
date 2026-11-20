<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (TableCell)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class TableCell implements HasChildren
{
    /**
     * @param  list<Node>  $children
     * @param  ?bool  $vMerge  Transient marker carried only during the reader's
     *                         row-span computation. Always null after the
     *                         reader has resolved spans; the HTML converter
     *                         ignores it.
     */
    public function __construct(
        public array $children = [],
        public int $colSpan = 1,
        public int $rowSpan = 1,
        public ?bool $vMerge = null,
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
            colSpan: $this->colSpan,
            rowSpan: $this->rowSpan,
            vMerge: $this->vMerge,
        );
    }
}
