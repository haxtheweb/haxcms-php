<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356).
 *
 * The ctx a PHP plugin receives (spec/13 section 4), in the PHP spelling the spec's bridge uses
 * (harness/php/bridge.php): the same property and method names, so a plugin written against the reference harness
 * runs unchanged here. Unlike the bridge, every call takes effect in the engine at once (no replay after the call).
 */

/** A transaction as plugins see it: ['id', 'origin', 'bulk', 'atomic', 'marks']. Shared by every ctx in it. */
class HAXCMSPluginTransaction extends ArrayObject
{
    /** @var array|null snapshot for atomic rollback (engine-private) */
    public $snap = null;
    public function toArray()
    {
        return $this->getArrayCopy();
    }
}

class HAXCMSPluginRolledBack extends RuntimeException
{
    public $status;
    public function __construct($message, $status = 500)
    {
        parent::__construct($message, (int) $status);
        $this->status = (int) $status;
    }
}

class HAXCMSPluginCtxStorage
{
    private $e;
    private $p;
    public function __construct($e, $p)
    {
        $this->e = $e;
        $this->p = $p;
    }
    public function read($id)
    {
        return $this->e->readStore($this->p, $id);
    }
    public function write($id, $value)
    {
        $this->e->writeStore($this->p, $id, $value);
        return true;
    }
}

class HAXCMSPluginCtxPackage
{
    private $p;
    public function __construct($p)
    {
        $this->p = $p;
    }
    private function inData($path, $allowRoot)
    {
        $data = realpath($this->p->dir . '/data');
        $full = realpath($this->p->dir . '/' . $path);
        if ($data === false || $full === false || !(($allowRoot && $full === $data) || strpos($full, $data . DIRECTORY_SEPARATOR) === 0)) {
            return false;
        }
        return $full;
    }
    public function read($path, $opts = [])
    {
        $full = $this->inData($path, false);
        if ($full === false || !is_file($full)) {
            throw new Exception("package->read is limited to the plugin's data/ folder");
        }
        return file_get_contents($full);
    }
    public function list($path)
    {
        $base = $this->inData($path, true);
        if ($base === false) {
            throw new Exception("package->list is limited to the plugin's data/ folder");
        }
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($base) + 1));
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }
}

class HAXCMSPluginCtxLog
{
    private $e;
    private $id;
    public function __construct($e, $id)
    {
        $this->e = $e;
        $this->id = $id;
    }
    private function add($level, $message, $data)
    {
        $entry = ['level' => $level, 'plugin' => $this->id, 'message' => (string) $message];
        if ($data !== null) {
            $entry['data'] = $data;
        }
        $this->e->log[] = $entry;
    }
    public function info($m, $d = null)
    {
        $this->add('info', $m, $d);
    }
    public function warn($m, $d = null)
    {
        $this->add('warn', $m, $d);
    }
    public function error($m, $d = null)
    {
        $this->add('error', $m, $d);
    }
}

class HAXCMSPluginCtxEntities
{
    private $e;
    private $p;
    /** @var array<string, int> revisions seen by this ctx, "type:id" => rev */
    private $readRevs = [];
    public function __construct($e, $p)
    {
        $this->e = $e;
        $this->p = $p;
    }
    public function get($type, $id = null)
    {
        $this->e->requireCap($this->p, $type, 'read');
        $key = $type === 'site' ? ($id ?: $this->e->site['id']) : $id;
        $this->readRevs["$type:$key"] = $this->e->rev($type, $key);
        return $this->e->records[$type][$key] ?? null;
    }
    public function list($type, $query = [])
    {
        $this->e->requireCap($this->p, $type, 'read');
        return $this->e->listRecords($type, $query);
    }
    public function delete($type, $id)
    {
        $this->e->requireCap($this->p, $type, 'write');
        return $this->e->deleteEntity($type, $id);
    }
    public function save($type, $record)
    {
        $this->e->requireCap($this->p, $type, 'write');
        $key = $type === 'site' ? ($record['id'] ?? $this->e->site['id']) : ($record['id'] ?? $record['uuid'] ?? null);
        $seen = $this->readRevs["$type:$key"] ?? null;
        // a copy read before someone else changed the record is refused rather than overwriting their change
        if ($seen !== null && $seen !== $this->e->rev($type, $key)) {
            $this->e->log[] = ['level' => 'error', 'plugin' => $this->p->id, 'message' => "stale $type $key: changed since it was read; re-read and try again"];
            return ['record' => $this->e->records[$type][$key] ?? null, 'conflict' => true];
        }
        $r = $this->e->saveEntity($type, $record);
        $this->readRevs["$type:$key"] = $this->e->rev($type, $key);
        return $r;
    }
}

