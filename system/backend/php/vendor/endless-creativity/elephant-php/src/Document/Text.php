<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Text implements Node
{
    public function __construct(public string $value)
    {
    }
}
