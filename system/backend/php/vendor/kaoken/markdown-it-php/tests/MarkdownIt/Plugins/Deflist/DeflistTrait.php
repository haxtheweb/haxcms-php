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
 * use javascript version 3.0.1
 * @see https://github.com/markdown-it/markdown-it-deflist/blob/2.0.1/test/test.js
 */
namespace Kaoken\Test\MarkdownIt\Plugins\Deflist;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItDeflist;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait DeflistTrait
{
    private function deflist()
    {
        $this->group('markdown-it-deflist', function ($g) {
            $md = new MarkdownIt();
            $md->plugin(new MarkdownItDeflist());
            $test = new MarkdownItTestgen();

            $test->generate(__DIR__.'/Fixtures/deflist.txt', [ "header" => true, "assert" => $g ], $md);
        });
    }
}