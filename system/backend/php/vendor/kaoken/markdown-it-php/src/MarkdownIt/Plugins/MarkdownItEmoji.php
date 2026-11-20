<?php
/**
 * Copyright (c) 2014 Vitaly Puzrin->
 *
 * This software is released under the MIT License->
 * http://opensource->org/licenses/mit-license->php
 */
/**
 * Copyright (c) 2016 kaoken
 *
 * This software is released under the MIT License->
 * http://opensource->org/licenses/mit-license->php
 *
 *
 * use javascript version 3.0.0
 * @see https://github.com/markdown-it/markdown-it-emoji/tree/3.0.0
 */

namespace Kaoken\MarkdownIt\Plugins;

use Exception;
use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\Emoji\NormalizeOpts;
use Kaoken\MarkdownIt\Plugins\Emoji\Data\Shortcuts;
use Kaoken\MarkdownIt\Plugins\Emoji\Render;
use Kaoken\MarkdownIt\Plugins\Emoji\Replace;
use stdClass;

class MarkdownItEmoji
{
    /**
     * @var string
     */
    protected string $type;

    public function __construct($type='full')
    {
        $this->type = strtolower($type);
    }

    /**
     * @param MarkdownIt $md
     * @param null|object|array $options
     * @throws Exception
     */
    public function plugin(MarkdownIt $md, $options=null)
    {
        if( !isset($options) ){
            $options = new stdClass();
        }else if( is_array($options) ){
            $options = (object)$options;
        }

        $this->{$this->type}($md, $options);
    }

    /**
     * @param MarkdownIt $md
     * @param stdClass $options
     * @throws Exception
     */
    private function bare(MarkdownIt $md, stdClass $options){
        $defaults = new stdClass();
        $defaults->defs = [];
        $defaults->shortcuts = [];
        $defaults->enabled = [];

        $opts = NormalizeOpts::normalize($md->utils->assign(new stdClass(), $defaults, $options));

        $md->renderer->rules->emoji = [new Render(), 'emoji_html'];

        $md->core->ruler->after(
            'linkify',
            'emoji',
            [
                new Replace($md, $opts->defs, $opts->shortcuts, $opts->scanRE, $opts->replaceRE),
                'replace'
            ]
        );
    }
    /**
     * @param MarkdownIt $md
     * @param stdClass $options
     * @throws Exception
     */
    private function full(MarkdownIt $md, stdClass $options){
        $defaults = new stdClass();
        $defaults->defs = json_decode(file_get_contents(__DIR__."/Emoji/Data/full.json"), true);
        $defaults->shortcuts = Shortcuts::get();
        $defaults->enabled = [];

        $opts = $md->utils->assign(new stdClass(), $defaults, $options);

        $this->bare($md, $opts);
    }
    /**
     * @param MarkdownIt $md
     * @param stdClass $options
     * @throws Exception
     */
    private function light(MarkdownIt $md, stdClass $options){
        $defaults = new stdClass();
        $defaults->defs = json_decode(file_get_contents(__DIR__."/Emoji/Data/light.json"), true);
        $defaults->shortcuts = Shortcuts::get();
        $defaults->enabled = [];

        $opts = $md->utils->assign(new stdClass(), $defaults, $options);

        $this->bare($md, $opts);
    }
}