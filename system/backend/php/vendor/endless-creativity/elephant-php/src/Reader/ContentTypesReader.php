<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/content-types-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

final class ContentTypesReader
{
    public static function readFromXml(Element $element): ContentTypes
    {
        $extensionDefaults = [];
        $overrides = [];

        foreach ($element->children as $child) {
            if (! $child instanceof Element) {
                continue;
            }

            if ($child->name === 'content-types:Default') {
                $extension = $child->attribute('Extension');
                $contentType = $child->attribute('ContentType');
                if ($extension !== null && $contentType !== null) {
                    $extensionDefaults[$extension] = $contentType;
                }
            } elseif ($child->name === 'content-types:Override') {
                $partName = $child->attribute('PartName');
                $contentType = $child->attribute('ContentType');
                if ($partName !== null && $contentType !== null) {
                    if (str_starts_with($partName, '/')) {
                        $partName = mb_substr($partName, 1);
                    }
                    $overrides[$partName] = $contentType;
                }
            }
        }

        return new ContentTypes(overrides: $overrides, extensionDefaults: $extensionDefaults);
    }
}
