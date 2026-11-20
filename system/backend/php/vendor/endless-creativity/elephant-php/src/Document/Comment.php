<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (comment)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Comment
{
    /**
     * @param  list<Node>  $body
     */
    public function __construct(
        public string $commentId,
        public array $body,
        public ?string $authorName = null,
        public ?string $authorInitials = null,
    ) {
    }
}
