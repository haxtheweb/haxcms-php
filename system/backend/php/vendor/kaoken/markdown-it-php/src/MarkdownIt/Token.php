<?php
namespace Kaoken\MarkdownIt;

/**
 * new Token(type, tag, nesting)
 *
 * Create new token and fill passed properties.
 **/
class Token
{
    /**
     * Type of the token (string, e.g. "paragraph_open")
     * @var string
     **/
    public string $type     = '';

    /**
     * html tag $name, e.g. "p"
     * @var string
     **/
    public string $tag      = '';

    /**
     * Html attributes. Format: `[ [ $name1, $value1 ], [ $name2, $value2 ] ]`
     * @var null|array
     **/
    public ?array $attrs    = null;

    /**
     * Source map info. Format: `[ line_begin, line_end ]`
     * @var null|array
     **/
    public ?array $map      = null;

    /**
     * Level change (number in {-1, 0, 1} set), where:
     *
     * -  `1` means the tag is opening
     * -  `0` means the tag is self-closing
     * - `-1` means the tag is closing
     * @var integer
     **/
    public int $nesting  = 0;

    /**
     * nesting level, the same as `state.level`
     * @var integer
     **/
    public int $level    = 0;

    /**
     * An array of child nodes (inline and img tokens)
     * @var Token[]
     **/
    public ?array $children = null;

    /**
     * In a case of self-closing tag (code, html, fence, etc.),
     * it has contents of 	protected $tag.
     * @var string
     **/
	public string $content  = '';

    /**
     * '*' or '_' for emphasis, fence string for fence, etc.
     * @var string
     **/
    public string $markup   = '';

    /**
     * Additional information:
     *
     * - Info string for "fence" tokens
     * - The value "auto" for autolink "link_open" and "link_close" tokens
     * - The string value of the item marker for ordered-list "list_item_open" tokens
     * @var string
     **/
    public string $info     = '';

    /**
     * A place for plugins to store an arbitrary data
     * @var ?object
     **/
    public ?object $meta     = null;

    /**
     * True for block-level tokens, false for inline tokens.
     * Used in renderer to calculate line breaks
     * @var boolean
     **/
    public bool $block    = false;

    /**
     * If it's true, ignore 	protected $element when rendering. Used for tight lists
     * to hide paragraphs.
     * @var boolean
     **/
    public bool $hidden  = false;

    /**
     * Token constructor.
     * @param string $type
     * @param string $tag
     * @param integer $nesting
     */
    public function __construct(string $type, string $tag, int $nesting)
    {
        $this->type = $type;
        $this->tag = $tag;
        $this->nesting = $nesting;
    }

    /**
     * Search attribute index by $name.
     * @param $name
     * @return int
     */
    public function attrIndex($name): int
    {
        if (!isset($this->attrs)) { return -1; }

        for ($i = 0, $len = count($this->attrs); $i < $len; $i++) {
            if ($this->attrs[$i][0] === $name) { return $i; }
        }
        return -1;
    }


    /**
     * Add `[ $name, $value ]` attribute to list. Init  $attrs if necessary
     * @param array $attrData
     */
    public function attrPush(array $attrData)
    {
        $this->attrs[] = $attrData;
    }


    /**
     * Set `$name` attribute to `$value`. Override old $value if exists.
     * @param string $name
     * @param mixed $value
     */
    public function attrSet(string $name, $value)
    {
        $idx = $this->attrIndex($name);
        $attrData = [ $name, $value ];

        if ($idx < 0) {
            $this->attrPush($attrData);
        } else {
            $this->attrs[$idx] = $attrData;
        }
    }


    /**
     * Get the $value of attribute `$name`, or null if it does not exist.
     * @param string $name
     * @return mixed
     */
    public function attrGet(string $name)
    {
        $idx = $this->attrIndex($name);
        $value = null;
        if ($idx >= 0) {
            $value = $this->attrs[$idx][1];
        }
        return $value;
    }


    /**
     * Join $value to existing attribute via space. Or create new attribute if not
     * exists. Useful to operate with token classes.
     * @param string $name
     * @param mixed $value
     */
    public function attrJoin(string $name, $value)
    {
        $idx = $this->attrIndex($name);
        
        if ($idx < 0) {
            $this->attrPush([ $name, $value ]);
        } else {
            $this->attrs[$idx][1] = $this->attrs[$idx][1] . ' ' . $value;
        }
    }
}