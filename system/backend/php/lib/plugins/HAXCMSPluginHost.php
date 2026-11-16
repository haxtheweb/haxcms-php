<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356; mirrors haxcms-nodejs#138 src/lib/plugins/HaxcmsPluginHost.mjs).
 *
 * The plugin engine for one HAXcms site: HAXCMSPluginEngine with this site's real storage.
 *   - page and site records come from site.json and the page files, and saves write them back;
 *   - plugin stores live where spec/04 puts them (site: <site>/_config/data/<plugin>/, siteData:
 *     <root>/_config/siteData/<site uuid>/<plugin>/, system: <root>/_config/systemData/<plugin>/);
 *   - settings are saved to <root>/_config/plugins/config/<id>.json;
 *   - managed files are written into the site folder;
 *   - ctx->fetch goes to the network through SsrfGuard when liveNetwork is on, with the plugin's egress list checked
 *     again on every redirect hop;
 *   - ctx->exec runs the command when liveExec is on: no shell, files in a private {tmp} folder, a timeout;
 *   - ctx->now() is the real clock unless a test freezes it.
 * Unlike the Node host, an atomic transaction that fails also puts back what it wrote to disk (site.json, page
 * files, store files, managed files, plugin settings), not only the in-memory records.
 */
require_once __DIR__ . '/HAXCMSPluginEngine.php';

class HAXCMSPluginHost extends HAXCMSPluginEngine
{
    /** @var JSONOutlineSchema */
    public $manifest;
    public $siteDirectory;
    public $root;
    public $liveNetwork = false;
    public $liveExec = false;

    /**
     * @param array $o manifest (a loaded JSONOutlineSchema, or null for the system engine: HAXcms-wide work with no
     *   site, spec/13 section 8b), siteDirectory, root (HAXcms root for siteData, system stores and plugin settings),
     *   version, user (omitted: the engine's default admin), hookTimeout, liveNetwork, liveExec, specDir, now (null:
     *   the real clock)
     */
    public function __construct(array $o)
    {
        $m = $o['manifest'] ?? null;
        $site = null;
        if ($m) {
            $name = $m->metadata->site->name ?? basename($o['siteDirectory']);
            $site = ['id' => (string) $m->id, 'name' => $name, 'path' => rtrim($o['siteDirectory'], '/')];
        }
        $engine = [
            'haxcmsVersion' => $o['version'] ?? '26.10.1',
            'site' => $site,
            'hookTimeout' => $o['hookTimeout'] ?? 5000,
            'now' => array_key_exists('now', $o) ? $o['now'] : null,
        ];
        if (array_key_exists('user', $o)) {
            $engine['user'] = $o['user'];
        }
        if (!empty($o['target'])) {
            $engine['target'] = $o['target'];
        }
        if (!empty($o['specDir'])) {
            $engine['specDir'] = $o['specDir'];
        }
        parent::__construct($engine);
        $this->manifest = $m;
        $this->siteDirectory = $m ? rtrim($o['siteDirectory'], '/') : null;
        $this->root = rtrim($o['root'], '/');
        $this->liveNetwork = !empty($o['liveNetwork']);
        $this->liveExec = !empty($o['liveExec']);
        // HAXcms reads the site's records only once a plugin is active (HAXCMSPluginSystem::host); test hosts read now
        if (!array_key_exists('hydrate', $o) || $o['hydrate']) {
            $this->hydrate();
        }
    }

    /** Whether any plugin is active: when none is, nothing listens and core's saves need no engine work at all. */
    public function hasActive()
    {
        foreach ($this->plugins as $p) {
            if ($p->status === 'active') {
                return true;
            }
        }
        return false;
    }

