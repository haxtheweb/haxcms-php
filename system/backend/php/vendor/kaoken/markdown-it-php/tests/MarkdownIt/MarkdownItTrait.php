<?php
namespace Kaoken\Test\MarkdownIt;

use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;
use Kaoken\MarkdownIt\MarkdownIt;

trait MarkdownItTrait
{
    /**
     * @throws \Exception
     */
    public function markdownIt()
    {
        $md = new MarkdownIt([
            "html"=> true,
            "langPrefix"=> '',
            "typographer"=> true,
            "linkify"=> true
        ]);

        $this->group("markdown-it", function($g) {
            $md = new MarkdownIt([
                "html"=> true,
                "langPrefix"=> '',
                "typographer"=> true,
                "linkify"=> true
            ]);
            $o = new MarkdownItTestgen();
            $options = new \stdClass();
            $options->assert = $g;
            $o->generate(__DIR__.'/Fixtures/markdown-it', $options, $md);
        });
    }
}