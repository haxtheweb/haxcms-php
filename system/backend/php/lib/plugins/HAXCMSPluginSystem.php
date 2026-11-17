<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356; mirrors haxcms-nodejs#138 src/lib/plugins/pluginSystem.js).
 *
 * Finds plugins, keeps one engine (HAXCMSPluginHost) per site for the request, and gives core code three things:
 *   before($site, $type, $record, $previous) / after(...)  fire the entity hooks (and their page.* / manifest.*
 *                                                            aliases) around core's own saves and deletes
 *   rebuild($site, $reason)                                 rebuild.before / rebuild.after after core rewrote
 *                                                            its managed files
 *   host($site, $opts)                                      the engine, for routes and anything else
 *   systemHost($opts)                                       the system engine (no site), for system routes
 * Core never names a plugin here: everything comes from the plugin folders and the deployment's plugin config.
 * HAXCMS_PLUGINS=off turns all of it off (every call is then a no-op).
 *
 *   registry($site, $opts) / enable(...) / disable(...)      what is installed and what is on (spec/03, The plugin
 *                                                            registry)
 *
 * Where plugins come from (spec/03 tiers, spec/13 section 1; the same as haxcms-nodejs):
 *   - core plugins shipped with HAXcms: the spec package's core-plugins/ folder (none in its base today)
 *   - system contrib: <HAXCMS_ROOT>/_plugins/<id>/
 *   - site:           <site>/_plugins/<id>/
 *   - development and tests: HAXCMS_PLUGIN_DIRS (path list); these are on without being listed
 * What is on: <HAXCMS_ROOT>/_config/plugins/plugins.yaml for the system and <site>/_config/plugins/plugins.yaml for one
 * site, each { version: 1, enabled: [ids], plugins: {...} }; the system file also carries locked and blocked. A plugin
 * that is installed and not enabled is "available": the engine reads its manifest and loads none of its code.
 */
require_once __DIR__ . '/HAXCMSPluginHost.php';

class HAXCMSPluginSystem
{
    /** @var array<string, HAXCMSPluginHost> */
    private static $hosts = [];

    public static function enabled()
    {
        return getenv('HAXCMS_PLUGINS') !== 'off';
    }

    public static function specRoot()
    {
        $dir = HAXCMSPluginEngine::defaultSpecDir();
        return is_file("$dir/schema/plugin.schema.json") ? $dir : null;
    }

    private static function readJson($file, $fallback)
    {
        if (!is_file($file)) {
            return $fallback;
        }
        $v = json_decode((string) file_get_contents($file), true);
        return is_array($v) ? $v : $fallback;
    }

    private static function listPluginDirs($dir)
    {
        if (!$dir || !is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $n) {
            if ($n[0] !== '.' && is_file("$dir/$n/plugin.yaml")) {
                $out[] = "$dir/$n";
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    const ID = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    // ---- the registry files (spec/03) ----
    public static function registryFile($scopeRoot)
    {
        return rtrim($scopeRoot, '/') . '/_config/plugins/plugins.yaml';
    }

    private static function idList($v)
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $x) {
            if (is_string($x) && preg_match(self::ID, $x)) {
                $out[] = $x;
            }
        }
        return $out;
    }

    /** One scope's registry file, with every list present. A missing or unreadable file is an empty registry. */
    public static function readRegistryFile($scopeRoot)
    {
        $d = [];
        $file = self::registryFile($scopeRoot);
        if (is_file($file)) {
            try {
                $parsed = \Symfony\Component\Yaml\Yaml::parseFile($file);
                $d = is_array($parsed) ? $parsed : [];
            } catch (\Throwable $e) {
                $d = [];
            }
        }
        return [
            'version' => 1,
            'enabled' => self::idList($d['enabled'] ?? null),
            'locked' => self::idList($d['locked'] ?? null),
            'blocked' => self::idList($d['blocked'] ?? null),
            'plugins' => is_array($d['plugins'] ?? null) ? $d['plugins'] : [],
        ];
    }

    /** Written whole to a temp file and renamed, so a reader never sees half a registry. */
    public static function writeRegistryFile($scopeRoot, array $reg)
    {
        $file = self::registryFile($scopeRoot);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $enabled = $reg['enabled'];
        sort($enabled, SORT_STRING);
        $out = ['version' => 1, 'enabled' => $enabled];
        if ($reg['locked']) {
            $out['locked'] = $reg['locked'];
        }
        if ($reg['blocked']) {
            $out['blocked'] = $reg['blocked'];
        }
        $out['plugins'] = $reg['plugins'] ?: new \stdClass();
        $tmp = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, \Symfony\Component\Yaml\Yaml::dump($out, 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP));
        rename($tmp, $file);
    }

