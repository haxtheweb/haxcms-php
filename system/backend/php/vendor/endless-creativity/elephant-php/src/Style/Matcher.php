<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/document-matchers.js
//
// Subset for v0.1: paragraph and run matchers (with styleId / styleName
// equal or startsWith) plus run-property matchers (b, i, u, strike,
// all-caps, small-caps). Mammoth's table, highlight, comment-reference,
// list and break matchers are TODO.

namespace EndlessCreativity\ElephantPhp\Style;

use EndlessCreativity\ElephantPhp\Document\BreakType;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;

final readonly class Matcher
{
    public function __construct(
        public MatcherKind $kind,
        public ?string $styleId = null,
        public ?string $styleName = null,
        public StyleNameMatch $styleNameMatch = StyleNameMatch::Equal,
        /** Set when the matcher is one of mammoth's run-property forms (b, i, u, strike, all-caps, small-caps). */
        public ?RunProperty $runProperty = null,
        /** Color requested by a `highlight[color='X']` matcher; null means "any color". */
        public ?string $highlightColor = null,
        /** Break type required by `br[type='line|page|column']` matcher. */
        public ?BreakType $breakType = null,
    ) {
    }

    public function matchesParagraph(Paragraph $paragraph): bool
    {
        if ($this->kind !== MatcherKind::Paragraph) {
            return false;
        }

        return $this->matchesStyle($paragraph->styleId, $paragraph->styleName);
    }

    public function matchesRun(Run $run): bool
    {
        if ($this->kind !== MatcherKind::Run) {
            return false;
        }
        if ($this->runProperty !== null && ! $this->runHasProperty($run, $this->runProperty)) {
            return false;
        }

        return $this->matchesStyle($run->styleId, $run->styleName);
    }

    public function matchesTable(\EndlessCreativity\ElephantPhp\Document\Table $table): bool
    {
        if ($this->kind !== MatcherKind::Table) {
            return false;
        }

        return $this->matchesStyle($table->styleId, $table->styleName);
    }

    public function matchesCommentReference(): bool
    {
        return $this->kind === MatcherKind::CommentReference;
    }

    public function matchesHighlight(string $color): bool
    {
        if ($this->kind !== MatcherKind::Highlight) {
            return false;
        }

        return $this->highlightColor === null || $this->highlightColor === $color;
    }

    public function matchesBreak(BreakType $breakType): bool
    {
        return $this->kind === MatcherKind::BreakKind && $this->breakType === $breakType;
    }

    private function runHasProperty(Run $run, RunProperty $property): bool
    {
        return match ($property) {
            RunProperty::Bold => $run->isBold,
            RunProperty::Italic => $run->isItalic,
            RunProperty::Underline => $run->isUnderline,
            RunProperty::Strikethrough => $run->isStrikethrough,
            RunProperty::AllCaps => $run->isAllCaps,
            RunProperty::SmallCaps => $run->isSmallCaps,
        };
    }

    private function matchesStyle(?string $styleId, ?string $styleName): bool
    {
        if ($this->styleId !== null && $this->styleId !== $styleId) {
            return false;
        }
        if ($this->styleName !== null) {
            if ($styleName === null) {
                return false;
            }
            if ($this->styleNameMatch === StyleNameMatch::Equal && $this->styleName !== $styleName) {
                return false;
            }
            if ($this->styleNameMatch === StyleNameMatch::StartsWith && ! str_starts_with($styleName, $this->styleName)) {
                return false;
            }
        }

        return true;
    }
}
