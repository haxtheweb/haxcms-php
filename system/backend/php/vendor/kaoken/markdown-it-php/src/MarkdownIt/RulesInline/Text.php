<?php
// Skip text characters for text token, place those to pending buffer
// and increment current pos
namespace Kaoken\MarkdownIt\RulesInline;

use Kaoken\MarkdownIt\Common\Utils;

class Text
{
    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function text(StateInline &$state, bool $silent=false): bool
    {
        $pos = $state->pos;

        while ($pos < $state->posMax && !$this->isTerminatorChar($state->src[$pos])) {
            $pos++;
        }

        if ($pos === $state->pos) { return false; }

        if (!$silent) { $state->pending .= substr($state->src, $state->pos, $pos-$state->pos); }

        $state->pos = $pos;
        return true;
    }

    /**
     * Rule to skip pure text
     * '{}$%@~+=:' reserved for extentions
     *  !, ", #, $, %, &, ', (, ), *, +, ,, -, ., /, :, ;, <, =, >, ?, @, [, \, ], ^, _, `, {, |, }, or ~
     * !!!! Don't confuse with "Markdown ASCII Punctuation" chars
     * @see http://spec.commonmark.org/0.15/#ascii-punctuation-character
     * @param string $ch
     * @return bool
     */
    protected function isTerminatorChar(string $ch): bool
    {
        switch ($ch) {
            case "\n":
            case "!":
            case "#":
            case "$":
            case "%":
            case "&":
            case "*":
            case "+":
            case "-":
            case ":":
            case "<":
            case "=":
            case ">":
            case "@":
            case "[":
            case "\\":
            case "]":
            case "^":
            case "_":
            case "`":
            case "{":
            case "}":
            case "~":
                return true;
            default:
                return false;
        }
    }
}