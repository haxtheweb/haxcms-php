<?php
/**
 * Thrown when a storage adapter is invoked for an entity type whose real
 * adapter has not been registered yet. EntityRegistry returns a
 * NotImplementedStorage for any unregistered type, so getStorage('theme'),
 * getStorage('item'), getStorage('skeleton'), getStorage('site'),
 * getStorage('system') — and in Phase 1 getStorage('file') too — all surface
 * this exception from load()/list()/save()/delete().
 *
 * The entity DEFINITION exists in entities.yaml (so getDefinition() works);
 * only the read/write adapter is reserved for a future phase.
 */
class EntityStorageNotImplementedException extends Exception
{
    /**
     * @param string $type The entity type whose storage adapter is not implemented.
     * @param string $operation The operation attempted (load/list/save/delete).
     */
    public function __construct($type = '', $operation = '')
    {
        $type = (string) $type;
        $operation = (string) $operation;
        $message = 'Storage adapter not implemented for entity "' . $type . '"';
        if ($operation !== '') {
            $message .= ' (operation: ' . $operation . ')';
        }
        $message .= '; the definition exists in entities.yaml but no adapter is registered yet.';
        parent::__construct($message);
    }
}
