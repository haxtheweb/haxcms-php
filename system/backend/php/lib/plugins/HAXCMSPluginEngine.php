<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356; spec: haxtheweb/haxcms-plugins#4).
 *
 * A native PHP implementation of the HAX backend platform contract (spec/13). It is a line-by-line port of the
 * spec's reference engine (harness/memory-backend.mjs), which haxcms-nodejs runs (haxcms-nodejs#138), so both
 * backends implement the same contract twice, in two languages, and the spec's conformance cases and every
 * plugin's own test cases run against each. A difference between the two is a bug in one of them or a gap in the
 * spec; HAXCMSPluginEngine never "fixes" one silently.
 *
 * It loads plugin folders (tiers, manifest validation, deployment config, dependencies, conflicts, requirements),
 * enforces route security from each plugin's OpenAPI document, dispatches hooks (priority, stages, cascade, entity
 * inheritance, aliases, config scope, veto, timeouts, discard on error or invalid return, overwrite warnings), runs
 * the render pipeline and transactions, and builds the ctx (HAXCMSPluginCtx). Records live in memory; a host
 * (HAXCMSPluginHost) overrides the storage methods to put them in a real HAXcms site.
 *
 * Plugin code runs in-process: entry.php is required once and its class (studly(id) . "Plugin") is instantiated once
 * per engine. Methods: hooks camelCase ("page.after.save" -> pageAfterSave), contracts name+op ("import.file" +
 * "convert" -> importFileConvert), operations by operationId, upgrades upgrade_1_2_0, schedules by handler name.
 */
require_once __DIR__ . '/HAXCMSPluginSchema.php';
require_once __DIR__ . '/HAXCMSPluginCtx.php';

use Symfony\Component\Yaml\Yaml;

class HAXCMSPluginRecord
{
    public $id;
    public $dir;
    /** @var array */
    public $manifest = [];
    public $tier = 'system-core';
    public $ops = [];
    public $stores = [];
    public $hookDefs = [];
    public $entities = [];
    public $entityMeta = [];
    public $settings = [];
    /** @var array<string, bool> settings someone saved (a default does not count) */
    public $explicit = [];
    public $form = [];
    public $storeOverrides = [];
    /** @var object|null the plugin's PHP class instance */
    public $instance = null;
    public $status = 'enabled';
    public $reason = null;
    public $unmet = [];
    /** @var array<int, string> what it needs from a backend (spec/14) */
    public $needs = [];
    /** @var array<int, array{key: string, message: string}> what this backend's target only half gives it */
    public $limited = [];
    /** @var array<int, array{key: string, message: string}> what this backend's target cannot give it at all */
    public $targetUnmet = [];
    public $inputHost = null;
}

class HAXCMSPluginEngine
{
    const TIERS = ['haxiam-core' => 1, 'haxiam-contrib' => 2, 'system-core' => 3, 'system-contrib' => 4, 'site' => 5];
    const LIMITED_USER = ['id', 'name', 'displayName', 'roles', 'avatar'];
    /** What the assets route serves, by extension (spec/07): scripts, styles, images, fonts and data a page loads. */
    const ASSET_TYPES = ['.js' => 'text/javascript', '.mjs' => 'text/javascript', '.css' => 'text/css', '.json' => 'application/json', '.svg' => 'image/svg+xml', '.png' => 'image/png', '.jpg' => 'image/jpeg', '.jpeg' => 'image/jpeg', '.gif' => 'image/gif', '.webp' => 'image/webp', '.avif' => 'image/avif', '.woff2' => 'font/woff2', '.woff' => 'font/woff', '.txt' => 'text/plain', '.map' => 'application/json'];
    /** Anonymous calls to one operation from one client (spec/05): the default, and the windows x-hax-rate-limit may name. */
    const RATE_DEFAULT = ['requests' => 60, 'per' => 'minute'];
    const RATE_WINDOWS = ['second' => 1000, 'minute' => 60000, 'hour' => 3600000];
    /** What a new site's site.json carries besides its id and title (boilerplate/site/site.json); the same in haxcms-nodejs. */
    const NEW_SITE = ['author' => '', 'description' => '', 'license' => 'by-sa'];
    const USER_TOKEN_REQUIRED = 'X-HAXCMS-User-Token header is required for this endpoint';

    public $specDir;
    public $specVersion;
    public $haxcms;
    public $runtime = 'php';
    /** @var array{id: string, name: string, path: string} */
    public $site;
    /** @var int|null fixed clock in ms (tests); null is the real clock */
    public $frozenNow = null;
    public $user;
    public $hookTimeout;
    /** @var array<int, HAXCMSPluginRecord> cascade order */
    public $plugins = [];
    public $memory = [];
    public $installed = [];
    public $entityParents = [];
    public $log = [];
    public $revs = [];
    /** @var array<string, array{plugin: string, content: string}> */
    public $managed = [];
    public $execCalls = [];
    public $fetchCalls = [];
    public $fetchFakes = [];
    public $execFakes = [];
    public $urlAliases = [];
    public $records;
    /** @var HAXCMSPluginTransaction|null */
    public $currentTx = null;
    public $fragments = [];
    public $lastRuns = [];
    public $flushed = 0;
    /** @var array<string, array{start: int, count: int}> anonymous calls per "<operation>|<client>" (spec/05) */
    public $rate = [];
    private $running = [];
    private $collectingPrompts = null;
    private $coreHooks;
    private $coreContracts;
    private $manifestSchema;
    /** @var array schema/targets.json of the spec: needs and what each deployment target provides */
    private $targets;
    public $target = 'server';
    private static $specCache = [];

