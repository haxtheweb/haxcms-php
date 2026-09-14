<?php
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 tests for EntityDefinition (issue #3043).
 *
 * Verifies that an EntityDefinition mirrors its entities.yaml entry 1:1 across
 * all fields, that isReadOnly()/isImplemented() reflect the storage block, and
 * that getStorage() returns a NotImplementedStorage for unregistered types
 * (both standalone with no resolver and via the registry in Phase 1).
 */
class EntityDefinitionTest extends TestCase
{
    public function testStandaloneFileDefinitionMirrorsEntryFields(): void
    {
        $entry = array(
            'type' => 'file',
            'scope' => 'site',
            'description' => 'A file asset in a site\'s files directory',
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
            'filterableFields' => array('mimetype', 'name', 'path'),
            'sortableFields' => array('path', 'name', 'size', 'dateCreated'),
            'selectableFields' => array('uuid', 'path', 'fullUrl', 'url', 'mimetype', 'name', 'size', 'dateCreated', 'width', 'height'),
            'formats' => array('json', 'md', 'yaml', 'xml'),
            'auth' => 'authenticated-site',
            'enabled' => true,
        );
        $def = new EntityDefinition($entry);

        $this->assertSame('file', $def->getType());
        $this->assertSame('site', $def->getScope());
        $this->assertSame("A file asset in a site's files directory", $def->getDescription());
        $this->assertSame('uuid', $def->getPrimaryKey());
        $this->assertSame(array('uuid'), $def->getUniqueKeys());
        $this->assertSame(array('uuid', 'path', 'name', 'mimetype'), $def->getRequiredFields());
        $this->assertSame('datastore', $def->getStorageType());
        $storage = $def->getStorageBlock();
        $this->assertSame('datastore', $storage['type']);
        $this->assertTrue($storage['enabled']);
        $this->assertSame('{siteDirectory}/files/files.json', $storage['location']);
        $this->assertSame('HAXCMS-FILE-SCHEMA-V1', $storage['storeSchema']);
        $this->assertSame('files', $storage['collectionKey']);
        $this->assertSame('uuid', $storage['indexKey']);
        $this->assertSame(array('load', 'save', 'list', 'delete', 'reconcile'), $def->getSupportedOperations());
        $this->assertSame(array('/x/api/v1/files', '/x/api/v1/files/{fileUuid}'), $def->getEndpoints());
        $this->assertSame(array('mimetype', 'name', 'path'), $def->getFilterableFields());
        $this->assertSame(array('path', 'name', 'size', 'dateCreated'), $def->getSortableFields());
        $this->assertContains('uuid', $def->getSelectableFields());
        $this->assertSame(array('json', 'md', 'yaml', 'xml'), $def->getFormats());
        $this->assertSame('authenticated-site', $def->getAuth());
        $this->assertTrue($def->isEnabled());
        // file is datastore => writable + implemented.
        $this->assertFalse($def->isReadOnly());
        $this->assertTrue($def->isImplemented());
    }

    public function testStandaloneGetStorageReturnsNotImplementedWithoutResolver(): void
    {
        $def = new EntityDefinition(array('type' => 'theme'));
        $storage = $def->getStorage();
        $this->assertInstanceOf('NotImplementedStorage', $storage);
        $this->assertSame('theme', $storage->getType());
    }

    public function testRegistryFileDefinitionMirrorsYaml(): void
    {
        $registry = new EntityRegistry();
        $def = $registry->getDefinition('file');
        $this->assertSame('file', $def->getType());
        $this->assertSame('site', $def->getScope());
        $this->assertSame('uuid', $def->getPrimaryKey());
        $this->assertSame(array('uuid'), $def->getUniqueKeys());
        $this->assertSame(array('uuid', 'path', 'name', 'mimetype'), $def->getRequiredFields());
        $this->assertSame('datastore', $def->getStorageType());
        $this->assertTrue($def->isImplemented());
        $this->assertFalse($def->isReadOnly());
        $this->assertContains('/x/api/v1/files', $def->getEndpoints());
        $this->assertContains('mimetype', $def->getFilterableFields());
        $this->assertContains('size', $def->getSortableFields());
    }

    public function testRegistrySiteDefinitionMirrorsYaml(): void
    {
        $registry = new EntityRegistry();
        $def = $registry->getDefinition('site');
        $this->assertSame('site', $def->getType());
        $this->assertSame('system', $def->getScope());
        $this->assertSame('metadata.site.name', $def->getPrimaryKey());
        $this->assertSame(array('id', 'metadata.site.name'), $def->getUniqueKeys());
        $this->assertSame(array('id', 'metadata.site.name', 'items'), $def->getRequiredFields());
        $this->assertSame('jos', $def->getStorageType());
        // site is jos (non-datastore) => read-only + not implemented this plan.
        $this->assertTrue($def->isReadOnly());
        $this->assertFalse($def->isImplemented());
        $this->assertSame(array('/system/api/v1/sites'), $def->getEndpoints());
        // site declares no filterable/sortable fields in entities.yaml.
        $this->assertSame(array(), $def->getFilterableFields());
        $this->assertSame(array(), $def->getSortableFields());
        $this->assertContains('id', $def->getSelectableFields());
    }

    public function testRegistryGetStorageReturnsNotImplementedForUnregisteredTypes(): void
    {
        $registry = new EntityRegistry();
        // Every type's definition returns NotImplementedStorage in Phase 1.
        foreach (array('file', 'item', 'theme', 'skeleton', 'site', 'system') as $type) {
            $storage = $registry->getDefinition($type)->getStorage();
            $this->assertInstanceOf('NotImplementedStorage', $storage, $type . ' storage should be NotImplementedStorage');
            $this->assertSame($type, $storage->getType());
        }
    }

    public function testToDescriptorArrayMirrorsEntryWithBackwardCompatName(): void
    {
        $registry = new EntityRegistry();
        $def = $registry->getDefinition('item');
        $descriptor = $def->toDescriptorArray();
        // Extended EntityDescriptor shape.
        $this->assertSame('item', $descriptor['type']);
        $this->assertSame('item', $descriptor['name']); // backward-compat alias
        $this->assertSame('site', $descriptor['scope']);
        $this->assertSame('id', $descriptor['primaryKey']);
        $this->assertSame(array('id', 'slug'), $descriptor['uniqueKeys']);
        $this->assertSame(array('id', 'title', 'slug', 'location'), $descriptor['requiredFields']);
        $this->assertSame('jos.items', $descriptor['storage']['type']);
        $this->assertFalse($descriptor['storage']['enabled']);
        $this->assertContains('/x/api/v1/items', $descriptor['endpoints']);
        $this->assertContains('load', $descriptor['supportedOperations']);
        $this->assertTrue($descriptor['enabled']);
    }
}
