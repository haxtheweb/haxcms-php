<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/writers/html-writer.js + lib/html/index.js

namespace EndlessCreativity\ElephantPhp\Html;

final class HtmlWriter
{
    /**
     * Block-like elements that get a leading newline + indentation
     * before their open tag (and before their close tag) when
     * prettyPrint is enabled. Mirrors mammoth's `indentedElements`
     * — intentionally minimal: inline tags like `<a>`, `<strong>`,
     * `<sup>` flow without indentation so paragraph text isn't
     * fragmented.
     *
     * @var array<string, true>
     */
    private const INDENTED_ELEMENTS = [
        'div' => true,
        'p' => true,
        'ul' => true,
        'li' => true,
    ];

    private const INDENTATION = '  ';

    /**
     * @param  list<Node>  $nodes
     */
    public static function write(array $nodes, bool $prettyPrint = false): string
    {
        if ($prettyPrint) {
            return (new self())->writePretty($nodes);
        }

        $fragments = [];
        foreach ($nodes as $node) {
            self::writeNode($node, $fragments);
        }

        return implode('', $fragments);
    }

    /**
     * @param  list<string>  $fragments
     * @param-out  list<string>  $fragments
     */
    private static function writeNode(Node $node, array &$fragments): void
    {
        if ($node instanceof Text) {
            $fragments[] = self::escapeText($node->value);

            return;
        }

        if ($node instanceof Element) {
            if ($node->isVoid()) {
                $fragments[] = '<'.$node->tag->tagName.self::renderAttributes($node->tag->attributes).' />';

                return;
            }

            $fragments[] = '<'.$node->tag->tagName.self::renderAttributes($node->tag->attributes).'>';
            foreach ($node->children as $child) {
                self::writeNode($child, $fragments);
            }
            $fragments[] = '</'.$node->tag->tagName.'>';
        }
    }

    private string $output = '';

    private int $indentLevel = 0;

    /** @var list<string> */
    private array $stack = [];

    private bool $atStart = true;

    private bool $inText = false;

    /**
     * @param  list<Node>  $nodes
     */
    private function writePretty(array $nodes): string
    {
        foreach ($nodes as $node) {
            $this->writePrettyNode($node);
        }

        return $this->output;
    }

    private function writePrettyNode(Node $node): void
    {
        if ($node instanceof Text) {
            // First text node after a tag open / close gets its own
            // indented line; subsequent sibling text continues on the
            // same line. Newlines inside text are re-indented to keep
            // the output's column alignment consistent (skip when
            // inside a <pre>, where whitespace is significant).
            if (! $this->inText) {
                $this->indent();
                $this->inText = true;
            }
            $value = $this->isInPre()
                ? $node->value
                : str_replace("\n", "\n".str_repeat(self::INDENTATION, $this->indentLevel), $node->value);
            $this->output .= self::escapeText($value);

            return;
        }

        if (! $node instanceof Element) {
            return;
        }

        if ($node->isVoid()) {
            $this->indent();
            $this->output .= '<'.$node->tag->tagName.self::renderAttributes($node->tag->attributes).' />';

            return;
        }

        $isIndented = isset(self::INDENTED_ELEMENTS[$node->tag->tagName]);

        if ($isIndented) {
            $this->indent();
        }
        $this->stack[] = $node->tag->tagName;
        $this->output .= '<'.$node->tag->tagName.self::renderAttributes($node->tag->attributes).'>';
        if ($isIndented) {
            $this->indentLevel++;
        }
        $this->atStart = false;

        foreach ($node->children as $child) {
            $this->writePrettyNode($child);
        }

        if ($isIndented) {
            $this->indentLevel--;
            $this->indent();
        }
        array_pop($this->stack);
        $this->output .= '</'.$node->tag->tagName.'>';
    }

    private function indent(): void
    {
        $this->inText = false;
        if ($this->atStart) {
            return;
        }
        if (! $this->insideIndentedElement()) {
            return;
        }
        if ($this->isInPre()) {
            return;
        }
        $this->output .= "\n".str_repeat(self::INDENTATION, $this->indentLevel);
    }

    private function insideIndentedElement(): bool
    {
        // At top level (no parent) we still want indentation between
        // sibling block elements; mammoth treats the empty stack as
        // an indented context for the same reason.
        if ($this->stack === []) {
            return true;
        }
        $top = $this->stack[count($this->stack) - 1];

        return isset(self::INDENTED_ELEMENTS[$top]);
    }

    private function isInPre(): bool
    {
        return in_array('pre', $this->stack, true);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function renderAttributes(array $attributes): string
    {
        $rendered = '';
        foreach ($attributes as $name => $value) {
            $rendered .= ' '.$name.'="'.self::escapeAttribute($value).'"';
        }

        return $rendered;
    }

    private static function escapeText(string $value): string
    {
        return strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    private static function escapeAttribute(string $value): string
    {
        return strtr($value, ['&' => '&amp;', '"' => '&quot;', '<' => '&lt;', '>' => '&gt;']);
    }
}
