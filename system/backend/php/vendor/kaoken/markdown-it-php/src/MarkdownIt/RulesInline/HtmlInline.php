<?php
// Process html tags
namespace Kaoken\MarkdownIt\RulesInline;
use Kaoken\MarkdownIt\Common\Utils;

use Kaoken\MarkdownIt\Common\HtmlRegex;

class HtmlInline
{
    /**
     * @param string $str
     * @return bool
     */
    protected function isLinkOpen(string $str): bool
    {
        return preg_match("/^<a[>\s]/i", $str);
    }

    /**
     * @param string $str
     * @return bool
     */
    protected function isLinkClose(string $str): bool
    {
        return preg_match("/^<\/a\s*>/i", $str);
    }

    /**
     * @param $c
     * @return bool
     */
    protected function isLetter($c): bool
    {
        $ch = ord($c);
        /*eslint no-bitwise:0*/
        $lc = $ch | 0x20; // to lower case
        return ($lc >= 0x61/* a */) && ($lc <= 0x7a/* z */);
    }

    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function htmlInline(StateInline &$state, $silent=false): bool
    {
        $pos = $state->pos;

        if (!$state->md->options->html) { return false; }

        // Check start
        $max = $state->posMax;
        if ($state->src[$pos] !== '<' || $pos + 2 >= $max) {
            return false;
        }

        // Quick fail on second char
        $ch = $state->src[$pos + 1];
        if ($ch !== '!' &&
            $ch !== '?' &&
            $ch !== '/' &&
            !self::isLetter($ch)) {
            return false;
        }

        if ( !preg_match(HtmlRegex::HTML_TAG_RE, substr($state->src, $pos), $match )) { return false; }

        if (!$silent) {
            $token         = $state->push('html_inline', '', 0);
            $token->content = $match[0];

            if ($this->isLinkOpen($token->content))  $state->linkLevel++;
            if ($this->isLinkClose($token->content)) $state->linkLevel--;
        }
        $state->pos += strlen($match[0]);
        return true;
    }
}