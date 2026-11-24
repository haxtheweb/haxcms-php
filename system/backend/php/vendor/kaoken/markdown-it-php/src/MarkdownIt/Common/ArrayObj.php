<?php
namespace Kaoken\MarkdownIt\Common;
use ArrayAccess;

class ArrayObj implements ArrayAccess
{
    private array $container = [];

    /**
     * @param int $length
     */
    public function __construct(int $length=0)
    {
        if($length > 0){
            $this->container = array_fill(0, $length, null);
        }
    }

    /**
     * Pop the element off the end of array
     * @return mixed Returns the value of the last element of array.
     * If array is empty (or is not an array), NULL will be returned.
     */
    public function pop()
    {
        return array_pop ( $this->container );
    }

    /**
     * Push one or more elements onto the end of array
     * @param mixed $value
     */
    public function push($value)
    {
        $this->container[] = $value;
    }

    /**
     * Count all elements in an array, or something in an object
     * @return int Returns the number of elements in array.
     * if array is empty, 0 will be returned.
     */
    public function length() : int
    {
        return count($this->container);
    }
    /**
     * Whether a offset exists
     * @link https://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return isset($this->container[$offset]);
    }

    /**
     * Offset to retrieve
     * @link https://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->container[$offset];
    }

    /**
     * Offset to set
     * @link https://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    /**
     * Offset to unset
     * @link https://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->container[$offset]);
    }
}