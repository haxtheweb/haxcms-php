<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/relationships-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final readonly class Relationship
{
    public function __construct(
        public string $relationshipId,
        public string $target,
        public string $type,
    ) {
    }
}
