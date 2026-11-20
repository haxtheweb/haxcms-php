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
 * use javascript version 1.3.0
 * @see https://github.com/markdown-it/markdown-it-emoji/blob/1.3.0/test/test.js
 */
namespace Kaoken\Test\MarkdownIt\Plugins\Emoji;


use Exception;
use Kaoken\Test\MarkdownItTestgen\MarkdownItTestgen;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\Emoji\Data\Shortcuts;
use Kaoken\MarkdownIt\Plugins\MarkdownItEmoji;

trait EmojiTrait
{
    /**
     * @throws Exception
     */
    private function emoji()
    {
        $this->group('markdown-it-emoji', function ($g){
            $g->group('default', function ($gg){
                $this->emojiDefault($gg);
            });
            $g->group('light', function ($gg){
                $this->emojiLight($gg);
            });
            $g->group('bare', function ($gg){
                $this->emojiBare($gg);
            });
            $g->group('integrity', function ($gg){
                $this->emojiIntegrity($gg);
            });
        });
    }

    /**
     * @param $g
     * @throws Exception
     */
    private function emojiDefault($g)
    {
        $obj = new MarkdownItTestgen();

        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji());
        $obj->generate(__DIR__.'/Fixtures/Default', [ 'header' => true, 'assert' => $g ], $md);

        $obj->generate(__DIR__.'/Fixtures/full.txt', [ 'header' => true, 'assert' => $g ], $md);


        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji(), [
            'defs' => [
                'one' => '!!!one!!!',
                'fifty' => '!!50!!'
            ],
            'shortcuts' => [
                'fifty' => [ ':50', '|50' ],
                'one' =>':uno'
            ]
        ]);
        $obj->generate(__DIR__.'/Fixtures/options.txt', [ 'header' => true, 'assert' => $g ], $md);


        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji(), [ 'enabled' => [ 'smile', 'grin' ] ]);
        $obj->generate(__DIR__.'/Fixtures/whitelist.txt', [ 'header' => true, 'assert' => $g ], $md);

        $md = (new MarkdownIt(['linkify' => true]))->plugin(new MarkdownItEmoji());
        $obj->generate(__DIR__.'/Fixtures/autolinks.txt', [ 'header' => true, 'assert' => $g ], $md);
    }

    /**
     * @param $g
     * @throws Exception
     */
    private function emojiLight($g)
    {
        $obj = new MarkdownItTestgen();

        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji('light'));
        $obj->generate(__DIR__.'/Fixtures/Default', [ 'header' => true, 'assert' => $g ], $md);

        $obj->generate(__DIR__.'/Fixtures/light.txt', [ 'header' => true, 'assert' => $g ], $md);

        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji('light'), [
            'defs' => [
                'one' => '!!!one!!!',
                'fifty' => '!!50!!'
            ],
            'shortcuts' => [
                'fifty' => [ ':50', '|50' ],
                'one' =>':uno'
            ]
        ]);
        $obj->generate(__DIR__.'/Fixtures/options.txt', [ 'header' => true, 'assert' => $g ], $md);


        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji('light'), [ 'enabled' => [ 'smile', 'grin' ] ]);
        $obj->generate(__DIR__.'/Fixtures/whitelist.txt', [ 'header' => true, 'assert' => $g ], $md);

        $md = (new MarkdownIt(['linkify' => true]))->plugin(new MarkdownItEmoji());
        $obj->generate(__DIR__.'/Fixtures/autolinks.txt', [ 'header' => true, 'assert' => $g ], $md);
    }

    /**
     * @param $g
     * @throws Exception
     */
    private function emojiBare($g){
        $obj = new MarkdownItTestgen();

        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji('bare'));
        $obj->generate(__DIR__.'/Fixtures/bare.txt', [ 'header' => true, 'assert' => $g ], $md);

        $md = (new MarkdownIt())->plugin(new MarkdownItEmoji('light'), [
            'defs' => [
                'one' => '!!!one!!!',
                'fifty' => '!!50!!'
            ],
            'shortcuts' => [
                'fifty' => [ ':50', '|50' ],
                'one' =>':uno'
            ]
        ]);

        $obj->generate(__DIR__.'/Fixtures/options.txt', [ 'header' => true, 'assert' => $g ], $md);
    }

    /**
     * @param $g
     * @throws Exception
     */
    private function emojiIntegrity($g)
    {
        $emojies_shortcuts  = Shortcuts::get();
        $emojies_defs       = json_decode(file_get_contents(__DIR__."/../../../../src/MarkdownIt/Plugins/Emoji/Data/full.json"), true);
        $emojies_defs_light = json_decode(file_get_contents(__DIR__."/../../../../src/MarkdownIt/Plugins/Emoji/Data/light.json"), true);

        $g->group('all shortcuts should exist', function ($gg) use(&$emojies_defs, &$emojies_shortcuts) {
            foreach ( $emojies_shortcuts as $name => &$val) {
                $gg->ok($emojies_defs[$name], "shortcut doesn't exist: " . $name);
            }
        });
        //-----------------------------------------------------------

        $g->group('no chars with "uXXXX" names allowed', function ($gg)  use(&$emojies_defs, &$emojies_shortcuts) {
            foreach ( $emojies_shortcuts as $name => &$val) {
                $gg->doesNotThrow(function () use(&$name) {
                    if ( preg_match("/^u[0-9a-b]{4,}$/i", $name) ) {
                        throw new \Exception('Name ' . $name . ' not allowed');
                    }
                },$name);
            }
        });
        //-----------------------------------------------------------
        $visible = file_get_contents(__DIR__.'/Fixtures/visible.txt');

        $available = array_map(function ($k) use(&$emojies_defs_light) {
            return preg_replace("/\x{FE0F}/u", '', $emojies_defs_light[$k]);
        }, array_keys($emojies_defs_light));

        $missed = '';

        foreach ( $available as &$ch) {
            if (array_search($ch, $available) === false) $missed .= $ch;
        }

        $g->doesNotThrow(function () use(&$missed) {
            if ($missed) {
                throw new \Exception('Characters ' . $missed . ' missed.');
            }
        }, 'all light chars should exist');
    }
}