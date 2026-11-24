<?php
/**
 * class Renderer
 *
 * Generates HTML from parsed token stream. Each instance has independent
 * copy of rules. Those can be rewritten with ease. Also, you can add new
 * rules if you create plugin and adds new token types.
 **/
namespace Kaoken\MarkdownIt;

use Kaoken\MarkdownIt\Rules\DefaultRules;

/**
 * new Renderer()
 *
 * Creates new [[Renderer]] instance and fill [[Renderer#rules]] with defaults.
 **/
class Renderer
{
    /**
     * Contains render rules for tokens. Can be updated and extended.
     *
     * ##### Example
     *
     * ```PHP
     * $md = new MarkdownIt();
     *
     * $md->renderer->rules->strong_open  = function () { return '<b>'; };
     * $md->renderer->rules->strong_close = function () { return '</b>'; };
     *
     * $result = $md->renderInline(...);
     * ```
     * OR
     * ```PHP
     * $md = new MarkdownIt();
     *
     * class Strong {
     *   public function open() { return '<b>'; }
     *   public function close() { return '</b>'; }
     * }
     * $st = new Strong();
     *
     * $md->renderer->rules->strong_open  = [ $st, 'open' ];
     * $md->renderer->rules->strong_close = [ $st, 'close' ];
     *
     * $result = $md->renderInline(...);
     * ```
     *
     * Each rule is called as independent static function with fixed signature:
     *
     * ```PHP
     * function my_token_render($tokens, $idx, $options, $env, $renderer) {
     *   // ...
     *   return $renderedHTML;
     * }
     * ```
     *
     * See [source code](https://github.com/markdown-it/markdown-it/blob/master/lib/renderer.js)
     * for more details and examples.
     *
     * @var DefaultRules|object
     **/
    public $rules;

    /**
     * Renderer constructor.
     */
    public function __construct()
    {
        $this->rules = new DefaultRules();
    }

    /**
     * Render token attributes to string.
     * @param Token $token
     * @return string
     */
    public function renderAttrs(Token $token): string
    {
        if (!isset($token->attrs)) return '';

        $result = '';

        foreach ($token->attrs as &$a) {
            $result .= ' ' . htmlspecialchars($a[0]) . '="' . htmlspecialchars($a[1]) . '"';
        }

        return $result;
    }


    /**
     * Default token renderer. Can be overriden by custom function
     * in [[Renderer#rules]].
     * @param Token[] $tokens list of tokens
     * @param integer $idx token index to render
     * @param object $options params of parser instance
     * @return string
     */
    public function renderToken(array &$tokens, int $idx, object $options): string
    {
        $result = '';
        $needLf = false;
        $token = $tokens[$idx];

        // Tight list paragraphs
        if ($token->hidden) {
            return '';
        }

        // Insert a newline between hidden paragraph and subsequent opening
        // block-level tag.
        //
        // For example, here we should insert a newline before blockquote:
        //  - a
        //    >
        //
        if ($token->block && $token->nesting !== -1 && $idx && $tokens[$idx - 1]->hidden) {
            $result .= "\n";
        }

        // Add token name, e.g. `<img`
        $result .= ($token->nesting === -1 ? '</' : '<') . $token->tag;

        // Encode attributes, e.g. `<img src="foo"`
        $result .= $this->renderAttrs($token);

        // Add a slash for self-closing tags, e.g. `<img src="foo" /`
        if ($token->nesting === 0 && $options->xhtmlOut) {
            $result .= ' /';
        }

        // Check if we need to add a newline after this tag
        if ($token->block) {
            $needLf = true;

            if ($token->nesting === 1) {
                if ($idx + 1 < count($tokens)) {
                    $nextToken = $tokens[$idx + 1];

                    if ($nextToken->type === 'inline' || $nextToken->hidden) {
                        // Block-level tag containing an inline tag.
                        //
                        $needLf = false;

                    } else if ($nextToken->nesting === -1 && $nextToken->tag === $token->tag) {
                        // Opening tag + closing tag of the same type. E.g. `<li></li>`.
                        //
                        $needLf = false;
                    }
                }
            }
        }

        $result .= $needLf ? ">\n" : '>';

        return $result;
    }


    /**
     * The same as [[Renderer.render]], but for single token of `inline` type.
     * @param Token[] $tokens list on block tokens to render
     * @param object $options params of parser instance
     * @param object $env additional data from parsed input (references, for example)
     * @return string
     */
    public function renderInline(array &$tokens, object $options, object $env): string
    {
        $result = '';
        $rules = $this->rules;

        for ($i = 0, $len = count($tokens); $i < $len; $i++) {
            $type = $tokens[$i]->type;

            if ( method_exists($rules, $type) ){
                $result .= $rules->{$type}($tokens, $i, $options, $env, $this);
            } else if ( property_exists($rules, $type) && is_callable($rules->{$type}) ) {
                $fn = $rules->{$type};
                $result .= $fn($tokens, $i, $options, $env, $this);
            } else {
                $result .= $this->renderToken($tokens, $i, $options);
            }
        }

        return $result;
    }


    /** internal
     * Special kludge for image `alt` attributes to conform CommonMark spec.
     * Don't try to use it! Spec requires to show `alt` content with stripped markup,
     * instead of simple escaping.
     * @param Token[] $tokens list on block tokens to render
     * @param object $options params of parser instance
     * @param object $env additional data from parsed input (references, for example)
     * @return string
     */
    public function renderInlineAsText(array &$tokens, object $options, object $env): string
    {
        $result = '';

        foreach ( $tokens as &$token) {
            switch ($token->type) {
                case 'html_block':
                case 'html_inline':
                case 'text':
                    $result .= $token->content;
                    break;
                case 'image':
                    $result .= $this->renderInlineAsText($token->children, $options, $env);
                    break;
                case 'softbreak':
                case 'hardbreak':
                    $result .= "\n";
                    break;
                default:
                    // all other tokens are skipped
            }
        }

        return $result;
    }


    /**
     * Takes token stream and generates HTML. Probably, you will never need to call
     * this method directly.
     * @param Token[] $tokens list on block tokens to render
     * @param object $options params of parser instance
     * @param object|null $env additional data from parsed input (references, for example)
     * @return string
     */
    public function render(array &$tokens, object $options, $env=null): string
    {
        $result = '';
        $rules = $this->rules;

        for ($i = 0, $len = count($tokens); $i < $len; $i++) {
            $type = $tokens[$i]->type;

            if ($type === 'inline') {
                $result .= $this->renderInline($tokens[$i]->children, $options, $env);
            } else if ( method_exists($rules, $type) ) {
                $result .= $rules->{$type}($tokens, $i, $options, $env, $this);
            } else if ( property_exists($rules, $type) && is_callable($rules->{$type}) ) {
                $fn = $rules->{$type};
                $result .= $fn($tokens, $i, $options, $env, $this);
            } else {
                $result .= $this->renderToken($tokens, $i, $options, $env);
            }
        }

        return $result;
    }
}