    /** What the deployment decided for every site: the system registry (enabled, locked, blocked). */
    public static function deploymentConfig($root)
    {
        return self::readRegistryFile($root);
    }

    /**
     * The plugin folders for a site, lowest tier first, and the ids that are on without being listed: plugins from
     * HAXCMS_PLUGIN_DIRS.
     */
    public static function pluginDirsFor($siteDirectory, $root)
    {
        $out = [];
        $dev = [];
        $spec = self::specRoot();
        if ($spec) {
            foreach (self::listPluginDirs("$spec/core-plugins") as $d) {
                $out[] = ['path' => $d, 'tier' => 'system-core'];
            }
        }
        foreach (self::listPluginDirs(rtrim($root, '/') . '/_plugins') as $d) {
            $out[] = ['path' => $d, 'tier' => 'system-contrib'];
        }
        foreach (array_filter(explode(PATH_SEPARATOR, (string) getenv('HAXCMS_PLUGIN_DIRS'))) as $d) {
            $found = is_file("$d/plugin.yaml") ? [$d] : self::listPluginDirs($d);
            foreach ($found as $x) {
                $out[] = ['path' => $x, 'tier' => 'system-contrib'];
                $dev[] = basename($x);
            }
        }
        if ($siteDirectory) {
            foreach (self::listPluginDirs("$siteDirectory/_plugins") as $d) {
                $out[] = ['path' => $d, 'tier' => 'site'];
            }
        }
        return ['dirs' => $out, 'dev' => $dev];
    }

    /** The ids enabled for a scope: the system's list, that site's own list, locked ones and development folders. */
    public static function enabledFor($siteDirectory, $root, array $dev)
    {
        $system = self::readRegistryFile($root);
        $own = $siteDirectory ? self::readRegistryFile($siteDirectory)['enabled'] : [];
        $ids = array_values(array_unique(array_merge($system['enabled'], $system['locked'], $own, $dev)));
        return [
            'enabled' => array_values(array_diff($ids, $system['blocked'])),
            'locked' => $system['locked'],
            'blocked' => $system['blocked'],
            'system' => $system['enabled'],
            'own' => $own,
        ];
    }

    private static function rootOf(array $opts)
    {
        return $opts['root'] ?? (defined('HAXCMS_ROOT') ? HAXCMS_ROOT : getcwd());
    }

    /** Saved settings: <root>/_config/plugins/config/<id>.json, then the site's own copy (site wins). */
    public static function pluginSettings(array $dirs, $siteDirectory, $root, $siteUuid = null)
    {
        $out = [];
        foreach ($dirs as $d) {
            $id = basename($d['path']);
            if (!preg_match(self::ID, $id)) {
                continue;
            }
            $system = self::readJson("$root/_config/plugins/config/$id.json", []);
            $local = $siteDirectory ? self::readJson("$siteDirectory/_config/plugins/config/$id.json", []) : [];
            // secret fields are kept outside every site folder (spec/13 section 1)
            $secrets = self::readJson("$root/_config/plugins/secrets/$id.json", []);
            $siteSecrets = $siteUuid ? self::readJson("$root/_config/siteData/$siteUuid/plugins/$id.secrets.json", []) : [];
            $all = array_merge($system, $local, $secrets, $siteSecrets);
            if ($all) {
                $out[$id] = $all;
            }
        }
        return $out;
    }

    public static function siteDirectory($site)
    {
        return rtrim($site->directory, '/') . '/' . $site->manifest->metadata->site->name;
    }

    /** The signed-in user as plugins see it (limited fields; spec/08), or null for a visitor. */
    public static function userRecord($userName)
    {
        if (!is_string($userName) || $userName === '') {
            return null;
        }
        return ['id' => $userName, 'name' => $userName, 'displayName' => $userName, 'roles' => ['admin'], 'avatar' => null];
    }

