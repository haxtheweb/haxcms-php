<?php
include_once dirname(__FILE__) . '/EntityStorage.php';
include_once dirname(__FILE__) . '/EntityDefinition.php';
include_once dirname(__FILE__) . '/EntityRegistry.php';
include_once dirname(__FILE__) . '/Entity.php';
include_once dirname(__FILE__) . '/FileEntity.php';
include_once dirname(__FILE__) . '/FilesDataStore.php';
/**
 * Writable EntityStorage adapter for the 'file' entity type (Phase 2, #3043).
 *
 * Wraps FilesDataStore (low-level files.json I/O) and implements EntityStorage
 * so EntityRegistry->getStorage('file') returns this real adapter instead of
 * NotImplementedStorage. load($uuid) is O(1) from the files.json uuid index;
 * list($filters) runs reconcileMissingFromDisk first; save($entity) upserts
 * into files.json; delete($uuid) removes the record AND scrubs the uuid from
 * every page's page.metadata.files (one manifest save).
 */
class FileStorage implements EntityStorage
{
    /** @var mixed The site context. */
    private $site;

    /** @var FilesDataStore */
    private $dataStore;

    /** @var EntityDefinition|null The 'file' definition (for building entities). */
    private $definition;

    /**
     * @param mixed $site The site context.
     * @param EntityDefinition|null $definition The 'file' EntityDefinition
     *   (from the registry) so loaded entities can save()/delete() back
     *   through the same adapter. If null, a standalone definition is built.
     */
    public function __construct($site, EntityDefinition $definition = null)
    {
        $this->site = $site;
        $this->dataStore = new FilesDataStore($site);
        $this->definition = $definition;
    }

    /**
     * Register this FileStorage on an EntityRegistry for the 'file' type.
     * Route handlers call this once after constructing the registry so
     * getStorage('file') returns the real adapter.
     *
     * @param EntityRegistry $registry
     * @return FileStorage The registered adapter.
     */
    public static function registerOn(EntityRegistry $registry)
    {
        $site = $registry->getSite();
        $definition = $registry->getDefinition('file');
        $storage = new self($site, $definition);
        $registry->registerStorage('file', $storage);
        return $storage;
    }

    /**
     * Load a single file entity by UUID (O(1) from the files.json index).
     *
     * @param string $uuid
     * @return FileEntity|null
     */
    public function load($key)
    {
        $record = $this->dataStore->getByUuid($key);
        if ($record === null) {
            return null;
        }
        return new FileEntity($this->resolveDefinition(), $record);
    }

    /**
     * List file entities. Runs reconcileMissingFromDisk first so manually
     * dropped files appear in the index. Optional filters: mimetype, name, path.
     *
     * @param array $filters
     * @return FileEntity[]
     */
    public function list($filters = array())
    {
        $this->dataStore->reconcileMissingFromDisk();
        $records = $this->dataStore->getRecords();
        $definition = $this->resolveDefinition();
        $entities = array();
        foreach ($records as $record) {
            $entities[] = new FileEntity($definition, $record);
        }
        return $entities;
    }

    /**
     * Upsert a file entity into files.json.
     *
     * @param Entity $entity
     * @return bool
     */
    public function save($entity)
    {
        if (!($entity instanceof Entity)) {
            return false;
        }
        $fields = $entity->getFields();
        if (!is_array($fields)) {
            return false;
        }
        // Ensure required fields are present.
        $uuid = isset($fields['uuid']) ? (string) $fields['uuid'] : '';
        $path = isset($fields['path']) ? (string) $fields['path'] : '';
        $name = isset($fields['name']) ? (string) $fields['name'] : '';
        $mimetype = isset($fields['mimetype']) ? (string) $fields['mimetype'] : '';
        if ($uuid === '' || $path === '' || $name === '' || $mimetype === '') {
            return false;
        }
        return $this->dataStore->upsertRecord($fields);
    }

