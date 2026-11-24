<?php
// Parse link title
//

namespace Kaoken\MarkdownIt\Helpers;

use stdClass;

trait ParseLinkTitle
{
    /**
     * Parse link title within `str` in [start, max] range,
     * or continue previous parsing if `prev_state` is defined (equal to result of last execution).
     * @param string $str
     * @param int $start
     * @param int $max
     * @param stdClass|null $prev_state
     * @return stdClass
     */
    public function parseLinkTitle(string $str, int $start, int $max, stdClass $prev_state=null): stdClass {
        $code = '';
        $pos = $start;

        $state = new stdClass();
        // if `true`, this is a valid link title
        $state->ok = false;
        // if `true`, this link can be continued on the next line
        $state->can_continue = false;
        // if `ok`, it's the position of the first character after the closing marker
        $state->pos = 0;
        // if `ok`, it's the unescaped title
        $state->str = '';
        // expected closing marker character code
        $state->marker = 0;

        if (is_object($prev_state)) {
            // this is a continuation of a previous parseLinkTitle call on the next line,
            // used in reference links only
            $state->str = $prev_state->str;
            $state->marker = $prev_state->marker;
        } else {
            if ($pos >= $max) { return $state; }

            $marker = $str[$pos];

            if ($marker !== '"' && $marker !== '\'' && $marker !== '(' ) {
            return $state;
            }

            $start++;
            $pos++;

            // if opening marker is "(", switch it to closing marker ")"
            if ($marker === '(') { $marker = ')'; }

            $state->marker = $marker;
        }

        while ($pos < $max) {
            $code = $str[$pos];
            if ($code === $state->marker) {
                $state->pos = $pos + 1;
                $state->str .= $this->utils->unescapeAll(substr($str, $start, $pos-$start));
                $state->ok = true;
                return $state;
            } else if ($code === '(' && $state->marker === ')') {
                return $state;
            } else if ($code === '\\' && $pos + 1 < $max) {
                $pos++;
            }

            $pos++;
        }

      // no closing marker found, but this link title may continue on the next line (for references)
      $state->can_continue = true;
      $state->str .= $this->utils->unescapeAll(substr($str, $start, $pos-$start));
      return $state;
    }

}