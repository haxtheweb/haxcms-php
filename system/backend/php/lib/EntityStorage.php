<?php
/**
 * Collection-access interface for one entity type.
 *
 * `load($key)` returns a single Entity by primary key; `list($filters)`
 * returns many. Writable implementations (FileStorage, Phase 2) also
 * implement save()/delete(); read-only / reserved implementations
 * (NotImplementedStorage) throw EntityStorageNotImplementedException on every
 * method.
 *
 * The primary key per type is declared in the entity's entities.yaml entry:
 * uuid for file, element for theme, name for skeleton, metadata.site.name for
 * site, id for item, name for system.
 */
interface EntityStorage
{
    /**
     * Load a single entity by its primary-key value.
     *
     * @param mixed $key The primary-key value (uuid/element/name/id/...).
     * @return Entity The loaded entity record.
     * @throws EntityStorageNotImplementedException When no adapter is registered.
     */
    public function load($key);

    /**
     * List entities, optionally filtered.
     *
     * @param array $filters Optional filter key/value map.
     * @return Entity[] The matched entity records.
     * @throws EntityStorageNotImplementedException When no adapter is registered.
     */
    public function list($filters = array());

    /**
     * Persist (upsert) an entity. Only datastore-backed types support this.
     *
     * @param Entity $entity The entity to save.
     * @throws EntityStorageNotImplementedException When no adapter is registered.
     */
    public function save($entity);

    /**
     * Delete an entity by its primary-key value.
     *
     * @param mixed $key The primary-key value to delete.
     * @throws EntityStorageNotImplementedException When no adapter is registered.
     */
    public function delete($key);
}
