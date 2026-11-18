<?php
/**
 * DRAFT (plugin platform). The PHP end of test/plugins/haxcms-php-adapter.mjs: one engine per process, driven by
 * JSON lines on stdin ({"m": method, "a": [args], "env": {...}}), one JSON line back per request with the result and
 * the state the spec's step runner reads synchronously (log, exec and fetch calls, statuses, managed files,
 * records, the open transaction's marks).
 *
 * HAXCMS_PLUGIN_ADAPTER_ENGINE=reference uses the in-memory HAXCMSPluginEngine; the default runs every case on a
 * real HAXcms site through HAXCMSPluginHost.
 */
$backend = dirname(__DIR__, 2) . '/system/backend/php';
require_once $backend . '/vendor/autoload.php';
require_once $backend . '/lib/plugins/HAXCMSPluginEngine.php';
if (getenv('HAXCMS_PLUGIN_ADAPTER_ENGINE') !== 'reference') {
    require_once __DIR__ . '/host-factory.php';
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    // a PHP warning inside the engine or a plugin is a bug in one of them: fail the step loudly
    // (unless the call was silenced with @, which leaves error_reporting() without that level)
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$engine = null;
$openTx = [];
$fireTx = null;

function adapter_state($e)
{
    global $fireTx;
    if (!$e) {
        return null;
    }
    $managed = [];
    foreach ($e->managed as $k => $v) {
        $managed[$k] = $v['content'];
    }
    return [
        'log' => $e->log,
        'execCalls' => $e->execCalls,
        'fetchCalls' => $e->fetchCalls,
        'status' => $e->status(),
        'managed' => (object) $managed,
        'records' => $e->records,
        'site' => $e->site,
        'tx' => $e->currentTx ? ['marks' => (object) $e->currentTx['marks']] : null,
        'fireMarks' => $fireTx ? (object) $fireTx['marks'] : null,
    ];
}

while (($line = fgets(STDIN)) !== false) {
    $msg = json_decode($line, true);
    $out = [];
    $fireTx = null;
    try {
        $a = $msg['a'] ?? [];
        if ($engine && isset($msg['env'])) {
            $env = $msg['env'];
            if (array_key_exists('now', $env)) { $engine->frozenNow = $env['now']; }
            if (array_key_exists('user', $env)) { $engine->user = $env['user']; }
            if (array_key_exists('execFakes', $env)) { $engine->execFakes = $env['execFakes'] ?: []; }
            if (array_key_exists('fetchFakes', $env)) { $engine->fetchFakes = $env['fetchFakes'] ?: []; }
            if (array_key_exists('urlAliases', $env)) { $engine->urlAliases = $env['urlAliases'] ?: []; }
        }
        switch ($msg['m']) {
            case 'create':
                $o = $a[0] ?? [];
                $engine = getenv('HAXCMS_PLUGIN_ADAPTER_ENGINE') === 'reference' ? new HAXCMSPluginEngine($o) : haxcms_plugin_test_host($o);
                $r = true;
                break;
            case 'load':
                $engine->load($a[0], $a[1] ?? []);
                $r = true;
                break;
            case 'fire':
                $o = $a[2] ?? [];
                if (isset($o['transaction']) && is_array($o['transaction'])) {
                    $fireTx = new HAXCMSPluginTransaction($o['transaction'] + ['operations' => [], 'marks' => []]);
                    $o['transaction'] = $fireTx;
                } else {
                    unset($o['transaction']);
                }
                $r = $engine->fire($a[0], $a[1], $o);
                unset($r['transaction']);
                break;
            case 'call':
                $r = $engine->call($a[0], $a[1] ?? [], $a[2]['credentials'] ?? 'both', $a[2]['client'] ?? null);
                break;
            case 'txBegin':
                $openTx[] = $engine->beginTransaction($a[0] ?? []);
                $r = true;
                break;
            case 'txEnd':
                $engine->endTransaction(array_pop($openTx), $a[0] ?? null);
                $r = true;
                break;
            case 'siteFileExists':
                $r = file_exists($engine->site['path'] . '/' . $a[0]);
                break;
            case 'render': case 'enable': case 'disable': case 'saveConfig': case 'upgrade': case 'saveEntity':
            case 'deleteEntity': case 'advertisedContracts': case 'rebuild': case 'callContract': case 'runSchedule':
            case 'importFile': case 'contractImplementers': case 'contractChoices': case 'exportUser': case 'eraseUser': case 'flushLog':
            case 'status': case 'unifiedContract': case 'reload': case 'registry': case 'asset':
                $r = $engine->{$msg['m']}(...$a);
                break;
            default:
                throw new RuntimeException("unknown adapter method {$msg['m']}");
        }
        $out['r'] = $r;
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        if (!empty($msg['trace']) || getenv('HAXCMS_PLUGIN_ADAPTER_TRACE')) {
            $out['trace'] = $e->getTraceAsString();
        }
    }
    $out['state'] = adapter_state($engine);
    $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    fwrite(STDOUT, $json . "\n");
    fflush(STDOUT);
}
