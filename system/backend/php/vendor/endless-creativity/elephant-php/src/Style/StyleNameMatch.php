<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/document-matchers.js (equalTo, startsWith)

namespace EndlessCreativity\ElephantPhp\Style;

enum StyleNameMatch: string
{
    case Equal = 'equal';
    case StartsWith = 'startsWith';
}
