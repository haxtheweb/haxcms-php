<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/styles-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final readonly class Styles
{
    /**
     * @param  array<string, Style>  $paragraphStyles
     * @param  array<string, Style>  $characterStyles
     * @param  array<string, Style>  $tableStyles
     * @param  array<string, string>  $numberingStyleNumIdByStyleId  Numbering-type style id => numId it points at.
     */
    public function __construct(
        private array $paragraphStyles = [],
        private array $characterStyles = [],
        private array $tableStyles = [],
        private array $numberingStyleNumIdByStyleId = [],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function findParagraphStyleById(string $styleId): ?Style
    {
        return $this->paragraphStyles[$styleId] ?? null;
    }

    public function findCharacterStyleById(string $styleId): ?Style
    {
        return $this->characterStyles[$styleId] ?? null;
    }

    public function findTableStyleById(string $styleId): ?Style
    {
        return $this->tableStyles[$styleId] ?? null;
    }

    /**
     * Returns the `<w:numId>` declared inside a `<w:style w:type="numbering">`
     * element, or null if the styleId doesn't match a numbering style. Used
     * by `Numbering::findLevel` to chase `<w:numStyleLink>` indirections from
     * one abstractNum to the numId held on a numbering style.
     */
    public function findNumberingStyleNumIdById(string $styleId): ?string
    {
        return $this->numberingStyleNumIdByStyleId[$styleId] ?? null;
    }
}
