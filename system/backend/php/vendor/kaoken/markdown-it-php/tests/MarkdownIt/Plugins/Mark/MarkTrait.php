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
 * use javascript version 2.0.0
 * @see https://github.com/markdown-it/markdown-it-mark/blob/2.0.0/test/test.js
 */

namespace Kaoken\Test\MarkdownIt\Plugins\Mark;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItMark;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait MarkTrait
{
    private function mark()
    {
        $this->group('markdown-it-mark', function ($g) {
            $o = new MarkdownItTestgen();
            $md = (new MarkdownIt())
                ->plugin(new MarkdownItMark());

            $o->generate(__DIR__.'/Fixtures/mark.txt', [ "assert" => $g ], $md);
        });
    }
}