<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/styles-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final readonly class Style
{
    public function __construct(
        public string $styleId,
        public ?string $name = null,
    ) {
    }
}
