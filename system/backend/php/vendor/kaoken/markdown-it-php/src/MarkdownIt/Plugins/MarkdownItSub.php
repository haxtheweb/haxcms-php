<?php
/**
 * Copyright (c) 2014-2015 Vitaly Puzrin, Alex Kocharin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 * use javascript version 2.0.0
 * @see https://github.com/markdown-it/markdown-it-sub/tree/2.0.0
 */
namespace Kaoken\MarkdownIt\Plugins;


use Exception;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\RulesInline\StateInline;

class MarkdownItSub
{
    // same as UNESCAPE_MD_RE plus a space
    const UNESCAPE_RE = "/\\\\([ \\\\!\"#$%&'()*+,.\/:;<=>?@[\]^_`{|}~-])/";


    /**
     * @param MarkdownIt $md
     * @throws Exception
     */
    public function plugin(MarkdownIt $md)
    {
        $md->inline->ruler->after('emphasis', 'sub', [$this, 'subscript']);
    }


    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     */
    public function subscript(StateInline &$state, $silent=false): bool
    {
        $max = $state->posMax;
        $start = $state->pos;

        if ($state->src[$start] !== '~') { return false; }
        if ($silent) { return false; } // don't run any pairs in validation mode
        if ($start + 2 >= $max) { return false; }

        $state->pos = $start + 1;

        $found = false;
        while ($state->pos < $max) {
            if ($state->src[$state->pos] === '~') {
                $found = true;
                break;
            }

            $state->md->inline->skipToken($state);
        }

        if (!$found || $start + 1 === $state->pos) {
            $state->pos = $start;
            return false;
        }

        $content = substr($state->src, $start + 1, $state->pos - ($start + 1));

        // don't allow unescaped spaces/newlines inside
        if (preg_match("/(^|[^\\\\])(\\\\\\\\)*\s/",$content)) {
            $state->pos = $start;
            return false;
        }

        // $found!
        $state->posMax = $state->pos;
        $state->pos = $start + 1;

        // Earlier we checked !$silent, but this implementation does not need it
        $token         = $state->push('sub_open', 'sub', 1);
        $token->markup  = '~';

        $token         = $state->push('text', '', 0);
        $token->content = preg_replace(self::UNESCAPE_RE, '$1', $content);

        $token         = $state->push('sub_close', 'sub', -1);
        $token->markup  = '~';

        $state->pos = $state->posMax + 1;
        $state->posMax = $max;
        return true;
    }
}