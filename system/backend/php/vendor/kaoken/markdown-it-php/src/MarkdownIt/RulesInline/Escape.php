<?php
// Process escaped chars and hardbreaks

namespace Kaoken\MarkdownIt\RulesInline;

use Kaoken\MarkdownIt\Common\Utils;


class Escape
{
    protected array $ESCAPED = [];
    public function __construct()
    {
        $this->ESCAPED = array_fill(0, 256, 0);
        foreach(str_split('\\!"#$%&\'()*+,./:;<=>?@[]^_`{|}~-') as $val){
            $this->ESCAPED[ord($val)] = 1;
        }
    }


    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function escape(StateInline &$state, bool $silent=false): bool
    {
        $pos = $state->pos;
        $max = $state->posMax;

        if ($state->src[$pos] !== '\\') return false;

        $pos++;

        // '\' at the end of the inline block
        if ($pos >= $max) return false;
        $ch1 = $state->src[$pos];

        if ($ch1 === "\n") {
            if (!$silent) {
                $state->push('hardbreak', 'br', 0);
            }

            $pos++;
            // skip leading whitespaces from next line
            while ($pos < $max) {
                $ch1 = $state->src[$pos];
                if (!$state->md->utils->isSpace($ch1)) break;
                $pos++;
            }

            $state->pos = $pos;
            return true;
        }
        // A backslash before a space is literal. Leave the space for the
        // newline rule, so two trailing spaces can still create a hard break.
        if ($ch1 === ' ') {
            if (!$silent) {
                $token          = $state->push('text_special', '', 0);
                $token->content = '\\';
                $token->markup  = '\\';
                $token->info    = 'escape';
            }

            $state->pos = $pos;
            return true;
        }
        $escapedStr = $state->md->utils->currentCharUTF8($state->src, $pos, $charLen);
        if ($charLen < 1) {
            $charLen = 1;
            $escapedStr = $state->src[$pos];
        }
        $pos += $charLen - 1;

        $origStr = '\\' . $escapedStr;

        if (!$silent) {
            $token = $state->push('text_special', '', 0);

            if (ord($ch1) < 256 && $this->ESCAPED[ord($ch1)] !== 0) {
                $token->content = $escapedStr;
            } else {
                $token->content = $origStr;
            }

            $token->markup = $origStr;
            $token->info   = 'escape';
        }

        $state->pos = $pos + 1;
        return true;
    }
}