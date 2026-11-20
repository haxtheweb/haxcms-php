<?php
// Convert input $options to more useable format
// and compile search regexp
namespace Kaoken\MarkdownIt\Plugins\Emoji;


class NormalizeOpts
{
    /**
     * @param string $str
     * @return mixed
     */
    public static function quoteRE(string $str)
    {
//        return preg_replace("/([\.\?*+^$\[\]\\\\(){}|-])/", '\\\\$1', preg_replace("/([\\\\])/", '\\\\$1', $str));
        return preg_replace("/([\/\.\?*+^$\[\]\\\\(){}|-])/", '\\\\$1',$str);
    }


    /**
     * @param object $options
     * @return \stdClass
     */
    public static function normalize(object $options): \stdClass
    {
        $emojies = &$options->defs;
        // Filter $emojies by whitelist, if needed
        if ( count($options->enabled) ) {
            $a = [];
            foreach ($emojies as $key => &$val) {
                if (array_search($key, $options->enabled) !== false) {
                    $a[$key] = $emojies[$key];
                }
            }
            $emojies = $a;
            unset($a);
        }
        // Flatten $shortcuts to simple object: { $alias: emoji_name }
        $shortcuts = [];
        foreach ($options->shortcuts as $key => &$val) {
            if (!isset($emojies[$key])) continue;
            if (is_array($options->shortcuts[$key])) {
                foreach ($options->shortcuts[$key] as &$alias) {
                    $shortcuts[$alias] = $key;
                }
                continue;
            }
            $shortcuts[$options->shortcuts[$key]] = $key;
        }


        $keys = isset($emojies) ? array_keys($emojies) : [];
        $names = "";
        // If no definitions are given, return empty regex to avoid replacements with 'undefined'.
        if (count($keys) === 0) {
            $names = '^$';
        } else {
            // Compile regexp
            $a = array_merge(
                array_map(function ($name) {
                    return ':' . $name . ':';
                }, $keys),
                array_keys($shortcuts)
            );
            rsort($a);
            $names = join('|',
                array_map(function ($name) {
                    return self::quoteRE($name);
                },$a)
            );
            unset($a);
        }

        $o = new \stdClass();
        $o->defs = &$emojies;
        $o->shortcuts = &$shortcuts;
        $o->scanRE = '/' . $names . '/';
        $o->replaceRE = '/' . $names . '/';

        return $o;
    }
}