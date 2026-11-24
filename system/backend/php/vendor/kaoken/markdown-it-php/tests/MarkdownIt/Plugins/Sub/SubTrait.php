<?php
/**
 * Copyright (c) 2014-2015 Vitaly Puzrin, Alex Kocharin.
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
 * use javascript version 1.0.0
 * @see https://github.com/markdown-it/markdown-it-sub/blob/1.0.0/test/test.js
 */

namespace Kaoken\Test\MarkdownIt\Plugins\Sub;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItSub;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait SubTrait
{
    private function sub()
    {
        /**
         * The javascript version of 'markdown-it v8' also looks like the following.
         *
         * It does not become
         *
         *  <p>x ~<s>foo</s>~</p>
         *
         * after rendering
         *
         * x ~~~foo~~~
         *
         * <p>x ~<s>foo</s>~</p>
         */
        $this->group('markdown-it-sub', function ($g) {
            $generate = new MarkdownItTestgen();
            $md = (new MarkdownIt())->plugin(new MarkdownItSub());
            $generate->generate(__DIR__.'/Fixtures/sub.txt', [ "assert" => $g ], $md);
        });
    }
}