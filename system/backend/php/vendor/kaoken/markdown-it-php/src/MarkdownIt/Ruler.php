<?php
/**
 * class Ruler
 *
 * Helper class, used by [[MarkdownIt#core]], [[MarkdownIt#block]] and
 * [[MarkdownIt#inline]] to manage sequences of functions (rules):
 *
 * - keep rules in defined order
 * - assign the $name to each $rule
 * - enable/disable rules
 * - add/replace rules
 * - allow assign rules to additional named $chains (in the same)
 * - cacheing lists of active rules
 *
 * You will not need use this class directly until write plugins. For simple
 * rules control use [[MarkdownIt.disable]], [[MarkdownIt.enable]] and
 * [[MarkdownIt.use]].
 **/

namespace Kaoken\MarkdownIt;


use Exception;

class Ruler
{

    /**
     * @var RulerObject[]  List of added rules. Each element is:
     * {
     *   name: XXX,
     *   enabled: Boolean,
     *   fn: Function(),
     *   alt: [ name2, name3 ]
     */
    protected array $__rules__ = [];

    // Cached rule chains.
    //
    // First level - chain name, '' for default.
    // Second level - diginal anchor for fast filtering by charcodes.
    //
    protected $__cache__ = null;

    // Helper methods, should not be used directly


    /**
     * Find rule index by $name
     * @param string $name
     * @return int
     */
    protected function __find__(string $name)
    {
        for ($i = 0; $i < count($this->__rules__); $i++) {
            if ($this->__rules__[$i]->name === $name) {
                return $i;
            }
        }
        return -1;
    }


    /**
     * Build rules lookup cache
     */
    protected function __compile__()
    {
        $chains = [ '' ];
    
        // collect unique names
        foreach ($this->__rules__ as &$rule) {
            if (!$rule->enabled) continue;
            foreach ($rule->alt as &$altName) {
                if (array_search($altName, $chains) === false) {
                    $chains[] = $altName;
                }
            }
        }

        $this->__cache__ = [];

        foreach ($chains as &$chain) {
            $this->__cache__[$chain] = [];
            foreach ($this->__rules__ as &$rule) {
                if (!$rule->enabled) continue;
                if ( strlen($chain) && array_search($chain, $rule->alt) === false) {
                    continue;
                }

                $this->__cache__[$chain][] = $rule->getEntity();
            }
        }
    }


    /**
     * Replace rule by name with new function & options. Throws error if name not
     * found.
     *
     * ##### Options:
     *
     * - __alt__ - array with names of "alternate" chains.
     *
     * ##### Example
     *
     * Replace existing typographer replacement rule with new one:
     *
     * ```PHP
     * class Instance{
     *     public property($state){...}
     * }
     * $md = new Mmarkdown-It();
     *
     * $md->core->ruler->at('replacements', [new Instance(), 'property']);
     * // or
     * $md->core->ruler->at('replacements', function replace($state) {
     *   //...
     * });
     * ```
     * @param string $name rule name to replace.
     * @param callable|array $entity new rule function or instance.
     * @param null $options new rule options (not mandatory).
     * @throws Exception
     */
    public function at(string $name, $entity, $options=null)
    {
        $index = $this->__find__($name);
        if( is_object($options) ) $opt = $options;
        else $opt = is_array($options) ? (object)$options : new \stdClass();

        if ($index === -1) { throw new Exception('Parser rule not found: ' . $name); }

        $this->__rules__[$index]->setEntity($entity);
        $this->__rules__[$index]->alt = $opt->alt ?? [];
        $this->__cache__ = null;
    }


    /**
     * Add new rule to chain before one with given name. See also
     * [[Ruler.after]], [[Ruler.push]].
     *
     * ##### Options:
     *
     * - __alt__ - array with names of "alternate" chains.
     *
     * ##### Example
     *
     * ```PHP
     * class Instance{
     *     public property($state){...}
     * }
     * $md = new MmarkdownIt();
     *
     * $md->core->ruler->before('paragraph', 'my_rule', [new Instance(), 'property']);
     * // or
     * $md->core->ruler->before('paragraph', 'my_rule', function replace($state) {
     *   //...
     * });
     * ```
     * @param string $beforeName new rule will be added before this one.
     * @param string $ruleName name of added rule.
     * @param callable|array $entity new rule function or instance.
     * @param null $options rule options (not mandatory).
     * @throws Exception
     */
    public function before(string $beforeName, string $ruleName, $entity, $options=null)
    {
        $index = $this->__find__($beforeName);
        if( is_object($options) ) $opt = $options;
        else $opt = is_array($options) ? (object)$options : new \stdClass();

        if ($index === -1) { throw new Exception('Parser rule not found: ' . $beforeName); }

        $obj = new RulerObject(
            $ruleName,
            true,
            $entity,
            $opt->alt ?? []
        );

        array_splice($this->__rules__, $index, 0, [$obj]);

        $this->__cache__ = null;
    }


