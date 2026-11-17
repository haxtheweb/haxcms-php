<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356).
 *
 * A JSON Schema (2020-12) validator for the keywords the plugin spec's schemas use, so haxcms-php can refuse an
 * invalid plugin.yaml the way the reference engine does (Ajv) without a new Composer dependency.
 *
 * Supported: type, enum, const, required, properties, additionalProperties, items, minItems, maxItems, uniqueItems,
 * contains, minLength, maxLength, pattern, minimum, maximum, format (uri, hostname, uuid), anyOf, oneOf, allOf, not,
 * if/then/else, $ref to "#/..." pointers inside the same document, $defs.
 * Anything else is ignored, as Ajv does with strict: false.
 *
 * Values must keep the JSON distinction between objects and arrays: decode JSON without the assoc flag and parse
 * YAML with Yaml::PARSE_OBJECT_FOR_MAP, so a map is a stdClass and a list is a PHP list.
 * Error messages follow Ajv's wording ("<path> must NOT have additional properties (name)") so logs and test
 * expectations read the same in both backends.
 */
class HAXCMSPluginSchema
{
    /** @var mixed */
    private $root;
    /** @var array<int, string> */
    private $errors = [];

    /** @param mixed $schema decoded with objects as stdClass */
    public function __construct($schema)
    {
        $this->root = $schema;
    }

    /**
     * @param mixed $value
     * @return array<int, string> error messages, empty when valid
     */
    public function validate($value)
    {
        $this->errors = [];
        $this->check($value, $this->root, '');
        return $this->errors;
    }

