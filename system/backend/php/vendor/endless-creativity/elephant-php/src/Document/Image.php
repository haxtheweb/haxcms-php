<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Image)

namespace EndlessCreativity\ElephantPhp\Document;

use Closure;

final readonly class Image implements Node
{
    /**
     * @param  Closure(): string  $readBytes  Lazy reader returning the raw image
     *                                        bytes; invoked at render time so
     *                                        documents that never render their
     *                                        images don't pay the I/O cost.
     */
    public function __construct(
        public Closure $readBytes,
        public ?string $contentType = null,
        public ?string $altText = null,
    ) {
    }
}
