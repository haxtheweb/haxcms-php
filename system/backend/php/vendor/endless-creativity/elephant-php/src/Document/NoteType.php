<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (note types)

namespace EndlessCreativity\ElephantPhp\Document;

enum NoteType: string
{
    case Footnote = 'footnote';
    case Endnote = 'endnote';
}
