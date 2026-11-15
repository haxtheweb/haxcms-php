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
 * Core never names a plugin here: everything comes from the plugin folders and the deployment's plugin config.
 * HAXCMS_PLUGINS=off turns all of it off (every call is then a no-op).
 *
 * Where plugins come from (spec/03 tiers, simplified for the draft, the same as haxcms-nodejs):
 *   - core plugins shipped with HAXcms: the spec package's core-plugins/ folder; required ones are always on, the
 *     rest only when listed in `enabled`
 *   - system tier: <HAXCMS_ROOT>/_config/plugins/<id>/
 *   - extra folders: HAXCMS_PLUGIN_DIRS (path list), for development and tests
 *   - site tier: <site>/_config/plugins/<id>/
 * Deployment config: <HAXCMS_ROOT>/_config/plugins/plugins.json { "enabled": [], "disabled": [], "blocked": [] }.
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

    public static function deploymentConfig($root)
    {
        $c = self::readJson("$root/_config/plugins/plugins.json", []);
        return [
            'enabled' => is_array($c['enabled'] ?? null) ? $c['enabled'] : [],
            'disabled' => is_array($c['disabled'] ?? null) ? $c['disabled'] : [],
            'blocked' => is_array($c['blocked'] ?? null) ? $c['blocked'] : [],
        ];
    }

    private static function manifestId($dir)
    {
        $text = @file_get_contents("$dir/plugin.yaml");
        if (!is_string($text) || !preg_match('/^id:\s*(\S+)/m', $text, $m)) {
            return [null, false];
        }
        return [$m[1], (bool) preg_match('/^required:\s*true/m', $text)];
    }

    /** The plugin folders for a site, lowest tier first. */
    public static function pluginDirsFor($siteDirectory, $root)
    {
        $cfg = self::deploymentConfig($root);
        $out = [];
        $spec = self::specRoot();
        if ($spec) {
            foreach (self::listPluginDirs("$spec/core-plugins") as $d) {
                [$id, $required] = self::manifestId($d);
                if ($id && ($required || in_array($id, $cfg['enabled'], true))) {
                    $out[] = ['path' => $d, 'tier' => 'system-core'];
                }
            }
        }
        foreach (self::listPluginDirs("$root/_config/plugins") as $d) {
            $out[] = ['path' => $d, 'tier' => 'system-contrib'];
        }
        foreach (array_filter(explode(PATH_SEPARATOR, (string) getenv('HAXCMS_PLUGIN_DIRS'))) as $d) {
            if (is_file("$d/plugin.yaml")) {
                $out[] = ['path' => $d, 'tier' => 'system-contrib'];
            } else {
                foreach (self::listPluginDirs($d) as $x) {
                    $out[] = ['path' => $x, 'tier' => 'system-contrib'];
                }
            }
        }
        if ($siteDirectory) {
            foreach (self::listPluginDirs("$siteDirectory/_config/plugins") as $d) {
                $out[] = ['path' => $d, 'tier' => 'site'];
            }
        }
        return ['dirs' => $out, 'cfg' => $cfg];
    }

    /** Saved settings: <root>/_config/plugins/config/<id>.json, then the site's own copy (site wins). */
    public static function pluginSettings(array $dirs, $siteDirectory, $root)
    {
        $out = [];
        foreach ($dirs as $d) {
            [$id] = self::manifestId($d['path']);
            if (!$id) {
                continue;
            }
            $system = self::readJson("$root/_config/plugins/config/$id.json", []);
            $local = $siteDirectory ? self::readJson("$siteDirectory/_config/plugins/config/$id.json", []) : [];
            if ($system || $local) {
                $out[$id] = array_merge($system, $local);
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
            ]);
            $found = self::pluginDirsFor($dir, $root);
            $h->load($found['dirs'], [
                'disabled' => $found['cfg']['disabled'],
                'blocked' => $found['cfg']['blocked'],
                'settings' => self::pluginSettings($found['dirs'], $dir, $root),
            ]);
            self::$hosts[$dir] = $h;
        }
        // core reloads the site during a request; keep the engine's view of site.json and pages current
        $h->manifest = $site->manifest;
        $h->hydrate();
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
        if (!$h) {
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
        if (!$h) {
            return ['warnings' => []];
        }
        $op = !empty($opts['delete']) ? 'delete' : ($previous ? 'update' : 'create');
        $r = $h->fire("entity.after.$op", HAXCMSPluginEngine::json($record), ['entity' => $type, 'previous' => HAXCMSPluginEngine::json($previous)]);
        return ['warnings' => $r['warnings']];
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
            return $h ? $h->rebuild($reason) : null;
        } catch (\Throwable $e) {
            error_log('HAXcms plugins: rebuild failed: ' . $e->getMessage());
            return null;
        } finally {
            $running = false;
        }
    }

    /** A veto as the site API answers it (the shape saveNode, deleteNode and saveManifest return). */
    public static function vetoFailure(array $veto)
    {
        return ['__failed' => ['status' => $veto['status'] ?: 409, 'message' => $veto['message'], 'data' => ['plugin' => $veto['plugin'] ?? null]]];
    }
}