    /**
     * Add new rule to chain after one with given name. See also
     * [[Ruler.before]], [[Ruler.push]].
     *
     * ##### Options:
     *
     * - __alt__ - array with names of "alternate" chains.
     *
     * ##### Example
     *
     * ```PHP
     * class Instance{
     *     public property($state){...}
     * }
     * $md = new MmarkdownIt();
     *
     * $md->inline->ruler->after('text', 'my_rule', [new Instance(), 'property']);
     * // or
     * $md->inline->ruler->after('text', 'my_rule', function replace($state) {
     *   //...
     * });
     * ```
     * @param string $afterName new rule will be added after this one.
     * @param string $ruleName name of added rule.
     * @param callable|array $entity new rule function or instance.
     * @param null $options rule options (not mandatory).
     * @throws Exception
     */
    public function after(string $afterName, string $ruleName, $entity, $options=null)
    {
        $index = $this->__find__($afterName);
        if( is_object($options) ) $opt = $options;
        else $opt = is_array($options) ? (object)$options : new \stdClass();

        if ($index === -1) { throw new Exception('Parser rule not found: ' . $afterName); }
        $obj = new RulerObject(
            $ruleName,
            true,
            $entity,
            $opt->alt ?? []
        );

        array_splice($this->__rules__, $index + 1, 0, [$obj]);

        $this->__cache__ = null;
    }

    /**
     * Push new rule to the end of chain. See also
     * [[Ruler.before]], [[Ruler.after]].
     *
     * ##### Options:
     *
     * - __alt__ - array with names of "alternate" chains.
     *
     * ##### Example
     *
     * ```PHP
     * class Instance{
     *     public property($state){...}
     * }
     *
     * $md = new MmarkdownIt();
     *
     * $md->core->ruler->push('my_rule', 'my_rule', [new Instance(), 'property']);
     * // or
     * $md->core->ruler->push('text', 'my_rule', function replace($state) {
     *   //...
     * });
     * ```
     * @param string $ruleName name of added rule.
     * @param callable|array $entity new rule function or instance.
     * @param array|null $options rule options (not mandatory).
     * @throws Exception
     */
    public function push(string $ruleName, $entity, $options=null)
    {
        if( is_object($options) ) $opt = $options;
        else $opt = is_array($options) ? (object)$options : new \stdClass();

        $obj = new RulerObject(
            $ruleName,
            true,
            $entity,
            $opt->alt ?? []
        );

        $this->__rules__[] = $obj;

        $this->__cache__ = null;
    }


    /**
     * Enable rules with given names. If any rule name not found - throw Error.
     * Errors can be disabled by second param.
     *
     * Returns list of found rule names (if no exception happened).
     *
     * See also [[Ruler.disable]], [[Ruler.enableOnly]].
     * @param string|array $list list of rule names to enable.
     * @param boolean $ignoreInvalid set `true` to ignore errors when $rule not found.
     * @return array
     * @throws Exception
     */
	 public function enable($list, $ignoreInvalid=false): array
     {
        if (!is_array($list)) { $list = [ $list ]; }

        $result = [];

         // Search by $name and enable
         foreach ($list as &$name){
             $idx = $this->__find__($name);

             if ($idx < 0) {
                 if ($ignoreInvalid) continue;
                 throw new Exception("Rules manager: invalid rule name '{$name}''");
             }
             $this->__rules__[$idx]->enabled = true;
             $result[] = $name;
         }

        $this->__cache__ = null;
        return $result;
    }


    /**
     * Enable rules with given names, and disable everything else. If any rule name
     * not found - throw Error. Errors can be disabled by second param.
     *
     * See also [[Ruler.disable]], [[Ruler.enable]].
     * @param array|string $list list of rule names to enable (whitelist).
     * @param bool $ignoreInvalid set `true` to ignore errors when $rule not found.
     * @throws Exception
     */
    public function enableOnly($list, $ignoreInvalid=false)
    {
        if (!is_array($list)) { $list = [ $list ]; }

        foreach ($this->__rules__ as &$rule) {
            $rule->enabled = false;
        }

        $this->enable($list, $ignoreInvalid);
    }


    /**
     * Disable rules with given names. If any rule name not found - throw Error.
     * Errors can be disabled by second param.
     *
     * Returns list of found $rule names (if no exception happened).
     *
     * See also [[Ruler.enable]], [[Ruler.enableOnly]].
     * @param string|array $list list of rule names to disable.
     * @param boolean $ignoreInvalid set `true` to ignore errors when rule not found.
     * @return array
     * @throws Exception
     */
    public function disable($list, $ignoreInvalid=false): array
    {
        if (!is_array($list)) { $list = [ $list ]; }

        $result = [];
        foreach ($list as &$name) {
            $idx = $this->__find__($name);

            if ($idx < 0) {
                if ($ignoreInvalid) continue;
                throw new Exception('Rules manager: invalid rule name ' . $name);
            }
            $this->__rules__[$idx]->enabled = false;
            $result[] = $name;
        }

        $this->__cache__ = null;
        return $result;
    }


    /**
     * Return array of active functions (rules) for given chain name. It analyzes
     * rules configuration, compiles caches if not exists and returns result.
     *
     * Default $chain $name is `''` (empty string). It can't be skipped. That's
     * done intentionally, to keep signature monomorphic for high speed.
     * @param string $chainName
     * @return array
     */
    public function getRules(string $chainName): array
    {
        if ($this->__cache__ === null) {
            $this->__compile__();
        }

        // Chain can be empty, if rules disabled. But we still have to return Array.
        return isset($this->__cache__[$chainName]) ? $this->__cache__[$chainName] : [];
    }

}