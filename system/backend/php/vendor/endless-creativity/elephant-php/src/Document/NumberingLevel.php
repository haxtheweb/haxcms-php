<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/numbering-xml.js (level shape)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class NumberingLevel
{
    public function __construct(
        public int $level,
        public bool $isOrdered,
        // First number to display for an ordered level. Word's exporter
        // sometimes splits a visually-continuous "1., 2., 3." sequence
        // into one abstractNum per item, each with its own `<w:start>`,
        // so we need to honour that value when rendering. Null means
        // "no explicit start" (treat as 1).
        public ?int $start = null,
    ) {
    }
}
