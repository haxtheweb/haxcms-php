<?php
namespace Kaoken\Test\MarkdownIt\Plugins;

use Kaoken\Test\EasyTest;
use Kaoken\Test\MarkdownIt\Plugins\Abbr\AbbrTrait;
use Kaoken\Test\MarkdownIt\Plugins\Container\ContainerTrait;
use Kaoken\Test\MarkdownIt\Plugins\Emoji\EmojiTrait;
use Kaoken\Test\MarkdownIt\Plugins\ForInline\ForInlineTrait;
use Kaoken\Test\MarkdownIt\Plugins\Ins\InsTrait;
use Kaoken\Test\MarkdownIt\Plugins\Mark\MarkTrait;
use Kaoken\Test\MarkdownIt\Plugins\Sub\SubTrait;
use Kaoken\Test\MarkdownIt\Plugins\Sup\SupTrait;
use Kaoken\Test\MarkdownIt\Plugins\Footnote\FootnoteTrait;
use Kaoken\Test\MarkdownIt\Plugins\Deflist\DeflistTrait;

class CTest extends EasyTest
{
    use AbbrTrait, EmojiTrait, ContainerTrait, DeflistTrait, FootnoteTrait, ForInlineTrait, InsTrait, MarkTrait, SubTrait, SupTrait;

    public function __construct($pageTitle = "")
    {
        parent::__construct($pageTitle);

        $this->abbr();
        $this->container();
        $this->deflist();
        $this->emoji();
        $this->footnote();
        $this->forInline();
        $this->ins();
        $this->mark();
        $this->sub();
        $this->sup();
    }
}