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
 * use javascript version 3.0.2
 * @see https://github.com/markdown-it/markdown-it-footnote/blob/3.0.2/test/test.js
 */
namespace Kaoken\Test\MarkdownIt\Plugins\Footnote;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItFootnote;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;

trait FootnoteTrait
{


    /**
     * Most of the rest of this is inlined from generate(), but modified
     * so we can pass in an `$env` object
     * @param string $fixturePath
     * @param MarkdownIt $md
     * @param $g
     * @param null $env
     * @throws \Exception
     */
    private function generateFootnote($fixturePath, $md, $g, $env=null)
    {
        $generate = new MarkdownItTestgen();
        $env = (object)$env;

        $generate->load($fixturePath, function ($data) use($md, $g, $env, $fixturePath) {
            $data->meta = $data->meta ?? new \stdClass();

            $desc = $data->meta->desc ?? $fixturePath;

            foreach ( $data->fixtures as &$fixture){
                // add variant character after "↩", so we don't have to worry about
                // invisible characters in tests
                $out1 = $md->render($fixture->first->text, clone $env);
                $out2 = preg_replace("/\x{21a9}(?!\x{fe0e})/u", '↩︎', $fixture->second->text);
                $g->strictEqual(
                    $out1,
                    $out2,
                    'line ' . ($fixture->first->range[0] - 1)
                );
            }
        });
    }
    private function footnote()
    {
        $this->group('markdown-it-footnote', function ($g){
            $g->group('footnote.txt', function ($gg) {
                $md = new MarkdownIt(["linkify"=>true]);
                $md->plugin(new MarkdownItFootnote());

                // Check that defaults work correctly
                $this->generateFootnote(__DIR__.'/Fixtures/footnote.txt', $md, $gg);
            });

            $g->group('custom docId in env', function ($gg) {
                $md = new MarkdownIt();
                $md->plugin(new MarkdownItFootnote());

                // Now check that using `$env.documentId` works to prefix IDs
                $this->generateFootnote(__DIR__.'/Fixtures/footnote-prefixed.txt', $md, $gg, [ "docId" => 'test-doc-id' ]);
            });
        });
    }
}