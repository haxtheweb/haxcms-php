<?php
// Simple typographic replacements
//
// (c) (C) → ©
// (tm) (TM) → ™
// (r) (R) → ®
// +- → ±
// ... → … (also ?.... → ?.., !.... → !..)
// ???????? → ???, !!!!! → !!!, `,,` → `,`
// -- → &ndash;, --- → &mdash;
//
namespace Kaoken\MarkdownIt\RulesCore;


use Kaoken\MarkdownIt\Token;

class ReplaceMents
{
    // TODO:
    // - fractionals 1/2, 1/4, 3/4 -> ½, ¼, ¾
    // - multiplications 2 x 4 -> 2 × 4

    const RARE_RE = "/\+-|\.\.|\?\?\?\?|!!!!|,,|--/";

    // Workaround for phantomjs - need regex without /g flag,
    // or root check will fail every second time
    const SCOPED_ABBR_TEST_RE = "/\((c|tm|r)\)/i"; // /g

    const SCOPED_ABBR_RE = [
        '/\(c\)/i',
        '/\(r\)/i',
        '/\(tm\)/i',
    ]; // /g
    const SCOPED_ABBR = [
        '©',
        '®',
        '™'
    ];

    /**
     * @param Token[] $inlineTokens
     */
    protected function replace_scoped(array &$inlineTokens): void
    {
        $inside_autolink = 0;
        for ($i = count($inlineTokens) - 1; $i >= 0; $i--) {
            $token = $inlineTokens[$i];

            if ($token->type === 'text' && !$inside_autolink) {
                $token->content = preg_replace(self::SCOPED_ABBR_RE, self::SCOPED_ABBR, $token->content);
            }

            if ($token->type === 'link_open' && $token->info === 'auto') {
                $inside_autolink--;
            }

            if ($token->type === 'link_close' && $token->info === 'auto') {
                $inside_autolink++;
            }
        }
    }

    /**
     * @param Token[] $inlineTokens
     */
    protected function replace_rare(array &$inlineTokens): void
    {
        $inside_autolink = 0;

        for ($i = count($inlineTokens) - 1; $i >= 0; $i--) {
            $token = $inlineTokens[$i];

            if ($token->type === 'text' && !$inside_autolink) {
                if (preg_match(self::RARE_RE,$token->content)) {
                    $p = preg_replace("/\+-/", '±', $token->content);
                    // .., ..., ....... -> …
                    // but ?..... & !..... -> ?.. & !..
                    $p = preg_replace("/\.{2,}/", '…', $p);
                    $p = preg_replace("/([?!])…/", '$1..', $p);
                    $p = preg_replace("/([?!]){4,}/", '$1$1$1', $p);
                    $p = preg_replace("/,{2,}/", ',', $p);
                    // em-dash
                    $p = preg_replace("/(^|[^-])---(?=[^-]|$)/m", '$1—', $p);    // '$1\u2014'
                    // en-dash
                    $p = preg_replace("/(^|\s)--(?=\s|$)/m", '$1–', $p);            // '$1\u2013'
                    $token->content = preg_replace("/(^|[^-\s])--(?=[^-\s]|$)/m", '$1–', $p);    // '$1\u2013'
                }
            }

            if ($token->type === 'link_open' && $token->info === 'auto') {
                $inside_autolink--;
            }

            if ($token->type === 'link_close' && $token->info === 'auto') {
                $inside_autolink++;
            }
        }
    }

    /**
     * @param StateCore $state
     */
    public function set(StateCore &$state): void
    {
        if (!$state->md->options->typographer) { return; }

        for ($blkIdx = count($state->tokens) - 1; $blkIdx >= 0; $blkIdx--) {

            if ($state->tokens[$blkIdx]->type !== 'inline') { continue; }

            if (preg_match(self::SCOPED_ABBR_TEST_RE, $state->tokens[$blkIdx]->content)) {
                $this->replace_scoped($state->tokens[$blkIdx]->children);
            }

            if (preg_match(self::RARE_RE, $state->tokens[$blkIdx]->content)) {
                $this->replace_rare($state->tokens[$blkIdx]->children);
            }

        }
    }
}