    /**
     * The plugin engine for a site (cached for this request). $opts: root (HAXCMS_ROOT), user (a user record or null).
     * @return HAXCMSPluginHost|null
     */
    public static function host($site, array $opts = [])
    {
        if (!self::enabled() || !$site || !isset($site->manifest) || !self::specRoot()) {
            return null;
        }
        $dir = self::siteDirectory($site);
        $root = $opts['root'] ?? (defined('HAXCMS_ROOT') ? HAXCMS_ROOT : getcwd());
        $h = self::$hosts[$dir] ?? null;
        if (!$h) {
            $version = '0.0.0';
            if (isset($GLOBALS['HAXCMS']) && method_exists($GLOBALS['HAXCMS'], 'getHAXCMSVersion')) {
                $version = (string) $GLOBALS['HAXCMS']->getHAXCMSVersion();
            }
            $h = new HAXCMSPluginHost([
                'manifest' => $site->manifest,
                'siteDirectory' => $dir,
                'root' => $root,
                'version' => $version,
                'user' => $opts['user'] ?? null,
                'liveNetwork' => true,
                'liveExec' => true,
                'hydrate' => false,
            ]);
            $found = self::pluginDirsFor($dir, $root);
            $on = self::enabledFor($dir, $root, $found['dev']);
            $h->load($found['dirs'], [
                'enabled' => $on['enabled'],
                'blocked' => $on['blocked'],
                'required' => $on['locked'],
                'settings' => self::pluginSettings($found['dirs'], $dir, $root, (string) ($site->manifest->id ?? '')),
            ]);
            self::$hosts[$dir] = $h;
        }
        // core reloads the site during a request; keep the engine's view of site.json and pages current. The
        // records are read only when a plugin is active: with none, nothing listens and nothing is read
        $h->manifest = $site->manifest;
        if ($h->hasActive() || !empty($opts['hydrate'])) {
            $h->hydrate();
        }
        if (array_key_exists('user', $opts)) {
            $h->user = $opts['user'];
        }
        return $h;
    }

    /**
     * The system engine (cached for this request): HAXcms-wide plugins with no site, for system-scope operations,
     * the plugin list and dashboard contributions (spec/13 section 8b). Site-tier plugins are not part of it.
     * @return HAXCMSPluginHost|null
     */
    public static function systemHost(array $opts = [])
    {
        if (!self::enabled() || !self::specRoot()) {
            return null;
        }
        $root = $opts['root'] ?? (defined('HAXCMS_ROOT') ? HAXCMS_ROOT : getcwd());
        $key = "\0system:$root";
        $h = self::$hosts[$key] ?? null;
        if (!$h) {
            $version = '0.0.0';
            if (isset($GLOBALS['HAXCMS']) && method_exists($GLOBALS['HAXCMS'], 'getHAXCMSVersion')) {
                $version = (string) $GLOBALS['HAXCMS']->getHAXCMSVersion();
            }
            $h = new HAXCMSPluginHost([
                'manifest' => null,
                'root' => $root,
                'version' => $version,
                'user' => $opts['user'] ?? null,
                'liveNetwork' => true,
                'liveExec' => true,
            ]);
            $found = self::pluginDirsFor(null, $root);
            $on = self::enabledFor(null, $root, $found['dev']);
            $h->load($found['dirs'], [
                'enabled' => $on['enabled'],
                'blocked' => $on['blocked'],
                'required' => $on['locked'],
                'settings' => self::pluginSettings($found['dirs'], null, $root),
            ]);
            self::$hosts[$key] = $h;
        }
        if (array_key_exists('user', $opts)) {
            $h->user = $opts['user'];
        }
        return $h;
    }

    /** Forget cached engines (after plugins are installed, enabled or disabled; between tests). */
    public static function reset()
    {
        self::$hosts = [];
    }

