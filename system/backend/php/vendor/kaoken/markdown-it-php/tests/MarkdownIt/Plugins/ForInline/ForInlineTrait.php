<?php
/**
 * Copyright (c) 2014 Vitaly Puzrin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 * use javascript version 0.1.1
 * @see https://github.com/markdown-it/markdown-it-for-inline/blob/master/test/test.js
 */

namespace Kaoken\Test\MarkdownIt\Plugins\ForInline;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItForInline;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait ForInlineTrait
{
    private function forInline()
    {
        $this->group('markdown-it-for-inline', function ($g) {
            $generate = new MarkdownItTestgen();

            $md = (new MarkdownIt())->plugin(new MarkdownItForInline(), 'text_replace', 'text', function($tokens, $idx) {
                $tokens[$idx]->content = preg_replace("/foo/", 'bar', $tokens[$idx]->content);
            });
            $generate->generate(__DIR__ . '/Fixtures/text.txt', [ "header" => true, "assert" => $g ], $md);


            $md = (new MarkdownIt([ "linkify" => true ]))
                ->plugin(new MarkdownItForInline(), 'link_replace', 'link_open', function($tokens, $idx) {
                    if (($tokens[$idx + 2]->type !== 'link_close') ||
                        ($tokens[$idx + 1]->type !== 'text')) {
                        return;
                    }
                    $str = preg_replace("/google/", 'shmugle', $tokens[$idx + 1]->content);
                    $str = preg_replace("/^https?:\/\//", '', $str);
                    $tokens[$idx + 1]->content = preg_replace("/^www./", '', $str);
                  });
            $generate->generate(__DIR__ . '/Fixtures/link.txt', [ "header" => true, "assert" => $g ], $md);
        });
    }
}