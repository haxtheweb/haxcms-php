<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/xml/nodes.js

namespace EndlessCreativity\ElephantPhp\Reader\Xml;

final readonly class Text implements Node
{
    public function __construct(public string $value)
    {
    }
}
