<?php
namespace Kaoken\MarkdownIt\RulesCore;

use Kaoken\MarkdownIt\Common\Utils;
use Kaoken\MarkdownIt\Token;

class SmartQuotes
{
    const QUOTE_TEST_RE = "/['\"]/";
    const QUOTE_RE = "/['\"]/";

    const APOSTROPHE = "’"; /* \u2019 */

    /**
     * @var Utils|null
     */
    public ?Utils $utils;

    public function __construct()
    {
        $this->utils = Utils::getInstance();
    }

    /**
     * Record a replacement against the original token content. Delaying all
     * writes avoids repeatedly rebuilding long strings and keeps offsets stable.
     *
     * @param array $replacements
     * @param integer $tokenIdx
     * @param integer $pos
     * @param string $ch
     */
    protected function addReplacement(array &$replacements, int $tokenIdx, int $pos, string $ch): void
    {
        if (!isset($replacements[$tokenIdx])) {
            $replacements[$tokenIdx] = [];
        }

        $replacements[$tokenIdx][] = [ 'pos' => $pos, 'ch' => $ch ];
    }

    /**
     * @param string $str
     * @param array $replacements
     * @return string
     */
    protected function applyReplacements(string $str, array $replacements): string
    {
        usort($replacements, function ($a, $b) {
            return $a['pos'] <=> $b['pos'];
        });

        $result = '';
        $lastPos = 0;

        foreach ($replacements as $replacement) {
            $result .= substr($str, $lastPos, $replacement['pos'] - $lastPos);
            $result .= $replacement['ch'];
            $lastPos = $replacement['pos'] + 1;
        }

        return $result . substr($str, $lastPos);
    }

    /**
     * Return one Unicode character from the configured quote string.
     *
     * @param string|array $quotes
     * @param integer $index
     * @return string
     */
    protected function quoteAt($quotes, int $index): string
    {
        if (is_array($quotes)) {
            return $quotes[$index] ?? '';
        }

        if (preg_match_all('/./us', $quotes, $matches) === false) {
            return '';
        }

        return $matches[0][$index] ?? '';
    }

