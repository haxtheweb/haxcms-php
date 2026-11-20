<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/raw-text.js (convertElementToRawText)

namespace EndlessCreativity\ElephantPhp\Document;

final class RawTextExtractor
{
    public static function extract(Node $node): string
    {
        if ($node instanceof Text) {
            return $node->value;
        }

        $children = self::childrenOf($node);
        $body = '';
        foreach ($children as $child) {
            if ($child instanceof Node) {
                $body .= self::extract($child);
            }
        }

        // Mammoth appends "\n\n" after every paragraph block; everything
        // else just contributes its descendant text.
        return $body.($node instanceof Paragraph ? "\n\n" : '');
    }

    /**
     * @return list<mixed>
     */
    private static function childrenOf(Node $node): array
    {
        return match (true) {
            $node instanceof Document, $node instanceof Paragraph, $node instanceof Run, $node instanceof Hyperlink, $node instanceof Table, $node instanceof TableRow, $node instanceof TableCell => $node->children,
            default => [],
        };
    }
}
