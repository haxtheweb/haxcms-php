<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/results.js

namespace EndlessCreativity\ElephantPhp;

enum MessageType: string
{
    case Warning = 'warning';
    case Error = 'error';
}