    /** Converts YAML or JSON text decoded as assoc arrays into the object form this validator needs. */
    public static function toJsonShape($value)
    {
        return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function typeOf($v)
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v)) {
            return 'integer';
        }
        if (is_float($v)) {
            return floor($v) == $v && is_finite($v) ? 'integer' : 'number';
        }
        if (is_string($v)) {
            return 'string';
        }
        if (is_object($v)) {
            return 'object';
        }
        return 'array';
    }

    private static function isType($v, $t)
    {
        $actual = self::typeOf($v);
        if ($t === 'number') {
            return $actual === 'number' || $actual === 'integer';
        }
        return $actual === $t;
    }

    private static function equal($a, $b)
    {
        return json_encode(self::canonical($a)) === json_encode(self::canonical($b));
    }

    private static function canonical($v)
    {
        if (is_object($v)) {
            $a = get_object_vars($v);
            ksort($a);
            return array_map([self::class, 'canonical'], $a) + ['__object' => true];
        }
        if (is_array($v)) {
            return array_map([self::class, 'canonical'], $v);
        }
        if (is_float($v) && floor($v) == $v) {
            return (int) $v;
        }
        return $v;
    }

    private function resolve($ref)
    {
        if (strpos($ref, '#') !== 0) {
            return null;
        }
        $node = $this->root;
        foreach (array_filter(explode('/', substr($ref, 1)), function ($x) {
            return $x !== '';
        }) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
            if (is_object($node) && property_exists($node, $part)) {
                $node = $node->$part;
            } elseif (is_array($node) && array_key_exists($part, $node)) {
                $node = $node[$part];
            } else {
                return null;
            }
        }
        return $node;
    }

    private static function regex($pattern)
    {
        // ECMA-262 patterns as the spec writes them: no delimiters, unicode
        return '/' . str_replace('/', '\\/', $pattern) . '/u';
    }

    private static function formatOk($format, $v)
    {
        if (!is_string($v)) {
            return true;
        }
        switch ($format) {
            case 'uri':
                return (bool) preg_match('/^[a-z][a-z0-9+.-]*:[^\s]*$/i', $v);
            case 'hostname':
                return strlen($v) <= 253 && (bool) preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i', $v);
            case 'uuid':
                return (bool) preg_match('/^(?:urn:uuid:)?[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $v);
        }
        return true;
    }

    private function fail($path, $message)
    {
        $this->errors[] = ($path === '' ? '/' : $path) . ' ' . $message;
        return false;
    }

    /** @return bool */
    private function check($v, $s, $path)
    {
        if ($s === true || $s === null) {
            return true;
        }
        if ($s === false) {
            return $this->fail($path, 'boolean schema is false');
        }
        $s = (object) $s;
        $ok = true;
        if (isset($s->{'$ref'})) {
            $target = $this->resolve($s->{'$ref'});
            if ($target !== null && !$this->check($v, $target, $path)) {
                $ok = false;
            }
        }
        if (isset($s->type)) {
            $types = is_array($s->type) ? $s->type : [$s->type];
            $match = false;
            foreach ($types as $t) {
                if (self::isType($v, $t)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return $this->fail($path, 'must be ' . implode(',', $types));
            }
        }
        if (property_exists($s, 'const') && !self::equal($v, $s->const)) {
            $ok = $this->fail($path, 'must be equal to constant');
        }
        if (isset($s->enum)) {
            $in = false;
            foreach ($s->enum as $e) {
                if (self::equal($v, $e)) {
                    $in = true;
                    break;
                }
            }
            if (!$in) {
                $ok = $this->fail($path, 'must be equal to one of the allowed values');
            }
        }
        if (is_string($v)) {
            $len = mb_strlen($v, 'UTF-8');
            if (isset($s->minLength) && $len < $s->minLength) {
                $ok = $this->fail($path, "must NOT have fewer than {$s->minLength} characters");
            }
            if (isset($s->maxLength) && $len > $s->maxLength) {
                $ok = $this->fail($path, "must NOT have more than {$s->maxLength} characters");
            }
            if (isset($s->pattern) && !preg_match(self::regex($s->pattern), $v)) {
                $ok = $this->fail($path, "must match pattern \"{$s->pattern}\"");
            }
            if (isset($s->format) && !self::formatOk($s->format, $v)) {
                $ok = $this->fail($path, "must match format \"{$s->format}\"");
            }
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s->minimum) && $v < $s->minimum) {
                $ok = $this->fail($path, "must be >= {$s->minimum}");
            }
            if (isset($s->maximum) && $v > $s->maximum) {
                $ok = $this->fail($path, "must be <= {$s->maximum}");
            }
        }
        if (is_array($v)) {
            $n = count($v);
            if (isset($s->minItems) && $n < $s->minItems) {
                $ok = $this->fail($path, "must NOT have fewer than {$s->minItems} items");
            }
            if (isset($s->maxItems) && $n > $s->maxItems) {
                $ok = $this->fail($path, "must NOT have more than {$s->maxItems} items");
            }
            if (!empty($s->uniqueItems)) {
                $seen = [];
                foreach ($v as $i => $item) {
                    $key = json_encode(self::canonical($item));
                    if (isset($seen[$key])) {
                        $ok = $this->fail($path, "must NOT have duplicate items (items ## {$seen[$key]} and $i are identical)");
                        break;
                    }
                    $seen[$key] = $i;
                }
            }
            if (isset($s->items) && !is_array($s->items)) {
                foreach ($v as $i => $item) {
                    if (!$this->check($item, $s->items, "$path/$i")) {
                        $ok = false;
                    }
                }
            }
            if (isset($s->contains)) {
                $found = false;
                $saved = $this->errors;
                foreach ($v as $item) {
                    if ($this->check($item, $s->contains, $path)) {
                        $found = true;
                        break;
                    }
                }
                $this->errors = $saved;
                if (!$found) {
                    $ok = $this->fail($path, 'must contain at least 1 valid item(s)');
                }
            }
        }
        if (is_object($v)) {
            $props = get_object_vars($v);
            foreach ((array) ($s->required ?? []) as $r) {
                if (!array_key_exists($r, $props)) {
                    $ok = $this->fail($path, "must have required property '$r'");
                }
            }
            $declared = isset($s->properties) ? get_object_vars((object) $s->properties) : [];
            foreach ($declared as $k => $sub) {
                if (array_key_exists($k, $props) && !$this->check($props[$k], $sub, "$path/$k")) {
                    $ok = false;
                }
            }
            if (property_exists($s, 'additionalProperties')) {
                foreach ($props as $k => $pv) {
                    if (array_key_exists($k, $declared)) {
                        continue;
                    }
                    if ($s->additionalProperties === false) {
                        $ok = $this->fail($path, "must NOT have additional properties ($k)");
                    } elseif (is_object($s->additionalProperties) && !$this->check($pv, $s->additionalProperties, "$path/$k")) {
                        $ok = false;
                    }
                }
            }
        }
        foreach ((array) ($s->allOf ?? []) as $sub) {
            if (!$this->check($v, $sub, $path)) {
                $ok = false;
            }
        }
        if (isset($s->anyOf) || isset($s->oneOf)) {
            $list = isset($s->anyOf) ? $s->anyOf : $s->oneOf;
            $saved = $this->errors;
            $passed = 0;
            foreach ($list as $sub) {
                $this->errors = [];
                if ($this->check($v, $sub, $path)) {
                    $passed++;
                }
            }
            $this->errors = $saved;
            if (isset($s->anyOf) && $passed === 0) {
                $ok = $this->fail($path, 'must match a schema in anyOf');
            }
            if (isset($s->oneOf) && $passed !== 1) {
                $ok = $this->fail($path, 'must match exactly one schema in oneOf');
            }
        }
        if (property_exists($s, 'not')) {
            $saved = $this->errors;
            $matched = $this->check($v, $s->not, $path);
            $this->errors = $saved;
            if ($matched) {
                $ok = $this->fail($path, 'must NOT be valid');
            }
        }
        if (property_exists($s, 'if')) {
            $saved = $this->errors;
            $cond = $this->check($v, $s->if, $path);
            $this->errors = $saved;
            if ($cond && property_exists($s, 'then') && !$this->check($v, $s->then, $path)) {
                $ok = $this->fail($path, 'must match "then" schema');
            }
            if (!$cond && property_exists($s, 'else') && !$this->check($v, $s->else, $path)) {
                $ok = $this->fail($path, 'must match "else" schema');
            }
        }
        return $ok;
    }
}
