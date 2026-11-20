<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/style-reader.js (styleRule shape)

namespace EndlessCreativity\ElephantPhp\Style;

final readonly class StyleMapping
{
    public function __construct(
        public Matcher $from,
        public HtmlPath $to,
    ) {
    }
}