    /**
     * Delete a file entity by UUID: remove the files.json record and scrub
     * the uuid from every page's page.metadata.files (one manifest save).
     * The actual disk file deletion is handled by the route handler's
     * fileOperation() (which has the security validation); this method only
     * handles the data-layer cleanup.
     *
     * @param string $uuid
     * @return bool
     */
    public function delete($key)
    {
        $uuid = strtolower(trim((string) $key));
        if ($uuid === '') {
            return false;
        }
        $removed = $this->dataStore->removeRecord($uuid);
        $this->scrubUuidFromPages($uuid);
        return $removed;
    }

    /**
     * @return FilesDataStore The underlying datastore (for route handlers
     *   that need reconcileMissingFromDisk / flagOrphans / resolveUuidByPath).
     */
    public function getDataStore()
    {
        return $this->dataStore;
    }

    /**
     * Resolve the EntityDefinition for 'file'. Uses the injected definition
     * if available; otherwise builds a standalone one from a hardcoded entry
     * (the file entity definition is stable).
     *
     * @return EntityDefinition
     */
    private function resolveDefinition()
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $entry = array(
            'type' => 'file',
            'scope' => 'site',
            'primaryKey' => 'uuid',
            'uniqueKeys' => array('uuid'),
            'requiredFields' => array('uuid', 'path', 'name', 'mimetype'),
            'storage' => array(
                'type' => 'datastore',
                'enabled' => true,
                'scope' => 'site',
                'location' => '{siteDirectory}/files/files.json',
                'storeSchema' => 'HAXCMS-FILE-SCHEMA-V1',
                'collectionKey' => 'files',
                'indexKey' => 'uuid',
            ),
            'supportedOperations' => array('load', 'save', 'list', 'delete', 'reconcile'),
            'endpoints' => array('/x/api/v1/files', '/x/api/v1/files/{fileUuid}'),
            'formats' => array('json', 'md', 'yaml', 'xml'),
            'auth' => 'authenticated-site',
            'enabled' => true,
        );
        // Resolver returns $this so $entity->save() delegates back to us.
        $self = $this;
        $resolver = function ($type) use ($self) {
            if ($type === 'file') {
                return $self;
            }
            return null;
        };
        return new EntityDefinition($entry, $resolver);
    }

    /**
     * Walk the site manifest items and remove the given uuid from every
     * page's page.metadata.files array. Saves the manifest once if any
     * page was modified.
     *
     * @param string $uuid
     */
    private function scrubUuidFromPages($uuid)
    {
        $site = $this->site;
        if (!isset($site) || !is_object($site)) {
            return;
        }
        if (!isset($site->manifest) || !isset($site->manifest->items)) {
            return;
        }
        $items = $site->manifest->items;
        if (!is_array($items)) {
            return;
        }
        $modified = false;
        foreach ($items as $item) {
            if (!isset($item->metadata) || !is_object($item->metadata)) {
                continue;
            }
            if (!isset($item->metadata->files) || !is_array($item->metadata->files)) {
                continue;
            }
            $files = $item->metadata->files;
            $newFiles = array();
            $changed = false;
            foreach ($files as $entry) {
                // uuid-string shape (Phase 2): entries are plain strings.
                if (is_string($entry)) {
                    if (strtolower($entry) === $uuid) {
                        $changed = true;
                        continue;
                    }
                    $newFiles[] = $entry;
                }
                // Legacy object shape: entries are objects/arrays with no uuid
                // field — skip (they self-heal on the next page save).
                else {
                    $newFiles[] = $entry;
                }
            }
            if ($changed) {
                $item->metadata->files = array_values($newFiles);
                $modified = true;
            }
        }
        if ($modified) {
            if (method_exists($site, 'save')) {
                @$site->save();
            }
            else if (isset($site->manifest) && method_exists($site->manifest, 'save')) {
                @$site->manifest->save();
            }
        }
    }
}
