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
 * @see https://github.com/markdown-it/markdown-it-sup/blob/1.0.0/test/test.js
 */

namespace Kaoken\Test\MarkdownIt\Plugins\Sup;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItSup;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait SupTrait
{
    private function sup()
    {
        $this->group('markdown-it-sup', function ($g) {
            $generate = new MarkdownItTestgen();
            $md = (new MarkdownIt())->plugin(new MarkdownItSup());
            $generate->generate(__DIR__.'/Fixtures/sup.txt', [ "assert" => $g ], $md);
        });
    }
}