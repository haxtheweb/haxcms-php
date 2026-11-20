<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (BookmarkStart)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class BookmarkStart implements Node
{
    public function __construct(public string $name)
    {
    }
}
