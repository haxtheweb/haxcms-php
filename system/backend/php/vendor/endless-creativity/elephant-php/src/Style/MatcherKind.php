<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/document-matchers.js (paragraph/run)

namespace EndlessCreativity\ElephantPhp\Style;

enum MatcherKind: string
{
    case Paragraph = 'paragraph';
    case Run = 'run';
    case Table = 'table';
    case CommentReference = 'commentReference';
    case Highlight = 'highlight';
    case BreakKind = 'break';
}
