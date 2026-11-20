<?php
// Proceess '\n'
namespace Kaoken\MarkdownIt\RulesInline;

use Kaoken\MarkdownIt\Common\Utils;

class NewLine
{
    /**
     * @var Utils|null
     */
    public ?Utils $utils = null;

    public function __construct()
    {
        $this->utils = Utils::getInstance();
    }

    /**
     * @param StateInline $state
     * @param bool $silent
     * @return bool
     */
    public function newline(StateInline &$state, $silent=false): bool
    {
        $pos = $state->pos;

        if ($state->src[$pos] !== "\n") { return false; }

        $pmax = strlen ($state->pending) - 1;
        $max = $state->posMax;

        // '  \n' -> hardbreak
        // Lookup in pending chars is bad practice! Don't copy to other rules!
        // Pending string is stored in concat mode, indexed lookups will cause
        // convertion to flat mode.
        if (!$silent) {
            if ($pmax >= 0 && $state->pending[$pmax] === ' ') {
                if ($pmax >= 1 && $state->pending[$pmax - 1] === ' ') {
                    // Find whitespaces tail of pending chars.
                    $ws = $pmax - 1;
                    while ($ws >= 1 && $state->pending[$ws - 1] === ' ') $ws--;

                    $state->pending = substr($state->pending, 0, $ws);
                    $state->push('hardbreak', 'br', 0);
                } else {
                    $state->pending = substr($state->pending, 0, -1);
                    $state->push('softbreak', 'br', 0);
                }
            } else {
                $state->push('softbreak', 'br', 0);
            }
        }

        $pos++;

        // skip heading spaces for next line
        while ($pos < $max && $this->utils->isSpace($state->src[$pos])) { $pos++; }

        $state->pos = $pos;
        return true;
    }
}