    /**
     * @param array $o specDir (the @haxtheweb/haxcms-plugin-spec folder), haxcmsVersion, site, user, hookTimeout, now
     */
    public function __construct(array $o = [])
    {
        $this->specDir = rtrim($o['specDir'] ?? self::defaultSpecDir(), '/');
        $spec = self::spec($this->specDir);
        $this->specVersion = $spec['version'];
        $this->coreHooks = $spec['hooks'];
        $this->coreContracts = $spec['contracts'];
        $this->manifestSchema = $spec['schema'];
        $this->targets = $spec['targets'];
        // where this backend runs (spec/14): server, desktop or browser. haxcms-php is only ever a server; the
        // option exists so the same conformance cases run here
        $this->target = $o['target'] ?? 'server';
        if (!isset($this->targets['targets'][$this->target])) {
            throw new RuntimeException("unknown target \"{$this->target}\"; known: " . implode(', ', array_keys($this->targets['targets'])));
        }
        $this->haxcms = ['version' => $o['haxcmsVersion'] ?? '26.10.1', 'runtime' => 'php', 'target' => $this->target];
        // site null is the system engine (spec/13 section 8b): HAXcms-wide work with no site; anything that needs a
        // site refuses through requireSite()
        $site = array_key_exists('site', $o) ? $o['site'] : ['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'test-site', 'path' => ''];
        if ($site !== null && empty($site['path'])) {
            // a real, empty folder stands in for the site directory, so plugins that work with files can be tested
            $site['path'] = self::tempDir('hax-site-');
        }
        $this->site = $site;
        $this->frozenNow = array_key_exists('now', $o) ? $o['now'] : self::parseTime('2026-01-01T00:00:00Z');
        $this->user = array_key_exists('user', $o) ? $o['user'] : ['id' => 'u1', 'name' => 'admin', 'displayName' => 'Admin', 'roles' => ['admin'], 'avatar' => null, 'email' => 'admin@example.invalid', 'passwordHash' => 'never-exposed'];
        $this->hookTimeout = $o['hookTimeout'] ?? 5000;
        $this->records = ['config' => ['core' => ['appStore' => ['apiKeys' => []]]], 'site' => $site !== null ? [$site['id'] => ['id' => $site['id'], 'title' => $site['name']] + self::NEW_SITE + ['metadata' => ['plugins' => []], 'items' => []]] : [], 'page' => [], 'file' => []];
    }
    /** Store scopes that live with one site; the system engine has none of them. */
    const SITE_SCOPES = ['site', 'siteData', 'manifest'];
    /** The system engine has no site: site records, site stores, site files, rendering and rebuilds refuse there. */
    public function requireSite($what)
    {
        if ($this->site === null) {
            throw new RuntimeException("$what: needs a site; this is the system engine");
        }
    }

    // ---- spec files ----
    public static function defaultSpecDir()
    {
        $env = getenv('HAXCMS_PLUGIN_SPEC_DIR');
        if ($env) {
            return $env;
        }
        return dirname(__DIR__, 5) . '/node_modules/@haxtheweb/haxcms-plugin-spec';
    }
    private static function spec($dir)
    {
        if (!isset(self::$specCache[$dir])) {
            if (!is_file("$dir/schema/plugin.schema.json")) {
                throw new RuntimeException("plugin spec not found at $dir (set HAXCMS_PLUGIN_SPEC_DIR)");
            }
            self::$specCache[$dir] = [
                'version' => json_decode(file_get_contents("$dir/package.json"), true)['version'],
                'hooks' => json_decode(file_get_contents("$dir/schema/hook-contracts.json"), true),
                'contracts' => Yaml::parseFile("$dir/schema/core-contracts.yaml")['contracts'] ?? [],
                'schema' => new HAXCMSPluginSchema(json_decode(file_get_contents("$dir/schema/plugin.schema.json"))),
                'targets' => json_decode(file_get_contents("$dir/schema/targets.json"), true),
            ];
        }
        return self::$specCache[$dir];
    }
    public static function readAny($path)
    {
        if (substr($path, -5) === '.json') {
            $v = json_decode(file_get_contents($path), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(json_last_error_msg());
            }
            return $v;
        }
        return Yaml::parseFile($path);
    }
    public static function tempDir($prefix)
    {
        $d = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($d, 0775, true);
        return $d;
    }
    public static function parseTime($iso)
    {
        $d = new DateTimeImmutable($iso);
        return (int) $d->format('U') * 1000 + (int) $d->format('v');
    }
    public function now()
    {
        return $this->frozenNow !== null ? $this->frozenNow : (int) floor(microtime(true) * 1000);
    }

    // ---- small helpers shared with the reference engine ----
    public static function studly($id)
    {
        return implode('', array_map('ucfirst', explode('-', $id))) . 'Plugin';
    }
    /** camel case across "." and "-": fx-definer.after.ping -> fxDefinerAfterPing (spec/13) */
    public static function camelHook($name)
    {
        $parts = preg_split('/[.-]/', $name);
        return $parts[0] . implode('', array_map('ucfirst', array_slice($parts, 1)));
    }
    public static function contractMethod($name, $op)
    {
        return self::camelHook($name) . ucfirst($op);
    }
    public static function getPath($obj, $path)
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($obj) || !array_key_exists($k, $obj)) {
                return null;
            }
            $obj = $obj[$k];
        }
        return $obj;
    }
    public static function cmpVersion($a, $b)
    {
        $x = array_map('intval', explode('.', (string) $a));
        $y = array_map('intval', explode('.', (string) $b));
        for ($i = 0; $i < 3; $i++) {
            if (($x[$i] ?? 0) !== ($y[$i] ?? 0)) {
                return ($x[$i] ?? 0) < ($y[$i] ?? 0) ? -1 : 1;
            }
        }
        return 0;
    }
    public static function implEntry($m, $name)
    {
        foreach ($m['implements'] ?? [] as $e) {
            $e = is_string($e) ? ['name' => $e] : $e;
            if (($e['name'] ?? null) === $name) {
                return $e;
            }
        }
        return null;
    }
    /**
     * Values that cross the plugin boundary (store writes, saved records, hook results) are JSON data, as they are
     * in Node and through the reference bridge: a stdClass a plugin hands over becomes an array here, so the next
     * plugin (in PHP or Node) sees the same shape.
     */
    public static function json($v)
    {
        if ($v === null || is_scalar($v)) {
            return $v;
        }
        return json_decode(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }
    public static function isAssoc($v)
    {
        return is_array($v) && $v !== [] && !array_is_list($v);
    }
    private static function jsonEq($a, $b)
    {
        return json_encode($a) === json_encode($b);
    }
    private static function falsy($v)
    {
        return $v === null || $v === false || $v === 0 || $v === '' || $v === [];
    }

    public function get($id)
    {
        foreach ($this->plugins as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }
        return null;
    }
    public function active($id)
    {
        $p = $this->get($id);
        return $p !== null && $p->status === 'active';
    }
    public function status()
    {
        return array_map(function ($p) {
            return ['id' => $p->id, 'status' => $p->status, 'reason' => $p->reason, 'unmet' => $p->unmet, 'limited' => $p->status === 'active' ? $p->limited : []];
        }, $this->plugins);
    }
    public function coreHook($name)
    {
        foreach ($this->coreHooks as $c) {
            if ($c['name'] === $name) {
                return $c;
            }
        }
        return null;
    }
    public function hookContract($name)
    {
        $c = $this->coreHook($name);
        if ($c) {
            return $c;
        }
        foreach ($this->plugins as $p) {
            if (isset($p->hookDefs[$name])) {
                return $p->hookDefs[$name];
            }
        }
        return null;
    }
    private function activePlugins()
    {
        return array_values(array_filter($this->plugins, function ($x) {
            return $x->status === 'active';
        }));
    }

    /** Re-reads records from durable storage; the in-memory engine has none. */
    public function reload()
    {
    }

    public function seed(array $records = [])
    {
        if (isset($records['site'])) {
            $this->requireSite('site records');
            $this->records['site'][$this->site['id']] = array_merge(['id' => $this->site['id']] + self::NEW_SITE + ['metadata' => ['plugins' => []], 'items' => []], $records['site']);
        }
        foreach ($records as $type => $list) {
            if ($type === 'site') {
                continue;
            }
            foreach ($list as $r) {
                $this->records[$type][$r['id'] ?? $r['uuid']] = $r;
            }
        }
    }

    // ---- loading (spec/13 section 2) ----
    /**
     * @param array $dirs paths, or ['path' => ..., 'tier' => ...]
     * @param array $o settings, stores (by plugin id), enabled, disabled, blocked, required, records. `enabled`,
     *   when given, is the scope's list of enabled plugin ids (the registry, spec/03): a plugin that is not on it,
     *   not required and not locked is `available` and none of its code is loaded. Left out, every plugin found is
     *   enabled, which is what test cases over a few fixtures want.
     */
    public function load(array $dirs, array $o = [])
    {
        $settings = $o['settings'] ?? [];
        $stores = $o['stores'] ?? [];
        $disabled = $o['disabled'] ?? [];
        $blocked = $o['blocked'] ?? [];
        $required = $o['required'] ?? [];
        $enabled = array_key_exists('enabled', $o) && is_array($o['enabled']) ? $o['enabled'] : null;
        if (!empty($o['records'])) {
            $this->seed($o['records']);
        }
        $found = [];
        foreach ($dirs as $d) {
            $spec = is_string($d) ? ['path' => $d, 'tier' => 'system-core'] : $d;
            $dir = realpath($spec['path']) ?: $spec['path'];
            try {
                $m = self::readAny("$dir/plugin.yaml");
            } catch (\Throwable $e) {
                $this->log[] = ['level' => 'error', 'plugin' => $spec['path'], 'message' => 'cannot read plugin.yaml: ' . $e->getMessage()];
                continue;
            }
            $p = new HAXCMSPluginRecord();
            $p->id = is_array($m) ? ($m['id'] ?? null) : null;
            $p->dir = $dir;
            $p->manifest = is_array($m) ? $m : [];
            $p->tier = $spec['tier'] ?? 'system-core';
            $found[] = $p;
        }
        // higher tier wins a name collision
        usort($found, function ($a, $b) {
            return self::TIERS[$a->tier] - self::TIERS[$b->tier];
        });
        $byId = [];
        foreach ($found as $p) {
            if (isset($byId[$p->id])) {
                $o2 = $byId[$p->id];
                $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => "{$p->tier} copy ({$p->manifest['version']}) overrides {$o2->tier} copy ({$o2->manifest['version']})"];
            }
            $byId[$p->id] = $p;
        }
        foreach ($byId as $p) {
            $m = $p->manifest;
            $refuse = function ($reason) use ($p) {
                $p->status = 'refused';
                $p->reason = $reason;
                $this->log[] = ['level' => 'error', 'plugin' => $p->id, 'message' => $reason];
            };
            $errors = $this->manifestSchema->validate(Yaml::parseFile("{$p->dir}/plugin.yaml", Yaml::PARSE_OBJECT_FOR_MAP));
            if ($errors) {
                $refuse('invalid manifest: ' . implode('; ', $errors));
            } elseif (self::cmpVersion($m['specVersion'], $this->specVersion) > 0) {
                $refuse("specVersion {$m['specVersion']} is newer than this backend supports ({$this->specVersion})");
            } elseif (($m['kind'] ?? null) !== 'pack' && isset($m['runtimes']) && is_array($m['runtimes']) && !in_array($this->runtime, $m['runtimes'], true)) {
                // code for another runtime only: refused with the reason, rather than active with every operation
                // answering 501 and every hook silently skipped (spec/13 section 2)
                $refuse("no {$this->runtime} implementation: this plugin ships " . implode(', ', $m['runtimes']) . ' only');
            } elseif (in_array($p->id, $blocked, true)) {
                $refuse('blocked by deployment config');
            } elseif (in_array($p->id, $disabled, true) && empty($m['required']) && !in_array($p->id, $required, true)) {
                $p->status = 'disabled';
                $p->reason = 'disabled';
            } elseif ($enabled !== null && !in_array($p->id, $enabled, true) && empty($m['required']) && !in_array($p->id, $required, true)) {
                $p->status = 'available';
                $p->reason = 'not enabled';
            }
            if ($p->status === 'refused') {
                $this->plugins[] = $p;
                continue;
            }
            $api = !empty($m['openapi']) ? self::readAny("{$p->dir}/{$m['openapi']}") : null;
            // what it needs from a backend, and how this backend's target fits (spec/14)
            $p->needs = $this->backendNeeds($m, $api);
            $fit = $this->targetFit($m, $p->needs, $this->target);
            $p->targetUnmet = $fit['unmet'];
            $p->limited = $fit['limited'];
            if ($api !== null) {
                foreach ($api['paths'] ?? [] as $path => $verbs) {
                    foreach ($verbs as $verb => $op) {
                        if (!is_array($op) || empty($op['operationId'])) {
                            continue;
                        }
                        $p->ops[$op['operationId']] = [
                            'operationId' => $op['operationId'], 'path' => $path, 'verb' => $verb,
                            'scope' => $op['x-hax-scope'] ?? $api['x-hax-scope'] ?? 'site',
                            'public' => ($op['x-hax-public'] ?? null) === true,
                            'atomic' => ($op['x-hax-transaction'] ?? $api['x-hax-transaction'] ?? null) === 'atomic',
                            'security' => $op['security'] ?? $api['security'] ?? [],
                            'proxy' => $op['x-hax-proxy'] ?? null,
                            'rateLimit' => $op['x-hax-rate-limit'] ?? null,
                        ];
                    }
                }
            }
            if (!empty($m['datastores'])) {
                foreach (self::readAny("{$p->dir}/{$m['datastores']}")['stores'] as $s) {
                    $p->stores[$s['id']] = $s;
                }
            }
            if (!empty($m['entities'])) {
                $e = self::readAny("{$p->dir}/{$m['entities']}");
                $p->entities = $e['entities'] ?? [];
                $p->entityMeta = $e['metadata'] ?? [];
                foreach ($p->entities as $name => $def) {
                    if (!empty($def['extends'])) {
                        $this->entityParents[$name] = $def['extends'];
                    }
                }
            }
            if (!empty($m['hookDefinitions'])) {
                foreach (self::readAny("{$p->dir}/{$m['hookDefinitions']}") as $h) {
                    $p->hookDefs[$h['name']] = $h;
                }
            }
            // defaults: config/settings.defaults.json next to the settings form
            if (!empty($m['settings'])) {
                $dp = $p->dir . '/' . dirname($m['settings']) . '/settings.defaults.json';
                if (is_file($dp)) {
                    $p->settings = array_merge($p->settings, self::readAny($dp));
                }
            }
            $p->settings = array_merge($p->settings, $settings[$p->id] ?? []);
            $p->explicit = array_fill_keys(array_keys($settings[$p->id] ?? []), true);
            $p->form = !empty($m['settings']) && is_file("{$p->dir}/{$m['settings']}") ? (self::readAny("{$p->dir}/{$m['settings']}")['configure'] ?? []) : [];
            $p->storeOverrides = $stores[$p->id] ?? [];
            // code of a plugin that is not enabled is never loaded; enable() loads it then
            if ($p->status !== 'available') {
                $this->loadEntry($p);
            }
            $this->plugins[] = $p;
        }
        // dependencies: a missing or unusable dependency keeps the plugin disabled
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->plugins as $p) {
                if ($p->status !== 'enabled') {
                    continue;
                }
                foreach ($p->manifest['dependencies'] ?? [] as $dep) {
                    $d = $this->get(explode('@', $dep)[0]);
                    if (!$d || $d->status !== 'enabled') {
                        $p->status = 'disabled';
                        $p->reason = "missing dependency $dep";
                        $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => $p->reason];
                        $changed = true;
                        break;
                    }
                }
            }
        }
        foreach ($this->plugins as $p) {
            if ($p->status === 'enabled') {
                foreach ($p->manifest['conflicts'] ?? [] as $c) {
                    if ($this->get($c) && $this->get($c)->status === 'enabled') {
                        $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => "conflicts with $c; both are enabled"];
                    }
                }
            }
        }
        // cascade order: dependencies before dependents, then required, then tier, then id
        $depth = function ($p, $seen = []) use (&$depth) {
            if (isset($seen[$p->id])) {
                return 0;
            }
            $seen[$p->id] = true;
            $max = 0;
            foreach ($p->manifest['dependencies'] ?? [] as $dep) {
                $d = $this->get(explode('@', $dep)[0]);
                if ($d) {
                    $max = max($max, $depth($d, $seen));
                }
            }
            return 1 + $max;
        };
        usort($this->plugins, function ($a, $b) use ($depth) {
            return ($depth($a) - $depth($b))
                ?: ((empty($a->manifest['required']) ? 1 : 0) - (empty($b->manifest['required']) ? 1 : 0))
                ?: (self::TIERS[$a->tier] - self::TIERS[$b->tier])
                ?: strcmp((string) $a->id, (string) $b->id);
        });
        foreach ($this->plugins as $p) {
            if ($p->status === 'enabled') {
                $this->refreshStatus($p);
            }
        }
        return $this;
    }

    /**
     * One instance per plugin per engine. PHP has one class table per process: two copies of the same plugin (two
     * versions, or a fixture and its original) cannot both be loaded in one request, so the class is checked to come
     * from this plugin's own entry file.
     */
    private function loadEntry(HAXCMSPluginRecord $p)
    {
        $m = $p->manifest;
        if ($p->instance !== null || ($m['kind'] ?? null) === 'pack' || empty($m['entry']['php'])) {
            return;
        }
        try {
            $p->instance = $this->instantiate($p);
        } catch (\Throwable $e) {
            $p->status = 'failed';
            $p->reason = 'entry failed to load: ' . $e->getMessage();
            $this->log[] = ['level' => 'error', 'plugin' => $p->id, 'message' => $p->reason];
        }
    }

    /**
     * Count one anonymous call. Returns null when it is within the limit, else the seconds to wait. The engine keeps
     * the counts in memory; a host whose requests are separate processes keeps them where they all see them.
     */
    protected function countAnonymousCall($key, $max, $windowMs)
    {
        $now = $this->now();
        $slot = $this->rate[$key] ?? null;
        if (!$slot || $now - $slot['start'] >= $windowMs) {
            $this->rate[$key] = ['start' => $now, 'count' => 1];
            return null;
        }
        if ($slot['count'] >= $max) {
            return max(1, (int) ceil(($slot['start'] + $windowMs - $now) / 1000));
        }
        $this->rate[$key]['count']++;
        return null;
    }

    /**
     * ctx.storage.update (spec/04): read, change and write one store as a single step. One PHP process runs one step at
     * a time, so here it is read, $fn, write; a host with store files holds a lock on the file for the same span.
     */
    public function updateStore($p, $id, callable $fn)
    {
        if (($p->stores[$id]['scope'] ?? null) === 'package') {
            throw new RuntimeException("{$p->id}: store \"$id\" is read-only (package scope)");
        }
        $next = $fn($this->readStore($p, $id));
        if ($next !== null) {
            $this->writeStore($p, $id, self::json($next));
        }
        return $next === null ? $this->readStore($p, $id) : self::json($next);
    }

    /**
     * A front-end file of a plugin, for GET .../plugins/<id>/assets/<path> (spec/07, assets). Only files under
     * frontend/dist of a plugin that is on, with an extension from the list, and (once the manifest has `assets`)
     * only the files it lists whose hash still matches. Every refusal is the same 404.
     * @return array{status: int, file?: string, type?: string}
     */
    public function asset($pluginId, $path)
    {
        $none = ['status' => 404];
        $p = is_string($pluginId) ? $this->get($pluginId) : null;
        if (!$p || !($p->status === 'active' || $p->status === 'inactive')) {
            return $none;
        }
        $rel = (string) $path;
        if ($rel === '' || strpos($rel, '\\') !== false || strpos($rel, "\0") !== false) {
            return $none;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || $seg[0] === '.') {
                return $none;
            }
        }
        $dot = strrpos($rel, '.');
        $type = $dot === false ? null : (self::ASSET_TYPES[strtolower(substr($rel, $dot))] ?? null);
        if (!$type) {
            return $none;
        }
        $base = realpath($p->dir . '/frontend/dist');
        $file = $base === false ? false : realpath("$base/$rel");
        // a link that leaves frontend/dist is not served
        if ($base === false || $file === false || !is_file($file) || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) {
            return $none;
        }
        if (isset($p->manifest['assets']) && is_array($p->manifest['assets'])) {
            $entry = null;
            foreach ($p->manifest['assets'] as $a) {
                if (is_array($a) && ($a['id'] ?? null) === $rel) {
                    $entry = $a;
                }
            }
            // a file changed since the build is not what was packaged, reviewed or signed
            if (!$entry || ($entry['hash'] ?? '') !== 'sha256-' . base64_encode(hash_file('sha256', $file, true))) {
                return $none;
            }
        }
        return ['status' => 200, 'file' => $file, 'type' => $type];
    }

    // ---- deployment targets (spec/14); the same rules as the spec's scripts/backend-needs.mjs ----
    /** What a plugin needs from a backend, in the order of schema/targets.json. */
    public function backendNeeds($m, $api = null)
    {
        $caps = $m['capabilities'] ?? [];
        $visitors = false;
        foreach (($api['paths'] ?? []) as $verbs) {
            foreach ((is_array($verbs) ? $verbs : []) as $op) {
                if (!is_array($op) || empty($op['operationId'])) {
                    continue;
                }
                $security = $op['security'] ?? $api['security'] ?? [];
                $optional = false;
                foreach ($security as $req) {
                    if (is_array($req) && count($req) === 0) {
                        $optional = true;
                    }
                }
                if (($op['x-hax-public'] ?? null) === true || $optional) {
                    $visitors = true;
                }
            }
        }
        $has = [
            'backend' => ($m['kind'] ?? null) !== 'pack' && (!empty($m['entry']) || !empty($m['openapi'])),
            'process' => in_array('exec:run', $caps, true),
            'visitors' => $visitors,
            'schedule' => !empty($m['schedules']) && is_array($m['schedules']),
            'network' => in_array('network:egress', $caps, true),
            'secrets' => in_array('settings:secret', $caps, true),
        ];
        $out = [];
        foreach (array_keys($this->targets['needs']) as $n) {
            if (!empty($has[$n])) {
                $out[] = $n;
            }
        }
        return $out;
    }
    /** How a plugin fits one target: `unmet` keeps it inactive there, `limited` is a caveat while it runs. */
    public function targetFit($m, array $needs, $target)
    {
        $t = $this->targets['targets'][$target];
        $unmet = [];
        $limited = [];
        $declared = $m['targets'] ?? null;
        if (is_array($declared) && !in_array($target, $declared, true)) {
            return ['unmet' => [['key' => "target.$target", 'message' => "Not made for the {$t['title']}: this plugin lists " . implode(', ', $declared)]], 'limited' => []];
        }
        foreach ($needs as $n) {
            $need = $this->targets['needs'][$n];
            if (in_array($n, $t['provides'], true)) {
                continue;
            }
            if (isset($t['partial'][$n])) {
                $limited[] = ['key' => "backend.$n", 'message' => "On the {$t['title']}, {$t['partial'][$n]}"];
            } elseif (!empty($need['hard'])) {
                $unmet[] = ['key' => "backend.$n", 'message' => "Needs {$need['title']}, which the {$t['title']} does not have"];
            } else {
                $limited[] = ['key' => "backend.$n", 'message' => "Needs {$need['title']}, which the {$t['title']} does not have"];
            }
        }
        return ['unmet' => $unmet, 'limited' => $limited];
    }
    /** Per target: full, limited or no. */
    public function targetSummary($m, array $needs)
    {
        $out = [];
        foreach ($this->targets['targets'] as $name => $t) {
            $runtimes = ($m['kind'] ?? null) !== 'pack' && isset($m['runtimes']) && is_array($m['runtimes']) ? $m['runtimes'] : null;
            if ($runtimes !== null && !array_intersect($runtimes, $t['runtimes'])) {
                $out[$name] = 'no';
                continue;
            }
            $fit = $this->targetFit($m, $needs, $name);
            $out[$name] = $fit['unmet'] ? 'no' : ($fit['limited'] ? 'limited' : 'full');
        }
        return $out;
    }
    /** The plugin registry (spec/03): one entry per installed plugin with what the system keeps track of. */
    public function registry()
    {
        $on = function ($x) {
            return $x->status === 'active' || $x->status === 'inactive';
        };
        $out = [];
        foreach ($this->plugins as $p) {
            $m = $p->manifest;
            $dependents = [];
            foreach ($this->plugins as $x) {
                if (!$on($x)) {
                    continue;
                }
                foreach ($x->manifest['dependencies'] ?? [] as $d) {
                    if (explode('@', $d)[0] === $p->id) {
                        $dependents[] = $x->id;
                        break;
                    }
                }
            }
            $out[] = [
                'id' => $p->id, 'title' => $m['title'] ?? $p->id, 'version' => $m['version'] ?? null, 'tier' => $p->tier, 'kind' => $m['kind'] ?? 'plugin',
                'status' => $p->status, 'reason' => $p->reason, 'enabled' => $on($p), 'required' => ($m['required'] ?? null) === true,
                'unmet' => $p->unmet ?: [], 'limited' => $on($p) ? $p->limited : [],
                'needs' => $p->needs, 'targets' => $p->status === 'refused' ? [] : $this->targetSummary($m, $p->needs),
                'staticCapable' => array_key_exists('staticCapable', $m) ? $m['staticCapable'] === true : !in_array('backend', $p->needs, true),
                'dependencies' => array_map(function ($d) {
                    return explode('@', $d)[0];
                }, $m['dependencies'] ?? []),
                'dependents' => $dependents,
            ];
        }
        return $out;
    }
    private function instantiate(HAXCMSPluginRecord $p)
    {
        $file = realpath($p->dir . '/' . $p->manifest['entry']['php']);
        if ($file === false) {
            throw new RuntimeException("{$p->manifest['entry']['php']} not found");
        }
        $class = self::studly($p->id);
        if (class_exists($class, false)) {
            $declared = (new ReflectionClass($class))->getFileName();
            if ($declared !== $file) {
                throw new RuntimeException("class $class is already declared by $declared");
            }
        } else {
            require_once $file;
        }
        if (!class_exists($class, false)) {
            throw new RuntimeException("$file does not declare class $class");
        }
        return new $class();
    }

    // ---- scheduled jobs (spec/13) ----
    public function runSchedule($pluginId, $jobId)
    {
        $p = $this->get($pluginId);
        $job = null;
        foreach ($p ? ($p->manifest['schedules'] ?? []) : [] as $j) {
            if ($j['id'] === $jobId) {
                $job = $j;
            }
        }
        if (!$job) {
            return ['status' => 404, 'body' => ['error' => "no schedule $jobId in $pluginId"]];
        }
        if ($p->status !== 'active') {
            return ['status' => 409, 'body' => ['error' => "$pluginId is {$p->status}"]];
        }
        $scope = $job['scope'] ?? 'site';
        $subject = ['job' => $jobId, 'plugin' => $pluginId, 'scope' => $scope, 'siteId' => $this->site['id'] ?? null, 'lastRun' => $this->lastRuns["$pluginId:$jobId"] ?? null];
        $before = $this->fire('schedule.before.run', $subject);
        if (!empty($before['veto'])) {
            return ['status' => 200, 'body' => ['ran' => false, 'skipped' => $before['veto']['message']]];
        }
        $started = $this->now();
        $ok = true;
        $error = null;
        $value = null;
        try {
            $r = $this->transaction(function () use ($p, $job) {
                return $this->invoke($p, 'schedule', $job['handler'], $this->makeCtx($p, []), []);
            }, ['bulk' => true, 'origin' => "schedule:$pluginId:$jobId"]);
            if (!empty($r['missing'])) {
                throw new RuntimeException("handler {$job['handler']} is not implemented in {$this->runtime}");
            }
            $value = $r['value'] ?? null;
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
            $this->log[] = ['level' => 'error', 'plugin' => $pluginId, 'message' => "schedule $jobId: $error"];
        }
        $this->lastRuns["$pluginId:$jobId"] = $this->now();
        $this->fire('schedule.after.run', ['job' => $jobId, 'plugin' => $pluginId, 'scope' => $scope, 'siteId' => $this->site['id'] ?? null, 'ok' => $ok, 'durationMs' => $this->now() - $started] + ($error !== null ? ['error' => $error] : []));
        return ['status' => $ok ? 200 : 500, 'body' => ['ran' => true, 'ok' => $ok] + ($value !== null ? ['result' => $value] : []) + ($error !== null ? ['error' => $error] : [])];
    }

    // metadata.plugins.<id> on core records (spec/04): only declared fields, with the declared type
    public function checkPluginMetadata($type, &$record)
    {
        $warnings = [];
        if (!is_array($record) || !isset($record['metadata']['plugins']) || !is_array($record['metadata']['plugins'])) {
            return $warnings;
        }
        $typeOk = function ($t, $v) {
            switch ($t) {
                case 'any':
                    return true;
                case 'integer':
                    return is_int($v);
                case 'number':
                    return is_int($v) || is_float($v);
                case 'array':
                    return is_array($v) && array_is_list($v);
                case 'object':
                    return is_array($v) && ($v === [] || !array_is_list($v));
                case 'string':
                    return is_string($v);
                case 'boolean':
                    return is_bool($v);
            }
            return false;
        };
        foreach ($record['metadata']['plugins'] as $pid => $fields) {
            $p = $this->get($pid);
            if (!$p || !is_array($fields)) {
                continue;
            }
            $decl = $p->entityMeta[$type]['fields'] ?? [];
            if ($type === 'site') {
                foreach ($p->stores as $st) {
                    if (($st['scope'] ?? null) === 'manifest') {
                        $decl[$st['id']] = ['type' => 'any'];
                    }
                }
            }
            foreach ($fields as $k => $v) {
                if (!isset($decl[$k])) {
                    unset($record['metadata']['plugins'][$pid][$k]);
                    $warnings[] = ['message' => "metadata.plugins.$pid.$k is not declared by $pid for $type; dropped", 'severity' => 'warning', 'plugin' => $pid];
                } elseif ($v !== null && !$typeOk($decl[$k]['type'], $v)) {
                    unset($record['metadata']['plugins'][$pid][$k]);
                    $warnings[] = ['message' => "metadata.plugins.$pid.$k must be {$decl[$k]['type']}; dropped", 'severity' => 'warning', 'plugin' => $pid];
                }
            }
        }
        foreach ($warnings as $w) {
            $this->log[] = ['level' => 'warn', 'plugin' => $w['plugin'], 'message' => $w['message']];
        }
        return $warnings;
    }

    // the unified contract (spec/05): x-hax-proxy never leaves the server
    public function unifiedContract($opts = [])
    {
        $scope = $opts['scope'] ?? null;
        // plugin: only that plugin's operations, the document served at .../plugins/<id>/openapi.json
        $only = $opts['plugin'] ?? null;
        $paths = [];
        foreach ($this->activePlugins() as $p) {
            if (empty($p->manifest['openapi']) || ($only && $p->id !== $only)) {
                continue;
            }
            $api = self::readAny("{$p->dir}/{$p->manifest['openapi']}");
            foreach ($api['paths'] ?? [] as $path => $verbs) {
                foreach ($verbs as $verb => $op) {
                    if (!is_array($op) || empty($op['operationId'])) {
                        continue;
                    }
                    $sc = $op['x-hax-scope'] ?? $api['x-hax-scope'] ?? 'site';
                    if ($scope && $sc !== $scope) {
                        continue;
                    }
                    unset($op['x-hax-proxy']);
                    $full = ($sc === 'system' ? '/system/api' : '/x/api') . "/v1/plugins/{$p->id}$path";
                    $paths[$full][$verb] = array_merge($op, ['security' => $op['security'] ?? $api['security'] ?? [], 'tags' => [$p->id]]);
                }
            }
        }
        ksort($paths, SORT_STRING);
        return ['openapi' => '3.1.0', 'info' => ['title' => $only ? "HAXcms plugin $only" : 'HAXcms plugins', 'version' => $this->haxcms['version']], 'paths' => $paths];
    }

    // the merged theme registry
    public function themeEntry($element)
    {
        $core = ['clean-one' => ['element' => 'clean-one', 'name' => 'Clean one', 'colorSchemes' => ['light', 'dark']], 'clean-two' => ['element' => 'clean-two', 'name' => 'Clean two', 'colorSchemes' => ['light']]];
        foreach ($this->activePlugins() as $p) {
            $d = "{$p->dir}/registries/themes";
            if (!is_dir($d)) {
                continue;
            }
            foreach (self::listDir($d, '/\.(json|ya?ml)$/') as $f) {
                $t = self::readAny("$d/$f");
                if (($t['element'] ?? null) === $element) {
                    return array_merge(['colorSchemes' => ['light']], $t, ['plugin' => $p->id]);
                }
            }
        }
        return isset($core[$element]) ? $core[$element] + ['plugin' => null] : null;
    }
    private static function listDir($d, $re)
    {
        $out = array_values(array_filter(scandir($d) ?: [], function ($f) use ($re) {
            return $f[0] !== '.' && preg_match($re, $f);
        }));
        sort($out, SORT_STRING);
        return $out;
    }

    // import.file: the implementer for the file's extension converts; core turns HTML into pages
    public function importFile($input)
    {
        try {
            $results = $this->callContract('import.file', 'convert', $input);
        } catch (\Throwable $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
        $r = $results[0] ?? null;
        if (!$r || empty($r['result'])) {
            return ['status' => 500, 'body' => ['error' => 'the importer returned nothing']];
        }
        if (!empty($r['result']['error'])) {
            return ['status' => 422, 'body' => ['error' => $r['result']['error'], 'plugin' => $r['plugin']]];
        }
        $title = $r['result']['title'] ?? preg_replace('/\.[^.]+$/', '', (string) ($input['name'] ?? 'import'));
        $items = $r['result']['items'] ?? self::outlineFromHtml($r['result']['html'] ?? '', ['method' => $input['method'] ?? 'site', 'parentId' => $input['parentId'] ?? null, 'titleValue' => $title]);
        $body = ['plugin' => $r['plugin'], 'title' => $title, 'items' => $items];
        if (!empty($r['result']['files'])) {
            $body['files'] = array_map(function ($f) {
                return $f['path'];
            }, $r['result']['files']);
        }
        return ['status' => 200, 'body' => $body];
    }

    public function contractImplementers($name)
    {
        $out = [];
        foreach ($this->activePlugins() as $x) {
            $e = self::implEntry($x->manifest, $name);
            if (!$e) {
                continue;
            }
            $row = ['id' => $x->id, 'title' => $x->manifest['title'] ?? null, 'description' => $x->manifest['description'] ?? null];
            if (!empty($e['accepts'])) {
                $row['accepts'] = $e['accepts'];
            }
            if (!empty($e['provides'])) {
                $row['provides'] = $e['provides'];
            }
            $out[] = $row;
        }
        return $out;
    }
    public function advertisedContracts()
    {
        $out = [];
        foreach ($this->coreContracts as $name => $def) {
            if (empty($def['advertise'])) {
                continue;
            }
            $impl = $this->contractImplementers($name);
            if (!$impl) {
                continue;
            }
            $provides = [];
            foreach ($impl as $x) {
                foreach ($x['provides'] ?? [] as $v) {
                    $provides[$v] = true;
                }
            }
            $provides = array_keys($provides);
            sort($provides, SORT_STRING);
            $out[$name] = ['provides' => $provides, 'providers' => array_map(function ($x) {
                return ['title' => $x['title'], 'provides' => $x['provides'] ?? []];
            }, $impl)];
        }
        return $out;
    }

    public function isListed($pg)
    {
        $m = $pg['metadata'] ?? [];
        $str = function ($v) {
            return is_bool($v) ? ($v ? 'true' : 'false') : strtolower((string) $v);
        };
        $no = function ($v) use ($str) {
            return $v === false || $v === 0 || in_array($str($v), ['false', '0', 'no', 'off'], true);
        };
        $yes = function ($v) use ($str) {
            return $v === true || $v === 1 || in_array($str($v), ['true', '1', 'yes', 'on'], true);
        };
        return !(array_key_exists('published', $m) && $no($m['published']))
            && !(array_key_exists('hideInMenu', $m) && $yes($m['hideInMenu']))
            && !in_array($pg['id'] ?? null, $this->fragments, true);
    }
    public function listRecords($type, $query = [])
    {
        $listed = array_key_exists('listed', $query) ? $query['listed'] : null;
        unset($query['listed']);
        $out = [];
        foreach ($this->records[$type] ?? [] as $r) {
            if ($listed !== null && !($type === 'page' && $this->isListed($r) === $listed)) {
                continue;
            }
            foreach ($query as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    continue 2;
                }
            }
            $out[] = $r;
        }
        return $out;
    }
    public function siteInfo()
    {
        if ($this->site === null) {
            return null;
        }
        $rec = $this->records['site'][$this->site['id']] ?? [];
        $domain = $rec['metadata']['site']['domain'] ?? null;
        $url = rtrim((string) ($domain ?: "https://haxcms.example.invalid/sites/{$this->site['name']}/"), '/') . '/';
        return $this->site + ['url' => $url];
    }
    public function elements($p)
    {
        $d = "{$p->dir}/registries/elements";
        return is_dir($d) ? array_map(function ($f) use ($d) {
            return self::readAny("$d/$f");
        }, self::listDir($d, '/\.(ya?ml|json)$/')) : [];
    }

    // legacy.settings (spec/02): `config:a.b` is the core config; anything else is a path in site.json
    public function legacyLocation($path)
    {
        if (strpos($path, 'config:') === 0) {
            return ['type' => 'config', 'id' => 'core', 'keys' => explode('.', substr($path, 7))];
        }
        return ['type' => 'site', 'id' => $this->site['id'], 'keys' => explode('.', $path)];
    }
    public function legacyValue($path)
    {
        if ($this->site === null && strpos($path, 'config:') !== 0) {
            return null;
        }
        $l = $this->legacyLocation($path);
        return self::getPath($this->records[$l['type']][$l['id']] ?? null, implode('.', $l['keys']));
    }
    public function effectiveSettings($p)
    {
        $out = $p->settings;
        foreach ($p->manifest['legacy']['settings'] ?? [] as $key => $path) {
            if (isset($p->explicit[$key])) {
                continue;
            }
            $v = $this->legacyValue($path);
            if ($v !== null && $v !== '') {
                $out[$key] = $this->coerceSetting($p, $key, $v);
            }
        }
        return $out;
    }
    public function coerceSetting($p, $key, $v)
    {
        foreach ($p->form as $f) {
            if (($f['property'] ?? null) !== $key) {
                continue;
            }
            if (($f['inputMethod'] ?? null) === 'boolean') {
                if (is_bool($v)) {
                    return $v;
                }
                return $v === 1 || in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
            }
            if (($f['inputMethod'] ?? null) === 'number') {
                return $v + 0;
            }
            return $v;
        }
        return $v;
    }
    public function moveLegacySettings($p)
    {
        $moved = [];
        foreach ($p->manifest['legacy']['settings'] ?? [] as $key => $path) {
            $v = $this->legacyValue($path);
            if ($v === null || $v === '') {
                continue;
            }
            if (!isset($p->explicit[$key])) {
                $moved[$key] = $this->coerceSetting($p, $key, $v);
            }
            $l = $this->legacyLocation($path);
            $rec = $this->records[$l['type']][$l['id']];
            self::removePath($rec, $l['keys']);
            $this->saveEntity($l['type'], $rec);
        }
        if ($moved) {
            $r = $this->saveConfig($p->id, $moved);
            if ($r['status'] !== 200) {
                throw new RuntimeException('moving legacy settings failed: ' . $r['body']['error']);
            }
            $this->log[] = ['level' => 'info', 'plugin' => $p->id, 'message' => 'moved ' . implode(', ', array_keys($moved)) . ' from the core location into plugin settings'];
        }
    }
    // remove the key, then any parent it leaves empty
    private static function removePath(array &$rec, array $keys)
    {
        $k = array_shift($keys);
        if (!$keys) {
            unset($rec[$k]);
            return;
        }
        if (!isset($rec[$k]) || !is_array($rec[$k])) {
            return;
        }
        self::removePath($rec[$k], $keys);
        if ($rec[$k] === []) {
            unset($rec[$k]);
        }
    }

    // ---- network ----
    public static function isPrivateHost($host)
    {
        return (bool) (preg_match('/^(localhost|127\.|10\.|192\.168\.|169\.254\.|0\.|\[?::1\]?$)/', $host) || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) || substr($host, -9) === '.internal' || substr($host, -6) === '.local');
    }
    private static function hostOf($url)
    {
        $h = parse_url((string) $url, PHP_URL_HOST);
        if (!is_string($h) || $h === '') {
            throw new RuntimeException("Invalid URL: $url");
        }
        return strtolower($h);
    }
    /**
     * ctx->fetch: redirects are followed (up to 5) and every hop is checked again: egress list, then the SSRF guard.
     * In this engine the answer comes from fetch fakes (exact URL, URL without query, host); a host turns live
     * network on by overriding fetchLive().
     * @return array{status: int, url: string, headers: array, body: string}
     */
    public function fetchFor($p, $url, $init = [])
    {
        $current = (string) $url;
        for ($hop = 0; $hop < 6; $hop++) {
            $host = self::hostOf($current);
            $hosts = $this->egressHosts($p);
            if (self::isPrivateHost($host)) {
                throw new RuntimeException("fetch to $host not allowed (private address)");
            }
            if (!in_array('network:egress', $p->manifest['capabilities'] ?? [], true) || !(in_array('*', $hosts, true) || in_array($host, $hosts, true))) {
                throw new RuntimeException("fetch to $host not allowed" . ($hop ? ' (redirected)' : ''));
            }
            $this->secretGuard($p, $host, $current, $init, $hop);
            // header names are recorded so cases can see where a key travelled; values never are
            $headerNames = array_map('strtolower', array_map('strval', array_keys((array) ($init['headers'] ?? []))));
            sort($headerNames);
            $this->fetchCalls[] = ['plugin' => $p->id, 'method' => strtoupper($init['method'] ?? 'GET'), 'url' => $current, 'headers' => $headerNames];
            $res = $this->fetchOnce($p, $current, $init, $host);
            $status = $res['status'];
            if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($res['headers']['location'])) {
                $current = self::resolveUrl($current, $res['headers']['location']);
                continue;
            }
            $res['url'] = $current;
            return $res;
        }
        throw new RuntimeException('too many redirects');
    }
    /** One hop, no redirects followed. Fakes here; HAXCMSPluginHost goes to the network when live. */
    protected function fetchOnce($p, $url, $init, $host)
    {
        $fakes = $this->fetchFakes;
        $fake = $fakes[$url] ?? $fakes[explode('?', $url)[0]] ?? $fakes[$host] ?? null;
        if (!$fake) {
            throw new RuntimeException('network is disabled in the harness');
        }
        $headers = [];
        foreach ($fake['headers'] ?? [] as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
        $body = is_string($fake['body'] ?? null) ? $fake['body'] : json_encode($fake['body'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ['status' => $fake['status'] ?? 200, 'headers' => $headers, 'body' => $body];
    }
    public static function resolveUrl($base, $location)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($location, '//') === 0) {
            return $b['scheme'] . ':' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $origin . $dir . $location;
    }
    // egress: fixed hosts, plus hosts of the URLs in any setting named by { setting } (spec/08)
    public function egressHosts($p)
    {
        return $this->hostsFrom($p, $p->manifest['egress'] ?? []);
    }
    /**
     * x-secret-egress (spec/07): a secret setting's value may only travel (URL, header or body; plain, URL-encoded or
     * base64) to the hosts its field names. A secret field without x-secret-egress may go to any egress host.
     */
    public function secretGuard($p, $host, $url, $init, $hop)
    {
        $eff = $this->effectiveSettings($p);
        $carriers = [(string) $url];
        foreach ((array) ($init['headers'] ?? []) as $v) {
            $carriers[] = (string) $v;
        }
        $carriers[] = is_string($init['body'] ?? null) ? $init['body'] : '';
        foreach ($p->form as $f) {
            if (empty($f['x-secret']) || !isset($f['x-secret-egress']) || !is_array($f['x-secret-egress'])) {
                continue;
            }
            $v = $eff[$f['property']] ?? null;
            if (!is_string($v) || strlen($v) < 4) {
                continue;
            }
            $forms = [$v, rawurlencode($v), base64_encode($v)];
            $carried = false;
            foreach ($carriers as $c) {
                foreach ($forms as $x) {
                    if ($x !== '' && strpos($c, $x) !== false) {
                        $carried = true;
                    }
                }
            }
            if (!$carried) {
                continue;
            }
            $allowed = $this->hostsFrom($p, $f['x-secret-egress']);
            if (!(in_array('*', $allowed, true) || in_array($host, $allowed, true))) {
                throw new RuntimeException("secret setting \"{$f['property']}\" may not be sent to $host" . ($hop ? ' (redirected)' : ''));
            }
        }
    }
    /** Hosts named by a list of egress-shaped entries (egress itself, or a secret field's x-secret-egress). */
    public function hostsFrom($p, array $entries)
    {
        $eff = $this->effectiveSettings($p);
        $out = [];
        foreach ($entries as $e) {
            if (is_string($e)) {
                $out[] = $e;
            } elseif (!empty($e['any'])) {
                $out[] = '*';
            } elseif (!empty($e['input'])) {
                if ($p->inputHost) {
                    $out[] = $p->inputHost;
                }
            } else {
                foreach (explode("\n", (string) ($eff[$e['setting']] ?? '')) as $u) {
                    $h = parse_url(trim($u), PHP_URL_HOST);
                    if (is_string($h) && $h !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', trim($u))) {
                        $out[] = strtolower($h);
                    }
                }
            }
        }
        return $out;
    }
    // x-hax-proxy (spec/05): a GET answered by core from the declaration, with no plugin code
    public function proxy($p, $op, $req)
    {
        $d = $op['proxy'];
        $eff = $this->effectiveSettings($p);
        $fill = function ($v) use ($eff) {
            return preg_replace_callback('/\{\{settings\.(\w+)\}\}/', function ($m) use ($eff) {
                return (string) ($eff[$m[1]] ?? '');
            }, (string) $v);
        };
        $url = $fill($d['url']);
        $parts = explode('?', $url, 2);
        parse_str($parts[1] ?? '', $q);
        foreach ($d['query'] ?? [] as $k => $v) {
            $q[$k] = $fill($v);
        }
        foreach ($d['pass'] ?? [] as $k) {
            if (isset($req['query'][$k])) {
                $q[$k] = (string) $req['query'][$k];
            }
        }
        $url = $parts[0] . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
        try {
            $res = $this->fetchFor($p, $url);
            $body = json_decode($res['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = $res['body'];
            }
            return ['status' => $res['status'], 'body' => $body];
        } catch (\Throwable $e) {
            $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => ($op['operationId'] ?: 'proxy') . ': ' . $e->getMessage()];
            return ['status' => 502, 'body' => ['error' => parse_url($url, PHP_URL_HOST) . ' could not be reached']];
        }
    }
    // ctx->exec: checks the exec list, records the call (file names only), answers from exec fakes
    public function execFor($p, $cmd, $args, $opts = [])
    {
        if (!in_array('exec:run', $p->manifest['capabilities'] ?? [], true) || !in_array($cmd, $p->manifest['exec'] ?? [], true)) {
            throw new RuntimeException("{$p->id} may not run \"$cmd\" (not in its exec list)");
        }
        if (!is_array($args) || array_filter($args, function ($a) {
            return !is_string($a);
        })) {
            throw new RuntimeException('exec args must be a list of strings (no shell)');
        }
        $files = array_keys($opts['files'] ?? []);
        sort($files, SORT_STRING);
        $this->execCalls[] = ['plugin' => $p->id, 'cmd' => $cmd, 'args' => array_map(function ($a) {
            return str_replace('{tmp}', '<tmp>', $a);
        }, $args), 'files' => $files];
        return $this->execOnce($p, $cmd, $args, (array) $opts);
    }
    /**
     * Runs one checked command. Here the answer comes from the case's exec fakes; HAXCMSPluginHost runs the command
     * for real (no shell, a private {tmp} folder for files, a timeout).
     */
    protected function execOnce($p, $cmd, array $args, array $opts)
    {
        $fake = $this->execFakes[$cmd] ?? null;
        if (!$fake) {
            throw new RuntimeException("exec \"$cmd\" has no fake in this case (run.exec)");
        }
        return ['code' => $fake['code'] ?? 0, 'stdout' => $fake['stdout'] ?? '', 'stderr' => $fake['stderr'] ?? ''];
    }
    public function effectivePrivacy($p)
    {
        $pr = $p->manifest['privacy'] ?? null;
        if (!$pr) {
            return null;
        }
        if (empty($pr['consentSetting']) || ($p->settings[$pr['consentSetting']] ?? null) === true) {
            return $pr;
        }
        return array_merge($pr, ['requiresConsent' => []]);
    }

    // a required settings field without a value is an unmet requirement on its own (spec/03)
    public function requiredUnmet($p)
    {
        $eff = $this->effectiveSettings($p);
        // what this backend's target cannot give the plugin comes first (spec/14): no setting fixes that
        $out = $p->targetUnmet;
        foreach ($p->form as $f) {
            $v = $eff[$f['property']] ?? null;
            if (!empty($f['required']) && ($v === null || $v === '')) {
                $out[] = ['key' => "settings.{$f['property']}", 'message' => 'Set ' . ($f['title'] ?? $f['property'])];
            }
        }
        return $out;
    }
    public function refreshStatus($p)
    {
        $before = $this->fire('plugin.before.requirements', $this->requiredUnmet($p), ['only' => $p->id, 'includeInactive' => true]);
        $p->unmet = $before['subject'] ?: [];
        $prev = $p->status;
        $p->status = $p->unmet ? 'inactive' : 'active';
        $this->fire('plugin.after.requirements', ['id' => $p->id, 'scope' => 'system', 'status' => $p->status, 'unmet' => $p->unmet], ['only' => $p->id, 'includeInactive' => true, 'previous' => $prev]);
    }

    // ---- enable / disable (spec/03) ----
    public function enable($id)
    {
        $p = $this->get($id);
        if (!$p) {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        if ($p->status === 'refused') {
            return ['status' => 409, 'body' => ['error' => $p->reason]];
        }
        $subject = ['id' => $id, 'version' => $p->manifest['version'], 'scope' => 'system'];
        $was = $p->status;
        $this->loadEntry($p);
        if ($p->status === 'failed' && $p->instance === null && is_string($p->reason) && strpos($p->reason, 'entry failed') === 0) {
            return ['status' => 409, 'body' => ['error' => $p->reason, 'status' => 'failed', 'previous' => $was]];
        }
        // a dependency that is off keeps it off; the caller enables the dependency first (after confirmation, spec/03)
        $missing = [];
        foreach ($p->manifest['dependencies'] ?? [] as $dep) {
            $d = $this->get(explode('@', $dep)[0]);
            if (!$d || !($d->status === 'active' || $d->status === 'inactive')) {
                $missing[] = explode('@', $dep)[0];
            }
        }
        if ($missing) {
            return ['status' => 409, 'body' => ['error' => 'enable its dependencies first', 'dependencies' => $missing]];
        }
        $p->reason = null;
        $p->status = 'active';
        try {
            $first = !isset($this->installed["$id:system"]);
            foreach (array_merge($first ? ['plugin.before.install'] : [], ['plugin.before.enable']) as $name) {
                $r = $this->fire($name, $subject, ['strict' => true]);
                if (!empty($r['veto'])) {
                    throw new RuntimeException($r['veto']['message']);
                }
            }
            if ($first) {
                $this->installed["$id:system"] = true;
                $this->markInstalled($p);
                $this->moveLegacySettings($p);
                $this->fire('plugin.after.install', $subject, ['strict' => true]);
            }
            $this->fire('plugin.after.enable', $subject, ['strict' => true]);
            $this->refreshStatus($p);
            return ['status' => 200, 'body' => ['id' => $id, 'status' => $p->status]];
        } catch (\Throwable $e) {
            $p->status = 'failed';
            $p->reason = $e->getMessage();
            $this->log[] = ['level' => 'error', 'plugin' => $id, 'message' => 'enable failed: ' . $e->getMessage()];
            return ['status' => 409, 'body' => ['error' => $e->getMessage(), 'status' => 'failed', 'previous' => $was]];
        }
    }
    /** A host records first installs so they survive requests. */
    protected function markInstalled($p)
    {
    }

    public function disable($id)
    {
        $p = $this->get($id);
        if (!$p) {
            return ['status' => 404, 'body' => ['error' => 'not installed']];
        }
        if (!empty($p->manifest['required'])) {
            return ['status' => 409, 'body' => ['error' => 'required plugins cannot be disabled']];
        }
        $dependents = [];
        foreach ($this->plugins as $x) {
            if ($x->status !== 'active' && $x->status !== 'inactive') {
                continue;
            }
            foreach ($x->manifest['dependencies'] ?? [] as $d) {
                if (explode('@', $d)[0] === $id) {
                    $dependents[] = $x->id;
                    break;
                }
            }
        }
        if ($dependents) {
            return ['status' => 409, 'body' => ['error' => 'other plugins depend on it', 'dependents' => $dependents]];
        }
        $subject = ['id' => $id, 'version' => $p->manifest['version'], 'scope' => 'system'];
        $r = $this->fire('plugin.before.disable', $subject);
        if (!empty($r['veto'])) {
            return ['status' => $r['veto']['status'] ?: 409, 'body' => ['error' => $r['veto']['message']]];
        }
        $this->fire('plugin.after.disable', $subject);
        $p->status = 'disabled';
        $p->reason = 'disabled';
        // blocks already placed keep rendering; the owner is told where they are (spec/07)
        $tags = [];
        foreach ($this->elements($p) as $e) {
            if (($e['role'] ?? null) === 'block') {
                $tags[] = $e['tag'];
            }
        }
        $inUse = [];
        foreach ($this->records['page'] ?? [] as $pg) {
            $hit = array_values(array_filter($tags, function ($t) use ($pg) {
                return (bool) preg_match('/<' . preg_quote($t, '/') . '[\s>\/]/', (string) ($pg['content'] ?? ''));
            }));
            if ($hit) {
                $inUse[] = ['page' => $pg['id'], 'tags' => $hit];
            }
        }
        return ['status' => 200, 'body' => ['id' => $id, 'status' => 'disabled'] + ($inUse ? ['inUse' => $inUse] : [])];
    }

    // ---- settings (spec/13 section 7) ----
    public function saveConfig($id, $values)
    {
        $p = $this->get($id);
        $values = (array) $values;
        $subject = ['plugin' => $id, 'scope' => 'system', 'values' => array_merge($p->settings, $values)];
        $before = $this->fire('config.before.save', $subject, ['previous' => $p->settings, 'configOf' => $id]);
        if (!empty($before['veto'])) {
            return ['status' => $before['veto']['status'] ?: 409, 'body' => ['error' => $before['veto']['message']]];
        }
        $prev = $p->settings;
        $p->settings = (array) ($before['subject']['values'] ?? []);
        foreach (array_keys($values) as $k) {
            $p->explicit[$k] = true;
        }
        $this->fire('config.after.save', $before['subject'], ['previous' => $prev, 'configOf' => $id]);
        if ($p->status === 'active' || $p->status === 'inactive') {
            $this->refreshStatus($p);
        }
        return ['status' => 200, 'body' => ['status' => $p->status], 'warnings' => $before['warnings'] ?? []];
    }

    // ---- stores ----
    public function storeKey($p, $id)
    {
        $s = $p->stores[$id] ?? null;
        if (!$s) {
            throw new RuntimeException("{$p->id}: unknown store \"$id\"");
        }
        if (in_array($s['scope'], self::SITE_SCOPES, true)) {
            $this->requireSite("{$p->id}: store \"$id\" ({$s['scope']} scope)");
        }
        return "{$p->id}:{$s['scope']}:" . (in_array($s['scope'], self::SITE_SCOPES, true) ? $this->site['id'] : '-') . ":$id";
    }
    public function readStore($p, $id)
    {
        $s = $p->stores[$id] ?? null;
        if (!$s) {
            throw new RuntimeException("{$p->id}: unknown store \"$id\"");
        }
        if (array_key_exists($id, $p->storeOverrides)) {
            return $p->storeOverrides[$id];
        }
        if ($s['scope'] === 'package') {
            return self::readAny("{$p->dir}/data/{$s['file']}");
        }
        if ($s['scope'] === 'manifest') {
            $this->requireSite("{$p->id}: store \"$id\" (manifest scope)");
            return self::getPath($this->records['site'][$this->site['id']] ?? null, "metadata.plugins.{$p->id}.$id");
        }
        return $this->memory[$this->storeKey($p, $id)] ?? null;
    }
    public function writeStore($p, $id, $value)
    {
        if (isset($p->stores[$id]) && $p->stores[$id]['scope'] === 'package') {
            throw new RuntimeException("{$p->id}: store \"$id\" is read-only (package scope)");
        }
        unset($p->storeOverrides[$id]);
        $value = self::json($value);
        if (isset($p->stores[$id]) && $p->stores[$id]['scope'] === 'manifest') {
            $this->requireSite("{$p->id}: store \"$id\" (manifest scope)");
            $this->bump('site', $this->site['id']);
            $this->records['site'][$this->site['id']]['metadata']['plugins'][$p->id][$id] = $value;
            return;
        }
        $this->memory[$this->storeKey($p, $id)] = $value;
    }

    // ---- ctx (spec/13 section 4) ----
    public function userFor($p)
    {
        if (!$this->user) {
            return null;
        }
        $full = in_array('users:full', $p->manifest['capabilities'] ?? [], true);
        $out = [];
        foreach ($this->user as $k => $v) {
            if ($k !== 'passwordHash' && $k !== 'tokens' && ($full || in_array($k, self::LIMITED_USER, true))) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
    public function makeCtx($p, array $extras = [])
    {
        return new HAXCMSPluginCtx($this, $p, $extras);
    }

    public function pluginSkills($audience)
    {
        $out = [];
        foreach ($this->activePlugins() as $x) {
            foreach ($x->manifest['skills'] ?? [] as $sk) {
                if (($sk['audience'] ?? null) !== $audience) {
                    continue;
                }
                $dir = "{$x->dir}/skills/{$sk['name']}";
                if (!is_file("$dir/SKILL.md")) {
                    continue;
                }
                $md = file_get_contents("$dir/SKILL.md");
                $meta = preg_match('/^---\n([\s\S]*?)\n---/', $md, $fm) ? (Yaml::parse($fm[1]) ?: []) : [];
                $files = array_values(array_filter(self::walk($dir), function ($f) {
                    return (bool) preg_match('/\.(md|json|txt)$/', $f);
                }));
                usort($files, function ($a, $b) {
                    return $a === 'SKILL.md' ? -1 : ($b === 'SKILL.md' ? 1 : strcmp($a, $b));
                });
                $out[] = ['plugin' => $x->id, 'name' => $sk['name'], 'description' => $meta['description'] ?? '', 'files' => array_map(function ($f) use ($dir) {
                    return ['path' => $f, 'content' => file_get_contents("$dir/$f")];
                }, $files)];
            }
        }
        return $out;
    }
    private static function walk($d, $pre = '')
    {
        $out = [];
        foreach (self::listDir($d, '/./') as $f) {
            if (is_dir("$d/$f")) {
                $out = array_merge($out, self::walk("$d/$f", "$pre$f/"));
            } else {
                $out[] = "$pre$f";
            }
        }
        return $out;
    }
    public static function globToRegex($pat)
    {
        $q = preg_quote($pat, '#');
        $q = str_replace(['\*\*', '\*'], ["\0", '[^/]*'], $q);
        return '#^' . str_replace("\0", '.*', $q) . '$#';
    }
    // managed files: only with output:managedFiles and only paths matching the manifest's managedFiles patterns
    public function writeManaged($p, $path, $content)
    {
        if (!in_array('output:managedFiles', $p->manifest['capabilities'] ?? [], true)) {
            throw new RuntimeException("{$p->id} needs output:managedFiles to write $path");
        }
        $ok = false;
        foreach ($p->manifest['managedFiles'] ?? [] as $x) {
            if (preg_match(self::globToRegex(is_string($x) ? $x : $x['path']), $path)) {
                $ok = true;
            }
        }
        if (!$ok || strpos($path, '..') !== false) {
            throw new RuntimeException("{$p->id} may not write $path: not in its managedFiles");
        }
        $owner = $this->managed[$path] ?? null;
        if ($owner && $owner['plugin'] !== $p->id) {
            throw new RuntimeException("$path is managed by {$owner['plugin']}");
        }
        $this->managed[$path] = ['plugin' => $p->id, 'content' => (string) $content];
        return ['path' => $path];
    }
    public function sitePath($p, $path)
    {
        $clean = ltrim((string) $path, '/');
        if (!preg_match('#^files/[A-Za-z0-9._/-]+$#', $clean) || strpos($clean, '..') !== false) {
            throw new RuntimeException("{$p->id}: site files live under files/ ($path)");
        }
        $this->requireSite("{$p->id}: site files");
        return $this->site['path'] . '/' . $clean;
    }
    public function putSiteFile($p, $path, $data)
    {
        $this->requireCap($p, 'file', 'write');
        $full = $this->sitePath($p, $path);
        $this->rememberFile($full);
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, base64_decode((string) $data));
        return ['path' => $path];
    }
    public function rememberFile($full)
    {
        $tx = $this->currentTx;
        if ($tx && $tx->snap !== null && !array_key_exists($full, $tx->snap['files'])) {
            $tx->snap['files'][$full] = is_file($full) ? file_get_contents($full) : null;
        }
    }
    public function removeSiteFile($p, $path)
    {
        $this->requireCap($p, 'file', 'write');
        $full = $this->sitePath($p, $path);
        $this->rememberFile($full);
        return ['removed' => is_file($full) && @unlink($full)];
    }
    public function pluginOperations($filter = [])
    {
        $mcp = !empty($filter['mcp']);
        $out = [];
        foreach ($this->activePlugins() as $x) {
            if (empty($x->manifest['openapi'])) {
                continue;
            }
            $api = self::readAny("{$x->dir}/{$x->manifest['openapi']}");
            foreach ($api['paths'] ?? [] as $path => $verbs) {
                foreach ($verbs as $verb => $op) {
                    if (!is_array($op) || empty($op['operationId'])) {
                        continue;
                    }
                    if ($mcp && ($op['x-hax-mcp'] ?? null) !== true) {
                        continue;
                    }
                    $scope = $op['x-hax-scope'] ?? $api['x-hax-scope'] ?? 'site';
                    $out[] = ['plugin' => $x->id, 'operationId' => $op['operationId'], 'method' => strtoupper($verb), 'path' => ($scope === 'system' ? '/system/api' : '/x/api') . "/v1/plugins/{$x->id}$path", 'summary' => $op['summary'] ?? '', 'readOnly' => $verb === 'get', 'mcp' => ($op['x-hax-mcp'] ?? null) === true];
                }
            }
        }
        return $out;
    }
    public function staticPrompts()
    {
        $list = [];
        foreach ($this->activePlugins() as $x) {
            $f = $x->manifest['docs']['prompts'] ?? null;
            if ($f && is_file("{$x->dir}/$f")) {
                foreach (self::readAny("{$x->dir}/$f")['prompts'] ?? [] as $pr) {
                    $list[] = ['plugin' => $x->id] + $pr;
                }
            }
        }
        return $list;
    }
    public function pluginPrompts()
    {
        if ($this->collectingPrompts !== null) {
            return $this->collectingPrompts;
        }
        $list = $this->staticPrompts();
        $this->collectingPrompts = $list;
        try {
            $list = $this->fire('register.before.prompts', $list)['subject'];
        } finally {
            $this->collectingPrompts = null;
        }
        return $list;
    }
    public function activeList()
    {
        return array_map(function ($x) {
            return ['id' => $x->id, 'version' => $x->manifest['version'], 'privacy' => $this->effectivePrivacy($x), 'capabilities' => $x->manifest['capabilities'] ?? [], 'skills' => $x->manifest['skills'] ?? [], 'implements' => array_map(function ($i) {
                return is_string($i) ? $i : $i['name'];
            }, $x->manifest['implements'] ?? [])];
        }, $this->activePlugins());
    }
    public function assetUrl($p, $path)
    {
        if (!preg_match('#^frontend/dist/[A-Za-z0-9._/-]+$#', (string) $path) || strpos($path, '..') !== false) {
            throw new RuntimeException("{$p->id}: assets.url only serves files under frontend/dist");
        }
        return "x/api/v1/plugins/{$p->id}/assets/" . substr($path, strlen('frontend/dist/'));
    }

    // ---- rebuild (spec/07 managed files) ----
    public function rebuild($reason = 'save')
    {
        $this->requireSite('rebuild');
        $before = $this->fire('rebuild.before', ['scope' => 'site', 'siteId' => $this->site['id'], 'reason' => $reason, 'fragments' => []]);
        if (!empty($before['veto'])) {
            return ['status' => $before['veto']['status'] ?: 409, 'body' => ['error' => $before['veto']['message']]];
        }
        $this->fragments = array_values(array_unique($before['subject']['fragments'] ?? []));
        $lines = [];
        foreach ($this->records['page'] ?? [] as $pg) {
            if ($this->isListed($pg)) {
                $lines[] = "- [{$pg['title']}](" . (($pg['slug'] ?? '') !== '' ? $pg['slug'] : $pg['id']) . ')';
            }
        }
        $llms = implode("\n", $lines);
        $blocks = [];
        $registry = [];
        foreach ($this->plugins as $x) {
            foreach ($this->elements($x) as $e) {
                if ($x->status === 'active' && ($e['role'] ?? null) === 'block') {
                    $blocks[] = $e['tag'];
                }
                if ($x->status !== 'refused') {
                    $registry[$e['tag']] = strpos($e['path'], 'frontend/dist/') === 0 ? $this->assetUrl($x, $e['path']) : $e['path'];
                }
            }
        }
        sort($blocks, SORT_STRING);
        ksort($registry, SORT_STRING);
        $this->afterRebuild($registry);
        $this->fire('rebuild.after', ['scope' => 'site', 'siteId' => $this->site['id'], 'reason' => $reason, 'files' => ['llms.txt', 'wc-registry.json', 'appstore.json']]);
        $resources = [];
        foreach ($this->activePlugins() as $x) {
            foreach ($x->manifest['managedFiles'] ?? [] as $e) {
                if (is_array($e) && !empty($e['title']) && isset($this->managed[$e['path']])) {
                    $resources[] = "- [{$e['title']}]({$e['path']})";
                }
            }
        }
        if ($resources) {
            $llms .= "\n\n## Resources\n" . implode("\n", $resources);
        }
        $apps = [];
        foreach ($this->activePlugins() as $x) {
            $d = "{$x->dir}/registries/appstore";
            if (is_dir($d)) {
                foreach (self::listDir($d, '/\.json$/') as $f) {
                    foreach (self::readAny("$d/$f")['apps'] ?? [] as $a) {
                        if (!empty($a['details']['title'])) {
                            $apps[] = $a['details']['title'];
                        }
                    }
                }
            }
        }
        return ['status' => 200, 'body' => ['fragments' => $this->fragments, 'llms' => $llms, 'blocks' => $blocks, 'registry' => $registry, 'apps' => $apps]];
    }
    /** A host writes the merged element registry where its sites read it. */
    protected function afterRebuild(array $registry)
    {
    }

    // ---- core entities ----
    public function requireCap($p, $type, $action)
    {
        $area = ['site' => 'site', 'page' => 'items', 'file' => 'files', 'config' => 'settings'][$type] ?? null;
        if (!$area) {
            return;
        }
        if ($type !== 'config') {
            $this->requireSite("$type records");
        }
        $caps = $p->manifest['capabilities'] ?? [];
        if (!in_array("$area:$action", $caps, true) && !($action === 'read' && in_array("$area:write", $caps, true))) {
            throw new RuntimeException("{$p->id} needs the $area:$action capability to $action $type records");
        }
    }
    // pages and site.json are files in a HAXcms site
    protected function writeToDisk($type, $record)
    {
        if ($this->site === null) {
            return;
        }
        if ($type === 'page') {
            $d = $this->site['path'] . '/pages/' . $record['id'];
            if (!is_dir($d)) {
                @mkdir($d, 0775, true);
            }
            @file_put_contents("$d/index.html", (string) ($record['content'] ?? $record['title'] ?? ''));
        }
        if ($type === 'site') {
            @file_put_contents($this->site['path'] . '/site.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
    public function bump($type, $id)
    {
        $this->revs["$type:$id"] = ($this->revs["$type:$id"] ?? 0) + 1;
    }
    public function rev($type, $id)
    {
        return $this->revs["$type:$id"] ?? 0;
    }
    public function saveEntity($type, $record)
    {
        if (in_array($type, ['site', 'page', 'file'], true)) {
            $this->requireSite("$type records");
        }
        $record = self::json($record);
        $id = $type === 'site' ? ($record['id'] ?? $this->site['id']) : ($record['id'] ?? $record['uuid'] ?? null);
        $previous = $this->records[$type][$id] ?? null;
        $op = $previous !== null ? 'update' : 'create';
        $before = $this->fire("entity.before.$op", array_merge($record, ['id' => $id]), ['entity' => $type, 'previous' => $previous]);
        if (!empty($before['veto'])) {
            return ['record' => $previous, 'veto' => $before['veto'], 'warnings' => $before['warnings']];
        }
        $subject = $before['subject'];
        $metaWarnings = $this->checkPluginMetadata($type, $subject);
        $this->records[$type][$id] = $subject;
        $this->bump($type, $id);
        $this->writeToDisk($type, $this->records[$type][$id]);
        $after = $this->fire("entity.after.$op", $this->records[$type][$id], ['entity' => $type, 'previous' => $previous]);
        return ['record' => $after['subject'], 'warnings' => array_merge($before['warnings'], $metaWarnings)];
    }
    public function deleteEntity($type, $id)
    {
        $previous = $this->records[$type][$id] ?? null;
        if ($previous === null) {
            return ['deleted' => false];
        }
        $before = $this->fire('entity.before.delete', $previous, ['entity' => $type]);
        if (!empty($before['veto'])) {
            return ['deleted' => false, 'veto' => $before['veto']];
        }
        unset($this->records[$type][$id]);
        $this->bump($type, $id);
        $this->removeFromDisk($type, $id, $previous);
        $this->fire('entity.after.delete', $previous, ['entity' => $type]);
        return ['deleted' => true];
    }
    protected function removeFromDisk($type, $id, $previous)
    {
    }

    // ---- personal data (spec/04) ----
    public function personalStores()
    {
        $out = [];
        foreach ($this->plugins as $p) {
            foreach ($p->stores as $s) {
                if (!empty($s['personalData']) && $s['scope'] !== 'package' && ($this->site !== null || !in_array($s['scope'], self::SITE_SCOPES, true))) {
                    $out[] = ['p' => $p, 's' => $s, 'key' => $s['userKey'] ?? 'userId'];
                }
            }
        }
        return $out;
    }
    public function exportUser($userId)
    {
        $before = $this->fire('user.before.export', ['userId' => $userId]);
        if (!empty($before['veto'])) {
            return ['status' => $before['veto']['status'] ?: 409, 'body' => ['error' => $before['veto']['message']]];
        }
        $out = [];
        foreach ($this->personalStores() as $ps) {
            $v = $this->readStore($ps['p'], $ps['s']['id']);
            $rows = is_array($v) ? array_values($v) : [];
            $mine = array_values(array_filter($rows, function ($r) use ($ps, $userId) {
                return is_array($r) && ($r[$ps['key']] ?? null) === $userId;
            }));
            if ($mine) {
                $out["{$ps['p']->id}.{$ps['s']['id']}"] = $mine;
            }
        }
        foreach ($this->callContract('privacy.export', 'export', ['userId' => $userId]) as $r) {
            if ($r['result'] !== null) {
                $out[$r['plugin']] = $r['result'];
            }
        }
        $this->fire('user.after.export', ['userId' => $userId, 'stores' => array_keys($out)]);
        return ['status' => 200, 'body' => $out];
    }
    public function eraseUser($userId)
    {
        return $this->transaction(function () use ($userId) {
            return $this->eraseUserNow($userId);
        }, ['bulk' => false, 'origin' => 'privacy']);
    }
    private function eraseUserNow($userId)
    {
        $before = $this->fire('user.before.erase', ['userId' => $userId]);
        if (!empty($before['veto'])) {
            return ['status' => $before['veto']['status'] ?: 409, 'body' => ['error' => $before['veto']['message']]];
        }
        $removed = 0;
        $by = [];
        foreach ($this->personalStores() as $ps) {
            $v = $this->readStore($ps['p'], $ps['s']['id']);
            $n = 0;
            if (is_array($v)) {
                $keep = array_filter($v, function ($r) use ($ps, $userId) {
                    return !(is_array($r) && ($r[$ps['key']] ?? null) === $userId);
                });
                $n = count($v) - count($keep);
                if ($n) {
                    $this->writeStore($ps['p'], $ps['s']['id'], array_is_list($v) ? array_values($keep) : $keep);
                }
            }
            if ($n) {
                $by["{$ps['p']->id}.{$ps['s']['id']}"] = $n;
            }
            $removed += $n;
        }
        foreach ($this->callContract('privacy.delete', 'delete', ['userId' => $userId]) as $r) {
            $n = $r['result']['removed'] ?? 0;
            if ($n) {
                $by[$r['plugin']] = $n;
            }
            $removed += $n;
        }
        $this->fire('user.after.erase', ['userId' => $userId, 'removed' => $by]);
        return ['status' => 200, 'body' => ['removed' => $removed]];
    }

    // ---- upgrade steps (spec/13): every step newer than the installed version, in order ----
    public function upgrade($id, $fromVersion)
    {
        $p = $this->get($id);
        $versions = [];
        foreach ($p->instance ? get_class_methods($p->instance) : [] as $m) {
            if (strpos($m, 'upgrade_') === 0) {
                $versions[] = str_replace('_', '.', substr($m, 8));
            }
        }
        $versions = array_values(array_filter($versions, function ($v) use ($fromVersion, $p) {
            return self::cmpVersion($v, $fromVersion) > 0 && self::cmpVersion($v, $p->manifest['version']) <= 0;
        }));
        usort($versions, [self::class, 'cmpVersion']);
        $subject = ['id' => $id, 'version' => $p->manifest['version'], 'scope' => 'system'];
        try {
            $this->transaction(function ($tx) use ($p, $id, $versions, $subject, $fromVersion) {
                $b = $this->fire('plugin.before.upgrade', $subject, ['previous' => $fromVersion, 'strict' => true, 'transaction' => $tx]);
                if (!empty($b['veto'])) {
                    throw new RuntimeException($b['veto']['message']);
                }
                foreach ($versions as $v) {
                    $ctx = $this->makeCtx($p, ['transaction' => $tx, 'previous' => $fromVersion]);
                    $r = $this->invoke($p, 'upgrade', 'upgrade_' . str_replace('.', '_', $v), $ctx);
                    $this->log[] = ['level' => 'info', 'plugin' => $id, 'message' => "upgrade step $v ran"];
                    if (!empty($r['missing'])) {
                        throw new RuntimeException("upgrade step $v missing");
                    }
                }
                $this->fire('plugin.after.upgrade', $subject, ['previous' => $fromVersion, 'transaction' => $tx]);
            }, ['bulk' => true, 'origin' => 'upgrade']);
            return ['status' => 200, 'body' => ['id' => $id, 'ran' => $versions]];
        } catch (\Throwable $e) {
            $p->status = 'failed';
            $p->reason = 'upgrade failed: ' . $e->getMessage();
            $this->log[] = ['level' => 'error', 'plugin' => $id, 'message' => $p->reason];
            return ['status' => 409, 'body' => ['error' => $e->getMessage(), 'status' => 'failed']];
        }
    }

    // ---- transactions (spec/06): nested ones join the outer one ----
    /** A copy of everything an atomic transaction may put back. A host adds its own storage. */
    protected function snapshot()
    {
        return ['records' => $this->records, 'memory' => $this->memory, 'revs' => $this->revs, 'managed' => $this->managed, 'files' => []];
    }
    protected function restore(array $snap)
    {
        $this->records = $snap['records'];
        $this->memory = $snap['memory'];
        $this->revs = $snap['revs'];
        $this->managed = $snap['managed'];
        foreach ($snap['files'] as $full => $before) {
            if ($before === null) {
                @unlink($full);
            } else {
                if (!is_dir(dirname($full))) {
                    mkdir(dirname($full), 0775, true);
                }
                file_put_contents($full, $before);
            }
        }
    }
    /**
     * Opens a transaction (or joins the open one: returns null then). Pair with endTransaction(). transaction() is
     * the usual way; the split form exists for callers that cannot wrap the work in a closure (test adapters).
     */
    public function beginTransaction(array $o = [])
    {
        $atomic = !empty($o['atomic']);
        if ($this->currentTx) {
            if ($atomic && empty($this->currentTx['atomic'])) {
                throw new RuntimeException("an atomic transaction cannot join the open non-atomic one ({$this->currentTx['origin']})");
            }
            return null;
        }
        $tx = new HAXCMSPluginTransaction(['id' => 't' . count($this->log) . '-' . (int) (microtime(true) * 1000), 'origin' => $o['origin'] ?? 'harness', 'bulk' => array_key_exists('bulk', $o) ? (bool) $o['bulk'] : true, 'atomic' => $atomic, 'operations' => [], 'marks' => []]);
        $tx->snap = $atomic ? $this->snapshot() : null;
        $this->currentTx = $tx;
        try {
            $this->fire('transaction.before.start', $tx->toArray(), ['transaction' => $tx]);
            $this->fire('transaction.after.start', $tx->toArray(), ['transaction' => $tx]);
        } catch (\Throwable $e) {
            $this->currentTx = null;
            throw $e;
        }
        return $tx;
    }
    /**
     * Ends the transaction begun by beginTransaction(). $failure ['message', 'status'] (or a returned veto) rolls an
     * atomic one back and throws HAXCMSPluginRolledBack.
     */
    public function endTransaction($tx, $failure = null)
    {
        if ($tx === null) {
            return;
        }
        try {
            if ($failure !== null) {
                if ($tx->snap !== null) {
                    $this->rollBack($tx, $failure['message'], $failure['status'] ?? 500);
                }
                return;
            }
            $end = $this->fire('transaction.before.end', $tx->toArray(), ['transaction' => $tx]);
            if ($tx->snap !== null && !empty($end['veto'])) {
                $this->rollBack($tx, $end['veto']['message'], $end['veto']['status'] ?: 409);
            }
            $tx['ok'] = empty($end['veto']);
            $this->fire('transaction.after.end', $tx->toArray(), ['transaction' => $tx]);
        } finally {
            $this->currentTx = null;
        }
    }
    private function rollBack($tx, $message, $status)
    {
        $this->restore($tx->snap);
        $tx['ok'] = false;
        $tx['failed'] = ['message' => $message, 'status' => $status];
        $tx['marks'] = [];
        $this->fire('transaction.after.end', $tx->toArray(), ['transaction' => $tx]);
        throw new HAXCMSPluginRolledBack($message, $status);
    }
    /**
     * Atomic (opt-in): every record, store and site file the transaction wrote is put back if it fails; the default
     * keeps writes that happened, which is what the outline editor relies on.
     */
    public function transaction(callable $fn, array $o = [])
    {
        if ($this->currentTx) {
            if (!empty($o['atomic']) && empty($this->currentTx['atomic'])) {
                throw new RuntimeException("an atomic transaction cannot join the open non-atomic one ({$this->currentTx['origin']})");
            }
            return $fn($this->currentTx);
        }
        $o += ['bulk' => true, 'origin' => 'harness'];
        $tx = $this->beginTransaction($o);
        try {
            $result = $fn($tx);
        } catch (HAXCMSPluginRolledBack $e) {
            $this->currentTx = null;
            throw $e;
        } catch (\Throwable $e) {
            if ($tx->snap !== null) {
                $this->endTransaction($tx, ['message' => $e->getMessage(), 'status' => 500]);
            }
            $this->currentTx = null;
            throw $e;
        }
        if ($tx->snap !== null && is_array($result) && !empty($result['__veto'])) {
            $this->endTransaction($tx, ['message' => $result['message'], 'status' => $result['status'] ?? 409]);
        }
        $this->endTransaction($tx);
        return $result;
    }

    // ---- invoking plugin code ----
    public function invoke($p, $kind, $name, $ctx, array $args = [])
    {
        if (!$p->instance) {
            return ['missing' => true];
        }
        if ($kind === 'hook') {
            $method = self::camelHook($name);
        } elseif ($kind === 'contract') {
            [$c, $op] = explode('#', $name);
            $method = self::contractMethod($c, $op);
        } else {
            $method = $name;
        }
        if (!method_exists($p->instance, $method)) {
            return ['missing' => true];
        }
        return ['value' => $p->instance->$method($ctx, ...$args)];
    }

    // ---- contracts (spec/06, spec/13) ----
    public function callContract($name, $op, $input, $o = [])
    {
        $only = $o['only'] ?? null;
        $results = [];
        $def = $this->coreContracts[$name] ?? [];
        $dispatch = $def['dispatch'] ?? null;
        if ($dispatch === 'chosen' && !$only) {
            $ids = array_map(function ($x) {
                return $x['id'];
            }, $this->contractImplementers($name));
            throw new RuntimeException("$name calls one implementer the caller picks; choose one of: " . ($ids ? implode(', ', $ids) : 'none active'));
        }
        if ($dispatch === 'accepts' && !$only) {
            $ext = preg_match('/\.[a-z0-9]+$/', strtolower((string) ($input['name'] ?? '')), $mm) ? $mm[0] : '';
            foreach ($this->activePlugins() as $x) {
                if (in_array($ext, self::implEntry($x->manifest, $name)['accepts'] ?? [], true)) {
                    $only = $x->id;
                    break;
                }
            }
            if (!$only) {
                throw new RuntimeException("no active $name implementer accepts " . ($ext ?: 'files without an extension'));
            }
        }
        $wanted = $dispatch === 'provides' && is_array($input['provides'] ?? null) && $input['provides'] ? $input['provides'] : null;
        foreach ($this->activePlugins() as $p) {
            $impl = self::implEntry($p->manifest, $name);
            if (!$impl || ($only && $p->id !== $only) || ($wanted && !array_intersect($impl['provides'] ?? [], $wanted))) {
                continue;
            }
            $ctx = $this->makeCtx($p, ['subject' => $input]);
            if (is_array($input) && is_string($input['url'] ?? null) && trim($input['url']) !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', trim($input['url']))) {
                $input['url'] = 'https://' . trim($input['url']);
            }
            if (is_array($input) && !empty($input['url']) && $this->urlAliases) {
                $u = (string) $input['url'];
                foreach ($this->urlAliases as $a) {
                    $u = preg_replace('/' . str_replace('/', '\/', $a['from']) . '/', $a['to'], $u, 1);
                }
                $input['url'] = $u;
            }
            $h = is_array($input) && !empty($input['url']) ? parse_url((string) $input['url'], PHP_URL_HOST) : null;
            $p->inputHost = is_string($h) ? strtolower($h) : null;
            try {
                $r = $this->invoke($p, 'contract', "$name#$op", $ctx, [$input]);
                if (empty($r['missing'])) {
                    $results[] = ['plugin' => $p->id, 'result' => $r['value'] ?? null];
                }
            } catch (\Throwable $e) {
                $this->log[] = ['level' => 'error', 'plugin' => $p->id, 'message' => "$name.$op: " . $e->getMessage()];
            } finally {
                $p->inputHost = null;
            }
        }
        return $results;
    }
    // entries written since the last flush go to log.sink implementers (never their own entries)
    public function flushLog()
    {
        $start = $this->flushed;
        $this->flushed = count($this->log);
        $sinks = array_filter($this->activePlugins(), function ($x) {
            return self::implEntry($x->manifest, 'log.sink') !== null;
        });
        if (!$sinks) {
            return;
        }
        foreach (array_slice($this->log, $start, $this->flushed - $start) as $entry) {
            foreach ($sinks as $sink) {
                if (($entry['plugin'] ?? null) === $sink->id) {
                    continue;
                }
                $this->callContract('log.sink', 'write', array_merge(['time' => '1970-01-01T00:00:00.000Z', 'scope' => 'system'], $entry), ['only' => $sink->id]);
            }
        }
        $this->flushed = count($this->log);
    }

    // ---- operations (spec/13 section 5) ----
    /**
     * @param string $credentials both (user and site tokens), jwt (a JWT only) or none
     */
    /** $client is who is calling when nobody is signed in: the request's address. */
    public function call($operationId, array $request = [], $credentials = 'both', $client = null)
    {
        $p = null;
        foreach ($this->plugins as $x) {
            if (isset($x->ops[$operationId])) {
                $p = $x;
                break;
            }
        }
        if (!$p || $p->status !== 'active') {
            return ['status' => 404, 'body' => ['error' => 'not found']];
        }
        $op = $p->ops[$operationId];
        $optional = false;
        foreach ($op['security'] as $req) {
            if (is_array($req) && count($req) === 0) {
                $optional = true;
            }
        }
        if (!$op['public'] && !($optional && $credentials === 'none')) {
            $needsJwt = $needsUser = $needsSite = false;
            foreach ($op['security'] as $req) {
                $needsJwt = $needsJwt || array_key_exists('bearerAuth', (array) $req);
                $needsUser = $needsUser || array_key_exists('userTokenHeader', (array) $req) || array_key_exists('siteTokenHeader', (array) $req);
                $needsSite = $needsSite || array_key_exists('siteTokenHeader', (array) $req);
            }
            if ($needsJwt && $credentials === 'none') {
                return ['status' => 401, 'body' => ['error' => 'unauthorized']];
            }
            if ($needsUser && $credentials !== 'both') {
                return ['status' => 401, 'body' => ['error' => $needsSite ? 'X-HAXCMS-Site-Token header is required for this endpoint' : self::USER_TOKEN_REQUIRED]];
            }
        }
        // anonymous calls are limited per operation and client (spec/05): 60 a minute unless x-hax-rate-limit says otherwise
        if ($credentials === 'none' && ($op['public'] || $optional)) {
            $lim = is_array($op['rateLimit'] ?? null) && (int) ($op['rateLimit']['requests'] ?? 0) > 0 ? $op['rateLimit'] : self::RATE_DEFAULT;
            $win = self::RATE_WINDOWS[$lim['per'] ?? 'minute'] ?? self::RATE_WINDOWS['minute'];
            $retry = $this->countAnonymousCall("$operationId|" . ($client ?: '-'), (int) $lim['requests'], $win);
            if ($retry !== null) {
                return ['status' => 429, 'headers' => ['retry-after' => (string) $retry], 'body' => ['error' => 'too many requests']];
            }
        }
        $req = array_merge(['params' => [], 'query' => [], 'body' => [], 'headers' => [], 'files' => []], $request);
        $before = $this->fire('rest.before.request', array_merge(['method' => strtoupper($op['verb']), 'path' => $op['path'], 'operationId' => $operationId, 'plugin' => $p->id], $req));
        if (!empty($before['veto'])) {
            return ['status' => $before['veto']['status'] ?: 403, 'body' => ['error' => $before['veto']['message']]];
        }
        $req = array_merge($req, (array) $before['subject']);
        if ($op['proxy']) {
            $res = $this->proxy($p, $op, $req);
            $after = $this->fire('rest.after.request', ['status' => $res['status'], 'headers' => [], 'body' => $res['body']]);
            return array_merge($res, (array) $after['subject']);
        }
        $makeOpCtx = function () use ($p, $req, $credentials) {
            $c = $this->makeCtx($p, ['request' => $req]);
            if ($credentials === 'none') {
                $c->user = null;
            }
            return $c;
        };
        $ctx = $op['atomic'] ? null : $makeOpCtx();
        try {
            if ($op['atomic']) {
                $r = $this->transaction(function () use (&$ctx, $makeOpCtx, $p, $operationId, $req) {
                    $ctx = $makeOpCtx();
                    $out = $this->invoke($p, 'operation', $operationId, $ctx, [$req]);
                    return is_array($out['value'] ?? null) && !empty($out['value']['__veto']) ? $out['value'] : $out;
                }, ['bulk' => true, 'atomic' => true, 'origin' => "operation:$operationId"]);
            } else {
                $r = $this->invoke($p, 'operation', $operationId, $ctx, [$req]);
            }
            if (!empty($r['missing'])) {
                return ['status' => 501, 'body' => ['error' => "operation $operationId is not implemented in {$this->runtime}"]];
            }
            $v = $r['value'] ?? null;
            // a response envelope has an integer status and only status/headers/body keys (spec/13)
            $envelope = self::isAssoc($v) && is_int($v['status'] ?? null) && !array_diff(array_keys($v), ['status', 'headers', 'body']);
            $res = $envelope ? $v : ['status' => 200, 'body' => $v];
            $after = $this->fire('rest.after.request', ['status' => $res['status'], 'headers' => $res['headers'] ?? [], 'body' => $res['body'] ?? null]);
            return array_merge($res, (array) $after['subject'], ['warnings' => $ctx->warnings]);
        } catch (\Throwable $e) {
            $this->log[] = ['level' => 'error', 'plugin' => $p->id, 'message' => $e->getMessage()];
            $rolled = $e instanceof HAXCMSPluginRolledBack;
            if ($rolled && $e->status && $e->status !== 500) {
                return ['status' => $e->status, 'body' => ['error' => $e->getMessage(), 'rolledBack' => true]];
            }
            return ['status' => 500, 'body' => ['error' => 'internal error'] + ($rolled ? ['rolledBack' => true] : [])];
        }
    }

    // ---- hooks (spec/13 section 6) ----
    public function lineage($entity)
    {
        $out = [];
        $e = $entity;
        $guard = 0;
        while ($e && $guard++ < 32) {
            array_unshift($out, $e);
            $e = $this->entityParents[$e] ?? null;
        }
        return $out;
    }
    public function subscribers($name, array $o = [])
    {
        $entity = $o['entity'] ?? null;
        $subject = $o['subject'] ?? null;
        $previous = $o['previous'] ?? null;
        $only = $o['only'] ?? null;
        $includeInactive = !empty($o['includeInactive']);
        $configOf = $o['configOf'] ?? null;
        $out = [];
        $lineage = $entity ? $this->lineage($entity) : [];
        foreach ($this->plugins as $order => $p) {
            if ($only && $p->id !== $only) {
                continue;
            }
            $ownConfig = strpos($name, 'config.') === 0 && $configOf === $p->id && $p->status === 'inactive';
            if (!($p->status === 'active' || $ownConfig || ($includeInactive && ($p->status === 'inactive' || $p->status === 'enabled')))) {
                continue;
            }
            foreach ($p->manifest['hooks'] ?? [] as $sub) {
                $depthMatch = 0;
                $entityOk = function ($list) use ($entity, $lineage, &$depthMatch) {
                    if (!$list || !$entity) {
                        return true;
                    }
                    foreach ($lineage as $i => $t) {
                        if (in_array($t, $list, true)) {
                            $depthMatch = $i;
                            return true;
                        }
                    }
                    $depthMatch = -1;
                    return false;
                };
                $matches = false;
                if ($sub['name'] === $name) {
                    $matches = $entityOk($sub['entities'] ?? null);
                } else {
                    $c = $this->hookContract($sub['name']);
                    if ($c && !empty($c['aliasOf'])) {
                        foreach ($c['aliasOf'] as $a) {
                            if ($a['hook'] !== $name || !$entityOk($a['entities'] ?? null)) {
                                continue;
                            }
                            if (!empty($a['changed'])) {
                                $changed = false;
                                foreach ($a['changed'] as $path) {
                                    if (!self::jsonEq(self::getPath($subject, $path), self::getPath($previous, $path))) {
                                        $changed = true;
                                    }
                                }
                                if (!$changed) {
                                    continue;
                                }
                            }
                            $matches = true;
                            break;
                        }
                    }
                }
                if ($matches && strpos($name, 'config.') === 0) {
                    $scope = $sub['config'] ?? [$p->id];
                    $matches = in_array('*', $scope, true) || in_array($configOf, $scope, true);
                }
                if ($matches) {
                    $out[] = ['p' => $p, 'sub' => $sub, 'order' => $order, 'depth' => $depthMatch];
                }
            }
        }
        $stages = $this->hookContract($name)['stages'] ?? [];
        $stageOf = function ($x) use ($stages) {
            if (!$stages) {
                return 0;
            }
            $i = array_search($x['sub']['stage'] ?? $stages[0], $stages, true);
            return $i === false ? 0 : $i;
        };
        usort($out, function ($a, $b) use ($stageOf) {
            return ($a['depth'] - $b['depth']) ?: ($stageOf($a) - $stageOf($b)) ?: (($b['sub']['priority'] ?? 0) <=> ($a['sub']['priority'] ?? 0)) ?: ($a['order'] - $b['order']);
        });
        return $out;
    }
    public function requiredFieldsFor($entity)
    {
        foreach (array_reverse($this->lineage($entity)) as $t) {
            foreach ($this->plugins as $p) {
                if (!empty($p->entities[$t]['requiredFields'])) {
                    return $p->entities[$t]['requiredFields'];
                }
            }
        }
        return null;
    }

    /**
     * Fires a hook. Returns ['subject', 'veto' => [...]?, 'respond' => html?, 'warnings', 'transaction'].
     *
     * Timeouts: PHP cannot pre-empt a running function, so a subscriber that runs past its limit is not stopped;
     * when it returns, its result is discarded and the time-out is logged exactly as the reference engine logs it.
     * A subscriber that never returns is bounded by max_execution_time.
     */
    public function fire($name, $subject, array $o = [])
    {
        $current = $subject;
        $warnings = [];
        $setBy = [];
        $phase = $this->hookContract($name)['phase'] ?? (strpos($name, '.after') !== false ? 'after' : 'before');
        foreach ($this->subscribers($name, $o + ['subject' => $subject]) as $s) {
            $p = $s['p'];
            $sub = $s['sub'];
            // no re-entry (spec/06): a subscriber already running this hook further up the stack is skipped
            $key = $p->id . "\0" . $sub['name'];
            if (isset($this->running[$key])) {
                $this->log[] = ['level' => 'info', 'plugin' => $p->id, 'message' => "{$sub['name']}: not re-entered while it is already running"];
                continue;
            }
            $this->running[$key] = true;
            $ctx = $this->makeCtx($p, ['subject' => $current, 'previous' => $o['previous'] ?? null, 'transaction' => $o['transaction'] ?? null, 'entityType' => $o['entity'] ?? null]);
            try {
                $limit = $sub['timeout'] ?? $this->hookTimeout;
                $t0 = microtime(true);
                $r = $this->invoke($p, 'hook', $sub['name'], $ctx);
                if ((microtime(true) - $t0) * 1000 > $limit) {
                    throw new RuntimeException("{$p->id} {$sub['name']} timed out after $limit ms");
                }
                foreach ($ctx->warnings as $w) {
                    $warnings[] = $w + ['plugin' => $p->id];
                }
                if (!empty($r['missing'])) {
                    continue;
                }
                $v = self::json($r['value'] ?? null);
                if (is_array($v) && !empty($v['__veto'])) {
                    if ($phase === 'after') {
                        $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => "{$sub['name']}: after hooks cannot veto; ignored"];
                        continue;
                    }
                    return ['subject' => $current, 'veto' => ['message' => $v['message'], 'status' => $v['status'] ?? 409, 'plugin' => $p->id], 'warnings' => $warnings, 'transaction' => $o['transaction'] ?? null];
                }
                if (is_array($v) && array_key_exists('__respond', $v)) {
                    return ['subject' => $current, 'respond' => $v['__respond'], 'warnings' => $warnings];
                }
                if ($v !== null) {
                    $req = !empty($o['entity']) && strpos($name, 'entity.') === 0 ? $this->requiredFieldsFor($o['entity']) : null;
                    if ($req && is_array($v)) {
                        foreach ($req as $f) {
                            if (!array_key_exists($f, $v)) {
                                $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => "{$sub['name']}: returned {$o['entity']} is missing required fields; discarded"];
                                continue 2;
                            }
                        }
                    }
                    if (self::isAssoc($v) && is_array($current)) {
                        foreach ($v as $k => $val) {
                            if (!self::jsonEq($val, $current[$k] ?? null) || !array_key_exists($k, $current)) {
                                if (isset($setBy[$k]) && $setBy[$k] !== $p->id) {
                                    $this->log[] = ['level' => 'warn', 'plugin' => $p->id, 'message' => "{$sub['name']}: {$p->id} overwrote \"$k\" set by {$setBy[$k]}"];
                                }
                                $setBy[$k] = $p->id;
                            }
                        }
                    }
                    $current = $v;
                }
            } catch (\Throwable $e) {
                $this->log[] = ['level' => 'error', 'plugin' => $p->id, 'message' => "{$sub['name']}: " . $e->getMessage()];
                if (!empty($o['strict'])) {
                    throw $e;
                }
            } finally {
                unset($this->running[$key]);
            }
        }
        return ['subject' => $current, 'warnings' => $warnings, 'transaction' => $o['transaction'] ?? null];
    }

    // ---- render pipeline (spec/06) ----
    public function render($page, array $o = [])
    {
        $theme = $o['theme'] ?? 'clean-one';
        $mode = $o['mode'] ?? 'dynamic';
        $this->requireSite('rendering');
        $ctx0 = ['site' => ['id' => $this->site['id'], 'name' => $this->site['name']], 'page' => $page, 'theme' => $theme, 'mode' => $mode, 'cacheable' => true, 'head' => [], 'content' => $page['content'] ?? '', 'body' => ['start' => [], 'end' => []], 'html' => null];
        $before = $this->fire('render.before', $ctx0);
        if (array_key_exists('respond', $before)) {
            return ['html' => $before['respond'], 'cached' => true];
        }
        $c = $before['subject'];
        foreach (['render.before.head', 'render.after.head', 'render.before.content', 'render.after.content', 'render.before.body', 'render.after.body'] as $stage) {
            $c = $this->fire($stage, $c)['subject'];
        }
        $c['html'] = '<!doctype html><html><head>' . implode('', $c['head']) . '</head><body>' . implode('', $c['body']['start']) . '<main>' . $c['content'] . '</main>' . implode('', $c['body']['end']) . '</body></html>';
        $c = $this->fire('render.after', $c)['subject'];
        return ['html' => $c['html'], 'cached' => false];
    }

    // ---- core helpers the reference engine shares with haxcms-nodejs ----
    private static function slugify($t)
    {
        $s = strtolower((string) $t);
        $s = preg_replace('/<[^>]+>/', '', $s);
        $s = preg_replace('/&[a-z#0-9]+;/', '', $s);
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        return $s === '' ? 'page' : $s;
    }
    private static function textOf($h)
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', '', (string) $h)));
    }
    /** core's shared HTML-to-pages step (haxcms-nodejs importHtmlToItems) */
    public static function outlineFromHtml($html, array $o = [])
    {
        $method = $o['method'] ?? 'site';
        $parentId = $o['parentId'] ?? null;
        $titleValue = $o['titleValue'] ?? 'import';
        $items = [];
        $add = function ($title, $content, $parent, $slug, $indent = 0) use (&$items) {
            $order = count(array_filter($items, function ($x) use ($parent) {
                return $x['parent'] === $parent;
            }));
            $content = trim((string) $content);
            $it = ['id' => 'item-' . (count($items) + 1), 'title' => $title, 'slug' => $slug, 'parent' => $parent, 'order' => $order, 'indent' => $indent, 'content' => $content !== '' ? $content : '<p></p>'];
            $items[] = $it;
            return $it;
        };
        $levels = array_values(array_filter([1, 2, 3, 4], function ($n) use ($html) {
            return (bool) preg_match("/<h$n" . '[\s>]/i', $html);
        }));
        if ($method === 'page' || !$levels) {
            $add($titleValue, $html, $parentId, self::slugify($titleValue));
            return $items;
        }
        $top = $levels[0];
        $child = $method === 'site' && $top < 4 ? $top + 1 : null;
        $parts = preg_split("/(?=<h$top" . '[\s>])/i', $html);
        if (!preg_match("/^<h$top" . '[\s>]/i', $parts[0])) {
            $pre = array_shift($parts);
            if (self::textOf($pre) !== '') {
                $add($titleValue, $pre, $parentId, self::slugify($titleValue));
            }
        }
        foreach ($parts as $part) {
            if (!preg_match("/^<h$top" . "[^>]*>([\s\S]*?)<\/h$top>/i", $part, $m)) {
                continue;
            }
            $body = substr($part, strlen($m[0]));
            $title = self::textOf($m[1]) ?: $titleValue;
            if (!$child) {
                $add($title, $body, $parentId, self::slugify($title));
                continue;
            }
            $kids = preg_split("/(?=<h$child" . '[\s>])/i', $body);
            $root = $add($title, preg_match("/^<h$child" . '[\s>]/i', $kids[0]) ? '' : array_shift($kids), $parentId, self::slugify($title));
            foreach ($kids as $k) {
                if (!preg_match("/^<h$child" . "[^>]*>([\s\S]*?)<\/h$child>/i", $k, $km)) {
                    continue;
                }
                $kt = self::textOf($km[1]) ?: $title;
                $add($kt, substr($k, strlen($km[0])), $root['id'], $root['slug'] . '/' . self::slugify($kt), 1);
            }
        }
        return $items;
    }

    /** ctx->archive->read: a bounded zip reader core owns (the same limits and messages as the reference). */
    public static function readZip($b64, array $o = [])
    {
        $match = $o['match'] ?? '.*';
        $maxEntries = $o['maxEntries'] ?? 5000;
        $maxBytes = $o['maxBytes'] ?? 100 * 1024 * 1024;
        $binary = !empty($o['binary']);
        $buf = (string) base64_decode((string) $b64);
        $u32 = function ($i) use ($buf) {
            return unpack('V', substr($buf, $i, 4))[1];
        };
        $u16 = function ($i) use ($buf) {
            return unpack('v', substr($buf, $i, 2))[1];
        };
        if (strlen($buf) < 22 || $u32(0) !== 0x04034b50) {
            throw new RuntimeException('not a zip archive');
        }
        $eocd = -1;
        for ($i = strlen($buf) - 22; $i >= max(0, strlen($buf) - 65557); $i--) {
            if ($u32($i) === 0x06054b50) {
                $eocd = $i;
                break;
            }
        }
        if ($eocd < 0) {
            throw new RuntimeException('zip archive has no central directory');
        }
        $count = $u16($eocd + 10);
        if ($count > $maxEntries) {
            throw new RuntimeException("zip archive has $count entries (limit $maxEntries)");
        }
        $re = '/' . str_replace('/', '\/', $match) . '/';
        $out = [];
        $total = 0;
        $p = $u32($eocd + 16);
        for ($n = 0; $n < $count; $n++) {
            if ($u32($p) !== 0x02014b50) {
                throw new RuntimeException('corrupt zip central directory');
            }
            $method = $u16($p + 10);
            $csize = $u32($p + 20);
            $usize = $u32($p + 24);
            $nlen = $u16($p + 28);
            $elen = $u16($p + 30);
            $clen = $u16($p + 32);
            $off = $u32($p + 42);
            $name = substr($buf, $p + 46, $nlen);
            $p += 46 + $nlen + $elen + $clen;
            if (substr($name, -1) === '/' || !preg_match($re, $name)) {
                continue;
            }
            $total += $usize;
            if ($total > $maxBytes) {
                throw new RuntimeException("zip archive expands beyond $maxBytes bytes");
            }
            $start = $off + 30 + $u16($off + 26) + $u16($off + 28);
            $raw = substr($buf, $start, $csize);
            if ($method === 0) {
                $data = $raw;
            } elseif ($method === 8) {
                $data = @gzinflate($raw, max($usize, 1));
                if ($data === false) {
                    throw new RuntimeException("zip entry $name could not be inflated");
                }
            } else {
                throw new RuntimeException("zip entry $name uses unsupported compression $method");
            }
            $out[$name] = $binary ? base64_encode($data) : $data;
        }
        return $out;
    }
}