    /**
     * Fire the before hooks for a save core is about to make. Returns ['record', 'veto', 'warnings']: when veto is
     * set core must not write; otherwise it writes record (plugins may have changed it). $opts: delete, user.
     */
    public static function before($site, $type, $record, $previous, array $opts = [])
    {
        $h = self::host($site, $opts);
        if (!$h || !$h->hasActive()) {
            return ['record' => $record, 'veto' => null, 'warnings' => []];
        }
        $delete = !empty($opts['delete']);
        $op = $delete ? 'delete' : ($previous ? 'update' : 'create');
        $r = $h->fire("entity.before.$op", HAXCMSPluginEngine::json($record), ['entity' => $type, 'previous' => HAXCMSPluginEngine::json($previous)]);
        $subject = $r['subject'];
        $warnings = $r['warnings'];
        // fields under metadata.plugins.<id> must be declared by that plugin (spec/04); others are dropped
        if (empty($r['veto']) && !$delete) {
            $warnings = array_merge($warnings, $h->checkPluginMetadata($type, $subject));
        }
        return ['record' => $delete ? $record : $subject, 'veto' => $r['veto'] ?? null, 'warnings' => $warnings];
    }

    /** Fire the after hooks once core has written (or deleted) the record. */
    public static function after($site, $type, $record, $previous, array $opts = [])
    {
        $h = self::host($site, $opts);
        if (!$h || !$h->hasActive()) {
            return ['warnings' => []];
        }
        $op = !empty($opts['delete']) ? 'delete' : ($previous ? 'update' : 'create');
        $r = $h->fire("entity.after.$op", HAXCMSPluginEngine::json($record), ['entity' => $type, 'previous' => HAXCMSPluginEngine::json($previous)]);
        return ['warnings' => $r['warnings']];
    }

    /**
     * The before hooks for an operation that changes many records at once (an outline save, a bulk create), as one
     * bulk transaction whose origin names the operation. $changes is a list of ['op', 'record', 'previous']. The
     * first veto stops the rest, and core must then write nothing. What the hooks return is not applied: these
     * operations change structure, and a plugin that changes a page does it on that page's own save.
     */
    public static function beforeMany($site, $type, array $changes, array $opts = [])
    {
        $h = $changes ? self::host($site, $opts) : null;
        if (!$h || !$h->hasActive()) {
            return ['veto' => null, 'warnings' => []];
        }
        $veto = null;
        $warnings = [];
        $h->transaction(function () use ($h, $type, $changes, &$veto, &$warnings) {
            foreach ($changes as $c) {
                $r = $h->fire("entity.before.{$c['op']}", $c['record'], ['entity' => $type, 'previous' => $c['previous'] ?? null]);
                $warnings = array_merge($warnings, $r['warnings'] ?? []);
                if (!empty($r['veto'])) {
                    $veto = $r['veto'];
                    return null;
                }
            }
            return null;
        }, ['bulk' => true, 'origin' => $opts['origin'] ?? 'core']);
        return ['veto' => $veto, 'warnings' => $warnings];
    }

    /** The after hooks for the same changes, once core has written all of them, again as one bulk transaction. */
    public static function afterMany($site, $type, array $changes, array $opts = [])
    {
        $h = $changes ? self::host($site, $opts) : null;
        if (!$h || !$h->hasActive()) {
            return ['warnings' => []];
        }
        $warnings = [];
        $h->transaction(function () use ($h, $type, $changes, &$warnings) {
            foreach ($changes as $c) {
                $r = $h->fire("entity.after.{$c['op']}", $c['record'], ['entity' => $type, 'previous' => $c['previous'] ?? null]);
                $warnings = array_merge($warnings, $r['warnings'] ?? []);
            }
            return null;
        }, ['bulk' => true, 'origin' => $opts['origin'] ?? 'core']);
        return ['warnings' => $warnings];
    }

    /** True when a plugin is active for this site, so an operation can skip building what only hooks would read. */
    public static function listening($site, array $opts = [])
    {
        $h = self::host($site, $opts);
        return $h && $h->hasActive();
    }

