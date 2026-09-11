<?php
include_once dirname(__FILE__) . '/EntityStorage.php';
include_once dirname(__FILE__) . '/EntityStorageNotImplementedException.php';
/**
 * Default storage adapter returned by EntityRegistry for any entity type
 * whose real adapter is not registered.
 *
 * In Phase 1 NO real adapters are registered, so getStorage() for every type
 * (including file) returns a NotImplementedStorage. load()/list()/save()/
 * delete() all throw EntityStorageNotImplementedException.
 *
 * The entity DEFINITION still exists in entities.yaml (so getDefinition()
 * works for every type); only the read/write adapter is reserved for a future
 * phase. This keeps the EntityRegistry->getDefinition(type)->getStorage()
 * chain uniform across all types.
 */
class NotImplementedStorage implements EntityStorage
{
    /** @var string The entity type this stub stands in for. */
    private $type;

    /**
     * @param string $type The entity type whose adapter is not registered.
     */
    public function __construct($type = '')
    {
        $this->type = (string) $type;
    }

    /**
     * @return string The entity type this stub stands in for.
     */
    public function getType()
    {
        return $this->type;
    }

    public function load($key)
    {
        throw new EntityStorageNotImplementedException($this->type, 'load');
    }

    public function list($filters = array())
    {
        throw new EntityStorageNotImplementedException($this->type, 'list');
    }

    public function save($entity)
    {
        throw new EntityStorageNotImplementedException($this->type, 'save');
    }

    public function delete($key)
    {
        throw new EntityStorageNotImplementedException($this->type, 'delete');
    }
}