    // ---- records: site.json and the page files ----
    public function hydrate()
    {
        $m = $this->manifest;
        if (!$m) {
            return;
        }
        $this->records['site'] = [$this->site['id'] => [
            'id' => (string) $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'license' => $m->license,
            'metadata' => self::json($m->metadata) ?: [],
            'items' => [],
        ]];
        $this->records['page'] = [];
        foreach ($m->items ?: [] as $it) {
            $this->records['page'][$it->id] = self::json(get_object_vars($it)) + ['content' => $this->readPage($it)];
        }
    }
    /** Re-reads site.json and the page files (records are what is on disk, not what was last held in memory). */
    public function reload()
    {
        if (!$this->manifest) {
            return;
        }
        $m = new JSONOutlineSchema();
        if ($m->load($this->manifest->file)) {
            $this->manifest = $m;
        }
        $this->hydrate();
    }
    private function readPage($item)
    {
        $body = $item->readLocation($this->siteDirectory . '/');
        return is_string($body) ? $body : '';
    }
    // seeding (tests and conformance cases) goes through the same write path as saves
    public function seed(array $records = [])
    {
        parent::seed($records);
        if (isset($records['site'])) {
            $this->writeToDisk('site', $this->records['site'][$this->site['id']]);
        }
        foreach ($this->records['page'] ?? [] as $r) {
            $this->writeToDisk('page', $r);
        }
    }
    private static function slug($t)
    {
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', preg_replace('/<[^>]+>/', '', strtolower((string) $t))), '-');
        return $s === '' ? 'page' : $s;
    }
    private function writeFile($full, $content)
    {
        $this->rememberFile($full);
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        if (file_put_contents($full, (string) $content) === false) {
            throw new RuntimeException('could not write ' . basename($full));
        }
    }
    private function saveManifest()
    {
        $this->rememberFile($this->manifest->file);
        $this->manifest->save(false);
    }
    // called by the engine after the before hooks accepted a save
    protected function writeToDisk($type, $record)
    {
        $m = $this->manifest;
        if (!$m) {
            return;
        }
        if ($type === 'page') {
            $item = $m->getItemById($record['id']);
            if (!$item) {
                $item = new JSONOutlineSchemaItem();
                $item->id = (string) $record['id'];
                $item->location = 'pages/' . self::slug(($record['slug'] ?? '') ?: (($record['title'] ?? '') ?: $record['id'])) . '-' . substr((string) $record['id'], -6) . '/index.html';
                $m->items[] = $item;
            }
            foreach (['title', 'slug', 'parent', 'order', 'indent', 'description'] as $k) {
                if (array_key_exists($k, $record) && $record[$k] !== null) {
                    $item->{$k} = $record[$k];
                }
            }
            if (!$item->slug) {
                $item->slug = self::slug(($record['title'] ?? '') ?: $record['id']);
            }
            $meta = $record['metadata'] ?? [];
            $item->metadata = $meta ? json_decode(json_encode($meta)) : new stdClass();
            $this->writeFile($this->siteDirectory . '/' . $item->location, (string) ($record['content'] ?? ''));
            $this->records['page'][$record['id']] = self::json(get_object_vars($item)) + ['content' => (string) ($record['content'] ?? '')];
            $this->saveManifest();
        } elseif ($type === 'site') {
            if (array_key_exists('title', $record)) {
                $m->title = $record['title'];
            }
            if (array_key_exists('description', $record)) {
                $m->description = $record['description'];
            }
            if (isset($record['metadata'])) {
                $m->metadata = json_decode(json_encode($record['metadata'] ?: new stdClass()));
            }
            $this->saveManifest();
        }
    }
    protected function removeFromDisk($type, $id, $previous)
    {
        if ($type !== 'page' || !$this->manifest) {
            return;
        }
        $m = $this->manifest;
        $item = $m->getItemById($id);
        $m->items = array_values(array_filter($m->items, function ($x) use ($id) {
            return $x->id !== $id;
        }));
        if ($item && $item->location) {
            $full = $this->siteDirectory . '/' . $item->location;
            if (is_file($full)) {
                $this->rememberFile($full);
                unlink($full);
                @rmdir(dirname($full));
            }
        }
        $this->saveManifest();
    }

    // ---- the site's public URL: its domain, else HAXCMS_PUBLIC_URL + the sites path ----
    public function siteInfo()
    {
        $info = parent::siteInfo();
        if ($info === null) {
            return null;
        }
        $domain = $this->manifest->metadata->site->domain ?? null;
        $base = getenv('HAXCMS_PUBLIC_URL');
        if (!$domain && $base) {
            $sites = getenv('HAXCMS_SITES_PATH') ?: 'sites';
            $info['url'] = rtrim($base, '/') . "/$sites/{$this->site['name']}/";
        }
        return $info;
    }

