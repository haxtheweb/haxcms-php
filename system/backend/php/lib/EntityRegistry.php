<?php
include_once dirname(__FILE__) . '/EntityDefinition.php';
include_once dirname(__FILE__) . '/EntityStorage.php';
include_once dirname(__FILE__) . '/NotImplementedStorage.php';
/**
 * Single entry point for the entity system. Loads the merged entities.yaml
 * registry and serves definitions + storage adapters for every entity type
 * (file, item, theme, getDefinition('site'), skeleton, site, system).
 *
 * One instance serves both /x/api/v1/entities and /system/api/v1/entities:
 * getDefinitions() is the data behind both endpoints (optionally filtered by
 * ?scope=site|system). getDefinition($type) works for any type from any
 * context because the registry merges all types across scopes.
 *
 * Adapter registration: registerStorage($type, $adapter) wires a real storage
 * adapter for a type. In Phase 1 NO adapters are registered, so getStorage()
 * for every type (including file) returns a NotImplementedStorage. Phase 2
 * registers FileStorage for 'file'; future phases register the read-only
 * adapters for theme/skeleton/site/item/system.
 */
class EntityRegistry
{
    /** @var EntityDefinition[] Merged definitions keyed by type. */
    private $definitions = array();

    /** @var EntityStorage[] Registered adapters keyed by type. */
    private $adapters = array();

    /** @var mixed|null Optional site context for site-scoped storages. */
    private $site;

    /**
     * @param mixed|null $site Optional site context (site-scoped storages like
     *                         file/item need {siteDirectory}; system-scoped
     *                         theme/skeleton/site/system need no site).
     * @param string|null $yamlPath Optional override path to entities.yaml.
     */
    public function __construct($site = null, $yamlPath = null)
    {
        $this->site = $site;
        $this->load($yamlPath);
    }

    /**
     * Register a storage adapter for an entity type. Phase 2 registers
     * FileStorage for 'file'; future phases register the read-only adapters.
     *
     * @param string $type Entity type.
     * @param EntityStorage $adapter The storage adapter.
     */
    public function registerStorage($type, EntityStorage $adapter)
    {
        $this->adapters[(string) $type] = $adapter;
    }

    /**
     * All entity definitions merged across scopes (file/item/theme/skeleton/
     * site/system). Optionally filtered by scope.
     *
     * @param string|null $scope Optional 'site' or 'system' filter.
     * @return EntityDefinition[]
     */
    public function getDefinitions($scope = null)
    {
        $scope = $scope !== null ? (string) $scope : null;
        if ($scope === null || $scope === '') {
            return array_values($this->definitions);
        }
        $out = array();
        foreach ($this->definitions as $definition) {
            if ($definition->getScope() === $scope) {
                $out[] = $definition;
            }
        }
        return $out;
    }

    /**
     * One definition by type. Works for any type from any context (merged).
     *
     * @param string $type Entity type (file/item/theme/skeleton/site/system).
     * @return EntityDefinition|null
     */
    public function getDefinition($type)
    {
        $type = (string) $type;
        if (!array_key_exists($type, $this->definitions)) {
            return null;
        }
        return $this->definitions[$type];
    }

    /**
     * Shorthand for getDefinition($type)->getStorage(). Returns the registered
     * adapter or a NotImplementedStorage when no adapter is registered.
     *
     * @param string $type Entity type.
     * @return EntityStorage
     */
    public function getStorage($type)
    {
        $definition = $this->getDefinition($type);
        if ($definition === null) {
            return new NotImplementedStorage((string) $type);
        }
        return $definition->getStorage();
    }

    /**
     * @return mixed|null The site context passed at construction.
     */
    public function getSite()
    {
        return $this->site;
    }

    /**
     * Load and parse entities.yaml, building one EntityDefinition per entry
     * with a storage resolver bound to this registry's adapter map.
     *
     * @param string|null $yamlPath Optional override path.
     */
    private function load($yamlPath = null)
    {
        $path = $yamlPath !== null
            ? (string) $yamlPath
            : dirname(__FILE__) . '/entities.yaml';
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return;
        }
        $data = $this->parseYaml($contents);
        if (!is_array($data) || !isset($data['entities']) || !is_array($data['entities'])) {
            return;
        }
        // Resolver bound to this registry: returns the registered adapter for a
        // type, or null (EntityDefinition then falls back to NotImplementedStorage).
        $resolver = function ($type) {
            $type = (string) $type;
            if (array_key_exists($type, $this->adapters)) {
                return $this->adapters[$type];
            }
            return null;
        };
        foreach ($data['entities'] as $type => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $this->definitions[(string) $type] = new EntityDefinition($entry, $resolver);
        }
    }

    /**
     * Parse YAML text using symfony/yaml when available (it is a declared
     * composer dependency). Mirrors the pattern in convertYamlToJson.php.
     *
     * @param string $yamlText
     * @return array|null
     */
    private function parseYaml($yamlText)
    {
        if (class_exists('\\Symfony\\Component\\Yaml\\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parse($yamlText);
        }
        return null;
    }
}
