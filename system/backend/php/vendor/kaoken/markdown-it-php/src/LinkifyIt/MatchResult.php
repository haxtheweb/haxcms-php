<?php
/**
Copyright (c) 2016 Kaoken, Vitaly Puzrin.

This software is released under the MIT License.
http://opensource.org/licenses/mit-license.php
 */

namespace Kaoken\LinkifyIt;
use Kaoken\MarkdownIt\RulesCore\Linkify;

/**
 * class MatchResult
 *
 * Match result. Single element of array, returned by [[LinkifyIt#match]]
 **/
class MatchResult
{
    /**
     * Prefix (protocol) for matched string.
     * @var string
     **/
	public string $schema;
    /**
     * First position of matched string.
     * @var integer
     **/
	public int $index;
    /**
     * Next position after matched string.
     * @var integer
     **/
	public int $lastIndex;
    /**
     * Matched string.
     * @var string
     **/
	public $raw;
    /**
     * Notmalized text of matched string.
     * @var string
     **/
	public $text;
    /**
     * Normalized url of matched string.
     * @var string
     **/
	public $url;

    /**
     * Match constructor.
     * @param LinkifyIt $self
     * @param integer $shift
     */
    public function __construct(LinkifyIt $self, int $shift)
    {
        $start = $self->getIndex();
        $end   = $self->getLastIndex();
        $text  = substr($self->getTextCache(), $start, $end-$start);

        $this->schema    = strtolower($self->getSchema());
        $this->index     = $start + $shift;
        $this->lastIndex = $end + $shift;
        $this->raw       = $text;
        $this->text      = $text;
        $this->url       = $text;
        $self->normalizeFromCompiled($this->schema,$this);
    }
}
