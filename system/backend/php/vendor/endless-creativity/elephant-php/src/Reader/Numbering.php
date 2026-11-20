<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/numbering-xml.js (Numbering)

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Document\NumberingLevel;

final readonly class Numbering
{
    /**
     * @param  array<int, string>  $abstractNumIdByNumId  numId => abstractNumId
     * @param  array<int, array<int, NumberingLevel>>  $levelsByAbstractNumId  abstractNumId => (ilvl => NumberingLevel)
     * @param  array<int, string>  $numStyleLinkByAbstractNumId  abstractNumId => styleId of a numbering-type
     *                                                                          style holding the real numId.
     * @param  array<string, NumberingLevel>  $levelsByParagraphStyleId  paragraphStyleId => NumberingLevel for
     *                                                                                       paragraphs styled
     *                                                                                       this way.
     */
    public function __construct(
        private array $abstractNumIdByNumId = [],
        private array $levelsByAbstractNumId = [],
        private array $numStyleLinkByAbstractNumId = [],
        private array $levelsByParagraphStyleId = [],
        private ?Styles $styles = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function findLevel(string $numId, string $level): ?NumberingLevel
    {
        $abstractNumId = $this->abstractNumIdByNumId[(int) $numId] ?? null;
        if ($abstractNumId === null) {
            return null;
        }

        // `<w:numStyleLink>` is an abstractNum that defers to a numbering-type
        // style: chase the styleId to the numId held on that style and recurse.
        // Without a Styles reference (default Numbering) we can't follow the
        // link and silently treat it as "no level" — same as mammoth would
        // when `styles` is absent.
        $linkedStyleId = $this->numStyleLinkByAbstractNumId[(int) $abstractNumId] ?? null;
        if ($linkedStyleId !== null) {
            $linkedNumId = $this->styles?->findNumberingStyleNumIdById($linkedStyleId);
            if ($linkedNumId === null) {
                return null;
            }

            return $this->findLevel($linkedNumId, $level);
        }

        return $this->levelsByAbstractNumId[(int) $abstractNumId][(int) $level] ?? null;
    }

    /**
     * Returns the level a paragraph inherits from its style id, when the
     * numbering definition tied that style to a specific `<w:lvl>` via an
     * inner `<w:pStyle>`. Used as the fallback when a paragraph references
     * the style without an explicit `<w:numPr>` of its own.
     */
    public function findLevelByParagraphStyleId(string $styleId): ?NumberingLevel
    {
        return $this->levelsByParagraphStyleId[$styleId] ?? null;
    }
}
