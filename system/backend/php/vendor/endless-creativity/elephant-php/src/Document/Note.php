<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Note)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Note
{
    /**
     * @param  list<Node>  $body
     */
    public function __construct(
        public NoteType $noteType,
        public string $noteId,
        public array $body,
    ) {
    }
}
