<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/html-paths.js (HtmlPath, ignore)

namespace EndlessCreativity\ElephantPhp\Style;

use EndlessCreativity\ElephantPhp\Html\Element as HtmlElement;
use EndlessCreativity\ElephantPhp\Html\Node as HtmlNode;
use EndlessCreativity\ElephantPhp\Html\Tag;

final readonly class HtmlPath
{
    /**
     * @param  list<HtmlPathElement>  $elements  Wrappers from outermost
     *                                           (index 0) to innermost (last).
     * @param  bool  $ignore  When true, applying the path drops the children
     *                        entirely -- the DSL `!` form.
     */
    public function __construct(
        public array $elements = [],
        public bool $ignore = false,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function ignore(): self
    {
        return new self(ignore: true);
    }

    /**
     * @param  list<HtmlNode>  $children
     * @return list<HtmlNode>
     */
    public function applyTo(array $children): array
    {
        if ($this->ignore) {
            return [];
        }

        $result = $children;
        for ($i = count($this->elements) - 1; $i >= 0; $i--) {
            $element = $this->elements[$i];
            $result = [new HtmlElement(
                tag: new Tag(
                    tagName: $element->tagName,
                    attributes: $element->attributes,
                    fresh: $element->fresh,
                    separator: $element->separator,
                ),
                children: $result,
            )];
        }

        return $result;
    }
}
