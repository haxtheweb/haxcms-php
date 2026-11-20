<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Run implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public array $children = [],
        public ?string $styleId = null,
        public ?string $styleName = null,
        public bool $isBold = false,
        public bool $isItalic = false,
        public bool $isUnderline = false,
        public bool $isStrikethrough = false,
        public bool $isAllCaps = false,
        public bool $isSmallCaps = false,
        public bool $isHidden = false,
        public VerticalAlignment $verticalAlignment = VerticalAlignment::Baseline,
        public ?string $highlight = null,
        public ?string $font = null,
        public ?float $fontSize = null,
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
            isBold: $this->isBold,
            isItalic: $this->isItalic,
            isUnderline: $this->isUnderline,
            isStrikethrough: $this->isStrikethrough,
            isAllCaps: $this->isAllCaps,
            isSmallCaps: $this->isSmallCaps,
            isHidden: $this->isHidden,
            verticalAlignment: $this->verticalAlignment,
            highlight: $this->highlight,
            font: $this->font,
            fontSize: $this->fontSize,
        );
    }
}
