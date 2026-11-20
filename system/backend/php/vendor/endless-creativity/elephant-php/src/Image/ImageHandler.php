<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/images.js (imgElement contract)

namespace EndlessCreativity\ElephantPhp\Image;

use EndlessCreativity\ElephantPhp\Document\Image;

interface ImageHandler
{
    /**
     * Produces the HTML attributes for an `<img>` element. Implementations
     * must return at minimum a `src`. Other attributes (e.g. an explicit
     * `alt`) override the default alt-text handling in the converter.
     *
     * @return array<string, string>
     */
    public function attributes(Image $image): array;
}
