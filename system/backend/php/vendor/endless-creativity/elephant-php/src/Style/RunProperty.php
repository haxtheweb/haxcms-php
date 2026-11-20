<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/document-matchers.js (b/i/u/strike/all-caps/small-caps)

namespace EndlessCreativity\ElephantPhp\Style;

enum RunProperty: string
{
    case Bold = 'isBold';
    case Italic = 'isItalic';
    case Underline = 'isUnderline';
    case Strikethrough = 'isStrikethrough';
    case AllCaps = 'isAllCaps';
    case SmallCaps = 'isSmallCaps';
}
