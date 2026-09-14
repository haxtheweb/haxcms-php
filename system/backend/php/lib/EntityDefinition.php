<?php
include_once dirname(__FILE__) . '/EntityStorage.php';
include_once dirname(__FILE__) . '/NotImplementedStorage.php';
/**
 * Typed wrapper around one entities.yaml entry. Mirrors the OpenAPI
 * EntityDescriptor 1:1 and knows its `storage` block.
 *
 * Constructed by EntityRegistry with the raw parsed entry and an optional
 * storage-resolver callable (which the registry uses to hand back registered
 * adapters). When no resolver is supplied — or the resolver has no adapter for
 * this type — getStorage() returns a NotImplementedStorage, so the
 * EntityRegistry->getDefinition(type)->getStorage()->load() chain is uniform
 * across every type even before real adapters are wired.
 */
class EntityDefinition
{
    /** @var array The raw parsed entities.yaml entry for this type. */
    private $entry;

    /** @var callable|null Resolves a type string to an EntityStorage instance. */
    private $storageResolver;

    /**
     * @param array $entry One parsed entry from entities.yaml (the value under
     *                     entities.<type>).
     * @param callable|null $storageResolver fn(string $type): EntityStorage.
     */
    public function __construct(array $entry, $storageResolver = null)
    {
        $this->entry = $entry;
        $this->storageResolver = $storageResolver;
    }

    /** @return string The entity type (canonical identifier, e.g. 'file'). */
    public function getType()
    {
        return isset($this->entry['type']) ? (string) $this->entry['type'] : '';
    }

    /** @return string Site or system scope. */
    public function getScope()
    {
        return isset($this->entry['scope']) ? (string) $this->entry['scope'] : '';
    }

    /** @return string Human-readable description. */
    public function getDescription()
    {
        return isset($this->entry['description']) ? (string) $this->entry['description'] : '';
    }

    /** @return string Primary-key field name. */
    public function getPrimaryKey()
    {
        return isset($this->entry['primaryKey']) ? (string) $this->entry['primaryKey'] : '';
    }

    /** @return string[] Unique-key field names. */
    public function getUniqueKeys()
    {
        return isset($this->entry['uniqueKeys']) && is_array($this->entry['uniqueKeys'])
            ? array_values($this->entry['uniqueKeys'])
            : array();
    }

    /** @return string[] Required field names. */
    public function getRequiredFields()
    {
        return isset($this->entry['requiredFields']) && is_array($this->entry['requiredFields'])
            ? array_values($this->entry['requiredFields'])
            : array();
    }

    /** @return array The storage block (type/enabled/scope/location/storeSchema/collectionKey/indexKey/source). */
    public function getStorageBlock()
    {
        return isset($this->entry['storage']) && is_array($this->entry['storage'])
            ? $this->entry['storage']
            : array();
    }

    /** @return string The storage.type vocabulary value (datastore/jos.items/webcomponent/file/jos/config). */
    public function getStorageType()
    {
        $storage = $this->getStorageBlock();
        return isset($storage['type']) ? (string) $storage['type'] : '';
    }

    /** @return string[] Supported operation names. */
    public function getSupportedOperations()
    {
        return isset($this->entry['supportedOperations']) && is_array($this->entry['supportedOperations'])
            ? array_values($this->entry['supportedOperations'])
            : array();
    }

    /** @return string[] Endpoint paths. */
    public function getEndpoints()
    {
        return isset($this->entry['endpoints']) && is_array($this->entry['endpoints'])
            ? array_values($this->entry['endpoints'])
            : array();
    }

    /** @return string[] Filterable field names (may be empty for types that declare none). */
    public function getFilterableFields()
    {
        return isset($this->entry['filterableFields']) && is_array($this->entry['filterableFields'])
            ? array_values($this->entry['filterableFields'])
            : array();
    }

    /** @return string[] Sortable field names (may be empty). */
    public function getSortableFields()
    {
        return isset($this->entry['sortableFields']) && is_array($this->entry['sortableFields'])
            ? array_values($this->entry['sortableFields'])
            : array();
    }

    /** @return string[] Selectable field names (may be empty). */
    public function getSelectableFields()
    {
        return isset($this->entry['selectableFields']) && is_array($this->entry['selectableFields'])
            ? array_values($this->entry['selectableFields'])
            : array();
    }

    /** @return string[] Supported formats. */
    public function getFormats()
    {
        return isset($this->entry['formats']) && is_array($this->entry['formats'])
            ? array_values($this->entry['formats'])
            : array();
    }

    /** @return string Auth requirement label. */
    public function getAuth()
    {
        return isset($this->entry['auth']) ? (string) $this->entry['auth'] : '';
    }

    /** @return bool Whether the entity type is enabled in the registry. */
    public function isEnabled()
    {
        return isset($this->entry['enabled']) ? (bool) $this->entry['enabled'] : false;
    }

    /**
     * Whether this entity's storage adapter is implemented. Mirrors
     * `storage.enabled` from entities.yaml. Only `file` is true (and even then
     * the adapter is not registered until Phase 2, so getStorage() still
     * returns NotImplementedStorage in Phase 1).
     *
     * @return bool
     */
    public function isImplemented()
    {
        $storage = $this->getStorageBlock();
        return isset($storage['enabled']) ? (bool) $storage['enabled'] : false;
    }

    /**
     * Whether this entity is read-only. Only `datastore`-backed entities are
     * writable; every other storage.type (jos.items/webcomponent/file/jos/
     * config) is read-only. This drives the save()/delete() guard on Entity.
     *
     * @return bool
     */
    public function isReadOnly()
    {
        return $this->getStorageType() !== 'datastore';
    }

    /**
     * Build/return the storage adapter for this definition. If the registry
     * injected a resolver and it returns an adapter, use it; otherwise return
     * a NotImplementedStorage (the default for any unregistered type).
     *
     * @return EntityStorage
     */
    public function getStorage()
    {
        if (is_callable($this->storageResolver)) {
            $adapter = call_user_func($this->storageResolver, $this->getType());
            if ($adapter instanceof EntityStorage) {
                return $adapter;
            }
        }
        return new NotImplementedStorage($this->getType());
    }

    /**
     * Produce the descriptor array served by the /v1/entities endpoints.
     * Mirrors the entities.yaml entry 1:1 (extended EntityDescriptor shape),
     * with `name` included as a backward-compatible alias of `type`.
     *
     * @return array
     */
    public function toDescriptorArray()
    {
        $type = $this->getType();
        $descriptor = array(
            'type' => $type,
            'name' => $type,
            'scope' => $this->getScope(),
            'description' => $this->getDescription(),
            'primaryKey' => $this->getPrimaryKey(),
            'uniqueKeys' => $this->getUniqueKeys(),
            'requiredFields' => $this->getRequiredFields(),
            'storage' => $this->getStorageBlock(),
            'supportedOperations' => $this->getSupportedOperations(),
            'endpoints' => $this->getEndpoints(),
            'formats' => $this->getFormats(),
            'auth' => $this->getAuth(),
            'enabled' => $this->isEnabled(),
        );
        // Optional list fields: only include when declared in entities.yaml so
        // the descriptor mirrors the registry entry exactly.
        $filterable = $this->getFilterableFields();
        if (count($filterable) > 0) {
            $descriptor['filterableFields'] = $filterable;
        }
        $sortable = $this->getSortableFields();
        if (count($sortable) > 0) {
            $descriptor['sortableFields'] = $sortable;
        }
        $selectable = $this->getSelectableFields();
        if (count($selectable) > 0) {
            $descriptor['selectableFields'] = $selectable;
        }
        return $descriptor;
    }
}
