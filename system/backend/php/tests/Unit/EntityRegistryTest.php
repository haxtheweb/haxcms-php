<?php
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 tests for EntityRegistry (issue #3043).
 *
 * The registry loads the single merged entities.yaml and serves definitions +
 * storage adapters for all 6 entity types. In Phase 1 NO adapters are
 * registered, so getStorage() for every type (including file) returns a
 * NotImplementedStorage whose load()/list()/save()/delete() throw
 * EntityStorageNotImplementedException.
 */
class EntityRegistryTest extends TestCase
{
    /** @var EntityRegistry */
    private $registry;

    protected function setUp(): void
    {
        $this->registry = new EntityRegistry();
    }

    public function testGetDefinitionsReturnsAllSixTypesMerged(): void
    {
        $definitions = $this->registry->getDefinitions();
        $types = array();
        foreach ($definitions as $definition) {
            $types[] = $definition->getType();
        }
        // All 6 types are present, merged across site + system scopes.
        $this->assertContains('file', $types);
        $this->assertContains('item', $types);
        $this->assertContains('theme', $types);
        $this->assertContains('skeleton', $types);
        $this->assertContains('site', $types);
        $this->assertContains('system', $types);
        $this->assertSame(6, count($definitions));
    }

    /**
     * @dataProvider knownTypeProvider
     */
    public function testGetDefinitionWorksForEachTypeFromAnyScope($type): void
    {
        $definition = $this->registry->getDefinition($type);
        $this->assertNotNull($definition);
        $this->assertSame($type, $definition->getType());
        $this->assertInstanceOf('EntityDefinition', $definition);
    }

    public static function knownTypeProvider(): array
    {
        return array(
            array('file'),
            array('item'),
            array('theme'),
            array('skeleton'),
            array('site'),
            array('system'),
        );
    }

    public function testGetDefinitionReturnsNullForUnknownType(): void
    {
        $this->assertNull($this->registry->getDefinition('nonexistent'));
    }

    public function testGetStorageFileMatchesGetDefinitionFileStorage(): void
    {
        // getStorage($type) is shorthand for getDefinition($type)->getStorage().
        // Both return a NotImplementedStorage for 'file' in Phase 1 (no adapter
        // registered yet), with the same type.
        $shorthand = $this->registry->getStorage('file');
        $chained = $this->registry->getDefinition('file')->getStorage();
        $this->assertInstanceOf('NotImplementedStorage', $shorthand);
        $this->assertInstanceOf('NotImplementedStorage', $chained);
        $this->assertSame('file', $shorthand->getType());
        $this->assertSame('file', $chained->getType());
    }

    /**
     * Every getStorage load/list throws EntityStorageNotImplementedException,
     * including getStorage('file') in Phase 1 (adapter not registered yet).
     *
     * @dataProvider knownTypeProvider
     */
    public function testGetStorageLoadThrowsNotImplementedForEveryType($type): void
    {
        $storage = $this->registry->getStorage($type);
        $this->assertInstanceOf('NotImplementedStorage', $storage);
        $this->expectException('EntityStorageNotImplementedException');
        $storage->load('any-key');
    }

    /**
     * @dataProvider knownTypeProvider
     */
    public function testGetStorageListThrowsNotImplementedForEveryType($type): void
    {
        $storage = $this->registry->getStorage($type);
        $this->expectException('EntityStorageNotImplementedException');
        $storage->list();
    }

    public function testSaveOnNonDatastoreEntityThrowsReadOnly(): void
    {
        // theme is storage.type=webcomponent (non-datastore) => read-only.
        $definition = $this->registry->getDefinition('theme');
        $entity = new EntityRegistryTestEntity($definition, array('element' => 'clean-one'));
        $this->expectException('EntityReadOnlyException');
        $entity->save();
    }

    public function testDeleteOnNonDatastoreEntityThrowsReadOnly(): void
    {
        $definition = $this->registry->getDefinition('site');
        $entity = new EntityRegistryTestEntity(
            $definition,
            array('id' => 'site-uuid', 'metadata' => array('site' => array('name' => 'mysite')))
        );
        $this->expectException('EntityReadOnlyException');
        $entity->delete();
    }

    public function testSaveOnDatastoreEntityDelegatesToNotImplementedStorage(): void
    {
        // file is datastore (not read-only), so save() passes the read-only guard
        // and delegates to the storage adapter — which is NotImplementedStorage
        // in Phase 1, so it throws EntityStorageNotImplementedException.
        $definition = $this->registry->getDefinition('file');
        $entity = new EntityRegistryTestEntity(
            $definition,
            array('uuid' => 'a1b2c3d4', 'path' => 'files/x.jpg', 'name' => 'x.jpg', 'mimetype' => 'image/jpeg')
        );
        $this->assertFalse($definition->isReadOnly());
        $this->expectException('EntityStorageNotImplementedException');
        $entity->save();
    }

    /**
     * isReadOnly/isImplemented correct per type.
     *
     * @dataProvider readOnlyStateProvider
     */
    public function testIsReadOnlyAndIsImplementedPerType($type, $expectedReadOnly, $expectedImplemented): void
    {
        $definition = $this->registry->getDefinition($type);
        $this->assertSame($expectedReadOnly, $definition->isReadOnly());
        $this->assertSame($expectedImplemented, $definition->isImplemented());
    }

    public static function readOnlyStateProvider(): array
    {
        // Only file is datastore (writable) and implemented; all others are
        // read-only (non-datastore) and definition-only (enabled false).
        return array(
            array('file', false, true),
            array('item', true, false),
            array('theme', true, false),
            array('skeleton', true, false),
            array('site', true, false),
            array('system', true, false),
        );
    }

    public function testScopeFilterSiteReturnsFileAndItem(): void
    {
        $definitions = $this->registry->getDefinitions('site');
        $types = array();
        foreach ($definitions as $definition) {
            $types[] = $definition->getType();
        }
        sort($types);
        $this->assertSame(array('file', 'item'), $types);
    }

    public function testScopeFilterSystemReturnsThemeSkeletonSiteSystem(): void
    {
        $definitions = $this->registry->getDefinitions('system');
        $types = array();
        foreach ($definitions as $definition) {
            $types[] = $definition->getType();
        }
        sort($types);
        $this->assertSame(array('site', 'skeleton', 'system', 'theme'), $types);
    }

    public function testRegisterStorageReturnsRealAdapterForFileType(): void
    {
        // The adapter-registration mechanism: register a dummy adapter for
        // 'file' and getStorage('file') now returns it instead of
        // NotImplementedStorage. (Phase 2 will register the real FileStorage.)
        $dummy = new EntityRegistryTestDummyStorage('file');
        $this->registry->registerStorage('file', $dummy);
        $this->assertSame($dummy, $this->registry->getStorage('file'));
        // Unregistered types still return NotImplementedStorage.
        $this->assertInstanceOf('NotImplementedStorage', $this->registry->getStorage('theme'));
    }
}

/**
 * Minimal concrete Entity subclass for testing the save()/delete() guards.
 * The abstract Entity base requires a concrete type.
 */
class EntityRegistryTestEntity extends Entity
{
}

/**
 * Dummy EntityStorage for the adapter-registration test. Does not throw.
 */
class EntityRegistryTestDummyStorage implements EntityStorage
{
    public $type;
    public function __construct($type)
    {
        $this->type = (string) $type;
    }
    public function load($key)
    {
        return null;
    }
    public function list($filters = array())
    {
        return array();
    }
    public function save($entity)
    {
        return true;
    }
    public function delete($key)
    {
        return true;
    }
}
