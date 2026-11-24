<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/html-paths.js (Element)

namespace EndlessCreativity\ElephantPhp\Style;

final readonly class HtmlPathElement
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public string $tagName,
        public array $attributes = [],
        public bool $fresh = false,
        // Text inserted by the simplifier between two adjacent matching
        // elements when they get merged. Mammoth's `:separator('text')`
        // path modifier; null means "no separator".
        public ?string $separator = null,
    ) {
    }
}
