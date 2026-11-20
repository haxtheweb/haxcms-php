<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/transforms.js
//
// Helpers for transforming the parsed Document tree before HTML/Markdown
// conversion. Pair with Converter::$transformDocument: build a transformer
// here, hand it to the Converter, and the parsed Document is rewritten
// before rendering.

namespace EndlessCreativity\ElephantPhp\Document;

use Closure;

final class Transforms
{
    /**
     * Returns a Closure that walks any node and applies $transform to every
     * Paragraph descendant (post-order). Other nodes pass through untouched.
     *
     * @param  callable(Paragraph): Paragraph  $transform
     * @return Closure(Node): Node
     */
    public static function paragraph(callable $transform): Closure
    {
        return self::elementsOfType(Paragraph::class, $transform);
    }

    /**
     * @param  callable(Run): Run  $transform
     * @return Closure(Node): Node
     */
    public static function run(callable $transform): Closure
    {
        return self::elementsOfType(Run::class, $transform);
    }

    /**
     * Returns a Closure that walks the tree and applies $transform only to
     * descendants whose class matches $type. Pass an interface or a parent
     * class to widen the match (e.g. `HasChildren::class`).
     *
     * @template T of Node
     *
     * @param  class-string<T>  $type
     * @param  callable(T): Node  $transform
     * @return Closure(Node): Node
     */
    public static function elementsOfType(string $type, callable $transform): Closure
    {
        return self::elements(static function (Node $node) use ($type, $transform): Node {
            return $node instanceof $type ? $transform($node) : $node;
        });
    }

    /**
     * Generic post-order walker: recurses into every HasChildren node first,
     * then applies $transform to the (possibly rebuilt) parent. The callback
     * receives every node in the tree, leaves included.
     *
     * @param  callable(Node): Node  $transform
     * @return Closure(Node): Node
     */
    public static function elements(callable $transform): Closure
    {
        $walker = static function (Node $node) use (&$walker, $transform): Node {
            if ($node instanceof HasChildren) {
                $rebuilt = [];
                foreach ($node->getChildren() as $child) {
                    /** @var callable(Node): Node $walker */
                    $rebuilt[] = $walker($child);
                }
                $node = $node->withChildren($rebuilt);
            }

            return $transform($node);
        };

        return $walker;
    }

    /**
     * Returns every descendant of $node in document order (children of children
     * first, then siblings -- post-order to match mammoth's
     * `getDescendants`). The root node itself is not included.
     *
     * @return list<Node>
     */
    public static function getDescendants(Node $node): array
    {
        $descendants = [];
        self::visitDescendants($node, static function (Node $descendant) use (&$descendants): void {
            $descendants[] = $descendant;
        });

        return $descendants;
    }

    /**
     * Filters `getDescendants` by class.
     *
     * @template T of Node
     *
     * @param  class-string<T>  $type
     * @return list<T>
     */
    public static function getDescendantsOfType(Node $node, string $type): array
    {
        return array_values(array_filter(
            self::getDescendants($node),
            static fn (Node $descendant): bool => $descendant instanceof $type,
        ));
    }

    /**
     * @param  callable(Node): void  $visit
     */
    private static function visitDescendants(Node $node, callable $visit): void
    {
        if (! $node instanceof HasChildren) {
            return;
        }
        foreach ($node->getChildren() as $child) {
            self::visitDescendants($child, $visit);
            $visit($child);
        }
    }
}
