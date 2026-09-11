<?php
include_once dirname(__FILE__) . '/EntityDefinition.php';
include_once dirname(__FILE__) . '/EntityReadOnlyException.php';
include_once dirname(__FILE__) . '/EntityStorage.php';
/**
 * A single loaded entity record with instance-level save()/delete().
 *
 * Holds its type, primary-key value, all record fields, and a back-reference
 * to its EntityDefinition. save()/delete() enforce the #3043
 * "no datastore => can't save" rule per-type via the definition: they throw
 * EntityReadOnlyException when definition.isReadOnly() (storage.type !==
 * 'datastore'), and otherwise delegate to the storage adapter.
 *
 * Concrete subclasses (FileEntity in Phase 2) add typed field accessors; the
 * base is abstract because a record always has a concrete type.
 */
abstract class Entity
{
    /** @var EntityDefinition The definition describing this record's type. */
    protected $definition;

    /** @var array The record fields (uuid/path/name/... per type). */
    protected $fields;

    /**
     * @param EntityDefinition $definition The entity type definition.
     * @param array $fields The record fields keyed by field name.
     */
    public function __construct(EntityDefinition $definition, array $fields = array())
    {
        $this->definition = $definition;
        $this->fields = $fields;
    }

    /** @return EntityDefinition */
    public function getDefinition()
    {
        return $this->definition;
    }

    /** @return string The entity type. */
    public function getType()
    {
        return $this->definition->getType();
    }

    /** @return array The full record field map. */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Get one field value.
     *
     * @param string $name Field name.
     * @return mixed|null The field value or null if absent.
     */
    public function get($name)
    {
        $name = (string) $name;
        return array_key_exists($name, $this->fields) ? $this->fields[$name] : null;
    }

    /**
     * Set one field value (in-memory; persisted only on save()).
     *
     * @param string $name Field name.
     * @param mixed $value Field value.
     */
    public function set($name, $value)
    {
        $this->fields[(string) $name] = $value;
    }

    /** @return mixed The primary-key value (fields[definition.primaryKey]). */
    public function getPrimaryKeyValue()
    {
        $pk = $this->definition->getPrimaryKey();
        if ($pk === '' || !array_key_exists($pk, $this->fields)) {
            return null;
        }
        return $this->fields[$pk];
    }

    /**
     * Persist (upsert) this record. Throws EntityReadOnlyException when the
     * definition is read-only (non-datastore); otherwise delegates to the
     * storage adapter's save(). In Phase 1 every storage is a
     * NotImplementedStorage, so even a writable (datastore) entity's save()
     * surfaces EntityStorageNotImplementedException until Phase 2 registers the
     * real FileStorage adapter.
     *
     * @throws EntityReadOnlyException When definition.isReadOnly().
     * @throws EntityStorageNotImplementedException When no adapter is registered.
     */
    public function save()
    {
        if ($this->definition->isReadOnly()) {
            throw new EntityReadOnlyException(
                $this->definition->getType(),
                $this->definition->getStorageType()
            );
        }
        $storage = $this->definition->getStorage();
        $storage->save($this);
    }

    /**
     * Delete this record. Same read-only guard as save(); otherwise delegates
     * to the storage adapter's delete() with the primary-key value.
     *
     * @throws EntityReadOnlyException When definition.isReadOnly().
     * @throws EntityStorageNotImplementedException When no adapter is registered.
     */
    public function delete()
    {
        if ($this->definition->isReadOnly()) {
            throw new EntityReadOnlyException(
                $this->definition->getType(),
                $this->definition->getStorageType()
            );
        }
        $storage = $this->definition->getStorage();
        $storage->delete($this->getPrimaryKeyValue());
    }
}
