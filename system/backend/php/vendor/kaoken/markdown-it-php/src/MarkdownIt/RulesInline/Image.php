<?php
// Process ![image](<src> "title")

namespace Kaoken\MarkdownIt\RulesInline;

use Exception;
use Kaoken\MarkdownIt\Common\Utils;

class Image
{
    /**
     * @param StateInline $state
     * @param boolean $silent
     * @return bool
     * @throws Exception
     */
    public function image(StateInline &$state, bool $silent=false): bool
    {
        $href = '';
        $oldPos = $state->pos;
        $max = $state->posMax;

        if ($state->src[$state->pos] !== '!') { return false; }
        if (strlen($state->src) <= $state->pos+1 || $state->src[$state->pos + 1] !== '[') { return false; }

        $labelStart = $state->pos + 2;
        $labelEnd = $state->md->helpers->parseLinkLabel($state, $state->pos + 1, false);

        // parser failed to find ']', so it's not a valid link
        if ($labelEnd < 0) { return false; }

        $pos = $labelEnd + 1;
        if ($pos < $max && $state->src[$pos] === '(') {
            //
            // Inline link
            //

            // [link](  <$href>  "$title"  )
            //        ^^ skipping these spaces
            $pos++;
            for (; $pos < $max; $pos++) {
                $code = $state->src[$pos];
                if (!$state->md->utils->isSpace($code) && $code !== "\n") { break; }
            }
            if ($pos >= $max) { return false; }

            // [link](  <$href>  "$title"  )
            //          ^^^^^^ parsing link destination
            $start = $pos;
            $res = $state->md->helpers->parseLinkDestination($state->src, $pos, $state->posMax);
            if ($res->ok) {
                $href = $state->md->normalizeLink($res->str);
                if ($state->md->validateLink($href)) {
                    $pos = $res->pos;
                } else {
                    $href = '';
                }
            }

            // [link](  <$href>  "$title"  )
            //                ^^ skipping these spaces
            $start = $pos;
            for (; $pos < $max; $pos++) {
                $code = $state->src[$pos];
                if (!$state->md->utils->isSpace($code) && $code !== "\n") { break; }
            }

            // [link](  <$href>  "$title"  )
            //                  ^^^^^^^ parsing link $title
            $res = $state->md->helpers->parseLinkTitle($state->src, $pos, $state->posMax);
            if ($pos < $max && $start !== $pos && $res->ok) {
                $title = $res->str;
                $pos = $res->pos;

                // [link](  <$href>  "$title"  )
                //                         ^^ skipping these spaces
                for (; $pos < $max; $pos++) {
                    $code = $state->src[$pos];
                    if (!$state->md->utils->isSpace($code) && $code !== "\n") { break; }
                }
            } else {
                $title = '';
            }

            if ($pos >= $max || $state->src[$pos] !== ')') {
                $state->pos = $oldPos;
                return false;
            }
            $pos++;
        } else {
            //
            // Link reference
            //
            if ( !isset($state->env->references) ) { return false; }

            $label = false;
            if ($pos < $max && $state->src[$pos] === '[') {
                $start = $pos + 1;
                $pos = $state->md->helpers->parseLinkLabel($state, $pos);
                if ($pos >= 0) {
                    $label = substr($state->src, $start, ($pos++)-$start);
                } else {
                    $pos = $labelEnd + 1;
                }
            } else {
                $pos = $labelEnd + 1;
            }

            // covers $label === '' and $label === undefined
            // (collapsed reference link and shortcut reference link respectively)
            if (!$label) { $label = substr($state->src, $labelStart, $labelEnd-$labelStart); }


            $ref = false;
            $key = $state->md->utils->normalizeReference($label);
            if( isset($state->env->references) && array_key_exists($key, $state->env->references)){
                $ref = &$state->env->references[$key];
                if ($ref) {
                    $href = $ref['href'];
                    $title = $ref['title'];
                }
            }
            if( $ref === false ){
                $state->pos = $oldPos;
                return false;
            }
        }

        //
        // We found the end of the link, and know for a fact it's a valid link;
        // so all that's left to do is to call tokenizer.
        //
        if (!$silent) {
            $content = substr($state->src, $labelStart, $labelEnd-$labelStart);

            $tokens = [];
            $state->md->inline->parse(
                $content,
                $state->md,
                $state->env,
                $tokens
            );

            $token           = $state->push('image', 'img', 0);
            $attr            = [ [ 'src', $href ], [ 'alt', '' ] ];
            $token->attrs    = $attr;
            $token->children = &$tokens;
            $token->content  = $content;

            if ($title) {
                $token->attrs[] = [ 'title', $title ];
            }
        }

        $state->pos = $pos;
        $state->posMax = $max;
        return true;
    }
}