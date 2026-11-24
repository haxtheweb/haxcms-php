<?php
/** internal
 * class Core
 *
 * Top-level rules executor. Glues block/inline parsers and does intermediate
 * transformations.
 **/

namespace Kaoken\MarkdownIt;
use Exception;
use Kaoken\MarkdownIt\Ruler;
use \Kaoken\MarkdownIt\RulesCore\StateCore;

class ParserCore
{
    protected array $_rules = [
        'normalize' =>     \Kaoken\MarkdownIt\RulesCore\Normalize::class,
        'block' =>         \Kaoken\MarkdownIt\RulesCore\Block::class,
        'inline' =>        \Kaoken\MarkdownIt\RulesCore\Inline::class,
        'linkify' =>       \Kaoken\MarkdownIt\RulesCore\Linkify::class,
        'replacements' =>  \Kaoken\MarkdownIt\RulesCore\ReplaceMents::class,
        'smartquotes' =>   \Kaoken\MarkdownIt\RulesCore\SmartQuotes::class,
        // `text_join` finds `text_special` tokens (for escape sequences)
        // and joins them with the rest of the text
        'text_join' =>     \Kaoken\MarkdownIt\RulesCore\TextJoin::class
    ];

    /**
     * [[Ruler]] instance. Keep configuration of core rules.
     * @var Ruler
     */
    public Ruler $ruler;

    /**
     * new Core()
     *
     * @throws Exception
     */
    public function __construct()
    {
        $this->ruler = new Ruler();

        foreach ($this->_rules as $key => &$val) {
            $this->ruler->push($key, [new $val(), 'set']);
        }
    }

    /**
     * @param string $src
     * @param MarkdownIt $md
     * @param null $env
     * @return StateCore
     */
    public function createState(string $src, MarkdownIt $md, $env=null): StateCore
    {
        return new StateCore($src, $md, $env);
    }

    /**
     * @param object $state
     */
    public function process(object $state)
    {
        $rules = $this->ruler->getRules('');

        foreach ( $rules as &$rule) {
            $rule($state);
        }
    }
}