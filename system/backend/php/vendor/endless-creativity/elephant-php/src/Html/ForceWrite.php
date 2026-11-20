<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/html/ast.js (forceWrite)

namespace EndlessCreativity\ElephantPhp\Html;

/**
 * Marker node that prevents the Simplifier from removing the parent
 * element when it would otherwise look empty. Used to keep an empty
 * bookmark anchor (`<a id="...">`) alive in the output.
 */
final readonly class ForceWrite implements Node
{
}
