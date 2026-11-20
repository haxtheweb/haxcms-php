<?php
// Process html entity - &#123;, &#xAF;, &quot;, ...

namespace Kaoken\MarkdownIt\RulesInline;

use Kaoken\MarkdownIt\Common\Utils;

class Entity
{
    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function entity(StateInline &$state, bool $silent=false): bool
    {
        $pos = $state->pos;
        $max = $state->posMax;

        if ($state->src[$pos] !== '&') return false;

        if ($pos + 1 >= $max) return false;

        $ch = $state->src[$pos + 1];

        if ($ch === '#') {
            if (preg_match("/^&#((?:x[a-f0-9]{1,6}|[0-9]{1,7}));/i", substr($state->src,$pos), $match)) {
                if (!$silent) {
                    $code = strtolower($match[1][0]) === 'x' ? intval(substr($match[1],1),16) : intval($match[1], 10);

                    $token          = $state->push('text_special', '', 0);
                    $token->content = $state->md->utils->isValidEntityCode($code) ? $state->md->utils->fromCodePoint($code) : $state->md->utils->fromCodePoint(0xFFFD);
                    $token->markup  = $match[0];
                    $token->info    = 'entity';
                }
                $state->pos += strlen($match[0]);
                return true;
            }
        } else {
            if (preg_match("/^(&[a-z][a-z0-9]{1,31};)/i", substr($state->src, $pos), $match)) {
                $decode = html_entity_decode($match[1], ENT_QUOTES|ENT_HTML5, 'UTF-8');
                if ($decode !== $match[1]) {
                    if (!$silent) {
                        $token          = $state->push('text_special', '', 0);
                        $token->content = $decode;
                        $token->markup  = $match[0];
                        $token->info    = 'entity';
                    }
                    $state->pos += strlen($match[0]);
                    return true;
                }
            }
        }

        return false;
    }
}