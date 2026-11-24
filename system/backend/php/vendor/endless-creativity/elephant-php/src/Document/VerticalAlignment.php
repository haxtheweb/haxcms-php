<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (verticalAlignment)

namespace EndlessCreativity\ElephantPhp\Document;

enum VerticalAlignment: string
{
    case Baseline = 'baseline';
    case Superscript = 'superscript';
    case Subscript = 'subscript';
}
