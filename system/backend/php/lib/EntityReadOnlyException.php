<?php
/**
 * Thrown when save() or delete() is called on an Entity whose
 * EntityDefinition is read-only (storage.type !== 'datastore').
 *
 * This enforces the #3043 "no datastore => can't save" rule per-type via the
 * definition, not hardcoded: only `datastore`-backed entities are writable.
 */
class EntityReadOnlyException extends Exception
{
    /**
     * @param string $type The entity type that is read-only.
     * @param string $storageType The non-datastore storage.type that made it read-only.
     */
    public function __construct($type = '', $storageType = '')
    {
        $type = (string) $type;
        $storageType = (string) $storageType;
        $message = 'Entity "' . $type . '" is read-only';
        if ($storageType !== '') {
            $message .= ' (storage type: ' . $storageType . ')';
        }
        $message .= '; only datastore-backed entities support save()/delete().';
        parent::__construct($message);
    }
}