class HAXCMSPluginCtxConfig
{
    private $e;
    private $ctx;
    public function __construct($e, $ctx)
    {
        $this->e = $e;
        $this->ctx = $ctx;
    }
    public function save($values, $opts = [])
    {
        $r = $this->e->saveConfig($this->ctx->plugin['id'], $values);
        $p = $this->e->get($this->ctx->plugin['id']);
        if ($p) {
            $this->ctx->settings = $this->e->effectiveSettings($p);
        }
        return $r;
    }
}

class HAXCMSPluginCtxPlugins
{
    private $e;
    public function __construct($e)
    {
        $this->e = $e;
    }
    public function list()
    {
        return $this->e->activeList();
    }
    public function skills($audience)
    {
        return $this->e->pluginSkills($audience);
    }
    public function operations($filter = [])
    {
        return $this->e->pluginOperations($filter);
    }
    public function prompts()
    {
        return $this->e->pluginPrompts();
    }
    public function contract($opts = [])
    {
        return $this->e->unifiedContract($opts);
    }
}

class HAXCMSPluginCtxFiles
{
    private $e;
    private $p;
    public function __construct($e, $p)
    {
        $this->e = $e;
        $this->p = $p;
    }
    public function writeManaged($path, $content)
    {
        return $this->e->writeManaged($this->p, $path, $content);
    }
    public function readManaged($path)
    {
        return isset($this->e->managed[$path]) ? $this->e->managed[$path]['content'] : null;
    }
    /** @param string $data base64 */
    public function put($path, $data)
    {
        return $this->e->putSiteFile($this->p, $path, $data);
    }
    public function remove($path)
    {
        return $this->e->removeSiteFile($this->p, $path);
    }
}

class HAXCMSPluginCtxAssets
{
    private $e;
    private $p;
    public function __construct($e, $p)
    {
        $this->e = $e;
        $this->p = $p;
    }
    public function url($path)
    {
        return $this->e->assetUrl($this->p, $path);
    }
}

class HAXCMSPluginCtxArchive
{
    public function read($b64, $opts = [])
    {
        return HAXCMSPluginEngine::readZip($b64, $opts);
    }
}

class HAXCMSPluginCtxContracts
{
    private $e;
    public function __construct($e)
    {
        $this->e = $e;
    }
    public function call($name, $op, $input, $opts = [])
    {
        try {
            $r = $this->e->callContract($name, $op, $input, ['only' => $opts['plugin'] ?? null]);
            return count($r) ? $r[0]['result'] : ['error' => "no active implementer of $name"];
        } catch (\Throwable $ex) {
            return ['error' => $ex->getMessage()];
        }
    }
}

class HAXCMSPluginCtxHooks
{
    private $e;
    private $p;
    private $tx;
    public function __construct($e, $p, $tx)
    {
        $this->e = $e;
        $this->p = $p;
        $this->tx = $tx;
    }
    public function fire($name, $subject, $opts = [])
    {
        $core = $this->e->coreHook($name);
        if (!isset($this->p->hookDefs[$name]) && !($core && ($core['definedBy'] ?? null) === $this->p->id)) {
            throw new Exception("{$this->p->id} can only fire hooks it defines (not \"$name\")");
        }
        $r = $this->e->fire($name, $subject, array_merge((array) $opts, ['transaction' => $this->tx]));
        unset($r['transaction']);
        return $r;
    }
}

class HAXCMSPluginCtxRegistries
{
    private $e;
    public function __construct($e)
    {
        $this->e = $e;
    }
    public function theme($element)
    {
        return $this->e->themeEntry($element);
    }
}

