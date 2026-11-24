<?php
namespace Kaoken\MarkdownIt\Rules;

use Kaoken\MarkdownIt\Common\Utils;
use Kaoken\MarkdownIt\Renderer;
use Kaoken\MarkdownIt\Token;
use stdClass;

class DefaultRules extends stdClass
{
    public ?Utils $utils = null;

    public function __construct()
    {
        $this->utils = Utils::getInstance();
    }

    /**
     * @param Token[]  $tokens
     * @param integer  $idx
     * @param object   $options
     * @param null|object   $env
     * @param Renderer $slf
     * @return string
     */
    public function code_inline(array &$tokens, int $idx, object $options, ?object $env, Renderer $slf) : string
    {
        $token = $tokens[$idx];

        return  '<code' . $slf->renderAttrs($token) . '>' .
        htmlspecialchars($token->content) . '</code>';
    }


    /**
     * @param Token[]  $tokens
     * @param integer  $idx
     * @param object   $options
     * @param null|object   $env
     * @param Renderer $slf
     * @return string
     */
    public function code_block(array &$tokens, int $idx, object $options, ?object $env, Renderer $slf) : string
    {
        $token = $tokens[$idx];

        return  '<pre' . $slf->renderAttrs($token) . '><code>' .
        htmlspecialchars($tokens[$idx]->content) .
        "</code></pre>\n";
    }


    /**
     * @param Token[]  $tokens
     * @param integer  $idx
     * @param object   $options
     * @param null|object   $env
     * @param Renderer $slf
     * @return string
     */
    public function fence(array &$tokens, int $idx, object $options, ?object $env, Renderer $slf) : string
    {
        $token = $tokens[$idx];
        $info = $token->info ? trim($this->utils->unescapeAll( $token->info )) : '';
        $langName = '';
        $langAttrs = '';
        $arr = [];

        if ($info) {
            $arr = preg_split("/(\s+)/",$info,-1,PREG_SPLIT_DELIM_CAPTURE); // /g
            $langName = $arr[0];
            $langAttrs = implode ('', array_slice($arr, 2));
        }

        if ( isset($options->highlight) ) {
            $fn = $options->highlight;
            if( empty( $highlighted = $fn($token->content, $langName, $langAttrs)) )
                $highlighted = htmlspecialchars($token->content);
        } else {
            $highlighted = htmlspecialchars($token->content);
        }

        if (strpos($highlighted, '<pre') === 0) {
            return $highlighted . "\n";
        }

        // If language exists, inject class gently, without modifying original token.
        // May be, one day we will add .deepClone() for token and simplify this part, but
        // now we prefer to keep things local.
        if ($info) {
            $i        = $token->attrIndex('class');
            $tmpAttrs = $token->attrs ? clone $token->attrs : [];

            if ($i < 0) {
                $tmpAttrs[] = [ 'class', $options->langPrefix . $langName ];
            } else {
                $tmpAttrs[$i] = clone $tmpAttrs[$i];
                $tmpAttrs[$i][1] .= ' ' . $options->langPrefix . $langName;
            }

            // Fake $token just to render attributes
            $tmpToken = new Token('','',0);
            $tmpToken->attrs = $tmpAttrs;

            return  '<pre><code' . $slf->renderAttrs($tmpToken) . '>'
            . $highlighted
            . "</code></pre>\n";
        }


        return  '<pre><code' . $slf->renderAttrs($token) . '>'
        . $highlighted
        . "</code></pre>\n";
    }

    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param object $options
     * @param object $env
     * @param Renderer $slf
     * @return string
     */
    public function image(array &$tokens, int $idx, object $options, object $env, Renderer $slf) : string
    {
        $token = $tokens[$idx];

        // "alt" attr MUST be set, even if empty. Because it's mandatory and
        // should be placed on proper position for tests.
        //
        // Replace content with actual value

        $token->attrs[$token->attrIndex('alt')][1] =
            $slf->renderInlineAsText($token->children, $options, $env);

        return $slf->renderToken($tokens, $idx, $options);
    }


    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param object $options
     * @param null $env
     * @return string
     */
    public function hardbreak(array &$tokens, int $idx, object $options, $env=null): string
    {
        return $options->xhtmlOut ? "<br />\n" : "<br>\n";
    }

    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param object $options
     * @param null $env
     * @return string
     */
    public function softbreak(array &$tokens, int $idx, $options, $env=null): string
    {
        return $options->breaks ? ($options->xhtmlOut ? "<br />\n" : "<br>\n") : "\n";
    }


    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param null $options
     * @param null $env
     * @return string
     */
    public function text(array &$tokens, int $idx, $options=null, $env=null): string
    {
        return htmlspecialchars($tokens[$idx]->content,ENT_COMPAT);
    }

    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param object|null $options
     * @param object|null $env
     * @return string
     */
    public function html_block(array $tokens, int $idx, $options=null, $env=null): string
    {
        return $tokens[$idx]->content;
    }

    /**
     * @param Token[] $tokens
     * @param integer $idx
     * @param object|null $options
     * @param object|null $env
     * @return string
     */
    public function html_inline(array &$tokens, int $idx, $options=null, $env=null): string
    {
        return $tokens[$idx]->content;
    }
}