    // ---- settings: <root>/_config/plugins/config/<id>.json ----
    public function configFile($id)
    {
        return "{$this->root}/_config/plugins/config/$id.json";
    }
    public function saveConfig($id, $values)
    {
        $r = parent::saveConfig($id, $values);
        $p = $this->get($id);
        if ($r['status'] === 200 && $p) {
            $saved = [];
            foreach (array_keys($p->explicit) as $k) {
                $saved[$k] = $p->settings[$k] ?? null;
            }
            $this->writeFile($this->configFile($id), json_encode((object) $saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        return $r;
    }

    // ---- plugin stores at the spec/04 locations ----
    public function storeFile($p, $s)
    {
        $file = $s['file'] ?? "{$s['id']}.json";
        if (in_array($s['scope'], self::SITE_SCOPES, true)) {
            $this->requireSite("{$p->id}: store \"{$s['id']}\" ({$s['scope']} scope)");
        }
        switch ($s['scope']) {
            case 'site':
                return "{$this->siteDirectory}/_config/data/{$p->id}/$file";
            case 'siteData':
                return "{$this->root}/_config/siteData/{$this->site['id']}/{$p->id}/$file";
            case 'system':
                return "{$this->root}/_config/systemData/{$p->id}/$file";
        }
        return null;
    }
    public function readStore($p, $id)
    {
        $s = $p->stores[$id] ?? null;
        $file = $s && !array_key_exists($id, $p->storeOverrides) ? $this->storeFile($p, $s) : null;
        if (!$file) {
            return parent::readStore($p, $id);
        }
        if (!is_file($file)) {
            return null;
        }
        return json_decode((string) file_get_contents($file), true);
    }
    public function writeStore($p, $id, $value)
    {
        $s = $p->stores[$id] ?? null;
        $file = $s ? $this->storeFile($p, $s) : null;
        if (!$file) {
            parent::writeStore($p, $id, $value);
            return;
        }
        unset($p->storeOverrides[$id]);
        $this->writeFile($file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }

    // ---- managed files land in the site folder ----
    public function writeManaged($p, $path, $content)
    {
        $this->requireSite("{$p->id}: managed files");
        $r = parent::writeManaged($p, $path, $content);
        $this->writeFile($this->siteDirectory . '/' . $path, $content);
        return $r;
    }

    // ---- atomic transactions put back the disk too ----
    protected function snapshot()
    {
        $snap = parent::snapshot();
        $snap['explicit'] = [];
        $snap['settings'] = [];
        foreach ($this->plugins as $p) {
            $snap['settings'][$p->id] = $p->settings;
            $snap['explicit'][$p->id] = $p->explicit;
        }
        return $snap;
    }
    protected function restore(array $snap)
    {
        parent::restore($snap);
        foreach ($this->plugins as $p) {
            if (isset($snap['settings'][$p->id])) {
                $p->settings = $snap['settings'][$p->id];
                $p->explicit = $snap['explicit'][$p->id];
            }
        }
        // site.json was put back by the file snapshot: reload the outline from it
        $m = new JSONOutlineSchema();
        if ($this->manifest && $m->load($this->manifest->file)) {
            $this->manifest = $m;
        }
    }

    // ---- network: egress from the manifest, then core's SSRF-guarded fetch, one hop at a time ----
    protected function fetchOnce($p, $url, $init, $host)
    {
        if (!$this->liveNetwork) {
            return parent::fetchOnce($p, $url, $init, $host);
        }
        $body = $init['body'] ?? null;
        if ($body !== null && !is_string($body)) {
            $body = json_encode($body);
        }
        return SsrfGuard::safeFetchOnce($url, $init['method'] ?? 'GET', (array) ($init['headers'] ?? []), $body);
    }

    // ---- ctx->exec: the engine has checked the exec list and argument list; this runs the command (spec/13) ----
    /**
     * No shell: proc_open with an argument array. `files` go into a private temp folder, named {tmp} in arguments,
     * removed afterwards. The command runs in the site folder (or a `cwd` inside it) with a minimal environment
     * (PATH, HOME={tmp}, LANG), a timeout (60 s by default, 300 s at most) and a 10 MB cap on each of stdout and
     * stderr; a time-out or an output over the cap kills it and throws. Same rules as haxcms-nodejs's
     * HaxcmsPluginHost.execOnce.
     */
    protected function execOnce($p, $cmd, array $args, array $opts)
    {
        if (!$this->liveExec) {
            return parent::execOnce($p, $cmd, $args, $opts);
        }
        $files = (array) ($opts['files'] ?? []);
        foreach (array_keys($files) as $name) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', (string) $name) || $name === '.' || $name === '..') {
                throw new RuntimeException("exec file name \"$name\" is not a plain file name");
            }
        }
        // a site engine runs in the site folder (or under it); the system engine has none, so it runs in its temp folder
        $cwd = null;
        if ($this->siteDirectory !== null) {
            $site = realpath($this->siteDirectory);
            $cwd = realpath($site . '/' . (string) ($opts['cwd'] ?? '.'));
            if ($site === false || $cwd === false || ($cwd !== $site && strpos($cwd, $site . DIRECTORY_SEPARATOR) !== 0)) {
                throw new RuntimeException('exec cwd must be inside the site folder');
            }
        } elseif (isset($opts['cwd']) && $opts['cwd'] !== '.' && $opts['cwd'] !== '') {
            $this->requireSite('exec cwd');
        }
        $timeout = isset($opts['timeout']) && is_int($opts['timeout']) && $opts['timeout'] > 0 ? $opts['timeout'] : 60000;
        $timeout = min($timeout, 300000);
        $cap = 10 * 1024 * 1024;
        $tmp = self::tempDir('hax-exec-');
        chmod($tmp, 0700);
        if ($cwd === null) {
            $cwd = $tmp;
        }
        try {
            foreach ($files as $name => $f) {
                file_put_contents("$tmp/$name", (string) ($f['content'] ?? ''));
                chmod("$tmp/$name", isset($f['mode']) && is_int($f['mode']) ? $f['mode'] : 0600);
            }
            $argv = array_merge([$cmd], array_map(function ($a) use ($tmp) {
                return str_replace('{tmp}', $tmp, $a);
            }, $args));
            $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $tmp, 'LANG' => 'C.UTF-8'];
            $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
            if (!is_resource($proc)) {
                throw new RuntimeException("exec \"$cmd\" could not start");
            }
            $input = array_key_exists('input', $opts) && $opts['input'] !== null ? (string) $opts['input'] : '';
            if ($input !== '') {
                @fwrite($pipes[0], $input);
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $out = [1 => '', 2 => ''];
            $deadline = microtime(true) + $timeout / 1000;
            $failure = null;
            $open = [1 => $pipes[1], 2 => $pipes[2]];
            while ($open) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    $failure = "exec \"$cmd\" timed out after $timeout ms";
                    break;
                }
                $read = array_values($open);
                $w = null;
                $e = null;
                if (@stream_select($read, $w, $e, (int) floor($left), (int) (($left - floor($left)) * 1000000)) === false) {
                    break;
                }
                foreach ($open as $n => $pipe) {
                    if (!in_array($pipe, $read, true)) {
                        continue;
                    }
                    $chunk = fread($pipe, 65536);
                    if ($chunk === '' || $chunk === false) {
                        if (feof($pipe)) {
                            unset($open[$n]);
                        }
                        continue;
                    }
                    $out[$n] .= $chunk;
                    if (strlen($out[$n]) > $cap) {
                        $failure = "exec \"$cmd\" " . ($n === 1 ? 'stdout' : 'stderr') . ' exceeded 10 MB';
                        break 2;
                    }
                }
            }
            if ($failure !== null) {
                proc_terminate($proc, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($failure !== null) {
                throw new RuntimeException($failure);
            }
            return ['code' => $code < 0 ? 1 : $code, 'stdout' => $out[1], 'stderr' => $out[2]];
        } finally {
            self::removeTree($tmp);
        }
    }

    private static function removeTree($dir)
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') {
                self::removeTree("$dir/$n");
            }
        }
        @rmdir($dir);
    }
}