class HAXCMSPluginCtx
{
    public $entityType = null;
    public $subject;
    public $previous;
    public $request;
    /** @var array effective settings */
    public $settings;
    public $haxcms;
    public $plugin;
    public $site;
    public $user;
    public $archive;
    public $contracts;
    /** @var HAXCMSPluginTransaction */
    public $transaction;
    public $storage;
    public $package;
    public $log;
    public $hooks;
    public $entities;
    public $config;
    public $plugins;
    public $assets;
    public $files;
    public $registries;
    /** @var array<int, array> warnings added with warn() */
    public $warnings = [];
    private $e;
    private $p;

    public function __construct(HAXCMSPluginEngine $e, $p, array $extras = [])
    {
        $this->e = $e;
        $this->p = $p;
        $this->subject = $extras['subject'] ?? null;
        $this->previous = $extras['previous'] ?? null;
        $this->entityType = $extras['entityType'] ?? null;
        $this->request = $extras['request'] ?? null;
        $this->settings = $e->effectiveSettings($p);
        $this->haxcms = (object) $e->haxcms;
        $this->plugin = ['id' => $p->id, 'version' => $p->manifest['version'] ?? null, 'scope' => 'system'];
        $this->site = $e->siteInfo();
        $this->user = $e->userFor($p);
        $this->transaction = $extras['transaction'] ?? $e->currentTx ?? new HAXCMSPluginTransaction(['id' => 't' . (int) (microtime(true) * 1000), 'origin' => 'implicit', 'bulk' => false, 'operations' => [], 'marks' => []]);
        $this->storage = new HAXCMSPluginCtxStorage($e, $p);
        $this->package = new HAXCMSPluginCtxPackage($p);
        $this->log = new HAXCMSPluginCtxLog($e, $p->id);
        $this->hooks = new HAXCMSPluginCtxHooks($e, $p, $this->transaction);
        $this->archive = new HAXCMSPluginCtxArchive();
        $this->contracts = new HAXCMSPluginCtxContracts($e);
        $this->config = new HAXCMSPluginCtxConfig($e, $this);
        $this->plugins = new HAXCMSPluginCtxPlugins($e);
        $this->files = new HAXCMSPluginCtxFiles($e, $p);
        $this->assets = new HAXCMSPluginCtxAssets($e, $p);
        $this->entities = new HAXCMSPluginCtxEntities($e, $p);
        $this->registries = new HAXCMSPluginCtxRegistries($e);
    }

    public function veto($message, $status = 409)
    {
        return ['__veto' => true, 'message' => $message, 'status' => $status];
    }
    public function warn($message, $severity = 'warning')
    {
        $this->warnings[] = ['message' => $message, 'severity' => $severity];
    }
    public function respond($html)
    {
        return ['__respond' => $html];
    }
    public function now()
    {
        return $this->e->now();
    }
    public function pluginEnabled($id)
    {
        return $this->e->active($id);
    }

    /**
     * PHP spelling of ctx.transaction.run(): opens (or joins) a transaction for the duration of $fn.
     * Atomic: a throw or a returned veto puts back what the transaction wrote and throws.
     */
    public function runTransaction($fn, $opts = [])
    {
        $opts = $opts + ['origin' => 'plugin', 'bulk' => true];
        return $this->e->transaction(function ($tx) use ($fn) {
            return $fn($tx);
        }, $opts);
    }

    /** ctx->fetch: egress and the SSRF guard checked on every hop. Returns ['status', 'headers', 'body', 'json']. */
    public function fetch($url, $init = [])
    {
        $r = $this->e->fetchFor($this->p, $url, (array) $init);
        $body = (string) ($r['body'] ?? '');
        return ['status' => $r['status'], 'headers' => $r['headers'] ?? [], 'body' => $body, 'json' => json_decode($body, true)];
    }

    /** ctx->exec: commands outside the plugin's exec list are refused; no shell. */
    public function exec($cmd, $args = [], $opts = [])
    {
        return $this->e->execFor($this->p, $cmd, $args, $opts);
    }
}
