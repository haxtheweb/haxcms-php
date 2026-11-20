<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/xml/nodes.js

namespace EndlessCreativity\ElephantPhp\Reader\Xml;

use RuntimeException;

final readonly class Element implements Node
{
    /**
     * @param  array<string, string>  $attributes
     * @param  list<Node>  $children
     */
    public function __construct(
        public string $name,
        public array $attributes = [],
        public array $children = [],
    ) {
    }

    public static function empty(): self
    {
        static $empty = null;

        return $empty ??= new self(name: '');
    }

    public function isEmpty(): bool
    {
        return $this->name === '' && $this->attributes === [] && $this->children === [];
    }

    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    public function first(string $name): ?Element
    {
        foreach ($this->children as $child) {
            if ($child instanceof Element && $child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    public function firstOrEmpty(string $name): Element
    {
        return $this->first($name) ?? self::empty();
    }

    /**
     * @return list<Element>
     */
    public function getElementsByTagName(string $name): array
    {
        $matches = [];
        foreach ($this->children as $child) {
            if ($child instanceof Element && $child->name === $name) {
                $matches[] = $child;
            }
        }

        return $matches;
    }

    public function text(): string
    {
        if ($this->children === []) {
            return '';
        }

        if (count($this->children) !== 1 || ! $this->children[0] instanceof Text) {
            throw new RuntimeException('Cannot read text() of an element whose children are not a single text node');
        }

        return $this->children[0]->value;
    }
}
