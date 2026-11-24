<?php
namespace Kaoken\Test\MarkdownIt;

use Kaoken\Test\EasyTest;


class CTest extends EasyTest
{
    use CommonMarkTrait, MarkdownItTrait, MiscTrait, TokenTrait, RulerTrait, UtilsTrait;

    public function __construct()
    {
        parent::__construct("MarkdownIt test case !!");
        $this->commonMark();
        $this->markdownIt();
        $this->misc();
        $this->ruler();
        $this->token();
        $this->utils();
    }
}