    /**
     * What an outline save is about to do to each page, from the stored items and the submitted ones: a submitted
     * item marked delete whose page exists is a delete, one whose id is stored is an update, any other is a create.
     */
    public static function outlineChanges($stored, $submitted)
    {
        $byId = [];
        foreach (($stored ?: []) as $it) {
            $it = HAXCMSPluginEngine::json($it);
            if (is_array($it) && isset($it['id'])) {
                $byId[(string) $it['id']] = $it;
            }
        }
        $out = [];
        foreach (($submitted ?: []) as $raw) {
            $raw = HAXCMSPluginEngine::json($raw);
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                continue;
            }
            $id = isset($raw['id']) && is_scalar($raw['id']) ? (string) $raw['id'] : null;
            $before = $id !== null && isset($byId[$id]) ? $byId[$id] : null;
            $gone = isset($raw['delete']) && ($raw['delete'] === true || $raw['delete'] === 'true');
            if ($gone) {
                if ($before) {
                    $out[] = ['op' => 'delete', 'record' => $before, 'previous' => null];
                }
                continue;
            }
            unset($raw['delete'], $raw['content'], $raw['contents']);
            $out[] = $before
                ? ['op' => 'update', 'record' => array_merge($before, $raw), 'previous' => $before]
                : ['op' => 'create', 'record' => $raw, 'previous' => null];
        }
        return $out;
    }

    /** rebuild.before / rebuild.after around core's managed-file rewrite. A plugin failure never fails the save. */
    public static function rebuild($site, $reason = 'save', array $opts = [])
    {
        static $running = false;
        if ($running) {
            return null;
        }
        $running = true;
        try {
            $h = self::host($site, $opts);
            return $h && $h->hasActive() ? $h->rebuild($reason) : null;
        } catch (\Throwable $e) {
            error_log('HAXcms plugins: rebuild failed: ' . $e->getMessage());
            return null;
        } finally {
            $running = false;
        }
    }

    // ---- the registry (spec/03) ----
    /** Sites that enabled a plugin themselves: [pluginId => [site folder names]], from each site's registry file. */
    public static function sitesUsing($root, $sitesDirectory = '_sites')
    {
        $out = [];
        $base = rtrim($root, '/') . '/' . $sitesDirectory;
        if (!is_dir($base)) {
            return $out;
        }
        $names = scandir($base) ?: [];
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if ($name[0] === '.' || !is_file(self::registryFile("$base/$name"))) {
                continue;
            }
            foreach (self::readRegistryFile("$base/$name")['enabled'] as $id) {
                $out[$id][] = $name;
            }
        }
        return $out;
    }

    /**
     * The registry for the system ($site null) or for one site: one entry per installed plugin with its status, what
     * it needs from a backend, how it fits each target and what is limited here (spec/03, spec/14).
     */
    public static function registry($site, array $opts = [])
    {
        $root = self::rootOf($opts);
        $h = $site ? self::host($site, $opts) : self::systemHost($opts);
        if (!$h) {
            return [];
        }
        $system = self::readRegistryFile($root);
        $own = $site ? self::readRegistryFile(self::siteDirectory($site))['enabled'] : [];
        $using = $site ? [] : self::sitesUsing($root, $opts['sitesDirectory'] ?? '_sites');
        $out = [];
        foreach ($h->registry() as $e) {
            $e['locked'] = in_array($e['id'], $system['locked'], true);
            $e['blocked'] = in_array($e['id'], $system['blocked'], true);
            $e['scope'] = in_array($e['id'], $system['enabled'], true) ? 'system' : (in_array($e['id'], $own, true) ? 'site' : null);
            if (!$site) {
                $e['sites'] = $using[$e['id']] ?? [];
            }
            $out[] = $e;
        }
        return $out;
    }

    private static function entryOf($site, $id, array $opts)
    {
        foreach (self::registry($site, $opts) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        return null;
    }

    /**
     * Enable a plugin for the system ($site null) or for one site. Runs the engine's enable (install hooks on a first
     * enable, plugin.before.enable, requirements) and writes the registry file only when that succeeded.
     * @return array{status: int, body: mixed}
     */
    public static function enable($site, $id, array $opts = [])
    {
        $root = self::rootOf($opts);
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        $system = self::readRegistryFile($root);
        if (in_array($id, $system['blocked'], true)) {
            return ['status' => 409, 'body' => ['error' => 'blocked by deployment config']];
        }
        // install and enable hooks may read the site's records, which are not read while nothing is active
        $h = $site ? self::host($site, $opts + ['hydrate' => true]) : self::systemHost($opts);
        $p = $h ? $h->get($id) : null;
        if (!$p) {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        if ($site && $p->tier !== 'site' && in_array($id, $system['enabled'], true)) {
            // already on for every site: nothing to record for this one
            return ['status' => 200, 'body' => self::entryOf($site, $id, $opts)];
        }
        $r = $p->status === 'active' || $p->status === 'inactive' ? ['status' => 200] : $h->enable($id);
        if ($r['status'] !== 200) {
            return $r;
        }
        $scopeRoot = $site ? self::siteDirectory($site) : $root;
        $reg = self::readRegistryFile($scopeRoot);
        if (!in_array($id, $reg['enabled'], true)) {
            $reg['enabled'][] = $id;
        }
        $reg['plugins'][$id] = [
            'version' => (string) ($p->manifest['version'] ?? ''),
            'enabledAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'by' => (string) ($opts['user']['name'] ?? ''),
        ];
        self::writeRegistryFile($scopeRoot, $reg);
        if (!$site) {
            // every site's engine has to see the new system list
            self::reset();
        }
        return ['status' => 200, 'body' => self::entryOf($site, $id, $opts)];
    }

    /** Disable a plugin for the system or for one site. Refusals come from the engine (required, dependents). */
    public static function disable($site, $id, array $opts = [])
    {
        $root = self::rootOf($opts);
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        $system = self::readRegistryFile($root);
        $h = $site ? self::host($site, $opts + ['hydrate' => true]) : self::systemHost($opts);
        $p = $h ? $h->get($id) : null;
        if (!$p) {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        if (in_array($id, $system['locked'], true)) {
            return ['status' => 409, 'body' => ['error' => 'locked by deployment config']];
        }
        if ($site && in_array($id, $system['enabled'], true)) {
            return ['status' => 409, 'body' => ['error' => 'enabled for the whole system; a site cannot turn it off']];
        }
        $r = $h->disable($id);
        if ($r['status'] !== 200) {
            return $r;
        }
        $scopeRoot = $site ? self::siteDirectory($site) : $root;
        $reg = self::readRegistryFile($scopeRoot);
        $reg['enabled'] = array_values(array_diff($reg['enabled'], [$id]));
        unset($reg['plugins'][$id]);
        self::writeRegistryFile($scopeRoot, $reg);
        self::reset();
        $entry = self::entryOf($site, $id, $opts);
        if (!empty($r['body']['inUse']) && is_array($entry)) {
            $entry['inUse'] = $r['body']['inUse'];
        }
        return ['status' => 200, 'body' => $entry];
    }

    // ---- settings (spec/13 section 7) ----
    /** A plugin's settings form and values for the system ($site null) or one site; secrets come back as set or not. */
    public static function settings($site, $id, array $opts = [])
    {
        $h = $site ? self::host($site, $opts) : self::systemHost($opts);
        $view = $h && is_string($id) && preg_match(self::ID, $id) ? $h->settingsView($id) : null;
        return $view ? ['status' => 200, 'body' => $view] : ['status' => 404, 'body' => ['error' => 'not installed']];
    }

    /**
     * Save a plugin's settings. The system saves system-scope settings; a site saves its own only for a plugin whose
     * settingsScope is site or both. A secret left out or sent empty keeps its stored value.
     */
    public static function saveSettings($site, $id, $values, array $opts = [])
    {
        $h = $site ? self::host($site, $opts + ['hydrate' => true]) : self::systemHost($opts);
        $p = $h && is_string($id) && preg_match(self::ID, $id) ? $h->get($id) : null;
        if (!$p || $p->status === 'available' || $p->status === 'refused') {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        $scope = $p->manifest['settingsScope'] ?? 'system';
        if ($site ? $scope === 'system' : $scope === 'site') {
            return ['status' => 409, 'body' => ['error' => 'these settings are not saved in this scope']];
        }
        $secret = $h->secretFields($p);
        $clean = [];
        foreach (is_array($values) ? $values : [] as $k => $v) {
            if (isset($secret[$k]) && ($v === '' || $v === null)) {
                continue;
            }
            $clean[$k] = $v;
        }
        $r = $h->saveConfig($id, $clean);
        return $r['status'] !== 200 ? $r : ['status' => 200, 'body' => $h->settingsView($id)];
    }

    /** A veto as the site API answers it (the shape saveNode, deleteNode and saveManifest return). */
    public static function vetoFailure(array $veto)
    {
        return ['__failed' => ['status' => $veto['status'] ?: 409, 'message' => $veto['message'], 'data' => ['plugin' => $veto['plugin'] ?? null]]];
    }
}
