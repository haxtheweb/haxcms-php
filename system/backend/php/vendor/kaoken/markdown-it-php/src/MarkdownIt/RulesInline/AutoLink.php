<?php
// Process autolinks '<protocol:...>'

namespace Kaoken\MarkdownIt\RulesInline;
use Kaoken\MarkdownIt\Common\Utils;


class AutoLink
{
    /* eslint max-len:0 */
    const EMAIL_RE    = "/^([a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)$/u";
    /* eslint-disable-next-line no-control-regex */
    const AUTOLINK_RE = "/^([a-zA-Z][a-zA-Z0-9+.\-]{1,31}):([^<>\x{00}-\x{20}]*)$/u";

    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function autoLink(StateInline &$state, bool $silent=false): bool
    {
        $pos = $state->pos;

        if ($state->src[$pos] !== '<') { return false; }

        $start = $state->pos;
        $max = $state->posMax;
        while (true) {
            if (++$pos >= $max) return false;

            $ch = $state->src[$pos];

            if ($ch === '<') return false;
            if ($ch === '>') break;
        }

        $url = substr($state->src,$start + 1, $pos-($start + 1));

        if (preg_match(self::AUTOLINK_RE, $url)) {
            $fullUrl = $state->md->normalizeLink($url);
            if (!$state->md->validateLink($fullUrl)) { return false; }

            if (!$silent) {
                $token         = $state->push('link_open', 'a', 1);
                $token->attrs   = [ [ 'href', $fullUrl ] ];
                $token->markup  = 'autolink';
                $token->info    = 'auto';

                $token         = $state->push('text', '', 0);
                $token->content = $state->md->normalizeLinkText($url);

                $token         = $state->push('link_close', 'a', -1);
                $token->markup  = 'autolink';
                $token->info    = 'auto';
            }

            $state->pos += strlen($url) + 2;
            return true;
        }

        if (preg_match(self::EMAIL_RE, $url)) {
            $fullUrl = $state->md->normalizeLink('mailto:' . $url);
            if (!$state->md->validateLink($fullUrl)) { return false; }

            if (!$silent) {
                $token         = $state->push('link_open', 'a', 1);
                $token->attrs   = [ [ 'href', $fullUrl ] ];
                $token->markup  = 'autolink';
                $token->info    = 'auto';

                $token         = $state->push('text', '', 0);
                $token->content = $state->md->normalizeLinkText($url);

                $token         = $state->push('link_close', 'a', -1);
                $token->markup  = 'autolink';
                $token->info    = 'auto';
            }

            $state->pos += strlen($url) + 2;
            return true;
        }

        return false;
    }
}