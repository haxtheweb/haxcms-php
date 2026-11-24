<?php
/**
 * Copyright (c) 2016 Kaoken, Vitaly Puzrin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 *
 * javascript varsion 0.1.4
 * @see https://github.com/markdown-it/markdown-it-testgen/index.js
 */

namespace Kaoken\Test\MarkdownItTestgen;

use Exception;
use Kaoken\MarkdownIt\Common\Utils;
use Kaoken\MarkdownIt\MarkdownIt;
use Symfony\Component\Yaml\Yaml;

class MarkdownItTestgen
{
    /**
     * @var null | coloser | object
     */
    public $describe = null;
    public $utils;

    public function __construct()
    {
        $this->describe = function (){};
        $this->utils = Utils::getInstance();
    }

    /**
     * @param string $str
     * @return string
     */
    protected function fixLF ($str)
    {
        return strlen($str) ? $str . "\n" : $str;
    }

    /**
     * @param string $input
     * @param object $options
     * @return null|\stdClass
     */
    function parse(string $input, object $options): ?\stdClass
    {
        $lines = preg_split("/\r?\n/",$input); // /g
        $max = count($lines);
        $min = 0;
        $line = 0;

        $result = new \stdClass();
        $result->fixtures = [];
        $result->meta = '';

        /**
         * @var staring
         */
        $sep = count($options->sep) ? $options->sep : [ '.' ];

        // Try to parse meta
        if ( isset($lines[0]) && preg_match("/^-{3,}$/", $lines[0])) {
            $line++;
            while ($line < $max && !preg_match("/^-{3,}$/", $lines[$line])) { $line++; }

            // If meta end found - extract range
            if ($line < $max) {
                $result->meta = join("\n", array_slice($lines, 1, $line-1));
                $line++;
                $min = $line;

            } else {
                // if no meta closing - reset to start and try to parse $data without meta
                $line = 1;
            }
        }

        // Scan $fixtures
        while ($line < $max) {
            if (array_search($lines[$line], $sep) === false) {
                $line++;
                continue;
            }

            $currentSep = $lines[$line];

            $fixture = [
                "type" =>  $currentSep,
                "header" =>  '',
                "first" =>  [
                    "text" =>  '',
                    "range" =>  []
                ],
                "second" =>  [
                    "text" =>  '',
                    "range" =>  []
                ]
            ];
            $fixture = json_decode(json_encode($fixture));

            $line++;
            $blockStart = $line;

            // seek end of first block
            while ($line < $max && $lines[$line] !== $currentSep) { $line++; }
            if ($line >= $max) { break; }

            $fixture->first->text = $this->fixLF(join("\n", array_slice($lines, $blockStart, $line-$blockStart)));
            $fixture->first->range[] = $blockStart;
            $fixture->first->range[] = $line;
            $line++;
            $blockStart = $line;

            // seek end of second block
            while ($line < $max && $lines[$line] !== $currentSep) { $line++; }
            if ($line >= $max) { break; }

            $fixture->second->text = $this->fixLF(join("\n", array_slice($lines, $blockStart, $line-$blockStart)));
            $fixture->first->range[] = $blockStart;
            $fixture->first->range[] = $line;
            $line++;

            // Look back for header on 2 $lines before texture blocks
            $i = $fixture->first->range[0] - 2;
            while ($i >= max($min, $fixture->first->range[0] - 3)) {
                $l = $lines[$i];
                if ( array_search($l, $sep) !== false) { break; }
                if ( strlen(trim($l)) ) {
                    $fixture->header = trim($l);
                    break;
                }
                $i--;
            }

            $result->fixtures[] = $fixture;
        }

        return (!empty($result->meta) || count($result->fixtures)) ? $result : null;
    }


    // Read $fixtures recursively, and run $iterator on $parsed content
    //
    // Options
    //
    // - sep (String|Array) - allowed $fixture separator(s)
    //
    // Parsed $data fields:
    //
    // - file (String): file $name
    // - meta (Mixed):  metadata from header, if exists
    // - $fixtures
    //
    /**
     * @param string $path
     * @param $options
     * @param null $iterator
     * @return array|null|\stdClass
     * @throws Exception
     */
    function load(string $path, $options, $iterator=null)
    {
        if (is_callable($options)) {
            $iterator = $options;
            $options = new \stdClass();
            $options->sep = [ '.' ];
        } else if (is_string($options)) {
            $options->sep = str_split($options, 1);
        } else if (is_array($options)) {
            $options->sep = $options;
        }else if( is_object($options)){
            $options->sep = $options->sep ?? [ '.' ];
        }

        if (is_file($path)) {
            if( !file_exists($path))
                throw new Exception('No exists file "'.$path.'"!');
            $input = file_get_contents($path);

            $parsed = $this->parse($input, $options);

            if (!$parsed) { return null; }

            $parsed->file = basename($path);
            try {
                if( isset($parsed->meta) )
                    $parsed->meta = Yaml::parse($parsed->meta,Yaml::PARSE_OBJECT_FOR_MAP);
                else
                    $parsed->meta = Yaml::parse('');
            } catch (Exception $e) {
                $parsed->meta = null;
            }

            if ($iterator) {
                $iterator($parsed);
            }
            return $parsed;
        }

        if (is_dir($path)) {
            if( !file_exists($path))
                throw new Exception('No exists dir "'.$path.'"!');
            $result = [];

            foreach(glob($path.'/*') as $file){
                if(is_file($file)){
                    $res = $this->load($file, $options, $iterator);
                    if (is_array($res)) {
                        $result = $result->concat($res);
                    } else if ($res) {
                        $result[] = $res;
                    }
                }
            }

            return $result;
        }

        // Silently other entries (symlinks and so on)
        return null;
    }


    /**
     * @param string $path
     * @param object|MarkdownIt $options
     * @param MarkdownIt|null $md
     * @throws Exception
     */
    function generate(string $path, $options, $md=null)
    {
        if (!$md) {
            $md = $options;
            $options = [];
        }
        if( is_array($options) ){
            $options = (object)$options;
        }

        $options = $this->utils->assign(new \stdClass(), $options);
        if( !is_object($options->assert) )
            throw new Exception("Property 'assert' does not exist in object \$options.");

        $this->load($path, $options, function ($data) use($md, $options) {
            if(!isset($data->meta)) $data->meta = new \stdClass();

            $options->assert->group($data->file, function ($g) use($md, $options,$data) {
                foreach ($data->fixtures as &$fixture) {
                    $g->strictEqual(
                        $md->render($fixture->first->text),
                        $fixture->second->text,
                        isset($fixture->header) && isset($options->header) ? $fixture->header : 'line ' . ($fixture->first->range[0] - 1)
                    );
                }
            });
        });
    }
}