<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/numbering-xml.js
//
// Subset for the v0.1 port: we read w:abstractNum/w:lvl into per-level
// (level, isOrdered) entries, and w:num to map numId -> abstractNumId.
// w:numStyleLink (numbering inherited via a paragraph style) and
// findLevelByParagraphStyleId are intentionally deferred -- they are needed
// only by less common documents and require Styles to be wired in here.

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Document\NumberingLevel;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

final class NumberingReader
{
    public static function readFromXml(Element $root, ?Styles $styles = null): Numbering
    {
        $levelsByAbstractNumId = [];
        $numStyleLinkByAbstractNumId = [];
        $levelsByParagraphStyleId = [];
        foreach ($root->getElementsByTagName('w:abstractNum') as $abstractNum) {
            $abstractNumId = $abstractNum->attribute('w:abstractNumId');
            if ($abstractNumId === null) {
                continue;
            }
            $abstractNumIdInt = (int) $abstractNumId;
            $levels = self::readLevels($abstractNum);
            $levelsByAbstractNumId[$abstractNumIdInt] = $levels['byIlvl'];
            foreach ($levels['byParagraphStyleId'] as $styleId => $level) {
                // First-write-wins: an earlier abstractNum that already
                // claimed this paragraph style id keeps it.
                $levelsByParagraphStyleId[$styleId] ??= $level;
            }
            $linkStyleId = $abstractNum->first('w:numStyleLink')?->attribute('w:val');
            if ($linkStyleId !== null) {
                $numStyleLinkByAbstractNumId[$abstractNumIdInt] = $linkStyleId;
            }
        }

        $abstractNumIdByNumId = [];
        foreach ($root->getElementsByTagName('w:num') as $num) {
            $numId = $num->attribute('w:numId');
            $abstractNumId = $num->first('w:abstractNumId')?->attribute('w:val');
            if ($numId === null || $abstractNumId === null) {
                continue;
            }
            $abstractNumIdByNumId[(int) $numId] = $abstractNumId;
        }

        return new Numbering(
            abstractNumIdByNumId: $abstractNumIdByNumId,
            levelsByAbstractNumId: $levelsByAbstractNumId,
            numStyleLinkByAbstractNumId: $numStyleLinkByAbstractNumId,
            levelsByParagraphStyleId: $levelsByParagraphStyleId,
            styles: $styles,
        );
    }

    /**
     * @return array{byIlvl: array<int, NumberingLevel>, byParagraphStyleId: array<string, NumberingLevel>}
     */
    private static function readLevels(Element $abstractNum): array
    {
        $byIlvl = [];
        $byParagraphStyleId = [];
        $levelWithoutIndex = null;

        foreach ($abstractNum->getElementsByTagName('w:lvl') as $levelElement) {
            $levelIndex = $levelElement->attribute('w:ilvl');
            $numFmt = $levelElement->first('w:numFmt')?->attribute('w:val');
            $isOrdered = $numFmt !== 'bullet';
            $startAttr = $levelElement->first('w:start')?->attribute('w:val');
            $paragraphStyleId = $levelElement->first('w:pStyle')?->attribute('w:val');

            $level = new NumberingLevel(
                level: (int) ($levelIndex ?? 0),
                isOrdered: $isOrdered,
                start: $startAttr !== null ? (int) $startAttr : null,
            );

            if ($levelIndex === null) {
                $levelWithoutIndex ??= $level;
            } else {
                $byIlvl[(int) $levelIndex] = $level;
            }

            if ($paragraphStyleId !== null) {
                // Tie this level to the paragraph style it applies to.
                // First-write-wins for repeats within the same abstractNum.
                $byParagraphStyleId[$paragraphStyleId] ??= $level;
            }
        }

        // Per mammoth: malformed docs may declare a level with no w:ilvl;
        // fall back to level 0 if not already filled.
        if ($levelWithoutIndex !== null && ! isset($byIlvl[0])) {
            $byIlvl[0] = $levelWithoutIndex;
        }

        return ['byIlvl' => $byIlvl, 'byParagraphStyleId' => $byParagraphStyleId];
    }
}
