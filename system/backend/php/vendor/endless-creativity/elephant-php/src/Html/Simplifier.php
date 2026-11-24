<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/html/simplify.js

namespace EndlessCreativity\ElephantPhp\Html;

final class Simplifier
{
    /**
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    public static function simplify(array $nodes): array
    {
        return self::collapse(self::removeEmpty($nodes));
    }

    /**
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    private static function removeEmpty(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof ForceWrite) {
                // ForceWrite is "never empty" -- keeping it in the parent's
                // children prevents removeEmpty from dropping the parent.
                $result[] = $node;

                continue;
            }

            if ($node instanceof Text) {
                if ($node->value !== '') {
                    $result[] = $node;
                }

                continue;
            }

            if ($node instanceof Element) {
                $children = self::removeEmpty($node->children);
                if ($children === [] && ! $node->isVoid()) {
                    continue;
                }
                $result[] = $node->withChildren($children);
            }
        }

        return $result;
    }

    /**
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    private static function collapse(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $node) {
            self::appendChild($children, self::collapseNode($node));
        }

        return $children;
    }

    private static function collapseNode(Node $node): Node
    {
        if ($node instanceof Element) {
            return $node->withChildren(self::collapse($node->children));
        }

        return $node;
    }

    /**
     * @param  list<Node>  $children
     * @param-out  list<Node>  $children
     */
    private static function appendChild(array &$children, Node $child): void
    {
        $lastIndex = count($children) - 1;
        $last = $lastIndex >= 0 ? $children[$lastIndex] : null;

        if (
            $child instanceof Element
            && ! $child->tag->fresh
            && $last instanceof Element
            && $child->tag->matches($last->tag)
        ) {
            $merged = $last->children;
            if ($child->tag->separator !== null) {
                self::appendChild($merged, new Text(value: $child->tag->separator));
            }
            foreach ($child->children as $grandChild) {
                self::appendChild($merged, $grandChild);
            }
            $children[$lastIndex] = $last->withChildren($merged);

            return;
        }

        $children[] = $child;
    }
}
