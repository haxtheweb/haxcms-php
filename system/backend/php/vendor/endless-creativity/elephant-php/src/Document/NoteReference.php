<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (NoteReference)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class NoteReference implements Node
{
    public function __construct(
        public NoteType $noteType,
        public string $noteId,
    ) {
    }
}
