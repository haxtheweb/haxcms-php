<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/relationships-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

final class RelationshipsReader
{
    public static function readFromXml(Element $element): Relationships
    {
        $relationships = [];
        foreach ($element->children as $child) {
            if (! $child instanceof Element || $child->name !== 'relationships:Relationship') {
                continue;
            }

            $id = $child->attribute('Id');
            $target = $child->attribute('Target');
            $type = $child->attribute('Type');
            if ($id === null || $target === null || $type === null) {
                continue;
            }

            $relationships[] = new Relationship(relationshipId: $id, target: $target, type: $type);
        }

        return new Relationships($relationships);
    }
}
