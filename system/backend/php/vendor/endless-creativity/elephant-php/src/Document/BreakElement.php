<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Break)
//
// Named BreakElement rather than the more natural Break because `break`
// is a reserved keyword in PHP and cannot be used as a class name.

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class BreakElement implements Node
{
    public function __construct(public BreakType $breakType)
    {
    }
}
