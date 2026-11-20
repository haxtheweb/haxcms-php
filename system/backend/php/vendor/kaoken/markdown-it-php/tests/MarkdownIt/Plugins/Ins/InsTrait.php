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
 * @see https://github.com/markdown-it/markdown-it-ins/blob/2.0.0/test/test.js
 */

namespace Kaoken\Test\MarkdownIt\Plugins\Ins;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItIns;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait InsTrait
{
    private function ins()
    {
        $this->group('markdown-it-ins', function ($g) {
            $o = new MarkdownItTestgen();
            $md = (new MarkdownIt())
                ->plugin(new MarkdownItIns());

            $o->generate(__DIR__.'/Fixtures/ins.txt', [ "assert" => $g ], $md);
        });
    }
}