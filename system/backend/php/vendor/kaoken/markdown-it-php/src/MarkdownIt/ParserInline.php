<?php
/** internal
 * class ParserInline
 *
 * Tokenizes paragraph content.
 **/
namespace Kaoken\MarkdownIt;

use Kaoken\MarkdownIt\RulesInline;
use Kaoken\MarkdownIt\RulesInline\StateInline;
use Exception;

/**
 * new ParserInline()
 **/
class ParserInline
{
    protected array $_rules = [
        [ 'text',            \Kaoken\MarkdownIt\RulesInline\Text::class, 'text' ],
        [ 'linkify',         \Kaoken\MarkdownIt\RulesInline\Linkify::class, 'linkify'  ],
        [ 'newline',         \Kaoken\MarkdownIt\RulesInline\NewLine::class, 'newline'],
        [ 'escape',          \Kaoken\MarkdownIt\RulesInline\Escape::class, 'escape'],
        [ 'backticks',       \Kaoken\MarkdownIt\RulesInline\Backticks::class, 'backticks'],
        [ 'strikethrough',   \Kaoken\MarkdownIt\RulesInline\Strikethrough::class, 'tokenize' ],
        [ 'emphasis',        \Kaoken\MarkdownIt\RulesInline\Emphasis::class, 'tokenize' ],
        [ 'link',            \Kaoken\MarkdownIt\RulesInline\Link::class, 'link' ],
        [ 'image',           \Kaoken\MarkdownIt\RulesInline\Image::class, 'image' ],
        [ 'autolink',        \Kaoken\MarkdownIt\RulesInline\AutoLink::class, 'autoLink' ],
        [ 'html_inline',     \Kaoken\MarkdownIt\RulesInline\HtmlInline::class, 'htmlInline' ],
        [ 'entity',          \Kaoken\MarkdownIt\RulesInline\Entity::class, 'entity' ]
    ];

    // `rule2` ruleset was created specifically for emphasis/strikethrough
    // post-processing and may be changed in the future.
    //
    // Don't use this for anything except pairs (plugins working with `balance_pairs`).
    //
    protected array $_rules2 = [
        [ 'balance_pairs',   \Kaoken\MarkdownIt\RulesInline\BalancePairs::class, 'linkPairs'  ],
        [ 'strikethrough',   \Kaoken\MarkdownIt\RulesInline\Strikethrough::class, 'postProcess' ],
        [ 'emphasis',        \Kaoken\MarkdownIt\RulesInline\Emphasis::class, 'postProcess' ],
        // rules for pairs separate '**' into its own text tokens, which may be left unused,
        // rule below merges unused segments back with the rest of the text
        [ 'fragments_join',  \Kaoken\MarkdownIt\RulesInline\FragmentsJoin::class, 'fragmentsJoin' ],
    ];
    /**
     * @var Ruler
     */
    public $ruler;
    /**
     * @var Ruler
     */
    public $ruler2;

    /**
     * ParserInline constructor.
     * @param string $stateClass
     */
    public function __construct($stateClass='')
    {
        if(class_exists($stateClass))
            $this->stateClass = $stateClass;

        /**
         * ParserInline#ruler -> Ruler
         *
         * [[Ruler]] instance. Keep configuration of inline rules.
         **/
        $this->ruler = new Ruler();
        foreach ( $this->_rules as &$rule) {
            $class = $rule[1];
            $this->ruler->push($rule[0],
                [ new $class(), $rule[2] ]
            );
        }
        unset($this->_rules);

        /**
         * ParserInline#ruler2 -> Ruler
         *
         * [[Ruler]] instance. Second ruler used for post-processing
         * (e.g. in emphasis-like rules).
         **/
        $this->ruler2 = new Ruler();
        foreach ( $this->_rules2 as &$rule) {
            $class = $rule[1];
            $this->ruler2->push($rule[0],
                [ new $class(), $rule[2]]
            );
        }
        unset($this->_rules2);
    }

    /**
     * returns `true` if any rule reported success
     * @param StateInline $state
     * @throws Exception
     */
    public function skipToken(StateInline &$state)
    {
        $pos = $state->pos;
        $rules = $this->ruler->getRules('');
        $maxNesting = $state->md->options->maxNesting;
        $cache = &$state->cache;
        $ok = false;

        if ( isset($cache[$pos])) {
            $state->pos = $cache[$pos];
            return;
        }

        if ($state->level < $maxNesting) {
            foreach ($rules as &$rule) {
                // Increment state.level and decrement it later to limit recursion.
                // It's harmless to do here, because no tokens are created. But ideally,
                // we'd need a separate private state variable for this purpose.
                //
                $state->level++;

                if( is_array($rule) )
                    $ok = $rule[0]->{$rule[1]}($state, true);
                else
                    $ok = $rule($state, true);

                $state->level--;

                if ($ok) {
                    if ($pos >= $state->pos) { throw new Exception("inline rule didn't increment state.pos"); }
                    break;
                }
            }
        } else {
            // Too much nesting, just skip until the end of the paragraph.
            //
            // NOTE: this will cause links to behave incorrectly in the following case,
            //       when an amount of `[` is exactly equal to `maxNesting + 1`:
            //
            //       [[[[[[[[[[[[[[[[[[[[[foo]()
            //
            // TODO: remove this workaround when CM standard will allow nested links
            //       (we can replace it by preventing links from being parsed in
            //       validation mode)
            //
            $state->pos = $state->posMax;
        }

        if (!$ok) { $state->pos++; }
        $cache[$pos] = $state->pos;
    }


    /**
     * Generate tokens for input range
     * @param StateInline $state
     * @throws Exception
     */
    public function tokenize(StateInline &$state)
    {
        $i = 0;
        $rules = $this->ruler->getRules('');
        $len = count($rules);
        $end = $state->posMax;
        $maxNesting = $state->md->options->maxNesting;

        while ($state->pos < $end) {
            // Try all possible rules.
            // On success, rule should:
            //
            // - update `state.pos`
            // - update `state.tokens`
            // - return true
            $prevPos = $state->pos;
            $ok = false;

            if ($state->level < $maxNesting) {
                $i = 0;
                foreach ($rules as &$rule) {
                    $i++;
                    if( is_array($rule) )
                        $ok = $rule[0]->{$rule[1]}($state, false);
                    else
                        $ok = $rule($state, false);
                    if ($ok)  {
                        if ($prevPos >= $state->pos) { throw new Exception("inline rule didn't increment state.pos"); }
                        break;
                    }
                }
            }

            if ($ok) {
                if ($state->pos >= $end) { break; }
                continue;
            }

            $state->pending .= $state->src[$state->pos++];
        }

        if ($state->pending) {
            $state->pushPending();
        }
    }


    /**
     * Process input string and push inline tokens into `outTokens`
     *
     * @param string $str
     * @param MarkdownIt $md
     * @param null|object $env
     * @param Token[] $outTokens
     */
    public function parse(string $str, MarkdownIt $md, ?object $env, array &$outTokens)
    {
        $state = new StateInline($str, $md, $env, $outTokens);

        $this->tokenize($state);

        $rules = $this->ruler2->getRules('');

        foreach ( $rules as &$rule) {
            if( is_array($rule) )
                $rule[0]->{$rule[1]}($state);
            else
                $rule($state);
        }
    }
}