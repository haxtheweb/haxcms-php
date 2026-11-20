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
 * use javascript version 1.0.4
 * @see https://github.com/markdown-it/markdown-it-abbr/blob/1.0.4/test/test.js
 */
namespace Kaoken\Test\MarkdownIt\Plugins\Abbr;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItAbbr;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;


trait AbbrTrait
{
    private function abbr()
    {
        $this->group('markdown-it-abbr', function ($g) {
            $md = new MarkdownIt(["linkify" => true]);
            $md->plugin(new MarkdownItAbbr());
            $test = new MarkdownItTestgen();

            $test->generate(__DIR__.'/Fixtures/abbr.txt', [ "header" => true, "assert" => $g ], $md);
        });
    }
}