<?php
/**
 * Util ruler object
 * @see \Kaoken\MarkdownIt\Ruler
 */

namespace Kaoken\MarkdownIt;


use Exception;

class RulerObject
{
    /**
     * rule name.
     * @var string
     */
    public string $name = '';
    /**
     * rule Enable or Disable
     * @var bool
     */
    public bool $enabled = true;
    /**
     * @var null|object
     */
    public ?object $instance = null;
    /**
     * class Member function name
     * $this->{$this->method}(...);
     * @var string
     */
    public string $method = '';
    /**
     * @var callable
     */
    public $fn = null;
    /**
     * @var array
     */
    public array $alt = [];

    /**
     * RulerObject constructor.
     * @param string $name rule name.
     * @param bool $enabled rule Enable or Disable
     * @param callable|array $entity new rule function or instance.
     * @param array $alt rule options (not mandatory).
     * @throws Exception
     */
    public function __construct(string $name, bool $enabled, $entity, array $alt)
    {
        $this->name = $name;
        $this->setEntity($entity);
        $this->enabled = $enabled;
        $this->alt = $alt;
    }

    /**
     * @param callable|array  $entity    new rule function or instance.
     * @throws Exception
     */
    public function setEntity($entity)
    {
        if( is_array($entity) ){
            if( !is_object($entity[0]) )
                throw new Exception('$entity[0] is not an object.');
            else if( !is_string($entity[1]) )
                throw new Exception('$entity[1] is not an string.');
            else if( !method_exists($entity[0], $entity[1]))
                throw new Exception('Method `'.$entity[1].'` does not exist in class '.get_class($entity[0]) .'.');

            $this->fn = null;
            $this->instance = $entity[0];
            $this->method = $entity[1];
        }else if( is_callable($entity) ){
            $this->instance = null;
            $this->method = '';
            $this->fn = $entity;
        }else{
            throw new Exception('$entity argument is invalid.');
        }
    }

    /**
     * @return array|callable
     */
    public function getEntity()
    {
        if( $this->fn !== null ){
            return $this->fn;
        }else{
            return [$this->instance, $this->method];
        }
    }
}