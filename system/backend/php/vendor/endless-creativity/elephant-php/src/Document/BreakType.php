<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (lineBreak/pageBreak/columnBreak)

namespace EndlessCreativity\ElephantPhp\Document;

enum BreakType: string
{
    case Line = 'line';
    case Page = 'page';
    case Column = 'column';
}
