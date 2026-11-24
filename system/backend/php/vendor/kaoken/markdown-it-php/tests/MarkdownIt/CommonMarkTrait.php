<?php
namespace Kaoken\Test\MarkdownIt;

use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;
use Kaoken\MarkdownIt\MarkdownIt;

Trait CommonMarkTrait
{

    private function commonMark()
    {
        $this->group('CommonMark',function ($g){
            $o = new MarkdownItTestgen();

            $o->load(__DIR__.'/Fixtures/commonmark/good.txt', function ($data) use(&$g) {
                $normalize = function ($text) {
                    return preg_replace("/<blockquote>\n<\/blockquote>/", '<blockquote></blockquote>', $text);
                };
                $md = new MarkdownIt('commonmark');
                foreach ($data->fixtures as &$fixture) {
                    $g->strictEqual(
                        $md->render($fixture->first->text),
                        $normalize($fixture->second->text),
                        isset($fixture->header)  ? $fixture->header : 'line ' . ($fixture->first->range[0] - 1));
                }
            });
        });
    }
}