    /**
     * @param Token[] $tokens
     * @param StateCore $state
     */
    protected function process_inlines(array &$tokens, StateCore &$state): void
    {
        $stack = [];
        $replacements = [];
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            $thisLevel = $token->level;

            for ($j = count($stack) - 1; $j >= 0; $j--) {
                if ($stack[$j]->level <= $thisLevel) { break; }
            }
            $this->utils->resizeArray($stack, $j + 1);

            if ($token->type !== 'text') { continue; }

            $text = $token->content;
            $pos = 0;
            $max = strlen($text);

            while ($pos < $max) {
                if (!preg_match(self::QUOTE_RE, $text, $match, PREG_OFFSET_CAPTURE, $pos)) { break; }

                $quote = $match[0][0];
                $quotePos = $match[0][1];
                $canOpen = true;
                $canClose = true;
                $pos = $quotePos + 1;
                $isSingle = ($quote === "'");

                // Find the previous character. A line boundary is whitespace.
                $lastChar = ' ';
                if ($quotePos > 0) {
                    $dummy = 0;
                    $lastChar = $this->utils->lastCharUTF8($text, $quotePos, $dummy);
                } else {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if ($tokens[$j]->type === 'softbreak' || $tokens[$j]->type === 'hardbreak') { break; }
                        if ($tokens[$j]->content === '') { continue; }

                        $dummy = 0;
                        $content = $tokens[$j]->content;
                        $lastChar = $this->utils->lastCharUTF8($content, strlen($content), $dummy);
                        break;
                    }
                }

                // Find the next character. A line boundary is whitespace.
                $nextChar = ' ';
                if ($pos < $max) {
                    $dummy = 0;
                    $nextChar = $this->utils->currentCharUTF8($text, $pos, $dummy);
                } else {
                    for ($j = $i + 1; $j < $tokenCount; $j++) {
                        if ($tokens[$j]->type === 'softbreak' || $tokens[$j]->type === 'hardbreak') { break; }
                        if ($tokens[$j]->content === '') { continue; }

                        $dummy = 0;
                        $content = $tokens[$j]->content;
                        $nextChar = $this->utils->currentCharUTF8($content, 0, $dummy);
                        break;
                    }
                }

                $isLastPunctChar = $this->utils->isMdAsciiPunct($lastChar) || (bool)$this->utils->isPunctChar($lastChar);
                $isNextPunctChar = $this->utils->isMdAsciiPunct($nextChar) || (bool)$this->utils->isPunctChar($nextChar);

                $isLastWhiteSpace = $this->utils->isWhiteSpace($lastChar);
                $isNextWhiteSpace = $this->utils->isWhiteSpace($nextChar);

                if ($isNextWhiteSpace) {
                    $canOpen = false;
                } else if ($isNextPunctChar && !($isLastWhiteSpace || $isLastPunctChar)) {
                    $canOpen = false;
                }

                if ($isLastWhiteSpace) {
                    $canClose = false;
                } else if ($isLastPunctChar && !($isNextWhiteSpace || $isNextPunctChar)) {
                    $canClose = false;
                }

                if ($nextChar === '"' && $quote === '"' && strlen($lastChar) === 1) {
                    $lastCode = ord($lastChar);
                    if ($lastCode >= 0x30 && $lastCode <= 0x39) {
                        // Special case: 1"" - count first quote as an inch.
                        $canClose = false;
                        $canOpen = false;
                    }
                }

                if ($canOpen && $canClose) {
                    // Replace punctuation-adjacent quotes, but not quotes inside words.
                    $canOpen = $isLastPunctChar;
                    $canClose = $isNextPunctChar;
                }

                if (!$canOpen && !$canClose) {
                    if ($isSingle) {
                        $this->addReplacement($replacements, $i, $quotePos, self::APOSTROPHE);
                    }
                    continue;
                }

                $matchedClose = false;
                if ($canClose) {
                    for ($j = count($stack) - 1; $j >= 0; $j--) {
                        $item = $stack[$j];
                        if ($item->level < $thisLevel) { break; }

                        if ($item->single === $isSingle && $item->level === $thisLevel) {
                            if ($isSingle) {
                                $openQuote = $this->quoteAt($state->md->options->quotes, 2);
                                $closeQuote = $this->quoteAt($state->md->options->quotes, 3);
                            } else {
                                $openQuote = $this->quoteAt($state->md->options->quotes, 0);
                                $closeQuote = $this->quoteAt($state->md->options->quotes, 1);
                            }

                            $this->addReplacement($replacements, $i, $quotePos, $closeQuote);
                            $this->addReplacement($replacements, $item->token, $item->pos, $openQuote);

                            $this->utils->resizeArray($stack, $j);
                            $matchedClose = true;
                            break;
                        }
                    }
                }

                if ($matchedClose) { continue; }

                if ($canOpen) {
                    $item = new \stdClass();
                    $item->token = $i;
                    $item->pos = $quotePos;
                    $item->single = $isSingle;
                    $item->level = $thisLevel;
                    $stack[] = $item;
                } else if ($canClose && $isSingle) {
                    $this->addReplacement($replacements, $i, $quotePos, self::APOSTROPHE);
                }
            }
        }

        foreach ($replacements as $tokenIdx => $tokenReplacements) {
            $tokens[$tokenIdx]->content = $this->applyReplacements(
                $tokens[$tokenIdx]->content,
                $tokenReplacements
            );
        }
    }

    /**
     * @param StateCore $state
     */
    public function set(StateCore &$state): void
    {
        if (!$state->md->options->typographer) { return; }

        for ($blkIdx = count($state->tokens) - 1; $blkIdx >= 0; $blkIdx--) {
            if ($state->tokens[$blkIdx]->type !== 'inline' ||
                !preg_match(self::QUOTE_TEST_RE, $state->tokens[$blkIdx]->content)) {
                continue;
            }

            $this->process_inlines($state->tokens[$blkIdx]->children, $state);
        }
    }
}
