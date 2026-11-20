<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/html/ast.js

namespace EndlessCreativity\ElephantPhp\Html;

final readonly class Text implements Node
{
    public function __construct(public string $value)
    {
    }
}
