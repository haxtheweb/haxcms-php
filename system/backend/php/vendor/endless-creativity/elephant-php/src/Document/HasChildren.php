<?php

declare(strict_types=1);

// Marker interface used by Transforms to walk and rebuild the document
// tree generically. Mammoth gets away without it because JS objects are
// open structures; in PHP we need an explicit way to say "this node has
// children, here's how to clone it with new ones".

namespace EndlessCreativity\ElephantPhp\Document;

interface HasChildren extends Node
{
    /**
     * @return list<Node>
     */
    public function getChildren(): array;

    /**
     * Returns a new instance of the same type with the given children.
     * Implementations preserve all other fields verbatim.
     *
     * @param  list<Node>  $children
     */
    public function withChildren(array $children): self;
}
